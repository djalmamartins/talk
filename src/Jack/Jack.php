<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Support\Db;

/**
 * Jack (Jaqueline): faz a triagem de toda conversa nova, sem menu de números.
 *  - Assunto claro na mensagem → vai para a fila do setor na hora (sem esperar).
 *  - Assunto ainda não claro → espera a pessoa parar de escrever ($waitSeconds) e lê tudo o que ela mandou.
 *  - Assunto claro → abre o chamado no setor certo e conta ao cliente o número e o link de
 *    acompanhamento, com um texto aprovado (ADR 0004, revisão de 22/11/2026). O resto é com o atendente.
 *  - Cliente manda o número do chamado → ela responde como ele está.
 *  - Só cumprimentou ("bom dia") → não responde; espera a pessoa dizer o assunto.
 *  - Falou algo que ela não reconhece, ou passou $maxWaitSeconds → setor padrão (Administrativo).
 *  - mode "off": sem triagem; tudo cai direto no setor padrão.
 * Só usa textos aprovados (Service\Texts).
 */
final class Jack
{
    public const NAME = 'Jack';
    private const GREETING = '/^(oi+|ola|ole|bom dia|boa tarde|boa noite|tudo bem|td bem|tudo bom|e ai|opa|ei|alo|hello|hi|blz|beleza|ok|obrigad[oa]|por favor|pf|\?|\.|!|,|\s)+$/u';

    public function __construct(
        private Db $db,
        private Classifier $classifier = new Classifier(),
        private string $mode = 'auto',
        private int $waitSeconds = 5,
        private int $maxWaitSeconds = 30,
        private string $fallbackKey = 'administrativo',
        private int $typingBaseSeconds = 20,
        private int $generalSeconds = 60,
        private bool $announceTickets = true,
    ) {
        if (!in_array($mode, ['off', 'auto'], true)) {
            $this->mode = 'auto'; // "shadow" (fase J1) virou "auto" quando o menu saiu
        }
    }

    private ?int $userId = null;

