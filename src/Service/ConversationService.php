<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Media\MediaStore;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Domain\StatusUpdate;
use ConnectTalk\Jack\Jack;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Support\Db;
use ConnectTalk\Support\Phone;

/**
 * Regras do atendimento. Toda conversa nasce com protocolo e vira um chamado.
 *
 * Ciclo: bot (menu de setores) → pending (fila do setor) → open (com atendente) → closed
 * Uma mensagem que chega depois de encerrada abre nova conversa (novo protocolo).
 */
class ConversationService
{
    public function __construct(
        private Db $db,
        private EventBus $events,
        private AccessPolicy $policy,
        private ContactDirectory $directory,
        private Helpdesk $helpdesk,
        private Texts $texts = new Texts(),
        private ?Jack $jack = null,
        private ?MediaStore $media = null,
        private bool $askRating = true,
        private ?BusinessHours $hours = null,
        private bool $autoAssign = true,
        // chamado no SAC sozinho em toda conversa encaminhada (antes da 1.0). Desde 22/11/2026 o Djalma decidiu: só pelo
        // botão Abrir chamado, com o mesmo protocolo da conversa (ADR 0005). SAC_AUTO_TICKET=on volta o comportamento antigo.
        private bool $autoTicket = false,
        // Jack desligada (1.0, escolha do Djalma em 22/11/2026): menu de setores numerado no WhatsApp (TALK_MENU=on).
        // Sem resposta válida depois de 2 menus, ou calado por $menuTimeoutSeconds, vai para o setor padrão.
        private bool $sectorMenu = false,
        private int $menuTimeoutSeconds = 600,
    ) {
    }

    /** Menu de setores no lugar da Jack? (Jack desligada e TALK_MENU=on) */
    private function menuMode(): bool
    {
        return $this->sectorMenu && ($this->jack === null || $this->jack->mode() === 'off');
    }

    /** Setores do menu: os ativos com número (menu_key), sem o Geral da Jack. */
    private function menuSectors(): array
    {
        return $this->db->all(
            "SELECT id, name, menu_key FROM talk_sectors
              WHERE active = 1 AND menu_key <> '0' AND (jack_key IS NULL OR jack_key <> 'geral')
              ORDER BY CAST(menu_key AS UNSIGNED), menu_key"
        );
    }

