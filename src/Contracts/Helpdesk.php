<?php
declare(strict_types=1);

namespace ConnectTalk\Contracts;

/**
 * Ponte com o helpdesk do ERP. Cada conversa vira (ou se liga a) um chamado.
 * Implementação real grava nas tabelas de chamados do ERP.
 */
interface Helpdesk
{
    /** Abre o chamado da conversa e devolve o id no helpdesk. */
    public function open(array $conversation, array $contact, ?string $sectorName): ?int;

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void;

    public function close(int $ticketId, int $byUserId, ?string $resolution): void;
}
