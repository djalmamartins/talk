<?php
declare(strict_types=1);

// Dados de laboratório: setores, canal simulado e usuários de teste. Idempotente.
$app = require __DIR__ . '/../bootstrap.php';
$db = $app['db'];

$sectors = [
    ['Financeiro', '1', 'boleto, pagamento, segunda via, 2ª via, taxa, condomínio atrasado, acordo'],
    ['Administrativo', '2', 'assembleia, reserva, salão, mudança, cadastro, documento'],
    ['Manutenção', '3', 'vazamento, elevador, portão, lâmpada, infiltração, interfone, piscina'],
];
foreach ($sectors as [$name, $key, $kw]) {
    $db->run('INSERT INTO talk_sectors (name, menu_key, keywords) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), keywords = VALUES(keywords)', [$name, $key, $kw]);
}
$ids = [];
foreach ($db->all('SELECT id, name FROM talk_sectors') as $s) {
    $ids[$s['name']] = (int) $s['id'];
}

if (!$db->one("SELECT id FROM talk_channels WHERE kind = 'lab'")) {
    $db->insert('talk_channels', [
        'name' => 'Laboratório (simulado)', 'kind' => 'lab', 'purpose' => 'both', 'phone' => '5531900000000',
        'config_encrypted' => $app['crypto']->encrypt([]),
    ]);
}

$pass = getenv('LAB_PASSWORD') ?: 'connect123';
$users = [
    [1, 'Djalma (Supervisor)', 'supervisor@connect.lab', 'supervisor', null],
    [2, 'Ana (Financeiro)', 'financeiro@connect.lab', 'agent', 'Financeiro'],
    [3, 'Bruno (Administrativo)', 'administrativo@connect.lab', 'agent', 'Administrativo'],
    [4, 'Carlos (Manutenção)', 'manutencao@connect.lab', 'agent', 'Manutenção'],
    [5, 'Gerente Manutenção', 'gerente.manutencao@connect.lab', 'manager', 'Manutenção'],
];
foreach ($users as [$id, $name, $email, $role, $sector]) {
    $db->run('INSERT INTO talk_users (id, name, email, password_hash) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email)', [$id, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
    $sid = $sector ? $ids[$sector] : null;
    $exists = $db->one('SELECT id FROM talk_staff WHERE user_id = ? AND sector_id <=> ?', [$id, $sid]);
    if (!$exists) {
        $db->insert('talk_staff', ['user_id' => $id, 'sector_id' => $sid, 'role' => $role]);
    }
}
$quick = [
    ['bomdia', 'Bom dia, {primeiro_nome}! Aqui é {atendente}, da Connect. Como posso te ajudar?', null],
    ['protocolo', 'O protocolo do seu atendimento é *{protocolo}*. Guarde para acompanhar.', null],
    ['encerrar', 'Posso ajudar em mais alguma coisa, {primeiro_nome}? Se não, vou encerrar por aqui. Obrigado!', null],
    ['boleto-app', 'Você também pode pegar a 2ª via no aplicativo do morador, em *Financeiro > Boletos*.', 'Financeiro'],
    ['visita', 'Certo, {primeiro_nome}! Vamos agendar a visita da equipe de Manutenção e te avisamos o horário.', 'Manutenção'],
];
foreach ($quick as [$sc, $bodyText, $sector]) {
    $db->run('INSERT IGNORE INTO talk_quick_messages (shortcut, body, sector_id, created_by) VALUES (?, ?, ?, 1)', [$sc, $bodyText, $sector ? $ids[$sector] : null]);
}
echo "seed de laboratório ok (senha: $pass)\n";
