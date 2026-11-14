<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\SendResult;
use ConnectTalk\Domain\StatusUpdate;
use ConnectTalk\Http\HttpClient;

/**
 * Canal por QR Code via Evolution API (v2), que roda em um container ao lado.
 * config: base_url, instance, api_key, webhook_secret
 */
final class EvolutionGateway implements WhatsAppGateway
{
    public function __construct(private array $config, private HttpClient $http)
    {
    }

    private function url(string $action): string
    {
        return rtrim((string) $this->config['base_url'], '/') . "/message/{$action}/" . rawurlencode((string) $this->config['instance']);
    }

    public function sendText(string $toPhone, string $text): SendResult
    {
        $r = $this->http->postJson($this->url('sendText'), ['number' => $toPhone, 'text' => $text], ['apikey' => (string) $this->config['api_key']]);
        $data = json_decode($r['body'], true) ?: [];
        $id = $data['key']['id'] ?? null;
        if ($r['status'] >= 300 || !$id) {
            throw new GatewayException('Evolution: ' . ($data['response']['message'][0] ?? $data['message'] ?? "HTTP {$r['status']}"));
        }
        return new SendResult((string) $id);
    }

    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult
    {
        $b64 = base64_encode((string) file_get_contents($filePath));
        $r = $type === 'audio'
            ? $this->http->postJson($this->url('sendWhatsAppAudio'), ['number' => $toPhone, 'audio' => $b64], ['apikey' => (string) $this->config['api_key']])
            : $this->http->postJson($this->url('sendMedia'), array_filter([
                'number' => $toPhone, 'mediatype' => $type, 'mimetype' => $mime, 'media' => $b64,
                'fileName' => $fileName, 'caption' => $caption,
            ], fn ($v) => $v !== null && $v !== ''), ['apikey' => (string) $this->config['api_key']]);
        $data = json_decode($r['body'], true) ?: [];
        $id = $data['key']['id'] ?? null;
        if ($r['status'] >= 300 || !$id) {
            throw new GatewayException('Evolution: ' . ($data['response']['message'][0] ?? $data['message'] ?? "HTTP {$r['status']}"));
        }
        return new SendResult((string) $id);
    }

    public function downloadMedia(string $ref): array
    {
        $url = rtrim((string) $this->config['base_url'], '/') . '/chat/getBase64FromMediaMessage/' . rawurlencode((string) $this->config['instance']);
        $r = $this->http->postJson($url, ['message' => ['key' => ['id' => $ref]], 'convertToMp4' => false], ['apikey' => (string) $this->config['api_key']]);
        $data = json_decode($r['body'], true) ?: [];
        $bytes = isset($data['base64']) ? base64_decode((string) $data['base64'], true) : false;
        if ($r['status'] >= 300 || $bytes === false || $bytes === '') {
            throw new GatewayException("Evolution: mídia indisponível (HTTP {$r['status']})");
        }
        return ['bytes' => $bytes, 'mime' => $data['mimetype'] ?? null];
    }

    private function instanceUrl(string $path): string
    {
        return rtrim((string) $this->config['base_url'], '/') . "/$path/" . rawurlencode((string) $this->config['instance']);
    }

