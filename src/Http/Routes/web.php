<?php
declare(strict_types=1);

/**
 * Rotas fora da API: webhooks do WhatsApp (sem login), login/logout, tempo real (SSE),
 * arquivos das conversas e o simulador do laboratório. As telas ficam em pages.php.
 *
 * $h traz as funções do index.php: json, view, body, upload, csrf, user (exige login).
 */

use ConnectTalk\Gateway\CloudApiGateway;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, array $h, bool $isLab): void {
    ['json' => $json, 'view' => $view, 'body' => $body, 'upload' => $upload, 'csrf' => $csrf, 'user' => $user] = $h;

    // ------------------------------------------------------------ webhooks (sem login)
    $r->get('/webhook/{id}', function (array $p) use ($app) {
        $gw = $app['gateways']->forChannel($p['id']);
        $challenge = $gw instanceof CloudApiGateway ? $gw->verifySubscription($_GET) : null;
        http_response_code($challenge === null ? 403 : 200);
        echo $challenge ?? 'forbidden';
        exit;
    });
    $r->post('/webhook/{id}', function (array $p) use ($app, $json) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $res = $app['webhook']->handle($p['id'], (string) file_get_contents('php://input'), $headers);
        $json(['processed' => $res['processed']], $res['status']);
    });

    // ------------------------------------------------------------ login
    $r->get('/login', fn () => $view('login', ['error' => null, 'isLab' => $isLab]));
    $r->post('/login', function () use ($app, $view, $csrf, $isLab) {
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
    });
    $r->post('/logout', function () use ($csrf) {
        $csrf();
        session_destroy();
        header('Location: /login');
        exit;
    });

    // ------------------------------------------------------------ tempo real
    $r->get('/events', function () use ($app, $user, $isLab) {
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
    });

    // ------------------------------------------------------------ arquivos (com conferência de acesso)
    $r->get('/media/{id}', function (array $p) use ($app, $user) {
        $me = $user();
        session_write_close();
        $file = $app['inbox']->mediaFor($me, $p['id']);
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
    });

    // ------------------------------------------------------------ simulador (só laboratório)
    if (!$isLab) {
        return;
    }
    $r->post('/lab/simulate', function () use ($app, $json, $body, $upload, $csrf, $user) {
        $user();
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
    });
    $r->get('/lab/customer', function () use ($app, $json, $user) {
        $user();
        $json($app['lab']->customerView((string) ($_GET['phone'] ?? '')));
    });
};
