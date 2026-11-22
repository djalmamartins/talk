<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Service\DomainError;
use ConnectTalk\Support\Db;
use ConnectTalk\Support\Phone;

/**
 * Tela "Serviço da Jack" (pedido do Djalma em 22/11/2026): o que a Jack está fazendo agora, o que ela não
 * fez ou deu errado, as respostas que mandou, a ideia (o que ela entendeu e por quê) e o procedimento que
 * seguiu (assumiu no Geral, encaminhou, abriu o chamado no SAC, distribuiu para alguém, respondeu a situação).
 *
 * Só leitura: monta tudo a partir do que a Jack já grava (talk_jack_decisions e as mensagens e notas
 * que ela assina). Não muda o atendimento. Supervisor vê tudo; gerente, só os setores dele.
 */
final class Monitor
{
    /** Tipos de mensagem que a Jack manda ao cliente => o que ela fez. */
    private const REPLY = [
        'jack_ticket' => 'Contou ao cliente o número do chamado',
        'jack_status' => 'Respondeu a situação do chamado',
        'jack_identity' => 'Se apresentou (perguntaram se é robô)',
        'jack_scope' => 'Avisou que vai passar para o setor',
        'off_hours' => 'Avisou que o setor está fora do horário',
    ];

    /** Intenções do Classifier (e as da Jack) => como aparece na tela. */
    private const INTENT = [
        'boleto' => '2ª via de boleto',
        'financeiro' => 'assunto financeiro (pagamento, taxa, acordo)',
        'cotacao' => 'cotação ou orçamento',
        'administrativo' => 'assunto administrativo (assembleia, reserva, documento)',
        'manutencao' => 'manutenção (água, luz, vazamento, portão…)',
        'scope' => 'pergunta fora do que ela sabe',
    ];

    private const LIMIT = 60;

    public function __construct(private Db $db, private Jack $jack, private bool $sacOn = false)
    {
    }

    /**
     * Visão da tela: resumo, quem está com a Jack agora e as conversas que ela tocou no período.
     * $filter: all | now | problems | replies.
     */
    public function overview(StaffMember $u, int $days = 1, string $filter = 'all', string $search = ''): array
    {
        $this->requireSchema();
        $days = max(1, min(90, $days));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $ids = $this->touchedIds($u, $since, $search);
        $all = $this->summaries($ids);
        $counts = ['all' => count($all), 'now' => 0, 'problems' => 0, 'replies' => 0];
        foreach ($all as $c) {
            $counts['now'] += $c['situacao'] === 'agora' ? 1 : 0;
            $counts['problems'] += $c['pendencias'] !== [] ? 1 : 0;
            $counts['replies'] += $c['respostas'] > 0 ? 1 : 0;
        }
        $list = array_values(array_filter($all, static fn (array $c): bool => match ($filter) {
            'now' => $c['situacao'] === 'agora',
            'problems' => $c['pendencias'] !== [],
            'replies' => $c['respostas'] > 0,
            default => true,
        }));
        return [
            'modo' => $this->jack->mode(),
            'dias' => $days,
            'desde' => $since,
            'sac_ligado' => $this->sacOn,
            'ia_modo' => $this->jack->aiMode(),
            'ia' => self::aiScore(array_values(array_filter(array_column($all, 'ia')))),
            'resumo' => [
                'conversas' => count($all),
                'agora' => $counts['now'],
                'encaminhadas' => count(array_filter($all, static fn ($c) => $c['encaminhou'])),
                'respostas' => array_sum(array_column($all, 'respostas')),
                'chamados' => count(array_filter($all, static fn ($c) => $c['chamado'] !== null && $c['encaminhou'])),
                'pendencias' => $counts['problems'],
            ],
            'contagem' => $counts,
            'conversas' => array_slice($list, 0, self::LIMIT),
            'setores' => $this->db->all("SELECT id, name FROM talk_sectors WHERE active = 1 AND (jack_key IS NULL OR jack_key <> 'geral') ORDER BY name"),
            'sugestoes_novas' => (int) ($this->db->one("SELECT COUNT(*) n FROM talk_jack_feedback WHERE status = 'nova'" . ($u->supervisor ? '' : ' AND author_user_id = ?'), $u->supervisor ? [] : [$u->userId])['n'] ?? 0),
            'limite' => self::LIMIT,
        ];
    }

