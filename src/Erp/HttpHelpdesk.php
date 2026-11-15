<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\Helpdesk;

/**
 * Chamados no Help Desk do ERP (studio_support_tickets) via REST (docs/api-erp.md, seções 3 a 5):
 *   POST /talk/tickets                 → abre com origem "talk" (idempotente pelo protocolo do Talk)
 *   POST /talk/tickets/{id}/transfer   → muda a área, com nota interna
 *   POST /talk/tickets/{id}/close      → resolve, com a resolução
 */
final class HttpHelpdesk implements Helpdesk
{
    /** Setor do Talk → área do Help Desk (AREAS do TicketService). */
    public const AREA_BY_SECTOR = ['financeiro' => 'financial', 'manutencao' => 'technical', 'administrativo' => 'general'];

    public function __construct(private ErpApi $api, private string $talkUrl)
    {
    }

    public static function area(?string $sectorName): string
    {
        $key = strtolower(strtr((string) $sectorName, ['ç' => 'c', 'ã' => 'a', 'Ç' => 'c', 'Ã' => 'a']));
        return self::AREA_BY_SECTOR[$key] ?? 'general';
    }

    public function open(array $conversation, array $contact, ?string $sectorName): ?array
    {
        $details = is_array($contact['details'] ?? null) ? $contact['details'] : [];
        $r = $this->api->call('POST', '/talk/tickets', [
            'talk_protocol' => $conversation['protocol'],
            'subject' => $conversation['subject'] ?? ('WhatsApp ' . $conversation['protocol']),
            'message' => 'Conversa de WhatsApp no Connect Talk, protocolo ' . $conversation['protocol'] . '.',
            'area' => self::area($sectorName),
            'resident_id' => $contact['erp_person_id'] !== null ? (int) $contact['erp_person_id'] : null,
            'condominium_id' => isset($details['condominio_id']) ? (int) $details['condominio_id'] : null,
            'unit_id' => isset($details['unidade_id']) ? (int) $details['unidade_id'] : null,
            'requester_name' => $contact['name'] ?: null,
            'requester_phone' => $contact['phone'],
            'conversation_url' => rtrim($this->talkUrl, '/') . '/?c=' . $conversation['id'],
        ]);
        $t = $r['data']['data'] ?? [];
        if (!isset($t['id'])) {
            return null;
        }
        $protocol = trim((string) ($t['protocol'] ?? ''));
        return ['id' => (int) $t['id'], 'protocol' => $protocol !== '' ? mb_substr($protocol, 0, 40) : null];
    }

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void
    {
        $this->api->call('POST', "/talk/tickets/$ticketId/transfer", ['area' => self::area($toSectorName), 'sector' => $toSectorName, 'note' => $note, 'talk_user_id' => $byUserId]);
    }

    public function close(int $ticketId, int $byUserId, ?string $resolution): void
    {
        $this->api->call('POST', "/talk/tickets/$ticketId/close", ['resolution' => $resolution, 'talk_user_id' => $byUserId]);
    }
}
