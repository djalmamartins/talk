<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Support\Db;

/**
 * Tela "Fila de atendimento": quem está esperando, há quanto tempo, com quem está cada conversa
 * (Jack, atendente ou ninguém), por onde ela passou e os números do dia.
 * O escopo é o mesmo da caixa de entrada: supervisor vê tudo; os demais, os próprios setores.
 */
final class QueueQuery
{
    public const ONLINE_SECONDS = 60;

    public function __construct(private Db $db, private AccessPolicy $policy, private int $warnMinutes = 15, private int $lateMinutes = 30, private ?BusinessHours $hours = null)
    {
    }

    public function overview(StaffMember $u): array
    {
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $waiting = $this->db->all(
            self::SELECT . " WHERE c.status = 'pending' AND $where ORDER BY COALESCE(c.queued_at, c.opened_at), c.id LIMIT 200",
            $params
        );
        // em atendimento: com quem está e desde quando (sem posição)
        $attending = array_map(fn (array $r): array => array_diff_key($this->present($r), ['wait_from' => 1, 'now_db' => 1, 'wait_seconds' => 1]) + ['since' => $r['accepted_at']], $this->db->all(
            self::SELECT . " WHERE c.status = 'open' AND $where ORDER BY c.accepted_at DESC, c.id DESC LIMIT 200",
            $params
        ));
        foreach ($waiting as $i => &$w) {
            $w = $this->present($w);
            $w['position'] = $i + 1;
            // fora do horário do setor e feriados não contam como espera
            $w['wait_seconds'] = $this->hours
                ? $this->hours->openSeconds($w['sector_id'] !== null ? (int) $w['sector_id'] : null, new \DateTimeImmutable($w['wait_from']), new \DateTimeImmutable($w['now_db']))
                : (int) $w['wait_seconds'];
            // setor fechado agora: a espera fica parada até ele abrir
            $w['paused'] = $this->hours !== null && $w['sector_id'] !== null && !$this->hours->isOpen((int) $w['sector_id'], new \DateTimeImmutable($w['now_db']));
            unset($w['wait_from'], $w['now_db']);
            $w['level'] = $w['wait_seconds'] >= $this->lateMinutes * 60 ? 'late' : ($w['wait_seconds'] >= $this->warnMinutes * 60 ? 'warn' : 'ok');
        }
        unset($w);

        [$scope, $sp] = $this->sectorScope($u, 'c');
        $today = $this->db->one(
            "SELECT COUNT(*) AS total, SUM(c.status = 'closed') AS closed
               FROM talk_conversations c WHERE c.opened_at >= CURDATE() AND c.sector_id IS NOT NULL AND $scope",
            $sp
        );
        $total = (int) $today['total'];
        $waits = array_column($waiting, 'wait_seconds');

        // distribuição da fila por setor (inclui setores sem ninguém esperando)
        $sectors = $this->db->all('SELECT id, name, color FROM talk_sectors WHERE active = 1 ORDER BY menu_key');
        $visible = $u->supervisor ? null : $u->sectors();
        $dist = [];
        foreach ($sectors as $s) {
            if ($visible !== null && !in_array((int) $s['id'], $visible, true)) {
                continue;
            }
            $n = count(array_filter($waiting, fn ($w) => (int) $w['sector_id'] === (int) $s['id']));
            $dist[] = ['sector_id' => (int) $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'waiting' => $n];
        }

        return [
            'now' => $this->db->one('SELECT NOW() AS n')['n'],
            'thresholds' => ['warn_minutes' => $this->warnMinutes, 'late_minutes' => $this->lateMinutes],
            'stats' => [
                'waiting' => count($waiting),
                'ticking' => !array_filter($waiting, fn ($w) => $w['paused']), // números do topo correm sozinhos na tela
                'avg_wait_seconds' => $waits ? (int) round(array_sum($waits) / count($waits)) : 0,
                'max_wait_seconds' => $waits ? max($waits) : 0,
                'min_wait_seconds' => $waits ? min($waits) : 0,
                'today_total' => $total,
                'today_closed' => (int) $today['closed'],
                'resolution_rate' => $total > 0 ? (int) round(100 * (int) $today['closed'] / $total) : null,
            ],
            'distribution' => $dist,
            'attendants' => $this->attendants($u),
            'waiting' => $waiting,
            'attending' => $attending,
        ];
    }

