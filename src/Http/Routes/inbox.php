<?php
declare(strict_types=1);

/**
 * Rotas do dia a dia do atendimento: quem sou eu, avisos, lista de conversas, busca, fila, contatos,
 * etiquetas, mensagens rápidas, relatórios e acertos da Jack.
 */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, StaffMember $me, callable $json, callable $body): void {
    $r->get('/api/me', fn () => $json([
        'user' => ['id' => $me->userId, 'name' => $_SESSION['user_name'] ?? '', 'supervisor' => $me->supervisor,
            'agent_sectors' => $me->agentSectors, 'manager_sectors' => $me->managerSectors],
        ...$app['staff']->directory(),
    ]));
    $r->get('/api/notifications', fn () => $json($app['notifications']->for($me)));
    $r->get('/api/conversations', function () use ($app, $me, $json) {
        $sector = isset($_GET['sector']) && $_GET['sector'] !== '' ? (int) $_GET['sector'] : null;
        $tag = isset($_GET['tag']) && $_GET['tag'] !== '' ? (int) $_GET['tag'] : null;
        $json($app['inbox']->list($me, (string) ($_GET['tab'] ?? 'pending'), $sector, tagId: $tag));
    });
    $r->get('/api/search', fn () => $json($app['inbox']->search($me, $_GET)));
    $r->get('/api/queue', fn () => $json($app['queue']->overview($me)));
    $r->get('/api/jack/stats', function () use ($app, $me, $json) {
        if (!$me->supervisor && $me->managerSectors === []) {
            $json(['error' => 'Só supervisor e gerentes veem os acertos da Jack.'], 403);
        }
        $json($app['jack']->stats(max(1, min(365, (int) ($_GET['days'] ?? 30)))));
    });

    // contatos
    $cb = $app['contactbook'];
    $r->get('/api/contacts', fn () => $json($cb->list($me, $_GET)));
    $r->get('/api/contacts/{id}', fn (array $p) => $json($cb->show($me, $p['id'])));
    $r->post('/api/contacts/{id}/rename', fn (array $p) => $json($cb->rename($me, $p['id'], (string) ($body()['name'] ?? ''))));
    $r->post('/api/contacts/{id}/block', function (array $p) use ($cb, $me, $json, $body) {
        $b = $body();
        $json($cb->block($me, $p['id'], isset($b['reason']) ? (string) $b['reason'] : null));
    });
    $r->post('/api/contacts/{id}/unblock', fn (array $p) => $json($cb->unblock($me, $p['id'])));

    // etiquetas e mensagens rápidas
    $r->get('/api/tags', fn () => $json($app['contacts']->tags()));
    $r->post('/api/tags', fn () => $json($app['contacts']->saveTag($me, $body())));
    $r->post('/api/tags/{id}', fn (array $p) => $json($app['contacts']->saveTag($me, $body(), $p['id'])));
    $r->post('/api/tags/{id}/delete', function (array $p) use ($app, $me, $json) {
        $app['contacts']->deleteTag($me, $p['id']);
        $json(['ok' => true]);
    });
    $r->get('/api/quick-messages', fn () => $json($app['quick']->available($me)));
    $r->post('/api/quick-messages', fn () => $json($app['quick']->save($me, $body())));
    $r->post('/api/quick-messages/{id}', fn (array $p) => $json($app['quick']->save($me, $body(), $p['id'])));
    $r->post('/api/quick-messages/{id}/delete', function (array $p) use ($app, $me, $json) {
        $app['quick']->delete($me, $p['id']);
        $json(['ok' => true]);
    });

    // relatórios
    $r->get('/api/reports', fn () => $json($app['reports']->summary($me, $_GET)));
    $r->get('/api/reports/export.csv', function () use ($app, $me) {
        $csvBody = $app['reports']->exportCsv($me, $_GET);
        $day = static fn (string $k) => preg_replace('/[^0-9-]/', '', (string) ($_GET[$k] ?? ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="atendimentos-' . $day('from') . '-' . $day('to') . '.csv"');
        echo $csvBody;
        exit;
    });
};
