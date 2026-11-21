<?php
declare(strict_types=1);

/**
 * Rotas da API de uma conversa (/api/conversations/{id}/...).
 * Leitura por GET; toda ação que muda algo só por POST (o index.php confere o CSRF em todo POST).
 */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;
use ConnectTalk\Support\Signature;

return static function (Router $r, array $app, StaffMember $me, callable $json, callable $body, callable $upload): void {
    $svc = $app['conversations'];
    $sign = static fn (bool $on): ?string => $on ? Signature::of($_SESSION['user_name'] ?? null) : null;

    $r->get('/api/conversations/{id}', function (array $p) use ($app, $svc, $me, $json) {
        $conv = $svc->get($p['id']);
        $json(['conversation' => $conv, 'contact' => $app['inbox']->contact((int) $conv['contact_id']),
            'messages' => $app['inbox']->messages($me, $p['id']), 'can_act' => $app['policy']->canAct($me, $conv)]);
    });
    $r->get('/api/conversations/{id}/panel', fn (array $p) => $json($app['contacts']->panel($me, $p['id'])));
    $r->get('/api/conversations/{id}/history', fn (array $p) => $json($app['contacts']->history($me, $p['id'], (int) ($_GET['pagina'] ?? 1))));

    $r->post('/api/conversations/{id}/accept', fn (array $p) => $json($svc->accept($p['id'], $me)));
    $r->post('/api/conversations/{id}/status', fn (array $p) => $json($svc->setStatus($p['id'], $me, (string) ($body()['status'] ?? ''))));
    $r->post('/api/conversations/{id}/priority', fn (array $p) => $json($svc->setPriority($p['id'], $me, (string) ($body()['priority'] ?? ''))));
    $r->post('/api/conversations/{id}/tags', fn (array $p) => $json($app['contacts']->setContactTags($me, $p['id'], (array) ($body()['tag_ids'] ?? []))));
    $r->post('/api/conversations/{id}/contactnotes', fn (array $p) => $json($app['contacts']->addNote($me, $p['id'], (string) ($body()['body'] ?? ''))));
    $r->post('/api/conversations/{id}/contact-notes/{note}/delete', function (array $p) use ($app, $me, $json) {
        $app['contacts']->deleteNote($me, $p['id'], $p['note']);
        $json(['ok' => true]);
    });
    $r->post('/api/conversations/{id}/attach', function (array $p) use ($svc, $me, $json, $upload, $sign) {
        [$bytes, $mime, $fname] = $upload();
        $json(['message_id' => $svc->sendFile($p['id'], $me, $bytes, $mime, $fname,
            isset($_POST['caption']) ? (string) $_POST['caption'] : null, $sign(($_POST['sign'] ?? '1') !== '0'))]);
    });
    $r->post('/api/conversations/{id}/reply', function (array $p) use ($svc, $me, $json, $body, $sign) {
        $b = $body();
        $json(['message_id' => $svc->reply($p['id'], $me, (string) ($b['text'] ?? ''), $sign((bool) ($b['sign'] ?? true)))]);
    });
    $r->post('/api/conversations/{id}/transfer', function (array $p) use ($svc, $me, $json, $body) {
        $b = $body();
        $json($svc->transfer(
            $p['id'],
            $me,
            (int) ($b['sector_id'] ?? 0),
            isset($b['note']) ? (string) $b['note'] : null,
            !empty($b['user_id']) ? (int) $b['user_id'] : null,
            !isset($b['keep_history']) || (bool) $b['keep_history'],
        ));
    });
    $r->post('/api/conversations/{id}/close', fn (array $p) => $json($svc->close($p['id'], $me, $body()['resolution'] ?? null)));
    $r->post('/api/conversations/{id}/ticket', function (array $p) use ($svc, $me, $json, $body) {
        $b = $body();
        $json($svc->openTicketNow($p['id'], $me, (string) ($b['subject'] ?? ''), (string) ($b['description'] ?? '')));
    });
    $r->post('/api/conversations/{id}/notes', fn (array $p) => $json(['message_id' => $svc->addNote($p['id'], $me, (string) ($body()['note'] ?? ''))]));
    $r->post('/api/conversations/{id}/read', function (array $p) use ($svc, $me, $json) {
        $svc->markRead($p['id'], $me);
        $json(['ok' => true]);
    });
};
