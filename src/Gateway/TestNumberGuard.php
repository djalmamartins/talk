<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Domain\SendResult;
use ConnectTalk\Support\Phone;

/**
 * Trava de segurança da carga de teste da Jack: um WhatsApp de verdade (QR Code ou API oficial) nunca
 * manda nada para um número de teste (Phone::isTest). A mensagem fica como falha na fila de envio.
 * O GatewayFactory põe esta trava em volta de todo gateway que não é o simulado.
 */
final class TestNumberGuard implements WhatsAppGateway
{
    public function __construct(private WhatsAppGateway $inner)
    {
    }

    /** O gateway de verdade, para o que não manda mensagem (modelos da Meta, verificação do webhook). */
    public function inner(): WhatsAppGateway
    {
        return $this->inner;
    }

    private function check(string $toPhone): void
    {
        if (Phone::isTest($toPhone)) {
            throw new GatewayException('Número de teste da Jack: não sai pelo WhatsApp.');
        }
    }

    public function sendText(string $toPhone, string $text): SendResult
    {
        $this->check($toPhone);
        return $this->inner->sendText($toPhone, $text);
    }

    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult
    {
        $this->check($toPhone);
        return $this->inner->sendTemplate($toPhone, $template, $language, $params, $fallbackText);
    }

    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void
    {
        if (!Phone::isTest($toPhone)) {
            $this->inner->sendTyping($toPhone, $seconds, $replyToProviderId);
        }
    }

    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult
    {
        $this->check($toPhone);
        return $this->inner->sendMedia($toPhone, $type, $filePath, $mime, $fileName, $caption);
    }

    public function downloadMedia(string $ref): array
    {
        return $this->inner->downloadMedia($ref);
    }

    public function connect(?string $webhookUrl = null, ?string $secret = null): array
    {
        return $this->inner->connect($webhookUrl, $secret);
    }

    public function connectionState(): array
    {
        return $this->inner->connectionState();
    }

    public function logout(): void
    {
        $this->inner->logout();
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return $this->inner->verifyWebhook($rawBody, $headers);
    }

    public function parseWebhook(string $rawBody): array
    {
        return $this->inner->parseWebhook($rawBody);
    }
}