    /** O que a pessoa respondeu ao menu: número ("2", "2️⃣", "opção 2") ou o nome do setor. */
    public static function menuPick(string $text, array $sectors): ?array
    {
        $norm = static fn (string $t): string => trim(preg_replace('/\s+/', ' ', mb_strtolower(
            strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
                'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ç' => 'c', 'Ã' => 'a', 'Õ' => 'o', 'Â' => 'a', 'Ê' => 'e', 'Ô' => 'o'])
        )));
        $t = $norm($text);
        if (preg_match('/^(?:op(?:cao|ção)?\.?\s*)?(\d{1,2})(?:\x{FE0F}?\x{20E3})?[\s.)!-]*$/u', $t, $m)) {
            foreach ($sectors as $sec) {
                if ((string) $sec['menu_key'] === (string) (int) $m[1]) {
                    return $sec;
                }
            }
            return null;
        }
        foreach ($sectors as $sec) {
            if ($t !== '' && $t === $norm((string) $sec['name'])) {
                return $sec;
            }
        }
        return null;
    }

    /**
     * Conversa em "bot" com o menu ligado: manda o menu, ou encaminha para o setor escolhido.
     * Retorna o setor para encaminhar, ou null para esperar a resposta.
     */
    private function menuStep(array $conv, array $contact, InboundMessage $in, bool $isNew): ?array
    {
        $sectors = $this->menuSectors();
        if (count($sectors) < 2) {
            return $this->fallbackSector();
        }
        $sent = (int) $this->db->one(
            "SELECT COUNT(*) n FROM talk_messages WHERE conversation_id = ? AND direction = 'out' AND type = 'sector_menu'", [$conv['id']]
        )['n'];
        if ($sent > 0 && $in->type === 'text' && ($pick = self::menuPick((string) $in->body, $sectors)) !== null) {
            return $pick;
        }
        if ($sent >= 2) {
            $this->jackNote((int) $conv['id'], 'Não escolheu o setor no menu: foi para o setor padrão.');
            return $this->fallbackSector();
        }
        $first = $contact['name'] ? explode(' ', trim((string) $contact['name']))[0] : null;
        $this->queueOutbound($conv, $this->texts->sectorMenu($first, $sectors, $sent > 0), null, 'sector_menu');
        return null;
    }

    /** Menu sem resposta por $menuTimeoutSeconds: vai para o setor padrão (a pessoa não fica esquecida). */
    private function menuTimeouts(int $clockShift): int
    {
        $rows = $this->db->all(
            "SELECT c.id FROM talk_conversations c
               JOIN talk_messages m ON m.conversation_id = c.id
              WHERE c.status = 'bot'
              GROUP BY c.id
             HAVING TIMESTAMPDIFF(SECOND, MAX(m.created_at), NOW() + INTERVAL ? SECOND) >= ?",
            [$clockShift, $this->menuTimeoutSeconds]
        );
        $n = 0;
        foreach ($rows as $r) {
            $n += (int) $this->db->transaction(function () use ($r): bool {
                $conv = $this->lock((int) $r['id']);
                $fallback = $this->fallbackSector();
                if ($conv['status'] !== 'bot' || $fallback === null) {
                    return false;
                }
                $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                $this->jackNote((int) $conv['id'], 'Sem resposta ao menu de setores: foi para o setor padrão.');
                $this->route($conv, $fallback, $contact);
                $this->sayOffHours($conv, $fallback, $contact);
                return true;
            });
        }
        return $n;
    }

    // ------------------------------------------------------------------ entrada

    /** Processa o que chegou pelo webhook. Retorna o id da conversa (ou null se já processado). */
    public function handleInbound(int $channelId, InboundMessage $in): ?int
    {
        return $this->db->transaction(function () use ($channelId, $in) {
            if ($this->db->one('SELECT id FROM talk_messages WHERE provider_message_id = ?', [$in->providerMessageId])) {
                return null; // webhook repetido
            }
            if (ContactBook::dropIfBlocked($this->db, $in->fromPhone)) {
                return null; // contato bloqueado: descarta (fica só a contagem)
            }

            $contact = $this->resolveContact($in);
            $conv = $this->db->one(
                "SELECT * FROM talk_conversations WHERE contact_id = ? AND channel_id = ? AND status <> 'closed'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$contact['id'], $channelId]
            );
            if ($conv === null && ($rated = $this->answerRating($channelId, $contact, $in)) !== null) {
                return $rated; // era a nota do atendimento que acabou de encerrar
            }
            $isNew = $conv === null;
            if ($isNew) {
                $conv = $this->createConversation($channelId, $contact);
                $this->parkInGeneral($conv);
            }

            $msgId = $this->db->insert('talk_messages', [
                'conversation_id' => $conv['id'],
                'direction' => 'in',
                'type' => $in->type,
                'body' => $in->body,
                'media_mime' => $in->mediaMime,
                'media_path' => $in->mediaRef ? 'pending:' . $in->mediaRef : null,
                'provider_message_id' => $in->providerMessageId,
                'ack' => 'received',
            ]);
            $this->db->run(
                // a resposta do cliente tira o "aguardando cliente"
                "UPDATE talk_conversations SET unread = unread + 1, last_message_at = NOW(),
                        stage = IF(stage = 'waiting_customer', NULL, stage) WHERE id = ?",
                [$conv['id']]
            );
            $conv = $this->get((int) $conv['id']);

            if ($isNew) {
                $this->events->publish('conversation.created', (int) $conv['id'], $this->sector($conv), null, $this->summary($conv));
            }
            $this->events->publish('message.created', (int) $conv['id'], $this->sector($conv), null, [
                'message_id' => $msgId, 'direction' => 'in', 'type' => $in->type, 'body' => $in->body,
            ]);

            // O cliente mandou o número do chamado: a Jack conta como está (só chamados deste mesmo número)
            if ($this->jack !== null && $this->jack->mode() !== 'off' && $in->type === 'text' && $conv['assigned_user_id'] === null
                && ($code = self::ticketCode((string) $in->body)) !== null) {
                $this->answerTicketStatus($conv, $contact, $code);
                if ($isNew && self::onlyTicketCode((string) $in->body)) {
                    // só a consulta: não entra na fila de ninguém
                    $this->db->run(
                        "UPDATE talk_conversations SET status = 'closed', jack_pending = 0, unread = 0, closed_at = NOW(), closed_by = ? WHERE id = ?",
                        [$this->jack->userId(), $conv['id']]
                    );
                    $this->events->publish('conversation.updated', (int) $conv['id'], $this->sector($conv), null, $this->summary($this->get((int) $conv['id'])));
                    return (int) $conv['id'];
                }
            }

            // Sem menu: a Jack lê em silêncio e encaminha depois que a pessoa termina de escrever (triage()).
            // Ela só fala quando perguntam se é robô, ou quando o assunto sai do que ela sabe.
            if ($this->jack !== null && $in->type === 'text' && trim((string) $in->body) !== '') {
                $talk = $this->jack->converse($conv, (string) $in->body);
                if ($talk !== null && $talk['kind'] === 'identity') {
                    $this->jackSay($conv, $this->texts->jackIdentity(), 'jack_identity');
                    return (int) $conv['id'];
                }
                if ($talk !== null) {
                    $this->jackSay($conv, $this->texts->jackOutOfScope($talk['sector']['name']), 'jack_scope');
                    if (self::inTriage($conv)) {
                        $this->jack->record($conv, $talk['sector'], 'scope', null);
                        // o texto de "fora do escopo" já avisa a transferência
                        $this->route($conv, $talk['sector'], $contact, 'jack');
                        $this->sayOffHours($conv, $talk['sector'], $contact);
                    }
                    return (int) $conv['id'];
                }
            }
            if ($conv['status'] === 'bot' && ($this->jack === null || $this->jack->mode() === 'off')) {
                $fallback = $this->fallbackSector();
                if ($this->menuMode()) {
                    // número dedicado a um setor e resposta a envio em massa não passam pelo menu
                    $fixed = $this->db->one('SELECT s.id, s.name FROM talk_channels ch JOIN talk_sectors s ON s.id = ch.sector_id AND s.active = 1 WHERE ch.id = ?', [$conv['channel_id']]);
                    $campaign = $fixed ? null : CampaignReplies::replyTo($this->db, $contact);
                    $fallback = $fixed ?: ($campaign['sector'] ?? null) ?: $this->menuStep($conv, $contact, $in, $isNew);
                }
                if ($fallback !== null) {
                    $this->route($conv, $fallback, $contact);
                    $this->sayOffHours($conv, $fallback, $contact);
                }
            }
            return (int) $conv['id'];
        });
    }

    /**
     * Resposta à pergunta de avaliação: vale nas 24 h depois de encerrar e só para a última conversa
     * da pessoa naquele número. Se a mensagem não for uma nota, segue o fluxo normal (conversa nova).
     */
    private function answerRating(int $channelId, array $contact, InboundMessage $in): ?int
    {
        if ($in->type !== 'text' || trim((string) $in->body) === '') {
            return null;
        }
        $r = $this->db->one(
            'SELECT r.id, r.conversation_id FROM talk_ratings r
               JOIN talk_conversations c ON c.id = r.conversation_id
              WHERE r.contact_id = ? AND c.channel_id = ? AND r.answered_at IS NULL AND r.asked_at >= NOW() - INTERVAL 24 HOUR
                AND c.id = (SELECT MAX(x.id) FROM talk_conversations x WHERE x.contact_id = ? AND x.channel_id = ?)
              FOR UPDATE',
            [$contact['id'], $channelId, $contact['id'], $channelId]
        );
        if (!$r) {
            return null;
        }
        $parsed = self::parseRating((string) $in->body);
        if ($parsed === null) {
            return null;
        }
        [$score, $comment] = $parsed;
        $convId = (int) $r['conversation_id'];
        $msgId = $this->db->insert('talk_messages', [
            'conversation_id' => $convId, 'direction' => 'in', 'type' => 'rating', 'body' => $in->body,
            'provider_message_id' => $in->providerMessageId, 'ack' => 'received',
        ]);
        $this->db->run('UPDATE talk_ratings SET score = ?, comment = ?, answered_at = NOW() WHERE id = ?', [$score, $comment, $r['id']]);
        $conv = $this->get($convId);
        $this->events->publish('message.created', $convId, $this->sector($conv), null, [
            'message_id' => $msgId, 'direction' => 'in', 'type' => 'rating', 'body' => $in->body,
        ]);
        $this->queueOutbound($conv, $this->texts->ratingThanks($score), null, 'rating_thanks');
        return $convId;
    }

    /** "5", "4 - foi rápido", "⭐⭐⭐⭐", "ótimo"... → [nota, comentário]. Outra coisa → null. */
    public static function parseRating(string $text): ?array
    {
        $t = trim($text);
        // número sozinho, ou seguido de separador e comentário ("4 - foi rápido"); "2 via do boleto" não é nota
        if (preg_match('/^([1-5])(?:\s*(?:\/\s*5|de\s+5))?\s*(?:$|[-–—:.,!)]\s*(.*)$)/su', $t, $m)) {
            return [(int) $m[1], self::comment($m[2] ?? '')];
        }
        if (preg_match('/^((?:⭐|★|🌟)\x{FE0F}?\s*){1,5}(.*)$/su', $t, $m)) {
            $stars = preg_match_all('/⭐|★|🌟/u', mb_substr($t, 0, mb_strlen($t) - mb_strlen($m[2])));
            return [min(5, max(1, $stars)), self::comment($m[2])];
        }
        $words = ['pessimo' => 1, 'muito ruim' => 1, 'ruim' => 2, 'regular' => 3, 'razoavel' => 3, 'bom' => 4,
            'muito bom' => 5, 'otimo' => 5, 'excelente' => 5];
        $n = trim(preg_replace('/[^\p{L}\s]/u', '', \ConnectTalk\Jack\Classifier::normalize($t)));
        return isset($words[$n]) ? [$words[$n], null] : null;
    }

    private static function comment(string $rest): ?string
    {
        $rest = trim($rest);
        return $rest === '' ? null : mb_substr($rest, 0, 500);
    }

    public function handleStatus(StatusUpdate $s): void
    {
        $msg = $this->db->one('SELECT m.id, m.conversation_id, c.sector_id FROM talk_messages m JOIN talk_conversations c ON c.id = m.conversation_id WHERE m.provider_message_id = ?', [$s->providerMessageId]);
        if (!$msg) {
            CampaignReplies::status($this->db, $s->providerMessageId, $s->ack); // pode ser um envio em massa
            return;
        }
        // não regride (read não volta para delivered)
        $this->db->run(
            "UPDATE talk_messages SET ack = :ack WHERE id = :id AND FIELD(ack,'pending','sent','delivered','read','failed') < FIELD(:ack2,'pending','sent','delivered','read','failed')",
            ['ack' => $s->ack, 'ack2' => $s->ack, 'id' => $msg['id']]
        );
        $this->events->publish('message.ack', (int) $msg['conversation_id'], $msg['sector_id'] !== null ? (int) $msg['sector_id'] : null, null, [
            'message_id' => (int) $msg['id'], 'ack' => $s->ack,
        ]);
    }

    private function resolveContact(InboundMessage $in): array
    {
        $phone = Phone::normalize($in->fromPhone);
        $contact = $this->db->one('SELECT * FROM talk_contacts WHERE phone = ?', [$phone]);
        if ($contact && $contact['erp_person_id'] !== null) {
            return $contact;
        }
        $person = $this->directory->findByPhone(Phone::candidates($phone));
        $data = [
            'name' => $person['name'] ?? $in->contactName ?? $contact['name'] ?? null,
            'erp_person_id' => $person['person_id'] ?? null,
            'erp_snapshot' => $person ? json_encode($person['details'], JSON_UNESCAPED_UNICODE) : null,
        ];
        if ($contact) {
            $this->db->run('UPDATE talk_contacts SET name = :name, erp_person_id = :erp_person_id, erp_snapshot = :erp_snapshot WHERE id = :id', $data + ['id' => $contact['id']]);
            return $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$contact['id']]);
        }
        $id = $this->db->insert('talk_contacts', ['phone' => $phone] + $data);
        return $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$id]);
    }

    private function createConversation(int $channelId, array $contact): array
    {
        $id = $this->db->insert('talk_conversations', [
            'protocol' => $this->nextProtocol(),
            'channel_id' => $channelId,
            'contact_id' => $contact['id'],
            'status' => 'bot',
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->get($id);
    }

    /**
     * Protocolo do atendimento: AAMMDD + sequência do dia com 5 dígitos (ex.: 26111600004), sem colisão entre
     * processos. É o mesmo número do chamado quando o atendente abre o chamado no SAC (ADR 0637 do ERP). Até a 1.0 o
     * número tinha "SAC" na frente (a migração 027 tirou); os mais antigos ficam em legacy_protocol (migração 018).
     */
    public function nextProtocol(): string
    {
        $day = date('Y-m-d');
        $this->db->run(
            'INSERT INTO talk_protocol_seq (day, `last_value`) VALUES (?, 1) ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1',
            [$day]
        );
        $n = (int) $this->db->one('SELECT `last_value` FROM talk_protocol_seq WHERE day = ?', [$day])['last_value'];
        return date('ymd') . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ triagem (Jack)

    /**
     * Encaminha as conversas que estão na triagem (status "bot") quando a pessoa parou de escrever.
     * Roda no worker (e no fim de cada requisição no laboratório). $clockShift só para testes.
     * @return int quantas conversas foram encaminhadas
     */
    public function triage(int $clockShift = 0): int
    {
        if ($this->menuMode()) {
            return $this->menuTimeouts($clockShift) + $this->autoAssignWaiting();
        }
        if ($this->jack === null) {
            return $this->autoAssignWaiting();
        }
        $rows = $this->db->all(
            "SELECT c.id,
                    TIMESTAMPDIFF(SECOND, MAX(m.created_at), NOW() + INTERVAL ? SECOND) AS quiet,
                    TIMESTAMPDIFF(SECOND, c.opened_at, NOW() + INTERVAL ? SECOND) AS age,
                    c.jack_pending
               FROM talk_conversations c
               JOIN talk_messages m ON m.conversation_id = c.id AND m.direction = 'in'
              WHERE c.status = 'bot' OR (c.jack_pending = 1 AND c.status = 'pending' AND c.assigned_user_id IS NULL)
              GROUP BY c.id, c.opened_at, c.jack_pending",
            [$clockShift, $clockShift]
        );
        $routed = 0;
        foreach ($rows as $r) {
            if ((int) $r['jack_pending'] === 1) {
                // no Geral: espera o tempo combinado (padrão 1 min) antes de distribuir, mesmo com assunto claro
                if (!$this->jack->isDueInGeneral((int) $r['quiet'], (int) $r['age'])) {
                    continue;
                }
            } elseif (!$this->jack->isDue((int) $r['quiet'], (int) $r['age']) && !$this->jack->isClear($this->inboundText((int) $r['id']))) {
                // sem Geral: assunto já claro ("estamos sem água") vai para a fila na hora; senão espera a pessoa terminar
                continue;
            }
            $routed += (int) $this->db->transaction(function () use ($r): bool {
                $conv = $this->lock((int) $r['id']);
                if (!self::inTriage($conv)) {
                    return false; // outro processo já encaminhou, ou um atendente pegou no Geral
                }
                $texts = array_column($this->db->all(
                    "SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'in' AND type IN ('text','image','video') AND body IS NOT NULL ORDER BY id", // legenda de foto/vídeo também conta
                    [$conv['id']]
                ), 'body');
                // número dedicado a um setor (tela Conexão WhatsApp): vai direto para ele, sem a Jack adivinhar
                $fixed = $this->db->one(
                    'SELECT s.id, s.name FROM talk_channels ch JOIN talk_sectors s ON s.id = ch.sector_id AND s.active = 1 WHERE ch.id = ?',
                    [$conv['channel_id']]
                );
                // resposta a um envio em massa recente: vai para o setor da campanha, com a mensagem enviada como nota
                $contactRow = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                foreach ($texts as $t) {
                    CampaignReplies::checkOptOut($this->db, (int) $contactRow['id'], (string) $t);
                }
                $campaign = $fixed ? null : CampaignReplies::replyTo($this->db, $contactRow);
                if ($campaign) {
                    $fixed = $campaign['sector'];
                    $this->db->insert('talk_messages', [
                        'conversation_id' => $conv['id'], 'direction' => 'internal', 'type' => 'note', 'ack' => 'received',
                        'body' => "Resposta ao envio em massa \"{$campaign['campaign']}\". Mensagem enviada:\n{$campaign['text']}",
                    ]);
                }
                $d = $fixed ? ['action' => 'route', 'sector' => $fixed]
                    // no Geral o tempo de espera já passou: "bom dia" sozinho não segura mais a conversa
                    : $this->jack->decide($conv, implode("\n", $texts), (int) $r['quiet'], (int) $conv['jack_pending'] === 1 ? PHP_INT_MAX : (int) $r['age']);
                if ($d === null) {
                    return false; // já perguntou e a pessoa ainda não disse o assunto: espera
                }
                $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                $first = $contact['name'] ? explode(' ', trim((string) $contact['name']))[0] : null;
                $this->route($conv, $d['sector'], $contact, 'jack');
                // Fora do horário a Jack avisa quando a equipe volta (o texto já traz o número do chamado).
                // No horário, ela conta que abriu o chamado, com o número e o link (ADR 0004, revisão de 22/11/2026).
                $conv = $this->get((int) $conv['id']);
                $off = $this->offHours($conv, $d['sector'], $first);
                if ($off !== null) {
                    $url = $conv['helpdesk_url'] ?? null;
                    $this->jackSay($conv, $off . ($url ? "\n\n" . $this->texts->trackLink($url) : ''), 'off_hours');
                } elseif ($this->jack->announcesTickets() && ($this->autoTicket || $conv['helpdesk_ticket_id'])) { // sem chamado, não fala em chamado
                    $this->announceTicket($conv, $d['sector'], $first);
                }
                return true;
            });
        }
        return $routed + $this->autoAssignWaiting();
    }

    /** "Bom dia, Maria! Já abri o chamado CH… com o Administrativo…" (Texts::jackTicketOpened). */
    private function announceTicket(array $conv, array $sector, ?string $first): void
    {
        $who = null;
        if ($conv['status'] === 'open' && $conv['assigned_user_id'] !== null) {
            $name = (string) ($this->db->one('SELECT name FROM talk_users WHERE id = ?', [$conv['assigned_user_id']])['name'] ?? '');
            $who = trim(explode(' ', trim((string) preg_replace('/\s*\(.*\)$/', '', $name)))[0]) ?: null;
        }
        $text = $this->texts->jackTicketOpened($first, self::ticketNumber($conv), (string) $sector['name'], $conv['helpdesk_url'] ?? null, $who, (int) $conv['id'], (int) date('G'));
        $this->jackSay($conv, $text, 'jack_ticket');
    }

    // ------------------------------------------------------------------ consulta de chamado pelo WhatsApp

    /**
     * Número de chamado na mensagem: o protocolo do Talk e do SAC (26112200004, com ou sem "SAC" na frente) ou um chamado
     * antigo do ERP (CH2611220A1B). O protocolo tem data válida (aammdd), então um telefone não passa por ele.
     */
    private const CODE = '/(?<![A-Z0-9])(CH\d{6}[A-F0-9]{4}|(?:SAC)?(?:2[4-9]|3\d)(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])\d{5})(?![A-Z0-9])/i';

    public static function ticketCode(string $text): ?string
    {
        return preg_match(self::CODE, $text, $m) ? self::plainProtocol(strtoupper($m[1])) : null;
    }

    /** O protocolo como aparece para todos: sem o "SAC" que os números antigos tinham na frente. */
    public static function plainProtocol(string $protocol): string
    {
        return (string) preg_replace('/^SAC(?=\d{11}$)/', '', $protocol);
    }

    /** A mensagem é só a consulta ("CH2611220A1B", "status do chamado CH…?"), sem outro assunto. */
    public static function onlyTicketCode(string $text): bool
    {
        $rest = (string) preg_replace(self::CODE, ' ', $text);
        $rest = \ConnectTalk\Jack\Classifier::normalize($rest);
        $rest = (string) preg_replace('/\b(oi|ola|bom dia|boa tarde|boa noite|meu|minha|o|a|do|da|de|e|qual|como|esta|ta|status|situacao|andamento|chamado|protocolo|numero|n|no|por favor|pf|obrigad[oa])\b/u', ' ', $rest);
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $rest)) === '';
    }

    /** Responde a situação do chamado; só acha chamado do mesmo contato (o número de WhatsApp é a identificação). */
    private function answerTicketStatus(array $conv, array $contact, string $code): void
    {
        $first = $contact['name'] ? explode(' ', trim((string) $contact['name']))[0] : null;
        $found = $this->db->one(
            'SELECT c.*, s.name AS sector_name FROM talk_conversations c LEFT JOIN talk_sectors s ON s.id = c.sector_id
              WHERE c.contact_id = ? AND (c.helpdesk_protocol = ? OR c.protocol = ? OR c.protocol = ? OR c.legacy_protocol = ?) ORDER BY c.id DESC LIMIT 1',
            [$contact['id'], $code, $code, 'SAC' . $code, $code]
        );
        if ($found === null) {
            $this->jackSay($conv, $this->texts->jackTicketNotFound($first, $code), 'jack_status');
            $this->jackNote((int) $conv['id'], "Consulta do chamado $code: não é deste número de WhatsApp (nada foi mostrado).");
            return;
        }
        $remote = $found['helpdesk_ticket_id'] ? $this->safeHelpdesk(fn () => $this->helpdesk->status((int) $found['helpdesk_ticket_id'])) : null;
        if (is_array($remote)) {
            $label = $remote['label'];
            $url = $remote['url'] ?? $found['helpdesk_url'];
            $lastReply = $remote['last_reply_at'];
        } else {
            $label = match ($found['status']) {
                'closed' => 'encerrado',
                'open' => 'em atendimento',
                default => 'na fila, aguardando um atendente',
            };
            $url = $found['helpdesk_url'];
            $lastReply = null;
        }
        $this->jackSay($conv, $this->texts->jackTicketStatus($first, self::ticketNumber($found), $label, $found['sector_name'], $lastReply, $url), 'jack_status');
        $this->jackNote((int) $conv['id'], "Consulta do chamado $code pelo WhatsApp: a Jack respondeu \"$label\".");
    }

    private function jackNote(int $convId, string $text): void
    {
        $this->db->insert('talk_messages', [
            'conversation_id' => $convId, 'direction' => 'internal', 'author_user_id' => $this->jack?->userId(),
            'type' => 'note', 'body' => $text, 'ack' => 'received',
        ]);
    }

    // ------------------------------------------------------------------ distribuição automática

    /**
     * Só uma pessoa online pode atender o setor: a conversa vai direto para ela, sem o cliente esperar
     * na fila (pedido do Djalma em 22/11/2026). Online = Talk aberto no último minuto. Quem pôs a conversa
     * na fila (transferência ou "Na fila") não a recebe de volta. Desliga com AUTO_ASSIGN=off.
     */
    private function autoAssign(array $conv): bool
    {
        if (!$this->autoAssign || $conv['status'] !== 'pending' || $conv['assigned_user_id'] !== null
            || (int) ($conv['jack_pending'] ?? 0) === 1 || $conv['sector_id'] === null) {
            return false;
        }
        $online = $this->db->all(
            "SELECT DISTINCT u.id, u.name FROM talk_users u JOIN talk_staff s ON s.user_id = u.id
              WHERE u.active = 1 AND u.is_bot = 0 AND u.last_seen_at >= NOW() - INTERVAL " . QueueQuery::ONLINE_SECONDS . " SECOND
                AND (s.sector_id = ? OR s.role = 'supervisor')",
            [$conv['sector_id']]
        );
        if (count($online) !== 1 || ($conv['transferred_by'] !== null && (int) $online[0]['id'] === (int) $conv['transferred_by'])) {
            return false;
        }
        $to = $online[0];
        $jackId = $this->jack?->userId();
        $n = $this->db->run(
            "UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, accepted_at = COALESCE(accepted_at, NOW()),
                    transfer_pending = 1, transferred_by = ? WHERE id = ? AND status = 'pending' AND assigned_user_id IS NULL",
            [$to['id'], $jackId, $conv['id']]
        )->rowCount();
        if ($n === 0) {
            return false;
        }
        $sector = $this->db->one('SELECT name FROM talk_sectors WHERE id = ?', [$conv['sector_id']]);
        $this->db->insert('talk_messages', [
            'conversation_id' => $conv['id'], 'direction' => 'internal', 'author_user_id' => $jackId, 'type' => 'transfer', 'ack' => 'received',
            'body' => sprintf('Distribuída para %s (única pessoa online para %s).', $to['name'], $sector['name'] ?? 'o setor'),
        ]);
        $conv = $this->get((int) $conv['id']);
        $this->events->publish('conversation.updated', (int) $conv['id'], $this->sector($conv), (int) $to['id'], $this->summary($conv));
        return true;
    }

    /** Fila parada e alguém entrou: confere de novo quem está esperando (roda junto com a triagem). */
    public function autoAssignWaiting(): int
    {
        if (!$this->autoAssign) {
            return 0;
        }
        $n = 0;
        foreach ($this->db->all("SELECT id FROM talk_conversations WHERE status = 'pending' AND assigned_user_id IS NULL AND jack_pending = 0 AND sector_id IS NOT NULL ORDER BY COALESCE(queued_at, opened_at) LIMIT 50") as $r) {
            $n += (int) $this->db->transaction(fn (): bool => $this->autoAssign($this->lock((int) $r['id'])));
        }
        return $n;
    }

    /** Mensagem da Jaqueline: entra na fila com "digitando…" antes, como uma pessoa. */
    private function jackSay(array $conv, string $text, string $type): int
    {
        return $this->queueOutbound($conv, $text, $this->jack?->userId(), $type, $this->jack?->typingSeconds($text) ?? 0);
    }

    /** Texto de fora do horário (uma vez por conversa), ou null se o setor está atendendo. */
    private function offHours(array $conv, array $sector, ?string $first): ?string
    {
        if ($this->hours === null || !$this->hours->enabled()) {
            return null;
        }
        $conv = $this->get((int) $conv['id']); // já com o número do chamado do SAC
        $text = $this->hours->offHoursMessage((int) $sector['id'], (string) $sector['name'], $first, self::ticketNumber($conv));
        if ($text === null) {
            return null;
        }
        $n = $this->db->run('UPDATE talk_conversations SET off_hours_at = NOW() WHERE id = ? AND off_hours_at IS NULL', [$conv['id']])->rowCount();
        return $n > 0 ? $text : null;
    }

    private function sayOffHours(array $conv, array $sector, array $contact): void
    {
        $first = $contact['name'] ? explode(' ', trim((string) $contact['name']))[0] : null;
        $off = $this->offHours($conv, $sector, $first);
        if ($off !== null) {
            $this->jackSay($this->get((int) $conv['id']), $off, 'off_hours');
        }
    }

    private function fallbackSector(): ?array
    {
        return $this->jack?->fallbackSector()
            ?? $this->db->one("SELECT id, name FROM talk_sectors WHERE active = 1 ORDER BY menu_key LIMIT 1");
    }

    /** Conversa ainda nas mãos da Jack: na triagem antiga (status bot) ou esperando no Geral. */
    private static function inTriage(array $conv): bool
    {
        return $conv['status'] === 'bot'
            || ((int) ($conv['jack_pending'] ?? 0) === 1 && $conv['status'] === 'pending' && $conv['assigned_user_id'] === null);
    }

    /**
     * Conversa nova vai para a fila do Geral (à vista da equipe) e a Jack distribui depois.
     * Número dedicado a um setor não passa pelo Geral: a triagem manda direto para o setor dele.
     */
    private function parkInGeneral(array $conv): void
    {
        $general = $this->jack?->generalSector();
        if ($general === null) {
            return;
        }
        $fixed = $this->db->one('SELECT s.id FROM talk_channels ch JOIN talk_sectors s ON s.id = ch.sector_id AND s.active = 1 WHERE ch.id = ?', [$conv['channel_id']]);
        if ($fixed) {
            return;
        }
        $n = $this->db->run(
            "UPDATE talk_conversations SET sector_id = ?, status = 'pending', jack_pending = 1, queued_at = NOW() WHERE id = ? AND status = 'bot'",
            [$general['id'], $conv['id']]
        )->rowCount();
        if ($n > 0) {
            $this->jackNote((int) $conv['id'], 'Jack assumiu a conversa no Geral e vai encaminhar para o setor certo.');
        }
    }

    private function openTicketIfMissing(array $conv): void
    {
        if ($conv['helpdesk_ticket_id']) {
            return;
        }
        $sector = $conv['sector_id'] ? $this->db->one('SELECT name FROM talk_sectors WHERE id = ?', [$conv['sector_id']]) : null;
        $this->openTicket($conv, $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]), $sector['name'] ?? null);
    }

    /**
     * Coloca a conversa na fila do setor, abre o chamado e, se só uma pessoa pode atender agora, entrega
     * direto para ela. Não manda nada para o cliente. $by = 'jack' deixa marcado que foi a Jack.
     */
    private function route(array $conv, array $sector, array $contact, ?string $by = null): void
    {
        // vindo do Geral, a espera continua contando de quando a mensagem chegou
        $this->db->run(
            "UPDATE talk_conversations SET sector_id = ?, status = 'pending', jack_pending = 0, queued_at = IF(jack_pending = 1, queued_at, NOW()) WHERE id = ?",
            [$sector['id'], $conv['id']]
        );
        $conv = $this->get((int) $conv['id']);
        $this->openTicket($conv, $contact, $sector['name']);
        $this->jack?->settle((int) $conv['id'], (int) $sector['id']);
        if ($by === 'jack') {
            $this->db->insert('talk_messages', [
                'conversation_id' => $conv['id'], 'direction' => 'internal', 'author_user_id' => $this->jack?->userId(), 'type' => 'transfer', 'ack' => 'received',
                'body' => 'Jack encaminhou para ' . $sector['name'] . '.',
            ]);
        }
        $this->events->publish('conversation.updated', (int) $conv['id'], (int) $sector['id'], null, $this->summary($this->get((int) $conv['id'])));
        $this->autoAssign($this->get((int) $conv['id']));
    }

    /**
     * Conversa iniciada pela Connect (ex.: mensagem agendada): já entra em atendimento com quem
     * iniciou (ou na fila do setor, se a pessoa não estiver mais ativa) e abre o chamado.
     */
    public function startOutbound(int $channelId, array $contact, int $sectorId, ?int $userId, string $note): array
    {
        $sector = $this->db->one('SELECT id, name FROM talk_sectors WHERE id = ?', [$sectorId]);
        $active = $userId !== null && $this->db->one('SELECT id FROM talk_users WHERE id = ? AND active = 1', [$userId]);
        $id = $this->db->insert('talk_conversations', [
            'protocol' => $this->nextProtocol(),
            'channel_id' => $channelId,
            'contact_id' => $contact['id'],
            'sector_id' => $sectorId,
            'status' => $active ? 'open' : 'pending',
            'assigned_user_id' => $active ? $userId : null,
            'queued_at' => date('Y-m-d H:i:s'),
            'accepted_at' => $active ? date('Y-m-d H:i:s') : null,
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
        $conv = $this->get($id);
        $this->openTicket($conv, $contact, $sector['name']);
        $this->db->insert('talk_messages', [
            'conversation_id' => $id, 'direction' => 'internal', 'author_user_id' => $userId, 'type' => 'note', 'body' => $note, 'ack' => 'received',
        ]);
        $conv = $this->get($id);
        $this->events->publish('conversation.created', $id, $sectorId, $userId, $this->summary($conv));
        return $conv;
    }

    // ------------------------------------------------------------------ ações do atendente

    public function accept(int $convId, StaffMember $u): array
    {
        return $this->db->transaction(function () use ($convId, $u) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] !== 'pending') {
                throw new DomainError('Esta conversa já foi aceita por outra pessoa.');
            }
            $fromGeneral = (int) $conv['jack_pending'] === 1;
            $this->db->run("UPDATE talk_conversations SET status = 'open', jack_pending = 0, transfer_pending = 0, assigned_user_id = ?, unread = 0, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
            $conv = $this->get($convId);
            if ($fromGeneral) {
                // pegou no Geral antes da Jack distribuir: ela sai de cena e o chamado abre agora
                $this->jack?->settle($convId, (int) $conv['sector_id']);
                $this->openTicketIfMissing($conv);
                $conv = $this->get($convId);
            }
            $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            return $conv;
        });
    }

    /** Prioridade na fila (alta passa na frente na ordenação "Prioridade"). */
    public function setPriority(int $convId, StaffMember $u, string $priority): array
    {
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            throw new DomainError('Prioridade inválida.');
        }
        return $this->db->transaction(function () use ($convId, $u, $priority) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed') {
                throw new DomainError('Conversa encerrada.');
            }
            $this->db->run('UPDATE talk_conversations SET priority = ? WHERE id = ?', [$priority, $convId]);
            $conv = $this->get($convId);
            $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv) + ['priority' => $priority]);
            return $conv;
        });
    }

    public const STATUS_LABELS = [
        'pending' => 'Na fila',
        'open' => 'Em atendimento',
        'waiting_customer' => 'Aguardando cliente',
        'waiting_internal' => 'Aguardando retorno interno',
    ];

    /**
     * Muda o status pelo seletor da conversa. Encerrar tem a própria ação (close()).
     * - pending: devolve para a fila do setor, sem atendente;
     * - open: assume o atendimento (na fila) ou reabre um encerrado;
     * - waiting_customer / waiting_internal: continua com o atendente, marcado como aguardando.
     * Cada mudança fica como nota interna no histórico.
     */
    public function setStatus(int $convId, StaffMember $u, string $status): array
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            throw new DomainError('Status inválido.');
        }
        return $this->db->transaction(function () use ($convId, $u, $status) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'bot') {
                throw new DomainError('A conversa ainda está na triagem.');
            }
            $current = $conv['status'] === 'open' && $conv['stage'] ? $conv['stage'] : $conv['status'];
            if ($current === $status) {
                return $conv;
            }
            if ($conv['status'] === 'closed') {
                if ($status !== 'open') {
                    throw new DomainError('Reabra o atendimento antes de mudar o status.');
                }
                $other = $this->db->one(
                    "SELECT id, protocol FROM talk_conversations WHERE contact_id = ? AND channel_id = ? AND status <> 'closed' AND id <> ? LIMIT 1",
                    [$conv['contact_id'], $conv['channel_id'], $convId]
                );
                if ($other) {
                    throw new DomainError('Este contato já tem outro atendimento aberto (' . $other['protocol'] . ').');
                }
                $this->db->run(
                    "UPDATE talk_conversations SET status = 'open', stage = NULL, assigned_user_id = ?, closed_at = NULL, closed_by = NULL,
                            accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?",
                    [$u->userId, $convId]
                );
                $note = 'Atendimento reaberto.';
            } elseif ($status === 'pending') {
                $this->db->run(
                    "UPDATE talk_conversations SET status = 'pending', stage = NULL, assigned_user_id = NULL, transferred_by = ?, queued_at = NOW() WHERE id = ?",
                    [$u->userId, $convId]
                );
                $note = 'Status alterado para Na fila (voltou para a fila do setor).';
            } else {
                $this->db->run(
                    "UPDATE talk_conversations SET status = 'open', stage = ?, assigned_user_id = COALESCE(assigned_user_id, ?),
                            accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?",
                    [$status === 'open' ? null : $status, $u->userId, $convId]
                );
                $note = 'Status alterado para ' . self::STATUS_LABELS[$status] . '.';
            }
            // alguém mexeu no status: a Jack sai de cena (se estava no Geral) e o aviso de transferência some
            $this->db->run('UPDATE talk_conversations SET jack_pending = 0, transfer_pending = 0 WHERE id = ?', [$convId]);
            if ((int) $conv['jack_pending'] === 1) {
                $this->openTicketIfMissing($this->get($convId));
            }
            $noteId = $this->db->insert('talk_messages', [
                'conversation_id' => $convId, 'direction' => 'internal', 'author_user_id' => $u->userId,
                'type' => 'status', 'body' => $note, 'ack' => 'received',
            ]);
            $conv = $this->get($convId);
            $this->events->publish('message.created', $convId, $this->sector($conv), $u->userId, ['message_id' => $noteId, 'direction' => 'internal', 'type' => 'status', 'body' => $note]);
            $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            return $conv;
        });
    }

    /** Responde ao cliente. Se estava na fila, aceita automaticamente. */
    public function reply(int $convId, StaffMember $u, string $text, ?string $signature = null): int
    {
        $text = trim($text);
        if ($text === '') {
            throw new DomainError('Mensagem vazia.');
        }
        return $this->db->transaction(function () use ($convId, $u, $text, $signature) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed' || $conv['status'] === 'bot') {
                throw new DomainError('Conversa não está em atendimento.');
            }
            if ($conv['status'] === 'pending') {
                $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
                $conv = $this->get($convId);
                $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            }
            $body = $signature ? "*{$signature}:*\n{$text}" : $text;
            return $this->queueOutbound($conv, $body, $u->userId);
        });
    }

    /**
     * Envia um arquivo ao cliente (imagem, áudio, vídeo, PDF, documento). A legenda vai assinada
     * como as respostas; áudio não tem legenda no WhatsApp.
     */
    public function sendFile(int $convId, StaffMember $u, string $bytes, ?string $declaredMime, ?string $fileName, ?string $caption = null, ?string $signature = null): int
    {
        if ($this->media === null) {
            throw new DomainError('Envio de arquivos não configurado.', 500);
        }
        $caption = trim((string) $caption);
        if (mb_strlen($caption) > 1000) {
            throw new DomainError('Legenda: até 1000 caracteres.');
        }
        // confere o acesso antes de gravar qualquer coisa
        $this->authorize($u, $this->get($convId));
        $file = $this->media->save($bytes, $declaredMime, $fileName);
        return $this->db->transaction(function () use ($convId, $u, $file, $caption, $signature) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed' || $conv['status'] === 'bot') {
                throw new DomainError('Conversa não está em atendimento.');
            }
            if ($conv['status'] === 'pending') {
                $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
                $conv = $this->get($convId);
                $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            }
            $body = null;
            if ($file['type'] !== 'audio') {
                $body = $signature ? "*{$signature}:*" . ($caption !== '' ? "\n{$caption}" : '') : ($caption !== '' ? $caption : null);
            }
            return $this->queueOutbound($conv, (string) $body, $u->userId, $file['type'], 0, $file);
        });
    }

    /** Transfere para outro setor com nota interna (o cliente não vê a nota). */
    /**
     * Trocar atendente: para um setor (vai para a fila) ou direto para um atendente desse setor.
     * $keepHistory = false: o novo atendente só vê a conversa a partir da transferência
     * (supervisor e gerente do setor continuam vendo tudo).
     */
    public function transfer(int $convId, StaffMember $u, int $toSectorId, ?string $note = null, ?int $toUserId = null, bool $keepHistory = true): array
    {
        return $this->db->transaction(function () use ($convId, $u, $toSectorId, $note, $toUserId, $keepHistory) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed') {
                throw new DomainError('Conversa encerrada não pode ser transferida.');
            }
            $note = $note !== null ? trim($note) : null;
            if ($note !== null && mb_strlen($note) > 500) {
                throw new DomainError('O motivo da transferência pode ter até 500 caracteres.');
            }
            $to = $this->db->one('SELECT id, name FROM talk_sectors WHERE id = ? AND active = 1', [$toSectorId]);
            if (!$to) {
                throw new DomainError('Setor de destino inválido.');
            }
            $toUser = null;
            if ($toUserId !== null) {
                $toUser = $this->db->one(
                    "SELECT u.id, u.name FROM talk_users u JOIN talk_staff s ON s.user_id = u.id
                      WHERE u.id = ? AND u.active = 1 AND u.is_bot = 0 AND (s.sector_id = ? OR s.role = 'supervisor') LIMIT 1",
                    [$toUserId, $toSectorId]
                );
                if (!$toUser) {
                    throw new DomainError('Este atendente não faz parte do setor escolhido.');
                }
                if ((int) $toUser['id'] === (int) $conv['assigned_user_id'] && (int) $conv['sector_id'] === $toSectorId) {
                    throw new DomainError('A conversa já está com este atendente.');
                }
            }
            $from = $conv['sector_id'] ? $this->db->one('SELECT name FROM talk_sectors WHERE id = ?', [$conv['sector_id']]) : null;
            $fromSector = $this->sector($conv);

            $noteId = $this->db->insert('talk_messages', [
                'conversation_id' => $convId,
                'direction' => 'internal',
                'author_user_id' => $u->userId,
                'type' => 'transfer',
                'body' => sprintf(
                    'Transferido de %s para %s%s.%s',
                    $from['name'] ?? 'triagem',
                    $to['name'],
                    $toUser ? ' (' . $toUser['name'] . ')' : '',
                    $note ? "\nMotivo: " . $note : ''
                ),
                'ack' => 'received',
            ]);
            $this->db->run(
                'UPDATE talk_conversations SET sector_id = ?, status = ?, stage = NULL, jack_pending = 0, transfer_pending = ?, transferred_by = ?, assigned_user_id = ?, history_from_message_id = ?,
                        queued_at = IF(? = \'pending\', NOW(), queued_at) WHERE id = ?',
                [$toSectorId, $toUser ? 'open' : 'pending', ($toUser['id'] ?? null) === $u->userId ? 0 : 1, $u->userId, $toUser['id'] ?? null,
                 $keepHistory ? $conv['history_from_message_id'] : $noteId, $toUser ? 'open' : 'pending', $convId]
            );
            $this->jack?->settle($convId, $toSectorId);
            if ($conv['helpdesk_ticket_id']) {
                $this->safeHelpdesk(fn () => $this->helpdesk->transfer((int) $conv['helpdesk_ticket_id'], $to['name'], $u->userId, $note));
            } elseif ((int) $conv['jack_pending'] === 1) {
                $this->openTicketIfMissing($this->get($convId)); // tirou do Geral antes da Jack: chamado já no setor novo
            }
            $conv = $this->get($convId);
            $this->queueOutbound($conv, $this->texts->transferred($to['name']), null);
            // avisa o setor de origem (some da lista) e o de destino (aparece na fila)
            $this->events->publish('conversation.transferred', $convId, $fromSector, $u->userId, $this->summary($conv) + ['from_sector_id' => $fromSector]);
            $this->events->publish('conversation.updated', $convId, $toSectorId, $u->userId, $this->summary($conv));
            if (!$toUser && $this->autoAssign($conv)) {
                $conv = $this->get($convId);
            }
            return $conv;
        });
    }

    public function close(int $convId, StaffMember $u, ?string $resolution = null, bool $notifyCustomer = true): array
    {
        return $this->db->transaction(function () use ($convId, $u, $resolution, $notifyCustomer) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed') {
                return $conv;
            }
            if ($resolution) {
                $this->db->insert('talk_messages', [
                    'conversation_id' => $convId, 'direction' => 'internal', 'author_user_id' => $u->userId,
                    'type' => 'resolution', 'body' => trim($resolution), 'ack' => 'received',
                ]);
            }
            $this->db->run(
                "UPDATE talk_conversations SET status = 'closed', stage = NULL, closed_at = NOW(), closed_by = ?, unread = 0, transfer_pending = 0 WHERE id = ?",
                [$u->userId, $convId]
            );
            // chamado aberto pelo botão continua no SAC (a equipe de lá cuida); o aberto sozinho termina junto com a conversa
            if ($conv['helpdesk_ticket_id'] && $conv['helpdesk_opened_by'] === null) {
                $this->safeHelpdesk(fn () => $this->helpdesk->close((int) $conv['helpdesk_ticket_id'], $u->userId, $resolution));
            }
            $conv = $this->get($convId);
            if ($notifyCustomer) {
                $this->queueOutbound($conv, $this->texts->closed(self::ticketNumber($conv)), $u->userId);
                // avaliação: só quando alguém da equipe atendeu
                if ($this->askRating && $conv['accepted_at'] !== null && $conv['assigned_user_id'] !== null) {
                    $who = $this->db->one('SELECT name FROM talk_users WHERE id = ?', [$conv['assigned_user_id']]);
                    $first = $who ? trim(preg_replace('/\s*\(.*\)$/', '', explode(' ', trim($who['name']))[0])) : null;
                    $this->queueOutbound($conv, $this->texts->ratingAsk($first ?: null), $u->userId, 'rating_ask');
                    $this->db->run(
                        'INSERT IGNORE INTO talk_ratings (conversation_id, contact_id, sector_id, user_id) VALUES (?, ?, ?, ?)',
                        [$convId, $conv['contact_id'], $conv['sector_id'], $conv['assigned_user_id']]
                    );
                }
            }
            $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            return $conv;
        });
    }

    /**
     * Botão "Abrir chamado": abre na hora o chamado no SAC do ERP com o título e a descrição escritos pelo atendente,
     * para o setor escolhido (a área que vai cuidar lá), com o mesmo protocolo da conversa. Encerrar a conversa depois
     * não encerra o chamado. Diferente da abertura automática, aqui o erro aparece para a pessoa.
     */
    public function openTicketNow(int $convId, StaffMember $u, string $subject, string $description, ?int $sectorId = null): array
    {
        $subject = trim($subject);
        $description = trim($description);
        if ($subject === '' || mb_strlen($subject) > 200) {
            throw new DomainError('Informe o assunto do chamado (até 200 caracteres).');
        }
        if (mb_strlen($description) > 5000) {
            throw new DomainError('A descrição passou de 5.000 caracteres.');
        }
        $conv = $this->get($convId);
        $this->authorize($u, $conv);
        if ($conv['helpdesk_ticket_id']) {
            throw new DomainError('Esta conversa já tem o chamado ' . self::ticketNumber($conv) . '.');
        }
        if ($conv['status'] === 'bot') {
            throw new DomainError('Espere a conversa chegar à fila de um setor para abrir o chamado.');
        }
        if (mb_strlen($description) < 10) {
            throw new DomainError('Descreva o que está acontecendo (pelo menos 10 caracteres).');
        }
        $contact = $this->ticketContact($this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]));
        $target = $sectorId ?: ($conv['sector_id'] ? (int) $conv['sector_id'] : null);
        $sector = $target ? $this->db->one('SELECT name FROM talk_sectors WHERE id = ?', [$target]) : null;
        if ($sectorId && $sector === null) {
            throw new DomainError('Escolha para qual setor vai o chamado.');
        }
        try {
            // array_replace: a conversa já tem a coluna subject (vazia), e o título do atendente tem de valer
            $t = $this->helpdesk->open(array_replace($conv, ['subject' => $subject, 'ticket_message' => $description, 'transcript' => $this->transcript($convId)]), $contact, $sector['name'] ?? null);
        } catch (\Throwable $e) {
            error_log('[talk] abrir chamado: ' . $e->getMessage());
            throw new DomainError('O SAC do ERP não respondeu. Tente de novo em instantes.', 502);
        }
        if (!is_array($t) || !isset($t['id'])) {
            throw new DomainError(
                'O SAC do ERP ainda não está ligado ao Talk. O supervisor precisa preencher ERP_API_URL e ERP_API_TOKEN no .env do Talk '
                . '(token criado no ERP em Integrações, com o escopo tickets.write) e conferir com php bin/erp-check.php.',
                409
            );
        }
        $this->db->run(
            'UPDATE talk_conversations SET helpdesk_ticket_id = ?, helpdesk_protocol = ?, helpdesk_url = ?, helpdesk_opened_by = ?, subject = ? WHERE id = ?',
            [(int) $t['id'], $t['protocol'] ?? null, $t['url'] ?? null, $u->userId, $subject, $convId]
        );
        $conv = $this->get($convId);
        $this->addNote($convId, $u, 'Chamado aberto no SAC' . ($sector ? ' para ' . $sector['name'] : '') . ', protocolo ' . self::ticketNumber($conv) . ': ' . $subject);
        $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
        return $conv;
    }

    public function addNote(int $convId, StaffMember $u, string $note): int
    {
        $conv = $this->get($convId);
        $this->authorize($u, $conv);
        $id = $this->db->insert('talk_messages', [
            'conversation_id' => $convId, 'direction' => 'internal', 'author_user_id' => $u->userId,
            'type' => 'note', 'body' => trim($note), 'ack' => 'received',
        ]);
        $this->events->publish('message.created', $convId, $this->sector($conv), $u->userId, ['message_id' => $id, 'direction' => 'internal', 'type' => 'note', 'body' => trim($note)]);
        return $id;
    }

    public function markRead(int $convId, StaffMember $u): void
    {
        $conv = $this->get($convId);
        if (!$this->policy->canView($u, $conv)) {
            throw new DomainError('Sem acesso a esta conversa.', 403);
        }
        // quem recebeu a transferência abriu a conversa: o aviso do sino sai
        $this->db->run(
            'UPDATE talk_conversations SET unread = 0, transfer_pending = IF(assigned_user_id = ?, 0, transfer_pending) WHERE id = ?',
            [$u->userId, $convId]
        );
    }

    // ------------------------------------------------------------------ apoio

    /** O ERP fora do ar não pode travar o atendimento: registra o erro e segue. */
    /** Abre o chamado no SAC e guarda o id e o número dele (o número que o cliente vê). */
    private function openTicket(array $conv, array $contact, ?string $sectorName): void
    {
        if (!$this->autoTicket) {
            return; // só pelo botão Abrir chamado
        }
        $contact = $this->ticketContact($contact);
        $t = $this->safeHelpdesk(fn () => $this->helpdesk->open($conv + ['transcript' => $this->transcript((int) $conv['id'])], $contact, $sectorName));
        if (is_array($t) && isset($t['id'])) {
            $this->db->run(
                'UPDATE talk_conversations SET helpdesk_ticket_id = ?, helpdesk_protocol = ?, helpdesk_url = ? WHERE id = ?',
                [(int) $t['id'], $t['protocol'] ?? null, $t['url'] ?? null, $conv['id']]
            );
        }
    }

    /** O que o cliente e a equipe já disseram (sem notas internas), para o chamado no SAC. Até 40 mensagens. */
    private function transcript(int $convId): string
    {
        $rows = array_reverse($this->db->all(
            "SELECT m.direction, m.type, m.body, m.created_at, u.name AS author, u.is_bot
               FROM talk_messages m LEFT JOIN talk_users u ON u.id = m.author_user_id
              WHERE m.conversation_id = ? AND m.direction IN ('in','out') ORDER BY m.id DESC LIMIT 40",
            [$convId]
        ));
        $kinds = ['image' => '[foto]', 'audio' => '[áudio]', 'video' => '[vídeo]', 'document' => '[documento]'];
        return implode("\n", array_map(static function (array $m) use ($kinds): string {
            $who = $m['direction'] === 'in' ? 'Cliente' : ((int) ($m['is_bot'] ?? 0) === 1 ? 'Jack' : ($m['author'] ? trim((string) preg_replace('/\s*\(.*\)$/', '', (string) $m['author'])) : 'Connect'));
            $body = trim((string) $m['body']);
            $body = $body !== '' ? $body : ($kinds[$m['type']] ?? '[arquivo]');
            return '[' . date('d/m H:i', strtotime((string) $m['created_at'])) . "] $who: " . mb_substr(str_replace("\n", ' ', $body), 0, 500);
        }, $rows));
    }

    private function inboundText(int $convId): string
    {
        return implode("\n", array_column($this->db->all(
            "SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'in' AND type IN ('text','image','video') AND body IS NOT NULL ORDER BY id",
            [$convId]
        ), 'body'));
    }

    /** Contato com o que veio do cadastro do ERP (condomínio, unidade) em "details", para o chamado. */
    private function ticketContact(array $contact): array
    {
        if (!is_array($contact['details'] ?? null)) {
            $contact['details'] = json_decode((string) ($contact['erp_snapshot'] ?? ''), true) ?: [];
        }
        return $contact;
    }

    /** Número do chamado para o cliente: o do SAC, quando houver; senão, o protocolo do Talk. */
    public static function ticketNumber(array $conv): string
    {
        return self::plainProtocol((string) (($conv['helpdesk_protocol'] ?? null) ?: $conv['protocol']));
    }

    private function safeHelpdesk(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[talk] helpdesk do ERP falhou: ' . $e->getMessage());
            return null;
        }
    }

    /** Grava a mensagem de saída e coloca na fila de envio. */
    /** @param array{path:string, mime:string, name:string, size:int}|null $file */
    public function queueOutbound(array $conv, string $text, ?int $userId, string $type = 'text', int $typingSeconds = 0, ?array $file = null): int
    {
        $msgId = $this->db->insert('talk_messages', [
            'conversation_id' => $conv['id'],
            'direction' => 'out',
            'author_user_id' => $userId,
            'type' => $type,
            'body' => $file !== null && $text === '' ? null : $text,
            'media_path' => $file['path'] ?? null,
            'media_mime' => $file['mime'] ?? null,
            'media_name' => $file['name'] ?? null,
            'media_size' => $file['size'] ?? null,
            'ack' => 'pending',
        ]);
        $phone = $this->db->one('SELECT phone FROM talk_contacts WHERE id = ?', [$conv['contact_id']])['phone'];
        $this->db->insert('talk_outbox', [
            'message_id' => $msgId,
            'channel_id' => $conv['channel_id'],
            'to_phone' => $phone,
            'typing_seconds' => $typingSeconds,
        ]);
        $this->db->run('UPDATE talk_conversations SET last_message_at = NOW() WHERE id = ?', [$conv['id']]);
        $this->events->publish('message.created', (int) $conv['id'], $this->sector($conv), $userId, [
            'message_id' => $msgId, 'direction' => 'out', 'type' => $type, 'body' => $text, 'ack' => 'pending',
        ]);
        return $msgId;
    }

    public function get(int $id): array
    {
        $c = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$id]);
        if (!$c) {
            throw new DomainError('Conversa não encontrada.', 404);
        }
        return $c;
    }

    private function lock(int $id): array
    {
        $c = $this->db->one('SELECT * FROM talk_conversations WHERE id = ? FOR UPDATE', [$id]);
        if (!$c) {
            throw new DomainError('Conversa não encontrada.', 404);
        }
        return $c;
    }

    private function authorize(StaffMember $u, array $conv): void
    {
        if (!$this->policy->canAct($u, $conv)) {
            throw new DomainError('Você não tem acesso a esta conversa.', 403);
        }
    }

    private function sector(array $conv): ?int
    {
        return $conv['sector_id'] !== null ? (int) $conv['sector_id'] : null;
    }

    private function summary(array $conv): array
    {
        return [
            'id' => (int) $conv['id'],
            'protocol' => $conv['protocol'],
            'ticket' => self::ticketNumber($conv),
            'ticket_url' => $conv['helpdesk_url'] ?? null,
            'status' => $conv['status'],
            'stage' => $conv['stage'] ?? null,
            'sector_id' => $this->sector($conv),
            'assigned_user_id' => $conv['assigned_user_id'] !== null ? (int) $conv['assigned_user_id'] : null,
            'unread' => (int) $conv['unread'],
            'last_message_at' => $conv['last_message_at'],
        ];
    }
}
