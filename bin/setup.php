<?php
declare(strict_types=1);

// Primeira instalação em produção (rode depois do migrate). Idempotente: não mexe no que já existe.
//   php bin/setup.php --email=djalma@connect.com.br --name="Djalma"
// - cria os setores da Jack (Financeiro, Administrativo, Manutenção) se não houver nenhum setor;
// - cria o primeiro supervisor se ainda não houver supervisor, com senha gerada (mostrada uma vez).
$app = require __DIR__ . '/../bootstrap.php';
$db = $app['db'];
$opt = getopt('', ['email:', 'name:']);

if (!$db->one('SELECT id FROM talk_sectors LIMIT 1')) {
    $base = [
        ['Financeiro', 'financeiro', '1', '#12804A', 'boleto, pagamento, segunda via, 2ª via, taxa, condomínio atrasado, acordo'],
        ['Administrativo', 'administrativo', '2', '#2F6FB2', 'assembleia, reserva, salão, mudança, cadastro, documento'],
        ['Manutenção', 'manutencao', '3', '#C97A12', 'vazamento, elevador, portão, lâmpada, infiltração, interfone, piscina'],
    ];
    foreach ($base as [$name, $key, $menu, $color, $kw]) {
        $db->insert('talk_sectors', ['name' => $name, 'jack_key' => $key, 'menu_key' => $menu, 'color' => $color, 'keywords' => $kw]);
    }
    echo "Setores criados: Financeiro, Administrativo, Manutenção.\n";
} else {
    echo "Setores já existem; mantidos.\n";
}

if ($db->one("SELECT user_id FROM talk_staff WHERE role = 'supervisor' LIMIT 1")) {
    echo "Já existe supervisor; nada a fazer. Cadastre a equipe pela tela Equipe e setores.\n";
    exit(0);
}
$email = mb_strtolower(trim((string) ($opt['email'] ?? '')));
$name = trim((string) ($opt['name'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    fwrite(STDERR, "Informe o primeiro supervisor: php bin/setup.php --email=voce@connect.com.br --name=\"Seu nome\"\n");
    exit(1);
}
if ($db->one('SELECT id FROM talk_users WHERE email = ?', [$email])) {
    fwrite(STDERR, "Já existe um usuário com $email.\n");
    exit(1);
}
// senha forte, sem caracteres que se confundem (0/O, 1/l)
$chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
do {
    $password = '';
    for ($i = 0; $i < 14; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
} while (!preg_match('/\d/', $password) || !preg_match('/[a-z]/i', $password));
$id = $db->insert('talk_users', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
$db->insert('talk_staff', ['user_id' => $id, 'sector_id' => null, 'role' => 'supervisor']);
$db->insert('talk_audit_log', ['user_id' => null, 'action' => 'user.create', 'target_type' => 'user', 'target_id' => $id,
    'details' => json_encode(['via' => 'bin/setup.php', 'role' => 'supervisor'])]);
echo "\nSupervisor criado: $email\nSenha inicial: $password\n(anote agora; troque em \"Trocar senha\" depois de entrar)\n";