    /** Passo a passo de uma conversa: o que o cliente disse, o que a Jack entendeu, respondeu e fez. Null = fora do escopo. */
    public function detail(StaffMember $u, int $convId): ?array
    {
        $this->requireSchema();
        if ($this->touchedIds($u, '1970-01-01 00:00:00', '', $convId) === []) {
            return null;
        }
        $summary = $this->summaries([$convId])[0] ?? null;
        if ($summary === null) {
            return null;
        }
        $conv = $this->db->one('SELECT accepted_at, assigned_user_id FROM talk_conversations WHERE id = ?', [$convId]);
        $jackId = $this->jack->userId();
        $steps = [];
        // o que o cliente escreveu enquanto estava com a Jack (até alguém assumir), e os passos da Jack
        $msgs = $this->db->all(
            "SELECT m.id, m.direction, m.type, m.body, m.ack, m.created_at, m.author_user_id, u.name AS author, u.is_bot
               FROM talk_messages m LEFT JOIN talk_users u ON u.id = m.author_user_id
              WHERE m.conversation_id = ? ORDER BY m.id LIMIT 400",
            [$convId]
        );
        $acceptedAt = $conv['accepted_at'] ?? null;
        foreach ($msgs as $m) {
            $byJack = $this->isJackMessage($m, $jackId);
            if ($m['direction'] === 'in') {
                if ($acceptedAt !== null && $m['created_at'] > $acceptedAt) {
                    continue; // daí em diante é com o atendente
                }
                $steps[] = ['quando' => $m['created_at'], 'tipo' => 'cliente', 'titulo' => 'Cliente escreveu',
                    'texto' => self::bodyOf($m)];
            } elseif ($byJack && $m['direction'] === 'out') {
                $steps[] = ['quando' => $m['created_at'], 'tipo' => 'resposta', 'titulo' => self::REPLY[$m['type']] ?? 'Mandou mensagem',
                    'texto' => (string) $m['body'], 'entrega' => self::ackLabel((string) $m['ack']), 'falhou' => $m['ack'] === 'failed'];
            } elseif ($byJack) {
                $steps[] = ['quando' => $m['created_at'], 'tipo' => 'procedimento', 'titulo' => 'Procedimento', 'texto' => (string) $m['body']];
                if (str_starts_with((string) $m['body'], 'Jack encaminhou para') && $summary['chamado'] !== null) {
                    $steps[] = ['quando' => $m['created_at'], 'tipo' => 'procedimento', 'titulo' => 'Procedimento',
                        'texto' => 'Abriu o chamado ' . $summary['chamado']['numero'] . ' no SAC.'];
                }
            } elseif ($m['direction'] === 'internal' && $m['type'] === 'transfer') {
                $steps[] = ['quando' => $m['created_at'], 'tipo' => 'equipe', 'titulo' => 'Equipe: ' . self::firstName($m['author']), 'texto' => (string) $m['body']];
            }
        }
        foreach ($this->decisions([$convId])[$convId] ?? [] as $d) {
            if ($d['mode'] === 'shadow') {
                $ia = self::aiView($d);
                $steps[] = ['quando' => $d['created_at'], 'tipo' => 'ideia', 'titulo' => 'IA em teste (não decide)', 'texto' => $ia['texto']];
                continue;
            }
            $steps[] = ['quando' => $d['created_at'], 'tipo' => 'ideia', 'titulo' => ($d['source'] ?? 'regras') === 'ia' ? 'Ideia (IA)' : 'Ideia', 'texto' => $this->idea($d, false)['explicacao'],
                'termos' => $d['matched'] ? explode(', ', (string) $d['matched']) : [], 'pontos' => json_decode((string) $d['scores'], true) ?: (object) []];
        }
        if ($acceptedAt !== null && $conv['assigned_user_id'] !== null && $summary['atendente'] !== null) {
            $steps[] = ['quando' => $acceptedAt, 'tipo' => 'equipe', 'titulo' => 'Equipe', 'texto' => $summary['atendente'] . ' assumiu a conversa.'];
        }
        // ordem do tempo, ao segundo (a decisão não guarda milésimos); no mesmo segundo, o cliente fala,
        // a Jack entende e depois age
        $order = ['cliente' => 0, 'ideia' => 1, 'procedimento' => 2, 'resposta' => 3, 'equipe' => 4];
        foreach ($steps as $i => &$s) {
            $s['quando'] = substr((string) $s['quando'], 0, 19);
            $s['_k'] = [$s['quando'], $order[$s['tipo']], $i];
        }
        unset($s);
        usort($steps, static fn ($a, $b) => $a['_k'] <=> $b['_k']);
        $steps = array_map(static function (array $s): array {
            unset($s['_k']);
            return $s;
        }, $steps);
        return $summary + ['passos' => $steps, 'sugestoes' => $this->feedbackRows('f.conversation_id = ?', [$convId])];
    }

