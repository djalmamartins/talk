<?php
declare(strict_types=1);

/**
 * Montagem das dependências. No ERP, registrar no container/rotas da MovesCode e
 * trocar NullHelpdesk / login de laboratório pelas implementações reais.
 */

use ConnectTalk\Erp\ErpApi;
use ConnectTalk\Erp\HttpContactDirectory;
use ConnectTalk\Erp\HttpHelpdesk;
use ConnectTalk\Erp\NullContactDirectory;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Erp\SqlContactDirectory;
use ConnectTalk\Gateway\GatewayFactory;
use ConnectTalk\Http\CurlHttpClient;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Realtime\SseStream;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\InboxQuery;
use ConnectTalk\Service\OutboxWorker;
use ConnectTalk\Service\StaffRepository;
use ConnectTalk\Service\WebhookHandler;
use ConnectTalk\Support\Crypto;
use ConnectTalk\Support\Db;
use ConnectTalk\Jack\Classifier;
use ConnectTalk\Jack\Jack;

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/autoload.php';
}

// .env simples (KEY=valor), sem sobrescrever variáveis já definidas
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = is_readable($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if ($lines === false) {
        throw new RuntimeException('Não consegui ler o .env do Talk (permissão). O Apache precisa ter leitura.');
    }
    foreach ($lines as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

$env = static fn (string $k, ?string $d = null) => getenv($k) !== false ? (string) getenv($k) : $d;
date_default_timezone_set($env('TZ', 'America/Sao_Paulo'));

$db = Db::connect(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', 'localhost'), $env('DB_PORT', '3306'), $env('DB_DATABASE')),
    (string) $env('DB_USERNAME'),
    (string) $env('DB_PASSWORD')
);
$db->run("SET time_zone = '-03:00'");
$crypto = Crypto::fromBase64((string) $env('TALK_KEY'));
$policy = new AccessPolicy();
$events = new EventBus($db);
$http = new CurlHttpClient((int) $env('HTTP_TIMEOUT', '10'));

// Integração com o ERP: por API REST (hospedagens separadas). Sem ERP_API_URL, roda sozinho.
if ($env('ERP_API_URL')) {
    $erp = new ErpApi($http, (string) $env('ERP_API_URL'), (string) $env('ERP_API_TOKEN'));
    $directory = new HttpContactDirectory($erp, static fn (string $m) => error_log("[talk] $m"));
    $helpdesk = new HttpHelpdesk($erp, (string) $env('APP_URL', ''));
} else {
    $directory = $env('TALK_CONTACT_SQL') ? new SqlContactDirectory($db, (string) $env('TALK_CONTACT_SQL')) : new NullContactDirectory();
    $helpdesk = new NullHelpdesk();
}
$jack = new Jack(
    $db,
    new Classifier(),
    (string) $env('JACK_MODE', 'auto'),
    (int) $env('JACK_WAIT_SECONDS', '60'),       // espera a pessoa parar de escrever
    (int) $env('JACK_MAX_WAIT_SECONDS', '600'),  // depois disso vai para o setor padrão
    typingBaseSeconds: (int) $env('JACK_TYPING_SECONDS', '20'), // "digitando…" antes de cada mensagem dela
);
$conversations = new ConversationService($db, $events, $policy, $directory, $helpdesk, jack: $jack);
$gateways = new GatewayFactory($db, $crypto, $http);

return [
    'env' => $env,
    'db' => $db,
    'crypto' => $crypto,
    'policy' => $policy,
    'staff' => new StaffRepository($db),
    'conversations' => $conversations,
    'inbox' => new InboxQuery($db, $policy),
    'webhook' => new WebhookHandler($gateways, $conversations),
    'worker' => new OutboxWorker($db, $gateways, $events),
    'sse' => new SseStream($db, $policy),
    'gateways' => $gateways,
    'jack' => $jack,
    'quick' => new \ConnectTalk\Service\QuickMessages($db),
];
