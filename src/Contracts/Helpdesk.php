<?php
declare(strict_types=1);

namespace ConnectTalk\Contracts;

/**
 * Ponte com o SAC do ERP (o antigo Help Desk). Cada conversa vira (ou se liga a) um chamado.
 * Implementação real fala com a API /api/v1/talk/tickets do ERP.
 */
interface Helpdesk
{
    /**
     * Abre o chamado da conversa. Devolve o id e o número do chamado no SAC (é esse número que o
     * cliente vê) e o link onde ele acompanha, ou null quando não há SAC ligado.
     * @return array{id:int, protocol:?string, url?:?string}|null
     */
    public function open(array $conversation, array $contact, ?string $sectorName): ?array;

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void;

    public function close(int $ticketId, int $byUserId, ?string $resolution): void;

    /**
     * Situação do chamado agora, para a Jack responder quando o cliente manda o número.
     * @return array{status:string, label:string, last_reply_at:?string, url:?string}|null  null = SAC desligado ou sem resposta
     */
    public function status(int $ticketId): ?array;
}
