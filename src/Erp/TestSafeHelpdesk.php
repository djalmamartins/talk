<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Support\Phone;

/** Trava da carga de teste da Jack: conversa de número de teste (Phone::isTest) não abre chamado no SAC do ERP. */
final class TestSafeHelpdesk implements Helpdesk
{
    public function __construct(private Helpdesk $inner)
    {
    }

    public function open(array $conversation, array $contact, ?string $sectorName): ?array
    {
        if (Phone::isTest((string) ($contact['phone'] ?? ''))) {
            return null;
        }
        return $this->inner->open($conversation, $contact, $sectorName);
    }

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void
    {
        $this->inner->transfer($ticketId, $toSectorName, $byUserId, $note);
    }

    public function close(int $ticketId, int $byUserId, ?string $resolution): void
    {
        $this->inner->close($ticketId, $byUserId, $resolution);
    }

    public function status(int $ticketId): ?array
    {
        return $this->inner->status($ticketId);
    }
}