    // ------------------------------------------------------------------ sugestões para a Jack melhorar

    public const FEEDBACK_KINDS = ['ideia' => 'O que ela entendeu', 'resposta' => 'A resposta dela', 'procedimento' => 'O que ela fez', 'outro' => 'Outra coisa'];

    /**
     * Sugestão sobre o atendimento de uma conversa. Quem pode ver a conversa nesta tela pode sugerir.
     * @throws \InvalidArgumentException texto vazio/longo, tipo ou setor inválido; \DomainException conversa fora do alcance
     */
    public function suggest(StaffMember $u, int $convId, string $kind, string $text, ?int $expectedSectorId = null): array
    {
        $this->requireSchema();
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 1000) {
            throw new \InvalidArgumentException('Escreva a sugestão (até 1.000 caracteres).');
        }
        if (!isset(self::FEEDBACK_KINDS[$kind])) {
            throw new \InvalidArgumentException('Tipo de sugestão inválido.');
        }
        if ($expectedSectorId !== null && !$this->db->one('SELECT id FROM talk_sectors WHERE id = ? AND active = 1', [$expectedSectorId])) {
            throw new \InvalidArgumentException('Setor inválido.');
        }
        if ($this->touchedIds($u, '1970-01-01 00:00:00', '', $convId) === []) {
            throw new \DomainException('Conversa não encontrada.');
        }
        $id = $this->db->insert('talk_jack_feedback', ['conversation_id' => $convId, 'author_user_id' => $u->userId, 'kind' => $kind,
            'expected_sector_id' => $expectedSectorId, 'body' => $text]);
        return $this->feedbackRows('f.id = ?', [$id])[0];
    }

    /** Sugestões (supervisor vê todas; gerente, as dele). $status: nova | aplicada | descartada | '' (todas). */
    public function suggestions(StaffMember $u, string $status = 'nova'): array
    {
        $this->requireSchema();
        $where = ['1 = 1'];
        $args = [];
        if (in_array($status, ['nova', 'aplicada', 'descartada'], true)) {
            $where[] = 'f.status = ?';
            $args[] = $status;
        }
        if (!$u->supervisor) {
            $where[] = 'f.author_user_id = ?';
            $args[] = $u->userId;
        }
        return $this->feedbackRows(implode(' AND ', $where), $args, 200);
    }

    /** Supervisor marca a sugestão como aplicada (virou regra ou texto) ou descartada. */
    public function review(StaffMember $u, int $id, string $status): array
    {
        $this->requireSchema();
        if (!$u->supervisor) {
            throw new \DomainException('Só o supervisor marca as sugestões.');
        }
        if (!in_array($status, ['nova', 'aplicada', 'descartada'], true)) {
            throw new \InvalidArgumentException('Situação inválida.');
        }
        $this->db->run('UPDATE talk_jack_feedback SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$status, $u->userId, $id])->rowCount();
        $row = $this->feedbackRows('f.id = ?', [$id])[0] ?? null;
        if ($row === null) {
            throw new \DomainException('Sugestão não encontrada.');
        }
        return $row;
    }

    private function feedbackRows(string $where, array $args, int $limit = 50): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'conversation_id' => $r['conversation_id'] !== null ? (int) $r['conversation_id'] : null,
            'protocolo' => $r['protocol'], 'contato' => $r['contact'], 'tipo' => $r['kind'], 'tipo_rotulo' => self::FEEDBACK_KINDS[$r['kind']] ?? $r['kind'],
            'setor_certo' => $r['sector_name'], 'texto' => $r['body'], 'situacao' => $r['status'],
            'autor' => self::firstName($r['author']), 'quando' => $r['created_at'],
        ], $this->db->all(
            "SELECT f.*, u.name AS author, s.name AS sector_name, c.protocol, COALESCE(ct.name, ct.phone) AS contact
               FROM talk_jack_feedback f JOIN talk_users u ON u.id = f.author_user_id
               LEFT JOIN talk_sectors s ON s.id = f.expected_sector_id
               LEFT JOIN talk_conversations c ON c.id = f.conversation_id LEFT JOIN talk_contacts ct ON ct.id = c.contact_id
              WHERE $where ORDER BY f.id DESC LIMIT $limit",
            $args
        ));
    }

    private bool $schemaOk = false;

    /** Código novo com banco antigo (migrações 024/025 não rodadas): avisa em vez de dar erro de SQL. */
    private function requireSchema(): void
    {
        if ($this->schemaOk) {
            return;
        }
        $hasTable = $this->db->one("SHOW TABLES LIKE 'talk_jack_feedback'") !== null;
        $hasCol = $this->db->one("SHOW COLUMNS FROM talk_jack_decisions LIKE 'source'") !== null;
        if (!$hasTable || !$hasCol) {
            throw new DomainError('Falta atualizar o banco do Talk (migrações 024 e 025). Rode: php bin/migrate.php (no lab: docker exec dev-apache php /var/www/html/talk/bin/migrate.php --lab).', 503);
        }
        $this->schemaOk = true;
    }

    // ------------------------------------------------------------------ montagem

    /** Conversas em que a Jack atuou desde $since (ou que estão com ela agora), no escopo de quem vê. */
    private function touchedIds(StaffMember $u, string $since, string $search, ?int $only = null): array
    {
        $jackId = $this->jack->userId() ?? 0;
        $types = "'" . implode("','", array_keys(self::REPLY)) . "'";
        $where = ["(c.status = 'bot' OR (c.jack_pending = 1 AND c.status = 'pending' AND c.assigned_user_id IS NULL)
            OR EXISTS (SELECT 1 FROM talk_jack_decisions d WHERE d.conversation_id = c.id AND d.created_at >= :since1)
            OR EXISTS (SELECT 1 FROM talk_messages m WHERE m.conversation_id = c.id AND m.created_at >= :since2
                        AND (m.author_user_id = :jack OR (m.direction = 'out' AND m.type IN ($types)))))"];
        $args = ['since1' => $since, 'since2' => $since, 'jack' => $jackId];
        if (!$u->supervisor) {
            // gerente: o que está ou passou pelos setores dele (para onde a Jack mandou ou onde ficou)
            $in = implode(',', array_map('intval', $u->managerSectors ?: [0]));
            $where[] = "(c.sector_id IN ($in) OR EXISTS (SELECT 1 FROM talk_jack_decisions d2 WHERE d2.conversation_id = c.id
                         AND (d2.suggested_sector_id IN ($in) OR d2.final_sector_id IN ($in))))";
        }
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(c.protocol LIKE :q1 OR c.helpdesk_protocol LIKE :q2 OR ct.name LIKE :q3 OR ct.phone LIKE :q4)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $args += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }
        if ($only !== null) {
            $where[] = 'c.id = :only';
            $args['only'] = $only;
        }
        $rows = $this->db->all(
            'SELECT c.id FROM talk_conversations c JOIN talk_contacts ct ON ct.id = c.contact_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY c.last_message_at DESC, c.id DESC LIMIT 300',
            $args
        );
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /** @param list<int> $ids */
    private function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $convs = $this->db->all(
            "SELECT c.*, TIMESTAMPDIFF(SECOND, c.opened_at, NOW()) AS age, ct.name AS contact_name, ct.phone,
                    s.name AS sector_name, s.jack_key AS sector_jack_key, u.name AS assigned_name
               FROM talk_conversations c JOIN talk_contacts ct ON ct.id = c.contact_id
               LEFT JOIN talk_sectors s ON s.id = c.sector_id LEFT JOIN talk_users u ON u.id = c.assigned_user_id
              WHERE c.id IN ($in)"
        );
        $byId = array_column($convs, null, 'id');
        $decisions = $this->decisions($ids);
        $jackId = $this->jack->userId();
        $msgs = [];
        foreach ($this->db->all(
            "SELECT m.id, m.conversation_id, m.direction, m.type, m.body, m.ack, m.created_at, m.author_user_id,
                    TIMESTAMPDIFF(SECOND, m.created_at, NOW()) AS age
               FROM talk_messages m WHERE m.conversation_id IN ($in) ORDER BY m.id"
        ) as $m) {
            $msgs[(int) $m['conversation_id']][] = $m;
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $this->summary($byId[$id], $decisions[$id] ?? [], $msgs[$id] ?? [], $jackId);
            }
        }
        return $out;
    }

    private function summary(array $c, array $decisions, array $msgs, ?int $jackId): array
    {
        $inTriage = $c['status'] === 'bot' || ((int) $c['jack_pending'] === 1 && $c['status'] === 'pending' && $c['assigned_user_id'] === null);
        $steps = [];
        $replies = [];
        $failed = [];
        $stuck = [];
        $routed = false;
        $inbound = [];
        foreach ($msgs as $m) {
            if ($m['direction'] === 'in') {
                $inbound[] = $m;
                continue;
            }
            if (!$this->isJackMessage($m, $jackId)) {
                continue;
            }
            if ($m['direction'] === 'out') {
                $replies[] = $m;
                $steps[] = self::REPLY[$m['type']] ?? 'Mandou mensagem';
                if ($m['ack'] === 'failed') {
                    $failed[] = $m;
                } elseif ($m['ack'] === 'pending' && (int) $m['age'] > 300) {
                    $stuck[] = $m;
                }
                continue;
            }
            $body = (string) $m['body'];
            if (str_starts_with($body, 'Jack assumiu')) {
                $steps[] = 'Assumiu no Geral';
            } elseif (str_starts_with($body, 'Jack encaminhou para')) {
                $routed = true;
                $steps[] = 'Encaminhou para ' . rtrim(substr($body, strlen('Jack encaminhou para ')), '.');
                if ($c['helpdesk_ticket_id'] !== null) {
                    $steps[] = 'Abriu o chamado no SAC';
                }
            } elseif (str_starts_with($body, 'Distribuída para')) {
                $steps[] = 'Entregou para ' . self::firstName(trim((string) preg_replace('/^Distribuída para (.+?) \(.*$/u', '$1', $body)));
            } elseif (str_starts_with($body, 'Consulta do chamado')) {
                $steps[] = 'Consultou o chamado';
            } else {
                $steps[] = mb_strimwidth($body, 0, 60, '…');
            }
        }
        $shadow = array_values(array_filter($decisions, static fn ($d) => $d['mode'] === 'shadow'));
        $decisions = array_values(array_filter($decisions, static fn ($d) => $d['mode'] !== 'shadow'));
        $first = $decisions[0] ?? null;
        foreach ($decisions as $d) {
            if ((int) $d['confident'] === 1) {
                $first = $d; // a primeira decisão confiante é a que conta (igual aos acertos do Painel)
                break;
            }
        }
        $idea = $first !== null ? $this->idea($first, true) : $this->ideaWithoutDecision($c, $replies, $routed, $inTriage, $inbound);

        $problems = [];
        foreach ($failed as $m) {
            $problems[] = ['nivel' => 'erro', 'texto' => 'A mensagem "' . self::short((string) $m['body']) . '" não chegou ao cliente (falha no envio).'];
        }
        foreach ($stuck as $m) {
            $problems[] = ['nivel' => 'erro', 'texto' => 'A mensagem "' . self::short((string) $m['body']) . '" está há mais de 5 min na fila de envio.'];
        }
        if ($routed && $this->sacOn && $c['helpdesk_ticket_id'] === null && !Phone::isTest((string) $c['phone'])) { // teste não abre chamado
            $problems[] = ['nivel' => 'erro', 'texto' => 'Encaminhou, mas o chamado não abriu no SAC (o ERP não respondeu). Abra pela conversa, no botão Abrir chamado.'];
        }
        if ($inTriage && (int) $c['age'] > $this->jack->overdueSeconds()) {
            $problems[] = ['nivel' => 'erro', 'texto' => 'Está com a Jack há ' . self::minutes((int) $c['age']) . ' sem ser encaminhada. Confira se o worker está rodando.'];
        }
        if ($first !== null && $first['intent'] === null && (int) $first['confident'] === 0) {
            $problems[] = ['nivel' => 'aviso', 'texto' => 'Não reconheceu o assunto e mandou para o setor padrão (' . ($first['sector_name'] ?? '?') . ').'];
        }
        if ($first !== null && (int) $first['confident'] === 1 && $first['final_sector_id'] !== null
            && (int) $first['final_sector_id'] !== (int) $first['suggested_sector_id']) {
            $problems[] = ['nivel' => 'aviso', 'texto' => 'A equipe transferiu para ' . ($first['final_name'] ?? '?') . ': a Jack tinha mandado para ' . ($first['sector_name'] ?? '?') . '.'];
        }

        $last = $replies !== [] ? $replies[count($replies) - 1] : null;
        $lastIn = $inbound !== [] ? $inbound[count($inbound) - 1] : null;
        return [
            'conversation_id' => (int) $c['id'],
            'protocolo' => $c['protocol'],
            'contato' => $c['contact_name'] ?: $c['phone'],
            'teste' => Phone::isTest((string) $c['phone']), // carga de teste (bin/jack-carga.php)
            'situacao' => $inTriage ? 'agora' : ($problems !== [] && $problems[0]['nivel'] === 'erro' ? 'erro' : ($problems !== [] ? 'aviso' : 'feito')),
            'fazendo' => $inTriage ? $this->doingNow($c, $inbound) : null,
            'desde' => $c['opened_at'],
            'ultima_atividade' => $c['last_message_at'],
            'ultima_do_cliente' => $lastIn ? self::short(self::bodyOf($lastIn), 120) : null,
            'ideia' => $idea,
            'ia' => $shadow !== [] ? self::aiView($shadow[count($shadow) - 1]) : null,
            'procedimento' => array_values(array_unique($steps)),
            'encaminhou' => $routed,
            'respostas' => count($replies),
            'ultima_resposta' => $last ? ['texto' => (string) $last['body'], 'quando' => $last['created_at'], 'entrega' => self::ackLabel((string) $last['ack']),
                'falhou' => $last['ack'] === 'failed', 'tipo' => self::REPLY[$last['type']] ?? 'Mandou mensagem'] : null,
            'pendencias' => $problems,
            'setor' => $c['sector_name'],
            'atendente' => $c['assigned_name'] ? self::firstName($c['assigned_name']) : null,
            'status' => $c['status'],
            'chamado' => $c['helpdesk_ticket_id'] !== null || $c['helpdesk_protocol'] !== null
                ? ['numero' => $c['helpdesk_protocol'] ?: $c['protocol'], 'url' => $c['helpdesk_url']] : null,
        ];
    }

    /** O que ela está esperando para agir, numa frase. */
    private function doingNow(array $c, array $inbound): string
    {
        $text = implode("\n", array_map(static fn ($m) => (string) $m['body'], array_filter($inbound, static fn ($m) => $m['body'] !== null)));
        if ($text === '' || $this->jack->isOnlyGreeting($text)) {
            return 'Esperando a pessoa dizer o assunto (só cumprimentou até agora).';
        }
        if ((int) $c['jack_pending'] === 1) {
            return 'No Geral: lendo as mensagens e esperando o tempo combinado para encaminhar ao setor certo.';
        }
        return 'Lendo as mensagens: encaminha assim que a pessoa parar de escrever.';
    }

    /** "Ideia" a partir da decisão gravada: o que entendeu, por quais palavras, e para onde mandou. */
    private function idea(array $d, bool $short): array
    {
        $terms = $d['matched'] ? explode(', ', (string) $d['matched']) : [];
        $sector = $d['sector_name'] ?? '?';
        if ($d['intent'] === 'scope') {
            $text = "Perguntaram se ela é robô e depois seguiram com um assunto fora do que ela sabe; passou para $sector.";
        } elseif (($d['source'] ?? 'regras') === 'ia' && $d['intent'] !== null) {
            $label = self::INTENT[$d['intent']] ?? $d['intent'];
            $text = "A IA ({$d['model']}) entendeu que é $label" . ($d['ai_ms'] !== null ? ' em ' . self::seconds((int) $d['ai_ms']) : '') . "; mandou para $sector.";
        } elseif ($d['intent'] !== null && (int) $d['confident'] === 1) {
            $label = self::INTENT[$d['intent']] ?? $d['intent'];
            $text = "Entendeu que é $label" . ($terms ? ' pelas palavras "' . implode('", "', $terms) . '"' : '') . "; mandou para $sector.";
        } else {
            $text = 'Não reconheceu o assunto' . ($terms ? ' (achou só "' . implode('", "', $terms) . '", pouco para ter certeza)' : '')
                . "; mandou para o setor padrão, $sector.";
        }
        return ['intencao' => $d['intent'], 'rotulo' => $d['intent'] !== null ? (self::INTENT[$d['intent']] ?? $d['intent']) : 'não reconhecido',
            'confiante' => (int) $d['confident'] === 1, 'termos' => $terms, 'explicacao' => $text];
    }

    /** Sem decisão gravada: consulta de chamado, apresentação, número dedicado ou ainda pensando. */
    private function ideaWithoutDecision(array $c, array $replies, bool $routed, bool $inTriage, array $inbound): array
    {
        $types = array_column($replies, 'type');
        $text = match (true) {
            $inTriage => 'Ainda decidindo: ' . mb_strtolower(rtrim($this->doingNow($c, $inbound), '.')) . '.',
            in_array('jack_status', $types, true) => 'O cliente mandou o número de um chamado; ela respondeu como ele está.',
            in_array('jack_identity', $types, true) => 'Perguntaram se ela é robô; ela se apresentou com o texto aprovado.',
            $routed => 'Foi direto para ' . ($c['sector_name'] ?? 'o setor') . ' (número do setor ou resposta a um envio em massa), sem precisar adivinhar.',
            default => 'Sem decisão registrada.',
        };
        return ['intencao' => null, 'rotulo' => $inTriage ? 'decidindo' : '—', 'confiante' => false, 'termos' => [], 'explicacao' => $text];
    }

    /** @param list<int> $ids @return array<int, list<array>> */
    private function decisions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->db->all(
            "SELECT d.*, s.name AS sector_name, fs.name AS final_name FROM talk_jack_decisions d
               LEFT JOIN talk_sectors s ON s.id = d.suggested_sector_id LEFT JOIN talk_sectors fs ON fs.id = d.final_sector_id
              WHERE d.conversation_id IN ($in) ORDER BY d.id"
        ) as $d) {
            $out[(int) $d['conversation_id']][] = $d;
        }
        return $out;
    }

    private function isJackMessage(array $m, ?int $jackId): bool
    {
        if ($jackId !== null && $m['author_user_id'] !== null) {
            return (int) $m['author_user_id'] === $jackId;
        }
        // instalação sem a Jack cadastrada como atendente: reconhece pelo tipo e pelo texto das notas
        return ($m['direction'] === 'out' && isset(self::REPLY[$m['type']]))
            || ($m['direction'] === 'internal' && $m['author_user_id'] === null && preg_match('/^(Jack |Distribuída para |Consulta do chamado )/u', (string) $m['body']));
    }

    private static function bodyOf(array $m): string
    {
        $body = trim((string) $m['body']);
        return $body !== '' ? $body : (['image' => '[foto]', 'audio' => '[áudio]', 'video' => '[vídeo]', 'document' => '[documento]'][$m['type']] ?? '[arquivo]');
    }

    private static function ackLabel(string $ack): string
    {
        return ['pending' => 'na fila de envio', 'sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida',
            'failed' => 'falhou', 'received' => 'registrada'][$ack] ?? $ack;
    }

    private static function short(string $text, int $max = 70): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace('*Jack:*', '', $text)));
        return mb_strimwidth($t, 0, $max, '…');
    }

    private static function firstName(?string $name): string
    {
        $n = trim((string) preg_replace('/\s*\(.*\)$/', '', (string) $name));
        return $n === '' ? '?' : explode(' ', $n)[0];
    }

    /** Placar do modo sombra no período: quantas a IA acertaria, erraria, não teria certeza ou falhou, e o tempo médio. */
    private static function aiScore(array $views): array
    {
        $n = array_count_values(array_column($views, 'situacao'));
        $ms = array_values(array_filter(array_column($views, 'ms'), static fn ($v) => $v !== null));
        $judged = ($n['acertaria'] ?? 0) + ($n['erraria'] ?? 0);
        return ['total' => count($views), 'acertaria' => $n['acertaria'] ?? 0, 'erraria' => $n['erraria'] ?? 0,
            'sem_certeza' => $n['sem_certeza'] ?? 0, 'falhou' => $n['falhou'] ?? 0,
            'taxa' => $judged ? round(($n['acertaria'] ?? 0) / $judged, 3) : null,
            'ms_medio' => $ms ? (int) round(array_sum($ms) / count($ms)) : null, 'ms_max' => $ms ? max($ms) : null];
    }

    /** Modo sombra: o que a IA diria e se bate com onde a conversa ficou. */
    private static function aiView(array $d): array
    {
        $ms = $d['ai_ms'] !== null ? self::seconds((int) $d['ai_ms']) : null;
        if ($d['matched'] === 'IA não respondeu') {
            return ['situacao' => 'falhou', 'texto' => 'A IA não respondeu a tempo; valeu a regra.', 'setor' => null, 'ms' => $d['ai_ms'], 'modelo' => $d['model']];
        }
        $label = $d['intent'] !== null ? (self::INTENT[$d['intent']] ?? $d['intent']) : 'sem assunto';
        $where = $d['sector_name'] ?? null;
        $agrees = $where !== null && $d['final_sector_id'] !== null && (int) $d['suggested_sector_id'] === (int) $d['final_sector_id'];
        $text = 'A IA diria: ' . $label . ((int) $d['confident'] === 1 && $where ? " → $where" : ' (sem certeza)')
            . ($ms ? " ($ms)" : '') . '. ' . ($where === null || (int) $d['confident'] === 0 ? 'Não decidiria.' : ($agrees ? 'Bate com onde a conversa ficou.' : 'Não bate com onde a conversa ficou (' . ($d['final_name'] ?? '?') . ').'));
        return ['situacao' => $where === null || (int) $d['confident'] === 0 ? 'sem_certeza' : ($agrees ? 'acertaria' : 'erraria'),
            'texto' => $text, 'setor' => $where, 'ms' => $d['ai_ms'] !== null ? (int) $d['ai_ms'] : null, 'modelo' => $d['model']];
    }

    private static function seconds(int $ms): string
    {
        return $ms < 1000 ? "$ms ms" : str_replace('.', ',', (string) round($ms / 1000, 1)) . ' s';
    }

    private static function minutes(int $s): string
    {
        $m = intdiv($s, 60);
        return $m < 60 ? "$m min" : intdiv($m, 60) . ' h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }
}
