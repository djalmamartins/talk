<?php
declare(strict_types=1);

/**
 * Front controller do Connect Talk (aplicação própria, hospedada separada do ERP).
 * Fala com o ERP só por API REST (ver docs/api-erp.md).
 */

use ConnectTalk\Gateway\CloudApiGateway;
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
    // ---------------------------------------------------------------- webhooks (sem login)
    if (preg_match('#^/webhook/(\d+)$#', $path, $m)) {
        $channel = (int) $m[1];
        if ($method === 'GET') {
            $gw = $app['gateways']->forChannel($channel);
            $challenge = $gw instanceof CloudApiGateway ? $gw->verifySubscription($_GET) : null;
            http_response_code($challenge === null ? 403 : 200);
            echo $challenge ?? 'forbidden';
            exit;
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $r = $app['webhook']->handle($channel, (string) file_get_contents('php://input'), $headers);
        $json(['processed' => $r['processed']], $r['status']);
    }

    // ---------------------------------------------------------------- login
    if ($path === '/login') {
        if ($method === 'POST') {
            $csrf();
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if ($app['login']->blocked($email, $ip)) {
                http_response_code(429);
                $view('login', ['error' => 'Muitas tentativas. Espere 15 minutos e tente de novo.', 'isLab' => $isLab]);
            }
            $u = $app['login']->check($email, (string) ($_POST['password'] ?? ''));
            if ($u !== null) {
                $app['login']->succeeded($email);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $u['id'];
                $_SESSION['user_name'] = $u['name'];
                $_SESSION['auth_at'] = time();
                header('Location: /');
                exit;
            }
            $app['login']->failed($email, $ip);
            $view('login', ['error' => 'E-mail ou senha inválidos.', 'isLab' => $isLab]);
        }
        $view('login', ['error' => null, 'isLab' => $isLab]);
    }
    if ($path === '/logout' && $method === 'POST') {
        $csrf();
        session_destroy();
        header('Location: /login');
        exit;
    }
    if ($path === '/') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('inbox', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/contatos') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('contacts', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/agendamentos') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('schedules', ['isLab' => $isLab, 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/envios') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $me = $app['staff']->find((int) $_SESSION['user_id']);
        if (!$me->supervisor && !$me->managerSectors) {
            header('Location: /');
            exit;
        }
        $view('campaigns', ['isLab' => $isLab, 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/configuracoes') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        if (!$app['staff']->find((int) $_SESSION['user_id'])->supervisor) {
            header('Location: /');
            exit;
        }
        $view('settings', ['isLab' => $isLab, 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/equipe') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        if (!$app['staff']->find((int) $_SESSION['user_id'])->supervisor) {
            header('Location: /');
            exit;
        }
        $view('team', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/conexoes') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        if (!$app['staff']->find((int) $_SESSION['user_id'])->supervisor) {
            header('Location: /');
            exit;
        }
        $view('channels', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/painel') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('reports', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }
    if ($path === '/fila') {
        if (!$sessionOk()) {
            header('Location: /login');
            exit;
        }
        $view('queue', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }

    // ---------------------------------------------------------------- tempo real
    if ($path === '/events') {
        $me = $user();
        session_write_close(); // não travar a sessão enquanto o stream está aberto
        $app['queue']->touch($me->userId); // presença: conectado = online (o stream reconecta a cada ~25 s)
        $last = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last'] ?? 0);
        if ($last === 0) {
            $last = $app['events']->lastId();
        }
        // laboratório: sem worker, o próprio stream faz a triagem da Jack e os envios a cada ~2 s
        $app['sse']->stream($me, $last, tick: $isLab ? static function () use ($app): void {
            $app['conversations']->triage();
            $app['worker']->runOnce();
        } : null);
        exit;
    }

    // ---------------------------------------------------------------- arquivos (com conferência de acesso)
    if (preg_match('#^/media/(\d+)$#', $path, $m)) {
        $me = $user();
        session_write_close();
        $file = $app['inbox']->mediaFor($me, (int) $m[1]);
        $abs = $app['media']->absolute((string) $file['media_path']);
        $mime = (string) $file['media_mime'];
        $inline = preg_match('#^(image/(jpeg|png|webp)|audio/|video/|application/pdf$)#', $mime) === 1;
        $name = (string) ($file['media_name'] ?: basename($abs));
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        if ($mime !== 'application/pdf') { // o leitor de PDF do navegador não abre com sandbox
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . "\"; filename*=UTF-8''" . rawurlencode($name));
        readfile($abs);
        exit;
    }

    // ---------------------------------------------------------------- API
    if (str_starts_with($path, '/api/')) {
        $me = $user();
        if ($method !== 'GET') {
            $csrf();
        }

        if ($path === '/api/me') {
            $json([
                'user' => ['id' => $me->userId, 'name' => $_SESSION['user_name'] ?? '', 'supervisor' => $me->supervisor,
                    'agent_sectors' => $me->agentSectors, 'manager_sectors' => $me->managerSectors],
                ...$app['staff']->directory(),
            ]);
        }
        if ($path === '/api/notifications') {
            $json($app['notifications']->for($me));
        }
        if ($path === '/api/conversations') {
            $sector = isset($_GET['sector']) && $_GET['sector'] !== '' ? (int) $_GET['sector'] : null;
            $tag = isset($_GET['tag']) && $_GET['tag'] !== '' ? (int) $_GET['tag'] : null;
            $json($app['inbox']->list($me, (string) ($_GET['tab'] ?? 'pending'), $sector, tagId: $tag));
        }
        if ($path === '/api/jack/stats') {
            if (!$me->supervisor && $me->managerSectors === []) {
                $json(['error' => 'Só supervisor e gerentes veem os acertos da Jack.'], 403);
            }
            $json($app['jack']->stats(max(1, min(365, (int) ($_GET['days'] ?? 30)))));
        }
        if ($path === '/api/contacts') {
            $json($app['contactbook']->list($me, $_GET));
        }
        if (preg_match('#^/api/contacts/(\d+)(?:/(rename|block|unblock))?$#', $path, $m)) {
            $cb = $app['contactbook'];
            $cid = (int) $m[1];
            $action = $m[2] ?? null;
            if ($action === null && $method === 'GET') {
                $json($cb->show($me, $cid));
            }
            if ($method === 'POST') {
                $b = $body();
                $json(match ($action) {
                    'rename' => $cb->rename($me, $cid, (string) ($b['name'] ?? '')),
                    'block' => $cb->block($me, $cid, isset($b['reason']) ? (string) $b['reason'] : null),
                    'unblock' => $cb->unblock($me, $cid),
                    default => throw new DomainError('Ação inválida.', 404),
                });
            }
        }
        if ($path === '/api/templates') {
            $json($method === 'POST' ? $app['campaigns']->saveTemplate($me, $body()) : $app['campaigns']->templates($me));
        }
        if ($path === '/api/templates/sync' && $method === 'POST') {
            $json($app['campaigns']->syncTemplates($me, (int) ($body()['channel_id'] ?? 0)));
        }
        if (preg_match('#^/api/templates/(\d+)(/delete)?$#', $path, $m) && $method === 'POST') {
            if (!empty($m[2])) {
                $app['campaigns']->deleteTemplate($me, (int) $m[1]);
                $json(['ok' => true]);
            }
            $json($app['campaigns']->saveTemplate($me, $body(), (int) $m[1]));
        }
        if ($path === '/api/campaigns') {
            $json($method === 'POST' ? $app['campaigns']->save($me, $body()) : $app['campaigns']->list($me));
        }
        if ($path === '/api/campaigns/preview' && $method === 'POST') {
            $json($app['campaigns']->preview($me, $body()));
        }
        if (preg_match('#^/api/campaigns/(\d+)(?:/(start|pause|resume|cancel|recipients))?$#', $path, $m)) {
            $cid = (int) $m[1];
            $cs = $app['campaigns'];
            $json(match (true) {
                ($m[2] ?? '') === 'recipients' => $cs->recipients($me, $cid, (string) ($_GET['status'] ?? '')),
                $method === 'GET' => $cs->one($me, $cid),
                ($m[2] ?? '') === 'start' => $cs->start($me, $cid),
                ($m[2] ?? '') === 'pause' => $cs->pause($me, $cid),
                ($m[2] ?? '') === 'resume' => $cs->resume($me, $cid),
                ($m[2] ?? '') === 'cancel' => $cs->cancel($me, $cid),
                default => $cs->save($me, $body(), $cid),
            });
        }
        if ($path === '/api/schedules') {
            $json($method === 'POST' ? $app['schedules']->save($me, $body())
                : ['items' => $app['schedules']->list($me, $_GET), 'counts' => $app['schedules']->counts($me)]);
        }
        if (preg_match('#^/api/schedules/(\d+)(?:/(cancel))?$#', $path, $m)) {
            $sid = (int) $m[1];
            $json(match (true) {
                $method === 'GET' => $app['schedules']->one($me, $sid),
                ($m[2] ?? '') === 'cancel' => $app['schedules']->cancel($me, $sid),
                default => $app['schedules']->save($me, $body(), $sid),
            });
        }
        if ($path === '/api/settings/hours') {
            $json($method === 'POST' ? $app['hours']->save($me, $body()) : $app['hours']->overview($me));
        }
        if ($path === '/api/settings/holidays' && $method === 'POST') {
            $json($app['hours']->addHoliday($me, $body()));
        }
        if ($path === '/api/settings/holidays/import' && $method === 'POST') {
            $json($app['hours']->importNational($me, (int) ($body()['year'] ?? 0)));
        }
        if (preg_match('#^/api/settings/holidays/(\d+)/delete$#', $path, $m) && $method === 'POST') {
            $json($app['hours']->deleteHoliday($me, (int) $m[1]));
        }
        if ($path === '/api/channels') {
            $json($method === 'POST' ? $app['channels']->save($me, $body()) : $app['channels']->list($me));
        }
        if (preg_match('#^/api/channels/(\d+)(?:/(connect|state|logout|default|toggle|delete|simulate-scan))?$#', $path, $m)) {
            $ch = $app['channels'];
            $cid = (int) $m[1];
            $action = $m[2] ?? '';
            if ($action === '' && $method === 'GET') {
                $json($ch->one($me, $cid));
            }
            if ($method !== 'POST') {
                $json(['error' => 'Use POST.'], 405);
            }
            if ($action === 'delete') {
                $ch->delete($me, $cid);
                $json(['ok' => true]);
            }
            $json(match ($action) {
                '' => $ch->save($me, $body(), $cid),
                'connect' => $ch->connect($me, $cid),
                'state' => $ch->refresh($me, $cid),
                'logout' => $ch->logout($me, $cid),
                'default' => $ch->setDefault($me, $cid),
                'toggle' => $ch->toggle($me, $cid),
                'simulate-scan' => $ch->simulateScan($me, $cid),
            });
        }
        if ($path === '/api/reports') {
            $json($app['reports']->summary($me, $_GET));
        }
        if ($path === '/api/reports/export.csv') {
            $csvBody = $app['reports']->exportCsv($me, $_GET);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="atendimentos-' . preg_replace('/[^0-9-]/', '', (string) ($_GET['from'] ?? '')) . '-' . preg_replace('/[^0-9-]/', '', (string) ($_GET['to'] ?? '')) . '.csv"');
            echo $csvBody;
            exit;
        }
        if ($path === '/api/queue') {
            $json($app['queue']->overview($me));
        }
        if ($path === '/api/tags') {
            $json($method === 'POST' ? $app['contacts']->saveTag($me, $body()) : $app['contacts']->tags());
        }
        if (preg_match('#^/api/tags/(\d+)(/delete)?$#', $path, $m) && $method === 'POST') {
            if (!empty($m[2])) {
                $app['contacts']->deleteTag($me, (int) $m[1]);
                $json(['ok' => true]);
            }
            $json($app['contacts']->saveTag($me, $body(), (int) $m[1]));
        }
        if ($path === '/api/quick-messages') {
            $json($method === 'POST' ? $app['quick']->save($me, $body()) : $app['quick']->available($me));
        }
        if (preg_match('#^/api/quick-messages/(\d+)(/delete)?$#', $path, $m) && $method === 'POST') {
            if (!empty($m[2])) {
                $app['quick']->delete($me, (int) $m[1]);
                $json(['ok' => true]);
            }
            $json($app['quick']->save($me, $body(), (int) $m[1]));
        }
        if ($path === '/api/search') {
            $json($app['inbox']->search($me, $_GET));
        }
        // rotas por área em src/Http/Routes/ (as que ainda estão acima vão migrando aos poucos)
        $router = new Router();
        foreach (['conversations', 'team'] as $area) {
            (require __DIR__ . "/../src/Http/Routes/$area.php")($router, $app, $me, $json, $body, $upload);
        }
        $router->dispatch($method, $path);
        $json(['error' => 'Rota não encontrada.'], 404);
    }

    // ---------------------------------------------------------------- simulador (só laboratório)
    if ($isLab && str_starts_with($path, '/lab/')) {
        $user();
        if ($path === '/lab/simulate' && $method === 'POST') {
            $csrf();
            $b = $body();
            $text = trim((string) ($b['text'] ?? ''));
            $file = null;
            if (!empty($_FILES['file'])) {
                [$bytes, $mime, $fname] = $upload();
                $file = $app['media']->save($bytes, $mime, $fname);
            }
            if ($text === '' && $file === null) {
                $json(['error' => 'Escreva a mensagem.'], 422);
            }
            $id = $app['lab']->inbound((int) ($b['channel_id'] ?? 0), (string) ($b['phone'] ?? '5531999990001'), $text,
                ($b['name'] ?? '') ?: null, $file);
            $json(['conversation_id' => $id]);
        }
        if ($path === '/lab/customer') {
            $json($app['lab']->customerView((string) ($_GET['phone'] ?? '')));
        }
    }

    http_response_code(404);
    echo 'Não encontrado';
} catch (DomainError $e) {
    $json(['error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (\Throwable $e) {
    error_log('[talk] ' . $e);
    $json(['error' => $isLab ? $e->getMessage() : 'Erro interno.'], 500);
}
