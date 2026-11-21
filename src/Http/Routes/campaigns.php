<?php
declare(strict_types=1);

/** Rotas do envio em massa e dos modelos da API oficial (/api/campaigns/..., /api/templates/...). */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, StaffMember $me, callable $json, callable $body): void {
    $cs = $app['campaigns'];

    $r->get('/api/templates', fn () => $json($cs->templates($me)));
    $r->post('/api/templates', fn () => $json($cs->saveTemplate($me, $body())));
    $r->post('/api/templates/sync', fn () => $json($cs->syncTemplates($me, (int) ($body()['channel_id'] ?? 0))));
    $r->post('/api/templates/{id}', fn (array $p) => $json($cs->saveTemplate($me, $body(), $p['id'])));
    $r->post('/api/templates/{id}/delete', function (array $p) use ($cs, $me, $json) {
        $cs->deleteTemplate($me, $p['id']);
        $json(['ok' => true]);
    });

    $r->get('/api/campaigns', fn () => $json($cs->list($me)));
    $r->post('/api/campaigns', fn () => $json($cs->save($me, $body())));
    $r->post('/api/campaigns/preview', fn () => $json($cs->preview($me, $body())));
    $r->get('/api/campaigns/{id}', fn (array $p) => $json($cs->one($me, $p['id'])));
    $r->post('/api/campaigns/{id}', fn (array $p) => $json($cs->save($me, $body(), $p['id'])));
    $r->get('/api/campaigns/{id}/recipients', fn (array $p) => $json($cs->recipients($me, $p['id'], (string) ($_GET['status'] ?? ''))));
    foreach (['start', 'pause', 'resume', 'cancel'] as $action) {
        $r->post("/api/campaigns/{id}/$action", fn (array $p) => $json($cs->$action($me, $p['id'])));
    }
};
