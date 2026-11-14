<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\SendResult;
use ConnectTalk\Domain\StatusUpdate;
use ConnectTalk\Http\HttpClient;

/**
 * WhatsApp Cloud API (oficial da Meta). Sem risco de banimento; usado para
 * envios em massa (modelos aprovados) e, se quiserem, para atendimento.
 *
 * config: phone_number_id, access_token, app_secret, verify_token, api_version?
 */
final class CloudApiGateway implements WhatsAppGateway
{
    private string $base;

    public function __construct(private array $config, private HttpClient $http)
    {
        $version = $config['api_version'] ?? 'v21.0';
        $this->base = "https://graph.facebook.com/{$version}/{$config['phone_number_id']}/messages";
    }

    public function sendText(string $toPhone, string $text): SendResult
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => true],
        ]);
    }

    /** Indicador de digitação da API oficial: marca a última mensagem como lida e mostra "digitando…" (até ~25 s). */
    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void
    {
        if ($replyToProviderId === null) {
            return;
        }
        $this->http->postJson($this->base, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $replyToProviderId,
            'typing_indicator' => ['type' => 'text'],
        ], ['Authorization' => 'Bearer ' . $this->config['access_token']]);
    }

    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult
    {
        $components = $params === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $params),
        ]];
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'template',
            'template' => ['name' => $template, 'language' => ['code' => $language], 'components' => $components],
        ]);
    }

    private function send(array $payload): SendResult
    {
        $r = $this->http->postJson($this->base, $payload, ['Authorization' => 'Bearer ' . $this->config['access_token']]);
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 300 || empty($data['messages'][0]['id'])) {
            $err = $data['error']['message'] ?? "HTTP {$r['status']}";
            throw new GatewayException("Cloud API: $err");
        }
        return new SendResult($data['messages'][0]['id']);
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $sig = $headers['x-hub-signature-256'] ?? '';
        if (!str_starts_with($sig, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, (string) $this->config['app_secret']);
        return hash_equals($expected, $sig);
    }

    /** Responde ao GET de verificação do webhook (hub.challenge). */
    public function verifySubscription(array $query): ?string
    {
        if (($query['hub_mode'] ?? '') === 'subscribe'
            && hash_equals((string) $this->config['verify_token'], (string) ($query['hub_verify_token'] ?? ''))) {
            return (string) ($query['hub_challenge'] ?? '');
        }
        return null;
    }

    public function parseWebhook(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        $out = [];
        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $names = [];
                foreach ($value['contacts'] ?? [] as $c) {
                    $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? null;
                }
                foreach ($value['messages'] ?? [] as $m) {
                    $type = $m['type'] ?? 'other';
                    $body = match ($type) {
                        'text' => $m['text']['body'] ?? null,
                        'button' => $m['button']['text'] ?? null,
                        'interactive' => $m['interactive']['button_reply']['title']
                            ?? $m['interactive']['list_reply']['title'] ?? null,
                        'image', 'video', 'document' => $m[$type]['caption'] ?? null,
                        'location' => sprintf('%s,%s', $m['location']['latitude'] ?? '', $m['location']['longitude'] ?? ''),
                        default => null,
                    };
                    $media = in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true) ? $m[$type] ?? [] : [];
                    $out[] = new InboundMessage(
                        providerMessageId: (string) $m['id'],
                        fromPhone: (string) $m['from'],
                        type: in_array($type, ['text', 'image', 'audio', 'video', 'document', 'location'], true) ? $type : ($type === 'button' || $type === 'interactive' ? 'text' : 'other'),
                        body: $body,
                        contactName: $names[$m['from']] ?? null,
                        mediaRef: $media['id'] ?? null,
                        mediaMime: $media['mime_type'] ?? null,
                    );
                }
                foreach ($value['statuses'] ?? [] as $s) {
                    $ack = match ($s['status'] ?? '') {
                        'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', default => null,
                    };
                    if ($ack !== null) {
                        $out[] = new StatusUpdate((string) $s['id'], $ack);
                    }
                }
            }
        }
        return $out;
    }
}
