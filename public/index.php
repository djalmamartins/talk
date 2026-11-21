<?php
declare(strict_types=1);

/**
 * Front controller do Connect Talk (aplicação própria, hospedada separada do ERP).
 * Fala com o ERP só por API REST (ver docs/api-erp.md).
 */

use ConnectTalk\Http\Router;
use ConnectTalk\Service\DomainError;

$app = require __DIR__ . '/../bootstrap.php';
$env = $app['env'];
$isLab = $env('APP_ENV') === 'lab';

// Atrás do proxy de produção (Caddy no docker-compose): HTTPS e IP do cliente vêm dos cabeçalhos.
// Só vale com TRUST_PROXY=1 (senão qualquer um forjaria o IP e driblaria o limite de tentativas de login).
if ($env('TRUST_PROXY') === '1') {
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        $_SERVER['HTTPS'] = 'on';
    }
    $fwd = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    if (filter_var($fwd, FILTER_VALIDATE_IP)) {
        $_SERVER['REMOTE_ADDR'] = $fwd;
    }
}

// verificação de saúde (Docker e monitoramento): banco respondendo e worker vivo; sem sessão
if (rtrim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $age = $app['worker']->secondsSinceHeartbeat();
        echo json_encode(['ok' => true, 'db' => 'ok', 'worker' => ['ok' => \ConnectTalk\Service\OutboxWorker::isAlive($age), 'seconds_since' => $age]]);
    } catch (\Throwable) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'db' => 'erro']);
    }
    exit;
}

session_name('talk_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

$json = static function ($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};
// envio acima do post_max_size: o PHP descarta o corpo inteiro (sem $_POST/$_FILES); avisa em vez de "sessão expirada"
if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
    $json(['error' => 'Arquivo maior que 16 MB.'], 413);
}
$body = static fn (): array => json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
// sessão vale enquanto o usuário está ativo e a senha não mudou depois do login
$sessionOk = static function () use ($app): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    if (!$app['login']->sessionValid((int) $_SESSION['user_id'], (int) ($_SESSION['auth_at'] ?? 0))) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return false;
    }
    return true;
};
$user = static function () use ($app, $json, $sessionOk) {
    if (!$sessionOk()) {
        $json(['error' => 'Faça login.'], 401);
    }
    return $app['staff']->find((int) $_SESSION['user_id']);
};
$csrf = static function () use ($json): void {
    $t = $_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], (string) $t)) {
        $json(['error' => 'Sessão expirada, recarregue a página.'], 403); // 419 não existe no Apache (vira 500)
    }
};
// arquivo enviado pelo formulário (campo "file"): [conteúdo, mime declarado, nome]
$upload = static function () use ($json): array {
    $f = $_FILES['file'] ?? null;
    if (!$f || is_array($f['error'])) {
        $json(['error' => 'Escolha um arquivo.'], 422);
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        $json(['error' => 'Arquivo maior que 16 MB.'], 422);
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $json(['error' => 'Falha no envio do arquivo.'], 422);
    }
    return [(string) file_get_contents($f['tmp_name']), (string) $f['type'], (string) $f['name']];
};
// versão dos arquivos de tela: muda a cada atualização, e o navegador não fica com CSS/JS velho
define('TALK_ASSET_V', (string) max(array_map('filemtime', array_merge([__FILE__], glob(__DIR__ . '/assets/*.css') ?: [], glob(__DIR__ . '/assets/*.js') ?: []))));
$view = static function (string $name, array $vars = []) use ($app): never {
    $vars['csrf'] ??= $_SESSION['csrf'];
    // o menu lateral mostra "Conexão WhatsApp" só para o supervisor
    $sm = !empty($_SESSION['user_id']) ? $app['staff']->find((int) $_SESSION['user_id']) : null;
    $vars['isSupervisor'] = $sm !== null && $sm->supervisor;
    $vars['canBulk'] = $sm !== null && ($sm->supervisor || $sm->managerSectors);
    $vars['roleLabel'] = $sm === null ? '' : ($sm->supervisor ? 'Supervisor' : ($sm->managerSectors ? 'Gerente de setor' : 'Atendente'));
    $vars['userEmail'] = $sm !== null ? $app['staff']->email($sm->userId) : '';
    $vars['systems'] = $app['systems'];
    extract($vars);
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . "/../views/$name.php";
    exit;
};
// no laboratório o envio é instantâneo: processa a fila ao final da requisição
if ($isLab) {
    register_shutdown_function(static function () use ($app): void {
        try {
            $app['conversations']->triage();
            $app['schedules']->runDue();
            $app['campaigns']->runDue();
            $app['worker']->runOnce();
            $app['worker']->fetchMedia();
        } catch (\Throwable $e) {
            error_log('[talk] worker: ' . $e->getMessage());
        }
    });
}

try {
    // ---------------------------------------------------------------- telas e rotas fora da API
    $web = new Router();
    (require __DIR__ . '/../src/Http/Routes/web.php')($web, $app,
        ['json' => $json, 'view' => $view, 'body' => $body, 'upload' => $upload, 'csrf' => $csrf, 'user' => $user], $isLab);
    (require __DIR__ . '/../src/Http/Routes/pages.php')($web, $app, $view, $sessionOk, $isLab);
    $web->dispatch($method, $path);

    // ---------------------------------------------------------------- API
    if (str_starts_with($path, '/api/')) {
        $me = $user();
        if ($method !== 'GET') {
            $csrf();
        }

        // rotas da API por área, em src/Http/Routes/
        $router = new Router();
        foreach (['inbox', 'conversations', 'team', 'channels', 'campaigns', 'settings'] as $area) {
            (require __DIR__ . "/../src/Http/Routes/$area.php")($router, $app, $me, $json, $body, $upload);
        }
        $router->dispatch($method, $path);
        $json(['error' => 'Rota não encontrada.'], 404);
    }

    http_response_code(404);
    echo 'Não encontrado';
} catch (DomainError $e) {
    $json(['error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (\Throwable $e) {
    error_log('[talk] ' . $e);
    $json(['error' => $isLab ? $e->getMessage() : 'Erro interno.'], 500);
}
