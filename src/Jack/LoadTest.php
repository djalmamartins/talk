<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Support\Db;
use ConnectTalk\Support\Phone;

/**
 * Carga de teste da Jack (pedido do Djalma em 22/11/2026): centenas de mensagens fictícias de dezenas de
 * clientes fictícios, para ver se ela encaminha certo; o que ela errar volta com outro cliente (reteste).
 *
 * Segurança: só roda em número simulado (canal "lab"), e todo cliente tem número de teste (DDD 00,
 * Phone::isTest). Mesmo que algo mude de canal, o WhatsApp de verdade (TestNumberGuard) e o ERP
 * (TestSafeHelpdesk, TestSafeDirectory) recusam esses números.
 *
 * Cada atendimento: o cliente manda a frase (às vezes um cumprimento antes), a Jack faz a triagem e o
 * atendimento é encerrado sem avisar o cliente, para o mesmo cliente poder abrir outro.
 */
final class LoadTest
{
    private const FIRST = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fábio', 'Gabriela', 'Heitor', 'Isabela', 'João',
        'Karina', 'Lucas', 'Marina', 'Nelson', 'Olívia', 'Paulo', 'Quésia', 'Rafael', 'Sílvia', 'Tiago'];
    private const LAST = ['Teste', 'Simulado', 'Fictício'];
    private const GREETINGS = ['bom dia', 'boa tarde', 'oi', 'olá, tudo bem?', 'boa noite'];
    private const PREFIX = ['', '', '', 'bom dia, ', 'oi, ', 'boa tarde! ', 'olá, '];
    private const SUFFIX = ['', '', '', ' por favor', ' pfv', '?', ' obrigado'];

    private int $channelId;
    private StaffMember $closer;

    public function __construct(private Db $db, private ConversationService $svc, ?int $channelId = null)
    {
        $ch = $channelId !== null
            ? $this->db->one("SELECT id, kind FROM talk_channels WHERE id = ?", [$channelId])
            : $this->db->one("SELECT id, kind FROM talk_channels WHERE kind = 'lab' ORDER BY is_default DESC, id LIMIT 1");
        if ($ch === null || $ch['kind'] !== 'lab') {
            throw new \DomainException('A carga de teste só roda num número simulado (canal "lab"). Rode bin/seed-lab.php.');
        }
        $this->channelId = (int) $ch['id'];
        $jack = $this->db->one('SELECT id FROM talk_users WHERE is_bot = 1 ORDER BY id LIMIT 1');
        if ($jack === null) {
            throw new \DomainException('A Jack não está cadastrada como atendente (rode as migrações).');
        }
        $this->closer = new StaffMember((int) $jack['id'], true);
    }

    /** Número de teste do cliente $n: 55 + DDD 00 + 9 + 8 dígitos. Nunca é o celular de alguém. */
    public static function phone(int $n): string
    {
        $p = Phone::TEST_PREFIX . '9' . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
        assert(Phone::isTest($p));
        return $p;
    }

    public static function name(int $n): string
    {
        return self::FIRST[($n - 1) % count(self::FIRST)] . ' ' . self::LAST[intdiv($n - 1, count(self::FIRST)) % count(self::LAST)] . " $n";
    }

    /**
     * Manda cerca de $messages mensagens de $clients clientes, com as frases de $rows (frase, setor).
     * @param list<array{frase:string, setor:string}> $rows
     * @param callable(int $sent, int $total):void|null $progress
     * @return string a rodada
     */
    public function run(array $rows, int $messages = 500, int $clients = 60, ?callable $progress = null, int $seed = 0): string
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('Sem frases para mandar.');
        }
        $clients = max(1, min($clients, 9999));
        mt_srand($seed ?: random_int(1, PHP_INT_MAX));
        $round = 'carga-' . date('Ymd-His');
        $pool = [];
        $sent = 0;
        while ($sent < $messages) {
            $batch = [];
            for ($c = 1; $c <= $clients && $sent < $messages; $c++) {
                if ($pool === []) {
                    $pool = $rows;
                    shuffle($pool);
                }
                $row = array_pop($pool);
                $text = self::vary($row['frase']);
                $parts = mt_rand(1, 4) === 1 && $sent + 2 <= $messages ? [self::GREETINGS[mt_rand(0, count(self::GREETINGS) - 1)], $text] : [$text];
                $batch[] = $this->send($round, self::phone($c), self::name($c), $parts, $row['setor']);
                $sent += count($parts);
                $progress && $progress($sent, $messages);
            }
            $this->settle($batch);
        }
        return $round;
    }

    /**
     * Reteste: cada frase que a Jack errou na rodada (ainda não retestada) volta igual, de outro cliente.
     * @return array{rodada:?string, frases:int}
     */
    public function retest(?string $round = null): array
    {
        $round ??= $this->lastRound();
        if ($round === null) {
            return ['rodada' => null, 'frases' => 0];
        }
        $misses = $this->db->all(
            'SELECT k.* FROM talk_jack_carga k WHERE k.rodada = ? AND k.certo = 0
                AND NOT EXISTS (SELECT 1 FROM talk_jack_carga r WHERE r.reteste_de = k.id) ORDER BY k.id',
            [$round]
        );
        if ($misses === []) {
            return ['rodada' => null, 'frases' => 0];
        }
        $new = 'reteste-' . date('Ymd-His');
        $next = $this->nextRetestClient();
        $batch = [];
        foreach ($misses as $m) {
            $n = $next++;
            $batch[] = $this->send($new, self::phone($n), self::name($n), explode("\n", (string) $m['frase']), (string) $m['setor_esperado'], (int) $m['id']);
        }
        $this->settle($batch);
        return ['rodada' => $new, 'frases' => count($misses)];
    }

    /** Placar de uma rodada (padrão: a última) e o que ela errou. */
    public function report(?string $round = null): ?array
    {
        $round ??= $this->lastRound();
        if ($round === null) {
            return null;
        }
        $rows = $this->db->all('SELECT k.*, c.protocol FROM talk_jack_carga k LEFT JOIN talk_conversations c ON c.id = k.conversation_id WHERE k.rodada = ? ORDER BY k.id', [$round]);
        $per = [];
        foreach ($rows as $r) {
            $s = $r['setor_esperado'];
            $per[$s] ??= ['total' => 0, 'certos' => 0];
            $per[$s]['total']++;
            $per[$s]['certos'] += (int) $r['certo'];
        }
        ksort($per);
        $total = count($rows);
        $certos = array_sum(array_map(static fn ($r) => (int) $r['certo'], $rows));
        $exatos = array_sum(array_map(static fn ($r) => (int) $r['exato'], $rows));
        $graves = array_values(array_filter($rows, static fn ($r) => $r['setor_jack'] !== null && $r['setor_jack'] !== 'padrao' && $r['setor_jack'] !== $r['setor_esperado']));
        $fixed = (int) ($this->db->one(
            'SELECT COUNT(*) n FROM talk_jack_carga r JOIN talk_jack_carga o ON o.id = r.reteste_de WHERE r.rodada = ? AND r.certo = 1', [$round]
        )['n'] ?? 0);
        return [
            'rodada' => $round,
            'atendimentos' => $total,
            'mensagens' => array_sum(array_map(static fn ($r) => (int) $r['mensagens'], $rows)),
            'clientes' => count(array_unique(array_column($rows, 'phone'))),
            'certos' => $certos, 'exatos' => $exatos, 'graves' => count($graves),
            'taxa' => $total ? round($certos / $total, 3) : 0.0,
            'retestes_acertados' => $fixed,
            'por_setor' => $per,
            'erros' => array_map(static fn ($r) => ['protocolo' => $r['protocol'], 'frase' => $r['frase'], 'esperado' => $r['setor_esperado'],
                'jack' => $r['setor_jack'], 'ficou' => $r['setor_final']], array_values(array_filter($rows, static fn ($r) => (int) $r['certo'] === 0))),
        ];
    }

    public function lastRound(): ?string
    {
        return $this->db->one('SELECT rodada FROM talk_jack_carga ORDER BY id DESC LIMIT 1')['rodada'] ?? null;
    }

    // ------------------------------------------------------------------ apoio

    /** @param list<string> $parts @return int id da linha em talk_jack_carga */
    private function send(string $round, string $phone, string $name, array $parts, string $expected, ?int $retestOf = null): int
    {
        if (!Phone::isTest($phone)) {
            throw new \LogicException('Carga de teste só com número de teste.');
        }
        $conv = null;
        foreach ($parts as $text) {
            $conv = $this->svc->handleInbound($this->channelId, new InboundMessage('CARGA-' . bin2hex(random_bytes(8)), $phone, 'text', $text, $name)) ?? $conv;
        }
        return $this->db->insert('talk_jack_carga', [
            'rodada' => $round, 'conversation_id' => $conv, 'phone' => $phone, 'frase' => mb_substr(implode("\n", $parts), 0, 1000),
            'mensagens' => count($parts), 'setor_esperado' => $expected, 'reteste_de' => $retestOf,
        ]);
    }

    /** Triagem como se o cliente tivesse parado de escrever há 10 min; confere e encerra cada atendimento. @param list<int> $ids */
    private function settle(array $ids): void
    {
        $this->svc->triage(600);
        $final = static fn (string $s): string => $s === 'padrao' ? 'administrativo' : $s;
        foreach ($ids as $id) {
            $k = $this->db->one('SELECT * FROM talk_jack_carga WHERE id = ?', [$id]);
            if ($k === null || $k['conversation_id'] === null) {
                continue;
            }
            $conv = $this->db->one('SELECT c.status, s.jack_key, s.name FROM talk_conversations c LEFT JOIN talk_sectors s ON s.id = c.sector_id WHERE c.id = ?', [$k['conversation_id']]);
            $d = $this->db->one(
                "SELECT d.confident, s.jack_key, s.name FROM talk_jack_decisions d LEFT JOIN talk_sectors s ON s.id = d.suggested_sector_id
                  WHERE d.conversation_id = ? AND d.mode = 'auto' ORDER BY d.id LIMIT 1",
                [$k['conversation_id']]
            );
            $jack = $d !== null && (int) $d['confident'] === 1 ? (self::key($d) ?? 'padrao') : 'padrao';
            $ended = $conv !== null ? self::key($conv) : null;
            $ended = $ended === 'geral' ? null : $ended;
            $this->db->run('UPDATE talk_jack_carga SET setor_jack = ?, setor_final = ?, exato = ?, certo = ? WHERE id = ?', [
                $jack, $ended, (int) ($jack === $k['setor_esperado']),
                (int) ($ended !== null && $ended === $final((string) $k['setor_esperado'])), $id,
            ]);
            if ($conv !== null && $conv['status'] !== 'closed') {
                $this->svc->close((int) $k['conversation_id'], $this->closer, 'Carga de teste da Jack: encerrado automaticamente.', notifyCustomer: false);
            }
        }
    }

    /** Chave do setor: jack_key, ou o nome sem acento ("Manutenção" → manutencao) quando o setor não tem chave. */
    private static function key(array $sector): ?string
    {
        if (!empty($sector['jack_key'])) {
            return (string) $sector['jack_key'];
        }
        return !empty($sector['name']) ? str_replace(' ', '', Classifier::normalize((string) $sector['name'])) : null;
    }

    private function nextRetestClient(): int
    {
        $max = $this->db->one("SELECT MAX(phone) p FROM talk_jack_carga WHERE reteste_de IS NOT NULL")['p'] ?? null;
        return $max !== null ? (int) substr((string) $max, -8) + 1 : 10001; // clientes do reteste: 10001 em diante
    }

    /** O jeito do WhatsApp: cumprimento na frente, "por favor" no fim, às vezes sem acento ou tudo minúsculo. */
    private static function vary(string $text): string
    {
        $t = self::PREFIX[mt_rand(0, count(self::PREFIX) - 1)] . $text . self::SUFFIX[mt_rand(0, count(self::SUFFIX) - 1)];
        if (mt_rand(1, 4) === 1) {
            $t = strtr($t, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
        }
        return mb_substr($t, 0, 900);
    }
}
