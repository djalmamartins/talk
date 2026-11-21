<?php
declare(strict_types=1);

/** Rotas dos números de WhatsApp (/api/channels/...). Só o supervisor mexe (conferido no ChannelService). */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, StaffMember $me, callable $json, callable $body): void {
    $ch = $app['channels'];

    $r->get('/api/channels', fn () => $json($ch->list($me)));
    $r->post('/api/channels', fn () => $json($ch->save($me, $body())));
    $r->get('/api/channels/{id}', fn (array $p) => $json($ch->one($me, $p['id'])));
    $r->post('/api/channels/{id}', fn (array $p) => $json($ch->save($me, $body(), $p['id'])));
    $r->post('/api/channels/{id}/delete', function (array $p) use ($ch, $me, $json) {
        $ch->delete($me, $p['id']);
        $json(['ok' => true]);
    });
    $actions = [
        'connect' => fn (int $id) => $ch->connect($me, $id),
        'state' => fn (int $id) => $ch->refresh($me, $id),
        'logout' => fn (int $id) => $ch->logout($me, $id),
        'default' => fn (int $id) => $ch->setDefault($me, $id),
        'toggle' => fn (int $id) => $ch->toggle($me, $id),
        'simulate-scan' => fn (int $id) => $ch->simulateScan($me, $id),
    ];
    foreach ($actions as $action => $run) {
        $r->post("/api/channels/{id}/$action", fn (array $p) => $json($run($p['id'])));
    }
    // ação aberta pelo navegador (GET): avisa em vez de dar 404, como sempre foi
    foreach ([...array_keys($actions), 'delete'] as $action) {
        $r->get("/api/channels/{id}/$action", fn () => $json(['error' => 'Use POST.'], 405));
    }
};
