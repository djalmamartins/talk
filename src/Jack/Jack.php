<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Support\Db;

/**
 * Jack (Jaqueline): lê o que o cliente escreve antes de um humano assumir e decide o setor.
 *  - off:    não faz nada.
 *  - shadow: só registra e deixa uma nota interna com a sugestão (fase J1, padrão).
 *  - auto:   direciona em silêncio quando tem confiança; sem confiança, segue o menu/humano.
 * Nunca escreve para o cliente nesta fase.
 */
final class Jack
{
    public const NAME = 'Jack';

    public function __construct(private Db $db, private Classifier $classifier = new Classifier(), private string $mode = 'shadow')
    {
        if (!in_array($mode, ['off', 'shadow', 'auto'], true)) {
            $this->mode = 'shadow';
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Avalia a mensagem. Retorna o setor (linha de talk_sectors) que deve receber a conversa
     * agora, ou null quando a Jack não deve agir (modo sombra, sem confiança ou já com humano).
     */
    public function observe(array $conv, int $messageId, string $text): ?array
    {
        if ($this->mode === 'off' || !in_array($conv['status'], ['bot', 'pending'], true) || $conv['assigned_user_id'] !== null) {
            return null;
        }
        $r = $this->classifier->classify($text);
        $sector = $r['sector'] !== null ? $this->sectorByKey($r['sector']) : null;
        $act = $sector !== null && $r['confident'];
        $route = $act && $this->mode === 'auto' && $conv['status'] === 'bot';
        $suggest = $act && !$route && $this->shouldNote((int) $conv['id'], (int) $sector['id'], $conv);

        $this->db->insert('talk_jack_decisions', [
            'conversation_id' => $conv['id'],
            'message_id' => $messageId,
            'mode' => $this->mode,
            'intent' => $r['intent'],
            'suggested_sector_id' => $sector['id'] ?? null,
            'confident' => $r['confident'] ? 1 : 0,
            'scores' => mb_substr(json_encode($r['scores'], JSON_UNESCAPED_UNICODE), 0, 255),
            'matched' => $r['matched'] ? mb_substr(implode(', ', $r['matched']), 0, 255) : null,
            'action' => $route ? 'routed' : ($suggest ? 'suggested' : 'none'),
            'final_sector_id' => $conv['sector_id'],
        ]);

        if ($suggest) {
            $this->note((int) $conv['id'], 'jack', sprintf(
                'Sugestão: %s%s',
                $sector['name'],
                $r['intent'] === 'boleto' ? ' (boleto)' : ($r['intent'] === 'cotacao' ? ' (cotação)' : '')
            ));
        }
        if ($route) {
            $this->note((int) $conv['id'], 'jack', 'Direcionado para ' . $sector['name']);
            return $sector;
        }
        return null;
    }

    /** Guarda onde a conversa realmente ficou: é o gabarito dos acertos. */
    public function settle(int $conversationId, int $sectorId): void
    {
        $this->db->run('UPDATE talk_jack_decisions SET final_sector_id = ? WHERE conversation_id = ?', [$sectorId, $conversationId]);
    }

    /**
     * Acertos por conversa: compara a primeira sugestão confiante com o setor final.
     * @return array{conversas:int, com_sugestao:int, acertos:int, erros:int, taxa:?float, por_setor:list<array>}
     */
    public function stats(int $days = 30): array
    {
        $rows = $this->db->all(
            "SELECT d.conversation_id, d.suggested_sector_id, d.final_sector_id, s.name
               FROM talk_jack_decisions d
               JOIN (SELECT conversation_id, MIN(id) AS id FROM talk_jack_decisions
                      WHERE confident = 1 AND suggested_sector_id IS NOT NULL AND created_at >= NOW() - INTERVAL ? DAY
                      GROUP BY conversation_id) f ON f.id = d.id
               LEFT JOIN talk_sectors s ON s.id = d.suggested_sector_id",
            [$days]
        );
        $total = (int) ($this->db->one('SELECT COUNT(DISTINCT conversation_id) n FROM talk_jack_decisions WHERE created_at >= NOW() - INTERVAL ? DAY', [$days])['n'] ?? 0);
        $hits = $misses = 0;
        $per = [];
        foreach ($rows as $r) {
            if ($r['final_sector_id'] === null) {
                continue; // ainda sem setor final
            }
            $ok = (int) $r['final_sector_id'] === (int) $r['suggested_sector_id'];
            $ok ? $hits++ : $misses++;
            $per[$r['name']] ??= ['setor' => $r['name'], 'acertos' => 0, 'erros' => 0];
            $per[$r['name']][$ok ? 'acertos' : 'erros']++;
        }
        $judged = $hits + $misses;
        return ['modo' => $this->mode, 'conversas' => $total, 'com_sugestao' => count($rows), 'acertos' => $hits, 'erros' => $misses,
            'taxa' => $judged ? round($hits / $judged, 3) : null, 'por_setor' => array_values($per)];
    }

    private function shouldNote(int $convId, int $sectorId, array $conv): bool
    {
        if ($conv['sector_id'] !== null && (int) $conv['sector_id'] === $sectorId) {
            return false; // já está no setor que ela sugeriria
        }
        $last = $this->db->one("SELECT suggested_sector_id FROM talk_jack_decisions WHERE conversation_id = ? AND action = 'suggested' ORDER BY id DESC LIMIT 1", [$convId]);
        return $last === null || (int) $last['suggested_sector_id'] !== $sectorId;
    }

    private function sectorByKey(string $key): ?array
    {
        foreach ($this->db->all('SELECT id, name FROM talk_sectors WHERE active = 1') as $s) {
            if (str_replace(' ', '', Classifier::normalize($s['name'])) === $key) {
                return $s;
            }
        }
        return null;
    }

    private function note(int $convId, string $type, string $body): void
    {
        $this->db->insert('talk_messages', ['conversation_id' => $convId, 'direction' => 'internal', 'type' => $type, 'body' => $body, 'ack' => 'received']);
    }
}
