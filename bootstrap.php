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
    $charges = new \ConnectTalk\Erp\HttpChargeDirectory($erp);
} else {
    $directory = $env('TALK_CONTACT_SQL') ? new SqlContactDirectory($db, (string) $env('TALK_CONTACT_SQL')) : new NullContactDirectory();
    $helpdesk = new NullHelpdesk();
    $charges = new \ConnectTalk\Erp\NullChargeDirectory();
}
$sacOn = $helpdesk instanceof HttpHelpdesk;
// números de teste da Jack (DDD 00, bin/jack-carga.php) nunca vão ao ERP nem saem pelo WhatsApp (GatewayFactory)
$directory = new \ConnectTalk\Erp\TestSafeDirectory($directory);
$helpdesk = new \ConnectTalk\Erp\TestSafeHelpdesk($helpdesk);
$jack = new Jack(
    $db,
    new Classifier(),
    (string) $env('JACK_MODE', 'auto'),
    (int) $env('JACK_WAIT_SECONDS', '5'),       // espera a pessoa parar de escrever
    (int) $env('JACK_MAX_WAIT_SECONDS', '30'),  // depois disso vai para o setor padrão
    typingBaseSeconds: (int) $env('JACK_TYPING_SECONDS', '20'), // "digitando…" antes de cada mensagem dela
    generalSeconds: (int) $env('JACK_GENERAL_SECONDS', '60'),   // tempo no setor Geral antes de ela distribuir
    announceTickets: $env('JACK_TICKET_NOTICE', 'on') !== 'off', // avisa o número e o link do chamado ao cliente
    // IA local (docs/jack/IA.md): servidor de modelo na própria máquina (Ollama no Mac, llama-server no Pi)
    ai: $env('JACK_AI_URL') ? new \ConnectTalk\Jack\AiClassifier(
        new CurlHttpClient(max(1, (int) $env('JACK_AI_TIMEOUT', '4'))),
        (string) $env('JACK_AI_URL'),
        (string) $env('JACK_AI_MODEL', 'jack-ia-1b'),
    ) : null,
    aiMode: (string) $env('JACK_AI', 'off'), // off | shadow (só registra) | on (regras primeiro; IA só quando elas ficam sem certeza)
);
// arquivos das conversas: fora da pasta pública, servidos por /media/{id} com conferência de acesso
$media = new \ConnectTalk\Media\MediaStore(rtrim((string) $env('TALK_STORAGE', __DIR__ . '/storage'), '/'));
$hours = new \ConnectTalk\Service\BusinessHours($db);
$conversations = new ConversationService($db, $events, $policy, $directory, $helpdesk, jack: $jack, media: $media,
    askRating: $env('TALK_RATING', 'on') !== 'off', hours: $hours,
    autoAssign: $env('AUTO_ASSIGN', 'on') !== 'off'); // só uma pessoa online para o setor: a conversa vai direto para ela
$gateways = new GatewayFactory($db, $crypto, $http,
    ['base_url' => (string) $env('EVOLUTION_URL', ''), 'api_key' => (string) $env('EVOLUTION_API_KEY', '')],
    simulateQr: $env('APP_ENV') === 'lab');

return [
    'env' => $env,
    'db' => $db,
    'crypto' => $crypto,
    'policy' => $policy,
    'staff' => new StaffRepository($db),
    'conversations' => $conversations,
    'inbox' => new InboxQuery($db, $policy),
    'webhook' => new WebhookHandler($gateways, $conversations),
    'worker' => new OutboxWorker($db, $gateways, $events, $media),
    'media' => $media,
    'sse' => new SseStream($db, $policy),
    'gateways' => $gateways,
    'jack' => $jack,
    'jack_monitor' => new \ConnectTalk\Jack\Monitor($db, $jack, $sacOn), // tela Serviço da Jack
    'hours' => $hours,
    'reports' => new \ConnectTalk\Service\ReportService($db, $hours),
    'queue' => new \ConnectTalk\Service\QueueQuery($db, $policy, (int) $env('QUEUE_WARN_MINUTES', '15'), (int) $env('QUEUE_LATE_MINUTES', '30'), $hours),
    'contactbook' => new \ConnectTalk\Service\ContactBook($db, $conversations),
    'contacts' => new \ConnectTalk\Service\ContactPanel($db, $policy, $events),
    'second_copy' => new \ConnectTalk\Service\SecondCopy($db, $policy, $conversations, $charges),
    'quick' => new \ConnectTalk\Service\QuickMessages($db),
    'team' => new \ConnectTalk\Service\TeamService($db, $events, $_SERVER['REMOTE_ADDR'] ?? null),
    'login' => new \ConnectTalk\Service\LoginGuard($db),
    'schedules' => new \ConnectTalk\Service\ScheduleService($db, $policy, $conversations),
    'campaigns' => new \ConnectTalk\Service\CampaignService($db, $gateways, $env('APP_ENV') === 'lab'),
    'channels' => new \ConnectTalk\Service\ChannelService($db, $crypto, $gateways, (string) $env('APP_URL', ''), $env('APP_ENV') === 'lab',
        (string) $env('EVOLUTION_WEBHOOK_URL', '')),
    'notifications' => new \ConnectTalk\Service\Notifications($db, $policy, $hours),
    // seletor de sistemas (rodapé do topo do menu), como o "Ambientes Connect" do ERP.
    // Sem endereço, o sistema aparece apagado. A permissão de cada sistema é conferida nele mesmo, no login.
    'systems' => [
        ['key' => 'erp', 'label' => 'Connect ERP', 'description' => 'Gestão administrativa',
            'href' => $env('ERP_WEB_URL') ? rtrim((string) $env('ERP_WEB_URL'), '/') . '/dashboard' : null, 'current' => false],
        ['key' => 'talk', 'label' => 'Connect Talk', 'description' => 'Atendimento pelo WhatsApp', 'href' => '/', 'current' => true],
    ],
];