    /** Cadastro da Jack como atendente (talk_users.is_bot = 1), autora das mensagens dela. Null se não existir. */
    public function userId(): ?int
    {
        $this->userId ??= (int) ($this->db->one('SELECT id FROM talk_users WHERE is_bot = 1 AND active = 1 ORDER BY id LIMIT 1')['id'] ?? 0);
        return $this->userId ?: null;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** Ela avisa o cliente quando abre o chamado (número e link)? JACK_TICKET_NOTICE=off volta a encaminhar calada. */
    public function announcesTickets(): bool
    {
        return $this->mode !== 'off' && $this->announceTickets;
    }

    /** Quanto tempo ela "digita" antes de mandar: base + o tamanho do texto, até 90 s. */
    public function typingSeconds(string $text): int
    {
        return $this->typingBaseSeconds <= 0 ? 0 : min(90, $this->typingBaseSeconds + intdiv(mb_strlen($text), 8));
    }

    /** O assunto já está claro o bastante para ir para a fila sem esperar a pessoa terminar? */
    public function isClear(string $text): bool
    {
        if ($this->mode === 'off') {
            return true;
        }
        $r = $this->classifier->classify($text);
        return $r['sector'] !== null && $r['confident'];
    }

    /**
     * Setor Geral (jack_key "geral"): toda conversa nova espera nele, à vista da equipe, até a Jack
     * distribuir. Sem ele (ou com a Jack desligada) a triagem é a antiga, fora das filas.
     */
    public function generalSector(): ?array
    {
        return $this->mode === 'off' ? null : $this->sectorByKey('geral');
    }

    /** No Geral: distribui depois de $generalSeconds com a pessoa calada há $waitSeconds, ou no dobro do tempo de qualquer jeito. */
    public function isDueInGeneral(int $quietSeconds, int $ageSeconds): bool
    {
        return ($ageSeconds >= $this->generalSeconds && $quietSeconds >= $this->waitSeconds) || $ageSeconds >= 2 * $this->generalSeconds;
    }

    /** A pessoa parou de escrever (ou já esperou demais)? */
    public function isDue(int $quietSeconds, int $ageSeconds): bool
    {
        return $quietSeconds >= $this->waitSeconds || $ageSeconds >= $this->maxWaitSeconds;
    }

    /**
     * Decide o que fazer com uma conversa na triagem, lendo tudo o que a pessoa escreveu.
     * @return array{action:string, sector:?array}|null  action = route | fallback; null = esperar
     */
    public function decide(array $conv, string $text, int $quietSeconds, int $ageSeconds): ?array
    {
        $r = $this->classifier->classify($text);
        $sector = $r['sector'] !== null && $r['confident'] ? $this->sectorByKey($r['sector']) : null;
        if ($sector !== null) {
            $this->record($conv, $sector, $r['intent'], $r);
            return ['action' => 'route', 'sector' => $sector];
        }
        $onlyGreeting = (bool) preg_match(self::GREETING, Classifier::normalize($text));
        if ($onlyGreeting && $ageSeconds < $this->maxWaitSeconds) {
            return null; // só cumprimentou: espera calada a pessoa contar o assunto
        }
        $fallback = $this->fallbackSector();
        if ($fallback === null) {
            return null;
        }
        $this->record($conv, $fallback, null, $r);
        return ['action' => 'fallback', 'sector' => $fallback];
    }

    /** Registra a decisão (gabarito: final_sector_id é atualizado se um humano transferir). */
    public function record(array $conv, array $sector, ?string $intent, ?array $r): void
    {
        $this->db->insert('talk_jack_decisions', [
            'conversation_id' => $conv['id'],
            'message_id' => (int) ($this->db->one("SELECT MAX(id) id FROM talk_messages WHERE conversation_id = ? AND direction = 'in'", [$conv['id']])['id'] ?? 0),
            'mode' => 'auto',
            'intent' => $intent,
            'suggested_sector_id' => $sector['id'],
            'confident' => $r !== null && $r['confident'] ? 1 : 0,
            'scores' => mb_substr(json_encode($r['scores'] ?? [], JSON_UNESCAPED_UNICODE), 0, 255),
            'matched' => !empty($r['matched']) ? mb_substr(implode(', ', $r['matched']), 0, 255) : null,
            'action' => 'routed',
            'final_sector_id' => $sector['id'],
        ]);
    }

    public function fallbackSector(): ?array
    {
        return $this->sectorByKey($this->fallbackKey);
    }

    /**
     * Respostas da Jaqueline ao cliente antes de um humano assumir:
     *  - "identity": perguntaram se é robô/IA → ela se apresenta (nunca nega).
     *  - "scope": depois disso, a pessoa seguiu perguntando algo que ela não sabe → passa para o setor
     *    (Administrativo se ainda não tem setor). Cada uma no máximo uma vez por conversa.
     * @return array{kind:string, sector:?array}|null
     */
    public function converse(array $conv, string $text): ?array
    {
        if ($this->mode === 'off' || !in_array($conv['status'], ['bot', 'pending'], true) || $conv['assigned_user_id'] !== null) {
            return null;
        }
        $sent = array_column($this->db->all(
            "SELECT DISTINCT type FROM talk_messages WHERE conversation_id = ? AND direction = 'out' AND type IN ('jack_identity','jack_scope')",
            [$conv['id']]
        ), 'type');
        if (self::asksIfBot($text)) {
            return in_array('jack_identity', $sent, true) ? null : ['kind' => 'identity', 'sector' => null];
        }
        if (!in_array('jack_identity', $sent, true) || in_array('jack_scope', $sent, true)) {
            return null;
        }
        $n = Classifier::normalize($text);
        if (preg_match('/^\d{1,2}$/', $n) || $this->classifier->classify($text)['confident']) {
            return null; // escolheu opção do menu ou disse claramente o assunto: segue o fluxo normal
        }
        $sector = $conv['sector_id'] !== null
            ? $this->db->one('SELECT id, name FROM talk_sectors WHERE id = ?', [$conv['sector_id']])
            : $this->sectorByKey('administrativo');
        return $sector ? ['kind' => 'scope', 'sector' => $sector] : null;
    }

    public static function asksIfBot(string $text): bool
    {
        $t = Classifier::normalize($text);
        return (bool) preg_match(
            '/(?<![\p{L}])(robo|robozinho|bot|chatbot|inteligencia artificial)(?![\p{L}])'
            . '|(?<![\p{L}])(voce|vc|ce|tu|isso|aqui) (e|eh) (um |uma |a |o )?(ia|maquina|automatico|automatica|gravacao)(?![\p{L}])'
            . '|(pessoa|humano|gente) de verdade|pessoa real|(falando|conversando) com (uma |um )?(pessoa|humano|maquina)'
            . '|(e|eh) (uma )?pessoa\s*\?|(e|eh) humano/u',
            $t
        );
    }

    /** Guarda onde a conversa realmente ficou: é o gabarito dos acertos. */
    public function settle(int $conversationId, int $sectorId): void
    {
        $this->db->run('UPDATE talk_jack_decisions SET final_sector_id = ? WHERE conversation_id = ?', [$sectorId, $conversationId]);
    }

    /**
     * Acertos por conversa: compara a primeira sugestão confiante com o setor final.
     * $sectorIds limita aos setores de um gerente (sugeridos ou finais); null = tudo (supervisor).
     * @param list<int>|null $sectorIds
     * @return array{modo:string, dias:int, conversas:int, com_sugestao:int, sem_assunto:int, acertos:int, erros:int,
     *               taxa:?float, por_setor:list<array>, termos_dos_erros:list<array>, erros_recentes:list<array>}
     */
    public function stats(int $days = 30, ?array $sectorIds = null): array
    {
        $scope = '';
        $args = [$days];
        if ($sectorIds !== null) {
            $sectorIds = array_values(array_map('intval', $sectorIds)) ?: [0];
            $in = implode(',', array_fill(0, count($sectorIds), '?'));
            $scope = " AND (d.suggested_sector_id IN ($in) OR d.final_sector_id IN ($in))";
            $args = [$days, ...$sectorIds, ...$sectorIds];
        }
        $rows = $this->db->all(
            "SELECT d.conversation_id, d.suggested_sector_id, d.final_sector_id, d.intent, d.matched, d.created_at,
                    s.name, fs.name AS final_name, c.protocol, ct.name AS contact
               FROM talk_jack_decisions d
               JOIN (SELECT conversation_id, MIN(id) AS id FROM talk_jack_decisions
                      WHERE confident = 1 AND suggested_sector_id IS NOT NULL AND created_at >= NOW() - INTERVAL ? DAY
                      GROUP BY conversation_id) f ON f.id = d.id
               JOIN talk_conversations c ON c.id = d.conversation_id
               JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = d.suggested_sector_id
               LEFT JOIN talk_sectors fs ON fs.id = d.final_sector_id
              WHERE 1 = 1$scope
              ORDER BY d.id DESC",
            $args
        );
        $total = (int) ($this->db->one(
            "SELECT COUNT(DISTINCT d.conversation_id) n FROM talk_jack_decisions d WHERE d.created_at >= NOW() - INTERVAL ? DAY$scope",
            $args
        )['n'] ?? 0);
        $hits = $misses = 0;
        $per = [];
        $terms = [];
        $recent = [];
        foreach ($rows as $r) {
            if ($r['final_sector_id'] === null) {
                continue; // ainda sem setor final
            }
            $ok = (int) $r['final_sector_id'] === (int) $r['suggested_sector_id'];
            $ok ? $hits++ : $misses++;
            $per[$r['name']] ??= ['setor' => $r['name'], 'acertos' => 0, 'erros' => 0];
            $per[$r['name']][$ok ? 'acertos' : 'erros']++;
            if ($ok) {
                continue;
            }
            $matched = $r['matched'] === null || $r['matched'] === '' ? [] : explode(', ', (string) $r['matched']);
            foreach ($matched as $t) {
                $terms[$t] = ($terms[$t] ?? 0) + 1;
            }
            if (count($recent) < 20) {
                // sem o texto da mensagem: o supervisor abre a conversa pelo protocolo se precisar
                $recent[] = ['conversation_id' => (int) $r['conversation_id'], 'protocolo' => $r['protocol'], 'contato' => $r['contact'],
                    'sugerido' => $r['name'], 'final' => $r['final_name'], 'intencao' => $r['intent'], 'termos' => $matched,
                    'quando' => $r['created_at']];
            }
        }
        foreach ($per as &$p) {
            $p['taxa'] = round($p['acertos'] / max(1, $p['acertos'] + $p['erros']), 3);
        }
        unset($p);
        arsort($terms);
        $judged = $hits + $misses;
        return ['modo' => $this->mode, 'dias' => $days, 'conversas' => $total, 'com_sugestao' => count($rows),
            'sem_assunto' => max(0, $total - count($rows)), 'acertos' => $hits, 'erros' => $misses,
            'taxa' => $judged ? round($hits / $judged, 3) : null, 'por_setor' => array_values($per),
            'termos_dos_erros' => array_map(fn ($t, $n) => ['termo' => (string) $t, 'vezes' => $n], array_keys(array_slice($terms, 0, 10, true)), array_slice($terms, 0, 10)),
            'erros_recentes' => $recent];
    }

    private function sectorByKey(string $key): ?array
    {
        // setor marcado para a Jack (pode ter sido renomeado na tela Equipe e setores)
        $marked = $this->db->one('SELECT id, name FROM talk_sectors WHERE active = 1 AND jack_key = ?', [$key]);
        if ($marked) {
            return $marked;
        }
        foreach ($this->db->all('SELECT id, name FROM talk_sectors WHERE active = 1') as $s) {
            if (str_replace(' ', '', Classifier::normalize($s['name'])) === $key) {
                return $s;
            }
        }
        return null;
    }

}
