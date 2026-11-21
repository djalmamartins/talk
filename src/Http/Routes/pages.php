<?php
declare(strict_types=1);

/**
 * Telas do sistema. Cada uma precisa de login; algumas só para supervisor ou para quem faz envio em massa.
 * Sem permissão volta para a caixa de entrada; sem login vai para /login.
 */

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Http\Router;

return static function (Router $r, array $app, callable $view, callable $sessionOk, bool $isLab): void {
    $anyone = static fn (StaffMember $me): bool => true;
    $bulk = static fn (StaffMember $me): bool => $me->supervisor || (bool) $me->managerSectors;
    $supervisor = static fn (StaffMember $me): bool => $me->supervisor;

    $pages = [
        '/' => ['inbox', $anyone],
        '/contatos' => ['contacts', $anyone],
        '/agendamentos' => ['schedules', $anyone],
        '/envios' => ['campaigns', $bulk],
        '/configuracoes' => ['settings', $supervisor],
        '/equipe' => ['team', $supervisor],
        '/conexoes' => ['channels', $supervisor],
        '/painel' => ['reports', $anyone],
        '/fila' => ['queue', $anyone],
    ];
    foreach ($pages as $path => [$name, $allowed]) {
        $r->get($path, function () use ($app, $view, $sessionOk, $isLab, $name, $allowed): void {
            if (!$sessionOk()) {
                header('Location: /login');
                exit;
            }
            if (!$allowed($app['staff']->find((int) $_SESSION['user_id']))) {
                header('Location: /');
                exit;
            }
            $view($name, ['isLab' => $isLab, 'userName' => $_SESSION['user_name'] ?? '']);
        });
    }
};
