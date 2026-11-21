<?php
declare(strict_types=1);

/** Rotas de agendamentos e de horário de atendimento/feriados (/api/schedules/..., /api/settings/...). */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, StaffMember $me, callable $json, callable $body): void {
    $sc = $app['schedules'];
    $r->get('/api/schedules', fn () => $json(['items' => $sc->list($me, $_GET), 'counts' => $sc->counts($me)]));
    $r->post('/api/schedules', fn () => $json($sc->save($me, $body())));
    $r->get('/api/schedules/{id}', fn (array $p) => $json($sc->one($me, $p['id'])));
    $r->post('/api/schedules/{id}', fn (array $p) => $json($sc->save($me, $body(), $p['id'])));
    $r->post('/api/schedules/{id}/cancel', fn (array $p) => $json($sc->cancel($me, $p['id'])));

    $h = $app['hours'];
    $r->get('/api/settings/hours', fn () => $json($h->overview($me)));
    $r->post('/api/settings/hours', fn () => $json($h->save($me, $body())));
    $r->post('/api/settings/holidays', fn () => $json($h->addHoliday($me, $body())));
    $r->post('/api/settings/holidays/import', fn () => $json($h->importNational($me, (int) ($body()['year'] ?? 0))));
    $r->post('/api/settings/holidays/{id}/delete', fn (array $p) => $json($h->deleteHoliday($me, $p['id'])));
};
