<?php
declare(strict_types=1);

namespace ConnectTalk\Gateway;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\SendResult;
use ConnectTalk\Domain\StatusUpdate;

/**
 * Um provedor de WhatsApp. O restante do sistema só conhece esta interface,
 * então dá para ter canais QR Code (Evolution) e oficiais (Meta) lado a lado.
 */
interface WhatsAppGateway
{
    public function sendText(string $toPhone, string $text): SendResult;

    /**
     * Envio por modelo aprovado (obrigatório na API oficial fora da janela de 24h,
     * ex.: boletos em massa). Canais QR Code enviam o texto renderizado.
     * @param list<string> $params
     */
    public function sendTemplate(string $toPhone, string $template, string $language, array $params, string $fallbackText): SendResult;

    /**
     * Mostra "digitando…" para o cliente por alguns segundos (sem enviar mensagem).
     * $replyToProviderId: última mensagem recebida (a API oficial exige para o indicador).
     */
    public function sendTyping(string $toPhone, int $seconds, ?string $replyToProviderId = null): void;

    /**
     * Envia um arquivo. $type: image | audio | video | document. Áudio não tem legenda.
     */
    public function sendMedia(string $toPhone, string $type, string $filePath, string $mime, string $fileName, ?string $caption): SendResult;

    /**
     * Baixa a mídia de uma mensagem recebida ($ref = InboundMessage::mediaRef).
     * @return array{bytes:string, mime:?string}
     */
    public function downloadMedia(string $ref): array;

    /** Confere a autenticidade do webhook (assinatura/segredo). */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /** @return list<InboundMessage|StatusUpdate> */
    public function parseWebhook(string $rawBody): array;
}
