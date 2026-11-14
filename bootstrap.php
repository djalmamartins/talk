<?php
declare(strict_types=1);

/**
 * Montagem das dependências (referência). No ERP, registrar no container/rotas
 * da MovesCode e trocar NullContactDirectory/NullHelpdesk pelas implementações reais.
 */

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

$env = static fn (string $k, ?string $d = null) => getenv($k) !== false ? (string) getenv($k) : $d;

$db = Db::connect(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', 'localhost'), $env('DB_PORT', '3306'), $env('DB_DATABASE')),
    (string) $env('DB_USERNAME'),
    (string) $env('DB_PASSWORD')
);
$crypto = Crypto::fromBase64((string) $env('TALK_KEY'));
$policy = new AccessPolicy();
$events = new EventBus($db);
$directory = $env('TALK_CONTACT_SQL') ? new SqlContactDirectory($db, (string) $env('TALK_CONTACT_SQL')) : new NullContactDirectory();
$conversations = new ConversationService($db, $events, $policy, $directory, new NullHelpdesk());
$gateways = new GatewayFactory($db, $crypto, new CurlHttpClient());

return [
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
];