    private const SELECT = "SELECT c.id, c.protocol, c.helpdesk_protocol, c.status, c.sector_id, s.name AS sector_name, c.priority, c.queued_at, c.accepted_at, c.unread,
                    c.jack_pending, c.assigned_user_id, au.name AS assigned_name, c.subject AS ticket_subject,
                    TIMESTAMPDIFF(SECOND, COALESCE(c.queued_at, c.opened_at), NOW()) AS wait_seconds,
                    COALESCE(c.queued_at, c.opened_at) AS wait_from, NOW() AS now_db,
                    ct.name AS contact_name, ct.phone, ct.erp_snapshot,
                    (SELECT GROUP_CONCAT(tg.tag_id ORDER BY tg.tag_id) FROM talk_contact_tags tg WHERE tg.contact_id = c.contact_id) AS tag_ids,
                    (SELECT LEFT(COALESCE(NULLIF(m.body, ''), '(arquivo)'), 140) FROM talk_messages m
                      WHERE m.conversation_id = c.id AND m.direction = 'in' ORDER BY m.id LIMIT 1) AS subject,
                    (SELECT CONCAT(DATE_FORMAT(m.created_at, '%Y-%m-%d %H:%i:%s'), '|', LEFT(m.body, 200)) FROM talk_messages m
                      WHERE m.conversation_id = c.id AND m.direction = 'internal' AND m.type = 'transfer' ORDER BY m.id DESC LIMIT 1) AS last_move
               FROM talk_conversations c
               JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = c.sector_id
               LEFT JOIN talk_users au ON au.id = c.assigned_user_id";

    /** Vínculos do ERP em português, para "quem é". */
    private const RELATIONSHIPS = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];

    /** Quem é (cadastro do ERP), com quem está e o último encaminhamento, prontos para a tela. */
    private function present(array $r): array
    {
        $erp = json_decode((string) ($r['erp_snapshot'] ?? ''), true) ?: [];
        $who = array_values(array_filter([
            isset($erp['vinculo']) ? (self::RELATIONSHIPS[$erp['vinculo']] ?? $erp['vinculo']) : null,
            $erp['condominio'] ?? null,
            $erp['unidade'] ?? null,
        ]));
        [$moveAt, $move] = $r['last_move'] ? explode('|', (string) $r['last_move'], 2) + [1 => ''] : [null, null];
        $handler = match (true) {
            $r['status'] === 'open' && $r['assigned_name'] !== null => ['kind' => 'agent', 'name' => trim((string) preg_replace('/\s*\(.*\)$/', '', (string) $r['assigned_name']))],
            (int) $r['jack_pending'] === 1 => ['kind' => 'jack', 'name' => 'Jack'],
            default => ['kind' => 'none', 'name' => null],
        };
        unset($r['erp_snapshot'], $r['last_move'], $r['assigned_name']);
        return $r + [
            'who' => $who ? implode(' · ', $who) : 'Não identificado no ERP',
            'identified' => $who !== [],
            'handler' => $handler,
            'last_move' => $move !== null ? ['text' => $move, 'at' => $moveAt] : null,
            'ticket' => $r['helpdesk_protocol'] ?: $r['protocol'],
        ];
    }

    /** Atendentes dos setores visíveis: online (conectado no último minuto), atendendo ou disponível. */
    public function attendants(StaffMember $u): array
    {
        $params = [];
        $filter = '';
        if (!$u->supervisor) {
            $sectors = array_map('intval', $u->sectors());
            $filter = $sectors ? ' AND s.sector_id IN (' . implode(',', $sectors) . ')' : ' AND 1=0';
        }
        $rows = $this->db->all(
            "SELECT u.id, u.name, MAX(s.role = 'supervisor') AS is_supervisor,
                    GROUP_CONCAT(DISTINCT sec.name ORDER BY sec.name SEPARATOR ', ') AS sectors,
                    (u.last_seen_at IS NOT NULL AND u.last_seen_at >= NOW() - INTERVAL " . self::ONLINE_SECONDS . " SECOND) AS online,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.assigned_user_id = u.id AND c.status = 'open') AS open_count
               FROM talk_users u
               JOIN talk_staff s ON s.user_id = u.id
               LEFT JOIN talk_sectors sec ON sec.id = s.sector_id
              WHERE u.active = 1 AND u.is_bot = 0 $filter
              GROUP BY u.id, u.name, u.last_seen_at
              ORDER BY online DESC, u.name",
            $params
        );
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'sectors' => $r['sectors'] ?: ((int) $r['is_supervisor'] ? 'Supervisão' : ''),
            'online' => (bool) $r['online'],
            'open_count' => (int) $r['open_count'],
            'status' => !$r['online'] ? 'offline' : ((int) $r['open_count'] > 0 ? 'busy' : 'available'),
        ], $rows);
    }

    public function touch(int $userId): void
    {
        $this->db->run('UPDATE talk_users SET last_seen_at = NOW() WHERE id = ?', [$userId]);
    }

    /** @return array{0:string, 1:array} filtro de setor para os números do dia */
    private function sectorScope(StaffMember $u, string $alias): array
    {
        if ($u->supervisor) {
            return ['1=1', []];
        }
        $sectors = array_map('intval', $u->sectors());
        return [$sectors ? "$alias.sector_id IN (" . implode(',', $sectors) . ')' : '1=0', []];
    }
}
