<?php
declare(strict_types=1);

/**
 * Front controller do Connect Talk (aplicação própria, hospedada separada do ERP).
 * Fala com o ERP só por API REST (ver docs/api-erp.md).
 */

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Gateway\CloudApiGateway;
use ConnectTalk\Service\DomainError;

$app = require __DIR__ . '/../bootstrap.php';
$env = $app['env'];
$isLab = $env('APP_ENV') === 'lab';

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
$body = static fn (): array => json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$user = static function () use ($app, $json) {
    if (empty($_SESSION['user_id'])) {
        $json(['error' => 'Faça login.'], 401);
    }
    return $app['staff']->find((int) $_SESSION['user_id']);
};
$csrf = static function () use ($json): void {
    $t = $_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], (string) $t)) {
        $json(['error' => 'Sessão expirada, recarregue a página.'], 419);
    }
};
$view = static function (string $name, array $vars = []): never {
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
            $app['worker']->runOnce();
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
            $u = $app['db']->one('SELECT * FROM talk_users WHERE email = ? AND active = 1', [trim((string) ($_POST['email'] ?? ''))]);
            if ($u && password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $u['id'];
                $_SESSION['user_name'] = $u['name'];
                header('Location: /');
                exit;
            }
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
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        $view('inbox', ['isLab' => $isLab, 'csrf' => $_SESSION['csrf'], 'userName' => $_SESSION['user_name'] ?? '']);
    }

    // ---------------------------------------------------------------- tempo real
    if ($path === '/events') {
        $me = $user();
        session_write_close(); // não travar a sessão enquanto o stream está aberto
        $last = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['last'] ?? 0);
        if ($last === 0) {
            $last = (int) ($app['db']->one('SELECT COALESCE(MAX(id),0) m FROM talk_events')['m']);
        }
        $app['sse']->stream($me, $last);
        exit;
    }

    // ---------------------------------------------------------------- API
    if (str_starts_with($path, '/api/')) {
        $me = $user();
        if ($method !== 'GET') {
            $csrf();
        }
        $svc = $app['conversations'];

        if ($path === '/api/me') {
            $names = [];
            foreach ($app['db']->all('SELECT id, name FROM talk_users') as $r) {
                $names[(int) $r['id']] = $r['name'];
            }
            $json([
                'user' => ['id' => $me->userId, 'name' => $_SESSION['user_name'] ?? '', 'supervisor' => $me->supervisor,
                    'agent_sectors' => $me->agentSectors, 'manager_sectors' => $me->managerSectors],
                'sectors' => $app['db']->all('SELECT id, name, menu_key FROM talk_sectors WHERE active = 1 ORDER BY menu_key'),
                'users' => $names,
            ]);
        }
        if ($path === '/api/conversations') {
            $sector = isset($_GET['sector']) && $_GET['sector'] !== '' ? (int) $_GET['sector'] : null;
            $json($app['inbox']->list($me, (string) ($_GET['tab'] ?? 'pending'), $sector));
        }
        if ($path === '/api/jack/stats') {
            if (!$me->supervisor && $me->managerSectors === []) {
                $json(['error' => 'Só supervisor e gerentes veem os acertos da Jack.'], 403);
            }
            $json($app['jack']->stats(max(1, min(365, (int) ($_GET['days'] ?? 30)))));
        }
        if ($path === '/api/search') {
            $json($app['inbox']->search($me, $_GET));
        }
        if (preg_match('#^/api/conversations/(\d+)(?:/(\w+))?$#', $path, $m)) {
            $id = (int) $m[1];
            $action = $m[2] ?? null;
            $b = $method === 'POST' ? $body() : [];
            switch ($action) {
                case null:
                    $conv = $svc->get($id);
                    $messages = $app['inbox']->messages($me, $id);
                    $contact = $app['db']->one('SELECT id, name, phone, erp_person_id, erp_snapshot FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                    $json(['conversation' => $conv, 'contact' => $contact, 'messages' => $messages,
                        'can_act' => $app['policy']->canAct($me, $conv)]);
                case 'accept':
                    $json($svc->accept($id, $me));
                case 'reply':
                    $json(['message_id' => $svc->reply($id, $me, (string) ($b['text'] ?? ''), ($b['sign'] ?? true) ? explode(' (', (string) ($_SESSION['user_name'] ?? ''))[0] : null)]);
                case 'transfer':
                    $json($svc->transfer($id, $me, (int) ($b['sector_id'] ?? 0), $b['note'] ?? null));
                case 'close':
                    $json($svc->close($id, $me, $b['resolution'] ?? null));
                case 'notes':
                    $json(['message_id' => $svc->addNote($id, $me, (string) ($b['note'] ?? ''))]);
                case 'read':
                    $svc->markRead($id, $me);
                    $json(['ok' => true]);
            }
        }
        $json(['error' => 'Rota não encontrada.'], 404);
    }

    // ---------------------------------------------------------------- simulador (só laboratório)
    if ($isLab && str_starts_with($path, '/lab/')) {
        $user();
        if ($path === '/lab/simulate' && $method === 'POST') {
            $csrf();
            $b = $body();
            $text = trim((string) ($b['text'] ?? ''));
            if ($text === '') {
                $json(['error' => 'Escreva a mensagem.'], 422);
            }
            $channel = (int) $app['db']->one("SELECT id FROM talk_channels WHERE kind = 'lab' ORDER BY id LIMIT 1")['id'];
            $id = $app['conversations']->handleInbound($channel, new InboundMessage(
                'SIM-' . bin2hex(random_bytes(8)), (string) ($b['phone'] ?? '5531999990001'), 'text', $text, ($b['name'] ?? '') ?: null
            ));
            $json(['conversation_id' => $id]);
        }
        if ($path === '/lab/customer') {
            $phone = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? ''));
            $rows = $app['db']->all(
                "SELECT m.direction, m.body, m.ack, m.created_at, c.protocol FROM talk_messages m
                 JOIN talk_conversations c ON c.id = m.conversation_id JOIN talk_contacts ct ON ct.id = c.contact_id
                 WHERE ct.phone = ? AND m.direction <> 'internal' ORDER BY m.id DESC LIMIT 40",
                [\ConnectTalk\Support\Phone::normalize($phone)]
            );
            // o cliente só vê o que já foi enviado; "digitando…" enquanto houver mensagem na fila
            $rows = array_values(array_filter(array_reverse($rows), fn ($r) => $r['direction'] !== 'out' || $r['ack'] !== 'pending'));
            $typing = (bool) $app['db']->one(
                "SELECT o.id FROM talk_outbox o WHERE o.to_phone = ? AND o.status IN ('queued','sending')
                   AND (o.typing_seconds = 0 AND o.next_attempt_at > NOW()) LIMIT 1",
                [\ConnectTalk\Support\Phone::normalize($phone)]
            );
            $json(['messages' => $rows, 'typing' => $typing]);
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
