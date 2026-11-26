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

// Conexão WhatsApp: números de exemplo (nenhum real). O simulado padrão recebe o simulador da caixa de entrada.
$channelSeeds = [
    ['Laboratório (simulado)', 'lab', 'both', '5531900000000', null, [], 'connected', 'active', 1],
    ['Plantão Manutenção (simulado)', 'lab', 'attendance', '5531900000003', 'Manutenção', [], 'disconnected', 'active', 0],
    ['Financeiro — QR Code', 'qrcode', 'attendance', '5531900000001', 'Financeiro',
        ['instance' => 'financeiro', 'webhook_secret' => bin2hex(random_bytes(16))], 'disconnected', 'active', 0],
    ['Avisos em massa — API oficial', 'official', 'bulk', '5531900000009', null,
        ['phone_number_id' => '000000000000000', 'access_token' => 'lab-nao-real', 'app_secret' => 'lab-nao-real', 'verify_token' => bin2hex(random_bytes(12))], 'unknown', 'disabled', 0],
];
foreach ($channelSeeds as [$name, $kind, $purpose, $phone, $sector, $cfg, $conn, $status, $default]) {
    if ($db->one('SELECT id FROM talk_channels WHERE name = ?', [$name])) {
        continue;
    }
    $db->insert('talk_channels', [
        'name' => $name, 'kind' => $kind, 'purpose' => $purpose, 'phone' => $phone, 'sector_id' => $sector ? $ids[$sector] : null,
        'config_encrypted' => $app['crypto']->encrypt($cfg), 'connection_state' => $conn, 'status' => $status,
        'is_default' => $default && !$db->one('SELECT id FROM talk_channels WHERE is_default = 1') ? 1 : 0,
    ]);
}
$db->run("UPDATE talk_channels SET connection_state = 'connected' WHERE kind = 'lab' AND is_default = 1");

$pass = getenv('LAB_PASSWORD') ?: 'connect123';
$users = [
    [1, 'Djalma (Supervisor)', 'supervisor@connect.lab', 'supervisor', null],
    [2, 'Ana (Financeiro)', 'financeiro@connect.lab', 'agent', 'Financeiro'],
    [3, 'Bruno (Administrativo)', 'administrativo@connect.lab', 'agent', 'Administrativo'],
    [4, 'Carlos (Manutenção)', 'manutencao@connect.lab', 'agent', 'Manutenção'],
    [5, 'Gerente Manutenção', 'gerente.manutencao@connect.lab', 'manager', 'Manutenção'],
];
foreach ($users as [$id, $name, $email, $role, $sector]) {
    // is_bot = 0: numa instalação nova a migration 021 cria a Jack com o id 1, que é o do supervisor de teste
    $db->run('INSERT INTO talk_users (id, name, email, password_hash) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password_hash = IF(is_bot = 1, VALUES(password_hash), password_hash), is_bot = 0', [$id, $name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
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
// etiquetas e um morador "identificado no ERP" (dados fictícios) para ver o painel do contato
foreach ([['Síndico', '#C9A227'], ['Inadimplente', '#B42318'], ['2ª via', '#2F6FDE'], ['Obra', '#C2410C'], ['Conselho', '#7A3EC8']] as [$tn, $tc]) {
    $db->run('INSERT IGNORE INTO talk_tags (name, color, created_by) VALUES (?, ?, 1)', [$tn, $tc]);
}
$db->run(
    'INSERT INTO talk_contacts (phone, name, erp_person_id, erp_snapshot) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), erp_person_id = VALUES(erp_person_id), erp_snapshot = VALUES(erp_snapshot)',
    ['5531988887654', 'Mariana Souza', 9077, json_encode(['vinculo' => 'owner', 'condominio' => 'Residencial Jardins', 'unidade' => 'Bloco A 101', 'email' => 'mariana@exemplo.com'], JSON_UNESCAPED_UNICODE)]
);
// Jack (atendente automática) e o setor Geral, como no bin/setup.php
if (!$db->one('SELECT id FROM talk_users WHERE is_bot = 1')) {
    $db->insert('talk_users', ['name' => 'Jaqueline (Jack)', 'email' => 'jack@talk.connect', 'password_hash' => '!sem-login', 'active' => 1, 'is_bot' => 1]);
}
if (!$db->one("SELECT id FROM talk_sectors WHERE jack_key = 'geral' OR menu_key = '0'")) {
    $db->insert('talk_sectors', ['name' => 'Geral', 'jack_key' => 'geral', 'menu_key' => '0', 'color' => '#6B7280',
        'description' => 'Toda mensagem nova chega aqui; depois de 1 minuto a Jack distribui para o setor certo.']);
}
$db->run("INSERT INTO talk_staff (user_id, sector_id, role)
          SELECT u.id, s.id, 'agent' FROM talk_users u JOIN talk_sectors s ON s.jack_key = 'geral'
           WHERE u.is_bot = 1 AND NOT EXISTS (SELECT 1 FROM talk_staff st WHERE st.user_id = u.id AND st.sector_id = s.id)");
echo "seed de laboratório ok (senha: $pass)\n";
