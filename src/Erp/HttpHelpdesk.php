<?php
declare(strict_types=1);

namespace ConnectTalk\Erp;

use ConnectTalk\Contracts\Helpdesk;

/**
 * Chamados no SAC do ERP (o antigo Help Desk, studio_support_tickets) via REST (docs/api-erp.md, seções 3 a 5 e 7):
 *   POST /talk/tickets                 → abre com origem "talk" (idempotente pelo protocolo do Talk)
 *   POST /talk/tickets/{id}/transfer   → muda a área, com nota interna
 *   POST /talk/tickets/{id}/close      → resolve, com a resolução
 *   GET  /talk/tickets/{id}            → situação e respostas públicas (a Jack responde o status)
 */
final class HttpHelpdesk implements Helpdesk
{
    /** Setor do Talk → área do SAC (AREAS do TicketService). */
    public const AREA_BY_SECTOR = ['financeiro' => 'financial', 'manutencao' => 'technical', 'administrativo' => 'general'];

    /** Situações do chamado no SAC (TicketService::STATUS_LABELS do ERP), com as palavras do cliente. */
    public const STATUS_LABELS = ['open' => 'aberto, aguardando a equipe', 'in_progress' => 'em atendimento', 'waiting_customer' => 'aguardando uma resposta sua',
        'resolved' => 'resolvido', 'closed' => 'fechado'];

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
        $message = trim((string) ($conversation['ticket_message'] ?? '')) !== ''
            ? $conversation['ticket_message'] . "\n\n(Conversa de WhatsApp no Connect Talk, protocolo " . $conversation['protocol'] . '.)'
            : 'Conversa de WhatsApp no Connect Talk, protocolo ' . $conversation['protocol'] . '.';
        // tudo o que o SAC precisa para conferir sem abrir o Talk: quem é, de onde e o que já foi dito
        $about = array_filter([
            'Contato: ' . (($contact['name'] ?? '') ?: 'sem nome') . ' (' . $contact['phone'] . ')',
            isset($details['condominio']) ? 'Condomínio: ' . $details['condominio'] . (isset($details['unidade']) ? ', unidade ' . $details['unidade'] : '') : 'Contato não identificado no cadastro do ERP',
            $sectorName ? 'Setor no Talk: ' . $sectorName : null,
            !empty($conversation['opened_at']) ? 'Início da conversa: ' . date('d/m/Y H:i', strtotime((string) $conversation['opened_at'])) : null,
        ]);
        $message .= "\n\n" . implode("\n", $about);
        if (trim((string) ($conversation['transcript'] ?? '')) !== '') {
            $message .= "\n\nConversa até agora:\n" . $conversation['transcript'];
        }
        $r = $this->api->call('POST', '/talk/tickets', [
            'talk_protocol' => $conversation['protocol'],
            'subject' => $conversation['subject'] ?? ('WhatsApp ' . $conversation['protocol']),
            'message' => mb_substr($message, 0, 9000),
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
        return ['id' => (int) $t['id'], 'protocol' => $protocol !== '' ? mb_substr($protocol, 0, 40) : null, 'url' => self::safeUrl($t['tracking_url'] ?? null)];
    }

    public function status(int $ticketId): ?array
    {
        $r = $this->api->call('GET', "/talk/tickets/$ticketId");
        $t = $r['data']['data'] ?? null;
        if ($r['status'] === 404 || !is_array($t) || !isset($t['status'])) {
            return null;
        }
        // última resposta da equipe (o que veio do WhatsApp não conta)
        $last = null;
        foreach (array_reverse(is_array($t['messages'] ?? null) ? $t['messages'] : []) as $m) {
            if (empty($m['from_talk'])) {
                $last = (string) ($m['created_at'] ?? '') ?: null;
                break;
            }
        }
        $status = (string) $t['status'];
        return ['status' => $status, 'label' => self::STATUS_LABELS[$status] ?? $status, 'last_reply_at' => $last, 'url' => self::safeUrl($t['tracking_url'] ?? null)];
    }

    /** Só link http(s) vai para o cliente. */
    private static function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);
        return $url !== '' && strlen($url) <= 500 && preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
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
