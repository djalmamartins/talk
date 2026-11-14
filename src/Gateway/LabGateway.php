<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Domain\SendResult;

/** Canal de laboratório: não fala com o WhatsApp, só finge que enviou. */
final class LabGateway implements WhatsAppGateway
{
    public function sendText(string $toPhone, string $text): SendResult
    {
        return new SendResult('LAB-' . bin2hex(random_bytes(8)));
    }

    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult
    {
        return $this->sendText($toPhone, (string) $caption);
    }

    public function downloadMedia(string $ref): array
    {
        throw new GatewayException('Laboratório: o simulador já grava o arquivo direto.');
    }

    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void
    {
        // laboratório: o simulador mostra "digitando…" lendo a talk_outbox
    }

    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult
    {
        return $this->sendText($toPhone, $fallbackText);
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false; // laboratório não recebe webhook; use o simulador
    }

    public function parseWebhook(string $rawBody): array
    {
        return [];
    }
}
