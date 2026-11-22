<?php
declare(strict_types=1);

// Simulador de cliente (só no laboratório, APP_ENV=lab): faz chegar uma mensagem como se viesse do WhatsApp,
// num número simulado (canal "lab"). Serve para testar a Jack e a tela Serviço da Jack sem celular.
//
//   php bin/simular.php "o elevador parou" [telefone] [nome]
//   no lab do Mac: docker exec dev-apache php /var/www/html/talk/bin/simular.php "o elevador parou"
//
// A Jack decide depois do tempo do setor Geral (JACK_GENERAL_SECONDS). No lab não há worker: deixe uma tela do Talk aberta.
use ConnectTalk\Domain\InboundMessage;

$app = require __DIR__ . '/../bootstrap.php';
if ($app['env']('APP_ENV') !== 'lab') {
    fwrite(STDERR, "Só no laboratório (APP_ENV=lab).\n");
    exit(1);
}
$text = trim((string) ($argv[1] ?? ''));
if ($text === '') {
    fwrite(STDERR, "Uso: php bin/simular.php \"mensagem do cliente\" [telefone] [nome]\n");
    exit(1);
}
$phone = preg_replace('/\D/', '', (string) ($argv[2] ?? '5531999990001'));
$channel = $app['db']->one("SELECT id FROM talk_channels WHERE kind = 'lab' ORDER BY is_default DESC, id LIMIT 1");
if ($channel === null) {
    fwrite(STDERR, "Não há número simulado. Rode: php bin/seed-lab.php\n");
    exit(1);
}
$id = $app['conversations']->handleInbound((int) $channel['id'], new InboundMessage(
    'SIM-' . bin2hex(random_bytes(8)), $phone, 'text', $text, ($argv[3] ?? '') ?: null
));
echo "Mensagem entregue na conversa $id (cliente $phone). Abra Serviço da Jack em 1 a 2 minutos.\n";
