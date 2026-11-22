<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Support\Db;

/**
 * O sino do topo: o que pede a atenção da pessoa agora, calculado na hora (não há "lida"):
 * - conversas esperando na fila dos setores dela;
 * - conversas transferidas para ela ou para os setores dela (até aceitar ou abrir);
 * - conversas dela com mensagem do cliente ainda não lida;
 * - agendamentos que falharam nos últimos 7 dias (dos setores dela ou criados por ela);
 * - supervisor: números de WhatsApp ativos que caíram;
 * - avaliações que os clientes deram à pessoa nas últimas 12 h (parabéns com 4 ou 5 estrelas, convite para
 *   revisar com 3 ou menos), as 3 mais recentes.
 * Cada item some quando o motivo some.
 */
final class Notifications
{
    public function __construct(private Db $db, private AccessPolicy $policy, private ?BusinessHours $hours = null)
    {
    }

    /** @return array{count:int, items:list<array{kind:string,title:string,text:string,href:string,count:int}>} */
    public function for(StaffMember $u): array
    {
        $items = [];
        $plural = static fn (int $n, string $one, string $many) => $n === 1 ? $one : str_replace('#', (string) $n, $many);

        // fila: só o que a pessoa pode pegar (o filtro da caixa de entrada já cobre setor e papel)
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $q = $this->db->one(
            "SELECT COUNT(*) n, TIMESTAMPDIFF(MINUTE, MIN(COALESCE(c.queued_at, c.opened_at)), NOW()) wait_min FROM talk_conversations c WHERE c.status = 'pending' AND c.sector_id IS NOT NULL AND $where",
            $params
        );
        if ((int) $q['n'] > 0) {
            $min = max(0, (int) $q['wait_min']);
            if ($this->hours !== null) {
                // espera só conta com o setor atendendo (fora do horário e feriados, não)
                $rows = $this->db->all(
                    "SELECT c.sector_id, COALESCE(c.queued_at, c.opened_at) t, NOW() now_db FROM talk_conversations c WHERE c.status = 'pending' AND c.sector_id IS NOT NULL AND $where",
                    $params
                );
                $min = 0;
                foreach ($rows as $r) {
                    $min = max($min, intdiv($this->hours->openSeconds((int) $r['sector_id'], new \DateTimeImmutable($r['t']), new \DateTimeImmutable($r['now_db'])), 60));
                }
            }
            $items[] = ['kind' => 'queue', 'count' => (int) $q['n'], 'href' => '/fila',
                'title' => $plural((int) $q['n'], '1 conversa esperando na fila', '# conversas esperando na fila'),
                'text' => $min < 1 ? 'A mais antiga chegou agora.' : "A mais antiga espera há {$this->duration($min)}."];
        }

        // transferidas para a pessoa, ou para a fila de um setor dela, por outra pessoa
        $mine = array_map('intval', $u->sectors());
        $t = $this->db->all(
            "SELECT c.id, c.protocol, ct.name AS contact, ct.phone, s.name AS sector, c.status, u.name AS by_name, COALESCE(u.is_bot, 0) AS by_bot
               FROM talk_conversations c
               JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = c.sector_id
               LEFT JOIN talk_users u ON u.id = c.transferred_by
              WHERE c.transfer_pending = 1 AND (c.transferred_by IS NULL OR c.transferred_by <> ?)
                AND ((c.status = 'open' AND c.assigned_user_id = ?)"
                . ($mine ? " OR (c.status = 'pending' AND c.sector_id IN (" . implode(',', $mine) . '))' : '') . ')
              ORDER BY c.updated_at DESC LIMIT 5',
            [$u->userId, $u->userId]
        );
        foreach ($t as $r) {
            $who = $r['contact'] ?: $r['phone'];
            $items[] = ['kind' => 'transfer', 'count' => 1, 'href' => '/?c=' . (int) $r['id'],
                // a Jack entregou porque a pessoa era a única online (distribuição automática)
                'title' => $r['status'] === 'open' ? ((int) $r['by_bot'] === 1 ? "Conversa nova para você: $who" : "Conversa transferida para você: $who") : "Conversa transferida para {$r['sector']}: $who",
                'text' => 'Protocolo ' . $r['protocol'] . ((int) $r['by_bot'] === 1 ? ', distribuída pela Jack (você era a única pessoa online).' : ($r['by_name'] ? ', por ' . $r['by_name'] . '.' : '.'))];
        }

        $m = $this->db->one(
            "SELECT COUNT(*) n, SUM(c.unread) msgs FROM talk_conversations c WHERE c.status = 'open' AND c.assigned_user_id = ? AND c.unread > 0",
            [$u->userId]
        );
        if ((int) $m['n'] > 0) {
            $items[] = ['kind' => 'unread', 'count' => (int) $m['n'], 'href' => '/?aba=mine',
                'title' => $plural((int) $m['n'], '1 cliente seu respondeu', '# clientes seus responderam'),
                'text' => $plural((int) $m['msgs'], '1 mensagem nova nos seus atendimentos.', '# mensagens novas nos seus atendimentos.')];
        }

        // avaliações recebidas: incentivo para quem atendeu (pedido do Djalma, 22/11/2026)
        $rated = $this->db->all(
            "SELECT r.id, r.conversation_id, r.score, r.comment, ct.name AS contact, ct.phone
               FROM talk_ratings r JOIN talk_contacts ct ON ct.id = r.contact_id
              WHERE r.user_id = ? AND r.score IS NOT NULL AND r.answered_at >= NOW() - INTERVAL 12 HOUR
              ORDER BY r.answered_at DESC, r.id DESC LIMIT 3",
            [$u->userId]
        );
        foreach ($rated as $r) {
            $items[] = ['kind' => 'rating', 'count' => 1, 'id' => (int) $r['id'], 'score' => (int) $r['score'],
                'href' => '/?c=' . (int) $r['conversation_id']] + self::ratingText((int) $r['score'], self::firstName($r['contact'] ?: null), $r['comment']);
        }

        $sectors = array_map('intval', $u->sectors());
        $scope = $u->supervisor ? '1=1' : '(s.created_by = ' . $u->userId . ($sectors ? ' OR s.sector_id IN (' . implode(',', $sectors) . ')' : '') . ')';
        $f = (int) $this->db->one(
            "SELECT COUNT(*) n FROM talk_schedules s WHERE s.status = 'failed' AND COALESCE(s.updated_at, s.send_at) >= NOW() - INTERVAL 7 DAY AND $scope"
        )['n'];
        if ($f > 0) {
            $items[] = ['kind' => 'schedules', 'count' => $f, 'href' => '/agendamentos?aba=failed',
                'title' => $plural($f, '1 agendamento não foi enviado', '# agendamentos não foram enviados'),
                'text' => 'Veja o motivo e agende de novo.'];
        }

        if ($u->supervisor) {
            $down = $this->db->all(
                "SELECT name FROM talk_channels WHERE status = 'active' AND connection_state = 'disconnected' ORDER BY name"
            );
            if ($down) {
                $items[] = ['kind' => 'channels', 'count' => count($down), 'href' => '/conexoes',
                    'title' => $plural(count($down), '1 número de WhatsApp desconectado', '# números de WhatsApp desconectados'),
                    'text' => implode(', ', array_column($down, 'name')) . '. Leia o QR Code de novo.'];
            }
        }

        return ['count' => array_sum(array_column($items, 'count')), 'items' => $items];
    }

    /** Texto do aviso de avaliação: celebra 4 e 5 estrelas e convida a revisar de 1 a 3, sem bronca. */
    public static function ratingText(int $score, ?string $contact, ?string $comment): array
    {
        $stars = $score === 1 ? '1 estrela' : "$score estrelas";
        $from = $contact ? " de $contact" : '';
        $said = $comment !== null && trim($comment) !== '' ? '"' . mb_strimwidth(trim($comment), 0, 120, '…') . '"' : null;
        return match (true) {
            $score >= 5 => ['title' => "Parabéns! Você recebeu $stars$from", 'text' => $said ?? 'Atendimento nota máxima. Continue assim!'],
            $score === 4 => ['title' => "Muito bom! Você recebeu $stars$from", 'text' => $said ?? 'Ótimo atendimento, faltou pouco para a nota máxima.'],
            default => ['title' => "Vamos dar uma olhada? Você recebeu $stars$from", 'text' => ($said ? "$said " : '') . 'Reveja a conversa e veja o que dá para melhorar na próxima.'],
        };
    }

    private static function firstName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\(.*\)/', '', (string) $name));
        return $name === '' ? null : explode(' ', $name)[0];
    }

    private function duration(int $min): string
    {
        if ($min < 60) {
            return $min === 1 ? '1 minuto' : "$min minutos";
        }
        $h = intdiv($min, 60);
        return $h === 1 ? '1 hora' : ($h < 48 ? "$h horas" : intdiv($h, 24) . ' dias');
    }
}
