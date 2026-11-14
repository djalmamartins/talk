<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Support\Db;

/** Listas da caixa de entrada e busca de mensagens, sempre filtradas pela permissão. */
final class InboxQuery
{
    public function __construct(private Db $db, private AccessPolicy $policy)
    {
    }

    /**
     * @param string $tab pending | open | closed | mine
     */
    public function list(StaffMember $u, string $tab, ?int $sectorId = null, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $conds = [$where];
        switch ($tab) {
            case 'mine':
                $conds[] = "c.status = 'open' AND c.assigned_user_id = :me2";
                $params['me2'] = $u->userId;
                break;
            case 'pending':
            case 'open':
            case 'closed':
                $conds[] = 'c.status = :status';
                $params['status'] = $tab;
                break;
            default:
                throw new DomainError('Aba inválida.');
        }
        if ($sectorId !== null) {
            $conds[] = 'c.sector_id = :sector';
            $params['sector'] = $sectorId;
        }
        $sql = 'SELECT c.id, c.protocol, c.status, c.sector_id, s.name AS sector_name, c.assigned_user_id, c.unread,
                       c.last_message_at, ct.name AS contact_name, ct.phone, ct.erp_person_id, ct.erp_snapshot,
                       (SELECT body FROM talk_messages m WHERE m.conversation_id = c.id AND m.direction <> \'internal\' ORDER BY m.id DESC LIMIT 1) AS last_body
                FROM talk_conversations c
                JOIN talk_contacts ct ON ct.id = c.contact_id
                LEFT JOIN talk_sectors s ON s.id = c.sector_id
                WHERE ' . implode(' AND ', $conds) . '
                ORDER BY c.last_message_at DESC
                LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return $this->db->all($sql, $params);
    }

    /** Mensagens de uma conversa (inclui notas internas). */
    public function messages(StaffMember $u, int $convId, ?int $beforeId = null, int $limit = 50): array
    {
        $conv = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$convId]);
        if (!$conv || !$this->policy->canView($u, $conv)) {
            throw new DomainError('Sem acesso a esta conversa.', 403);
        }
        $params = [$convId];
        $extra = '';
        if ($beforeId) {
            $extra = ' AND id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->all(
            'SELECT id, direction, author_user_id, type, body, media_path, media_mime, ack, created_at
             FROM talk_messages WHERE conversation_id = ?' . $extra . ' ORDER BY id DESC LIMIT ' . (int) $limit,
            $params
        );
        return array_reverse($rows);
    }

    /**
     * Busca "o que chegou / o que saiu".
     * @param array{q?:string, direction?:string, from?:string, to?:string, sector_id?:int, contact?:string, user_id?:int} $f
     */
    public function search(StaffMember $u, array $f, int $limit = 100): array
    {
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $conds = [$where, "m.direction <> 'internal'"];
        if (!empty($f['q'])) {
            $conds[] = 'MATCH(m.body) AGAINST (:q IN BOOLEAN MODE)';
            $params['q'] = $this->booleanQuery((string) $f['q']);
        }
        if (!empty($f['direction']) && in_array($f['direction'], ['in', 'out'], true)) {
            $conds[] = 'm.direction = :dir';
            $params['dir'] = $f['direction'];
        }
        if (!empty($f['from'])) {
            $conds[] = 'm.created_at >= :dfrom';
            $params['dfrom'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $conds[] = 'm.created_at <= :dto';
            $params['dto'] = $f['to'] . ' 23:59:59';
        }
        if (!empty($f['sector_id'])) {
            $conds[] = 'c.sector_id = :fsector';
            $params['fsector'] = (int) $f['sector_id'];
        }
        if (!empty($f['user_id'])) {
            $conds[] = 'm.author_user_id = :fuser';
            $params['fuser'] = (int) $f['user_id'];
        }
        if (!empty($f['contact'])) {
            $conds[] = '(ct.name LIKE :cname OR ct.phone LIKE :cphone OR c.protocol = :proto)';
            $params['cname'] = '%' . $f['contact'] . '%';
            $params['cphone'] = '%' . preg_replace('/\D+/', '', (string) $f['contact']) . '%';
            $params['proto'] = $f['contact'];
        }
        return $this->db->all(
            'SELECT m.id, m.conversation_id, m.direction, m.body, m.created_at, m.author_user_id,
                    c.protocol, c.sector_id, ct.name AS contact_name, ct.phone
             FROM talk_messages m
             JOIN talk_conversations c ON c.id = m.conversation_id
             JOIN talk_contacts ct ON ct.id = c.contact_id
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY m.id DESC LIMIT ' . (int) $limit,
            $params
        );
    }

    /** "boleto atrasado" → "+boleto* +atrasado*" (todas as palavras, prefixo). */
    private function booleanQuery(string $q): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/[+\-<>()~*"@]+/u', ' ', $q) ?? '')) ?: [];
        return implode(' ', array_map(fn ($w) => '+' . $w . '*', array_filter($words, fn ($w) => mb_strlen($w) >= 2)));
    }
}
