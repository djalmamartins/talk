<?php
declare(strict_types=1);

/** Rotas da equipe: usuários, setores, auditoria e troca da própria senha (/api/team/..., /api/me/password). */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, StaffMember $me, callable $json, callable $body): void {
    $team = $app['team'];

    $r->post('/api/me/password', function () use ($team, $me, $json, $body) {
        $b = $body();
        $team->changeOwnPassword($me->userId, (string) ($b['current'] ?? ''), (string) ($b['new'] ?? ''));
        $_SESSION['auth_at'] = time(); // esta sessão continua; as outras caem
        $json(['ok' => true]);
    });

    $r->get('/api/team/users', fn () => $json($team->users($me)));
    $r->post('/api/team/users', fn () => $json($team->saveUser($me, $body())));
    $r->get('/api/team/users/{id}', fn (array $p) => $json($team->user($me, $p['id'])));
    // mexer no próprio cadastro mantém esta sessão e atualiza o nome do topo
    $self = static function (array $user, int $uid) use ($me, $json): never {
        if ($uid === $me->userId) {
            $_SESSION['auth_at'] = time();
            $_SESSION['user_name'] = $user['name'];
        }
        $json($user);
    };
    $r->post('/api/team/users/{id}', fn (array $p) => $self($team->saveUser($me, $body(), $p['id']), $p['id']));
    $r->post('/api/team/users/{id}/activate', fn (array $p) => $self($team->setActive($me, $p['id'], true), $p['id']));
    $r->post('/api/team/users/{id}/deactivate', fn (array $p) => $self($team->setActive($me, $p['id'], false), $p['id']));

    $r->get('/api/team/sectors', fn () => $json($team->sectors($me)));
    $r->post('/api/team/sectors', fn () => $json($team->saveSector($me, $body())));
    $r->get('/api/team/sectors/{id}', fn (array $p) => $json($team->sector($me, $p['id'])));
    $r->post('/api/team/sectors/{id}', fn (array $p) => $json($team->saveSector($me, $body(), $p['id'])));
    $r->post('/api/team/sectors/{id}/activate', fn (array $p) => $json($team->setSectorActive($me, $p['id'], true)));
    $r->post('/api/team/sectors/{id}/deactivate', fn (array $p) => $json($team->setSectorActive($me, $p['id'], false)));

    $r->get('/api/team/audit', fn () => $json($team->auditLog($me, (int) ($_GET['limit'] ?? 50))));
};
