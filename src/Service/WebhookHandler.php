<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\StatusUpdate;
use ConnectTalk\Gateway\GatewayFactory;

/**
 * Entrada dos webhooks dos dois tipos de canal.
 * Rota sugerida no ERP: POST /talk/webhook/{channelId}
 */
final class WebhookHandler
{
    public function __construct(private GatewayFactory $gateways, private ConversationService $conversations)
    {
    }

    /** @return array{status:int, processed:int} */
    public function handle(int $channelId, string $rawBody, array $headers): array
    {
        $gw = $this->gateways->forChannel($channelId);
        if (!$gw->verifyWebhook($rawBody, $headers)) {
            return ['status' => 401, 'processed' => 0];
        }
        $n = 0;
        foreach ($gw->parseWebhook($rawBody) as $item) {
            if ($item instanceof InboundMessage) {
                $this->conversations->handleInbound($channelId, $item);
                $n++;
            } elseif ($item instanceof StatusUpdate) {
                $this->conversations->handleStatus($item);
                $n++;
            }
        }
        return ['status' => 200, 'processed' => $n];
    }
}