    public function connect(?string $webhookUrl = null, ?string $secret = null): array
    {
        $key = ['apikey' => (string) $this->config['api_key']];
        $warn = null;
        if ($webhookUrl !== null) {
            // Evolution v2: webhook da instância com o segredo no cabeçalho (conferido em verifyWebhook)
            $w = $this->http->postJson($this->instanceUrl('webhook/set'), ['webhook' => [
                'enabled' => true, 'url' => $webhookUrl, 'byEvents' => false, 'base64' => false,
                'headers' => ['X-Talk-Secret' => (string) ($secret ?? $this->config['webhook_secret'] ?? '')],
                'events' => ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE'],
            ]], $key);
            if ($w['status'] >= 300) {
                $warn = "webhook não configurado (HTTP {$w['status']}); configure na Evolution";
            }
        }
        $state = $this->connectionState();
        if ($state['state'] === 'connected') {
            return $state + ['detail' => $warn];
        }
        $r = $this->http->request('GET', $this->instanceUrl('instance/connect'), null, $key);
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 300) {
            throw new GatewayException('Evolution: ' . ($data['response']['message'][0] ?? $data['message'] ?? "HTTP {$r['status']}"));
        }
        $qr = $data['base64'] ?? null;
        if ($qr !== null && !str_starts_with((string) $qr, 'data:image/')) {
            $qr = 'data:image/png;base64,' . $qr;
        }
        return ['state' => $qr ? 'connecting' : $state['state'], 'qr' => $qr, 'detail' => $warn];
    }

    public function connectionState(): array
    {
        $r = $this->http->request('GET', $this->instanceUrl('instance/connectionState'), null, ['apikey' => (string) $this->config['api_key']]);
        $data = json_decode($r['body'], true) ?: [];
        if ($r['status'] >= 300) {
            return ['state' => 'disconnected', 'detail' => $data['response']['message'][0] ?? "HTTP {$r['status']}"];
        }
        $s = strtolower((string) ($data['instance']['state'] ?? $data['state'] ?? ''));
        return ['state' => match ($s) { 'open' => 'connected', 'connecting' => 'connecting', default => 'disconnected' }];
    }

    public function logout(): void
    {
        $this->http->request('DELETE', $this->instanceUrl('instance/logout'), null, ['apikey' => (string) $this->config['api_key']]);
    }

    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void
    {
        $url = rtrim((string) $this->config['base_url'], '/') . '/chat/sendPresence/' . rawurlencode((string) $this->config['instance']);
        $this->http->postJson($url, ['number' => $toPhone, 'presence' => 'composing', 'delay' => $seconds * 1000], ['apikey' => (string) $this->config['api_key']]);
    }

    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult
    {
        // QR Code não tem modelos: envia o texto já montado
        return $this->sendText($toPhone, $fallbackText);
    }

    /** A Evolution não assina o corpo: usamos um segredo na URL/cabeçalho do webhook. */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $given = (string) ($headers['x-talk-secret'] ?? '');
        return $given !== '' && hash_equals((string) $this->config['webhook_secret'], $given);
    }

    public function parseWebhook(string $rawBody): array
    {
        $data = json_decode($rawBody, true) ?: [];
        $event = strtolower(str_replace('_', '.', (string) ($data['event'] ?? '')));
        $items = $data['data'] ?? [];
        if (isset($items['key']) || isset($items['keyId'])) {
            $items = [$items];
        }
        $out = [];
        foreach ($items as $d) {
            if ($event === 'messages.upsert') {
                $key = $d['key'] ?? [];
                $jid = (string) ($key['remoteJid'] ?? '');
                if (($key['fromMe'] ?? false) || str_ends_with($jid, '@g.us') || $jid === 'status@broadcast') {
                    continue; // ignora enviadas por nós, grupos e status
                }
                $msg = $d['message'] ?? [];
                [$type, $body, $mime] = match (true) {
                    isset($msg['conversation']) => ['text', $msg['conversation'], null],
                    isset($msg['extendedTextMessage']) => ['text', $msg['extendedTextMessage']['text'] ?? null, null],
                    isset($msg['imageMessage']) => ['image', $msg['imageMessage']['caption'] ?? null, $msg['imageMessage']['mimetype'] ?? null],
                    isset($msg['audioMessage']) => ['audio', null, $msg['audioMessage']['mimetype'] ?? null],
                    isset($msg['videoMessage']) => ['video', $msg['videoMessage']['caption'] ?? null, $msg['videoMessage']['mimetype'] ?? null],
                    isset($msg['documentMessage']) => ['document', $msg['documentMessage']['fileName'] ?? null, $msg['documentMessage']['mimetype'] ?? null],
                    isset($msg['locationMessage']) => ['location', ($msg['locationMessage']['degreesLatitude'] ?? '') . ',' . ($msg['locationMessage']['degreesLongitude'] ?? ''), null],
                    default => ['other', null, null],
                };
                $out[] = new InboundMessage(
                    providerMessageId: (string) $key['id'],
                    fromPhone: explode('@', $jid)[0],
                    type: $type,
                    body: $body,
                    contactName: $d['pushName'] ?? null,
                    mediaRef: in_array($type, ['image', 'audio', 'video', 'document'], true) ? (string) $key['id'] : null,
                    mediaMime: $mime,
                );
            } elseif ($event === 'messages.update') {
                $id = $d['keyId'] ?? $d['key']['id'] ?? null;
                $ack = match (strtoupper((string) ($d['status'] ?? ''))) {
                    'SERVER_ACK' => 'sent', 'DELIVERY_ACK' => 'delivered', 'READ', 'PLAYED' => 'read', 'ERROR' => 'failed', default => null,
                };
                if ($id && $ack) {
                    $out[] = new StatusUpdate((string) $id, $ack);
                }
            }
        }
        return $out;
    }
}
