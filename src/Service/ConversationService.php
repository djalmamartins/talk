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
        Helpdesk $helpdesk,
        private Texts $texts = new Texts(),
        private ?Jack $jack = null,
        private ?MediaStore $media = null,
        private bool $askRating = true,
        ?BusinessHours $hours = null,
        ?TicketBridge $tickets = null,
    ) {
        $this->tickets = $tickets ?? new TicketBridge($db, $helpdesk);
        $this->store = new ConversationStore($db, $events);
        $this->triage = new Triage($db, $events, $this->store, $this->tickets, $jack, $hours);
    }

    private TicketBridge $tickets;
    private ConversationStore $store;
    private Triage $triage;

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
                $this->events->publish('conversation.created', (int) $conv['id'], $this->store->sectorOf($conv), null, $this->store->summary($conv));
            }
            $this->events->publish('message.created', (int) $conv['id'], $this->store->sectorOf($conv), null, [
                'message_id' => $msgId, 'direction' => 'in', 'type' => $in->type, 'body' => $in->body,
            ]);

            // Sem menu: a Jack lê em silêncio e encaminha depois que a pessoa termina de escrever (triage()).
            // Ela só fala quando perguntam se é robô, ou quando o assunto sai do que ela sabe.
            if ($this->jack !== null && $in->type === 'text' && trim((string) $in->body) !== '') {
                $talk = $this->jack->converse($conv, (string) $in->body);
                if ($talk !== null && $talk['kind'] === 'identity') {
                    $this->triage->jackSay($conv, $this->texts->jackIdentity(), 'jack_identity');
                    return (int) $conv['id'];
                }
                if ($talk !== null) {
                    $this->triage->jackSay($conv, $this->texts->jackOutOfScope($talk['sector']['name']), 'jack_scope');
                    if ($conv['status'] === 'bot') {
                        $this->jack->record($conv, $talk['sector'], 'scope', null);
                        // o texto de "fora do escopo" já avisa a transferência
                        $this->triage->route($conv, $talk['sector'], $contact);
                        $this->triage->sayOffHours($conv, $talk['sector'], $contact);
                    }
                    return (int) $conv['id'];
                }
            }
            if ($conv['status'] === 'bot' && ($this->jack === null || $this->jack->mode() === 'off')) {
                $fallback = $this->triage->fallbackSector();
                if ($fallback !== null) {
                    $this->triage->route($conv, $fallback, $contact);
                    $this->triage->sayOffHours($conv, $fallback, $contact);
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
        $this->events->publish('message.created', $convId, $this->store->sectorOf($conv), null, [
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
     * Protocolo do atendimento: SAC + AAMMDD + sequência do dia com 5 dígitos (ex.: SAC26111600004),
     * sem colisão entre processos. "SAC" mostra que o número veio do Talk. Os números antigos ficam em
     * legacy_protocol (migração 018) e continuam achando a conversa na busca.
     */
    public function nextProtocol(): string
    {
        $day = date('Y-m-d');
        $this->db->run(
            'INSERT INTO talk_protocol_seq (day, `last_value`) VALUES (?, 1) ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1',
            [$day]
        );
        $n = (int) $this->db->one('SELECT `last_value` FROM talk_protocol_seq WHERE day = ?', [$day])['last_value'];
        return 'SAC' . date('ymd') . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ triagem (Jack): ver Triage

    /** Encaminha as conversas que estão com a Jack. Roda no worker. Retorna quantas foram para a fila. */
    public function triage(int $clockShift = 0): int
    {
        return $this->triage->run($clockShift);
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
        $this->tickets->openAuto($conv, $contact, $sector['name']);
        $this->db->insert('talk_messages', [
            'conversation_id' => $id, 'direction' => 'internal', 'author_user_id' => $userId, 'type' => 'note', 'body' => $note, 'ack' => 'received',
        ]);
        $conv = $this->get($id);
        $this->events->publish('conversation.created', $id, $sectorId, $userId, $this->store->summary($conv));
        return $conv;
    }

    // ------------------------------------------------------------------ ações do atendente

    public function accept(int $convId, StaffMember $u): array
    {
        return $this->db->transaction(function () use ($convId, $u) {
            $conv = $this->store->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] !== 'pending') {
                throw new DomainError('Esta conversa já foi aceita por outra pessoa.');
            }
            $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, unread = 0, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
            $conv = $this->get($convId);
            $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv));
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
            $conv = $this->store->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed') {
                throw new DomainError('Conversa encerrada.');
            }
            $this->db->run('UPDATE talk_conversations SET priority = ? WHERE id = ?', [$priority, $convId]);
            $conv = $this->get($convId);
            $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv) + ['priority' => $priority]);
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
            $conv = $this->store->lock($convId);
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
                    "UPDATE talk_conversations SET status = 'pending', stage = NULL, assigned_user_id = NULL, queued_at = NOW() WHERE id = ?",
                    [$convId]
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
            $noteId = $this->db->insert('talk_messages', [
                'conversation_id' => $convId, 'direction' => 'internal', 'author_user_id' => $u->userId,
                'type' => 'status', 'body' => $note, 'ack' => 'received',
            ]);
            $conv = $this->get($convId);
            $this->events->publish('message.created', $convId, $this->store->sectorOf($conv), $u->userId, ['message_id' => $noteId, 'direction' => 'internal', 'type' => 'status', 'body' => $note]);
            $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv));
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
            $conv = $this->store->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed' || $conv['status'] === 'bot') {
                throw new DomainError('Conversa não está em atendimento.');
            }
            if ($conv['status'] === 'pending') {
                $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
                $conv = $this->get($convId);
                $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv));
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
            $conv = $this->store->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed' || $conv['status'] === 'bot') {
                throw new DomainError('Conversa não está em atendimento.');
            }
            if ($conv['status'] === 'pending') {
                $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
                $conv = $this->get($convId);
                $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv));
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
            $conv = $this->store->lock($convId);
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
                      WHERE u.id = ? AND u.active = 1 AND (s.sector_id = ? OR s.role = 'supervisor') LIMIT 1",
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
            $fromSector = $this->store->sectorOf($conv);

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
                'UPDATE talk_conversations SET sector_id = ?, status = ?, stage = NULL, assigned_user_id = ?, history_from_message_id = ?,
                        queued_at = IF(? = \'pending\', NOW(), queued_at) WHERE id = ?',
                [$toSectorId, $toUser ? 'open' : 'pending', $toUser['id'] ?? null, $keepHistory ? $conv['history_from_message_id'] : $noteId, $toUser ? 'open' : 'pending', $convId]
            );
            $this->jack?->settle($convId, $toSectorId);
            $this->tickets->transfer($conv, $to['name'], $u->userId, $note);
            $conv = $this->get($convId);
            $this->queueOutbound($conv, $this->texts->transferred($to['name']), null);
            // avisa o setor de origem (some da lista) e o de destino (aparece na fila)
            $this->events->publish('conversation.transferred', $convId, $fromSector, $u->userId, $this->store->summary($conv) + ['from_sector_id' => $fromSector]);
            $this->events->publish('conversation.updated', $convId, $toSectorId, $u->userId, $this->store->summary($conv));
            return $conv;
        });
    }

    public function close(int $convId, StaffMember $u, ?string $resolution = null, bool $notifyCustomer = true): array
    {
        return $this->db->transaction(function () use ($convId, $u, $resolution, $notifyCustomer) {
            $conv = $this->store->lock($convId);
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
                "UPDATE talk_conversations SET status = 'closed', stage = NULL, closed_at = NOW(), closed_by = ?, unread = 0 WHERE id = ?",
                [$u->userId, $convId]
            );
            $this->tickets->close($conv, $u->userId, $resolution);
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
            $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv));
            return $conv;
        });
    }

    /**
     * Botão "Abrir chamado": abre na hora o chamado no Help Desk do ERP com o assunto e a descrição
     * escritos pelo atendente. Diferente da abertura automática, aqui o erro aparece para a pessoa.
     */
    public function openTicketNow(int $convId, StaffMember $u, string $subject, string $description): array
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
        $this->tickets->openNow($conv, $subject, $description);
        $conv = $this->get($convId);
        $this->addNote($convId, $u, 'Chamado ' . self::ticketNumber($conv) . ' aberto no Help Desk: ' . $subject);
        $this->events->publish('conversation.updated', $convId, $this->store->sectorOf($conv), $u->userId, $this->store->summary($conv));
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
        $this->events->publish('message.created', $convId, $this->store->sectorOf($conv), $u->userId, ['message_id' => $id, 'direction' => 'internal', 'type' => 'note', 'body' => trim($note)]);
        return $id;
    }

    public function markRead(int $convId, StaffMember $u): void
    {
        $conv = $this->get($convId);
        if (!$this->policy->canView($u, $conv)) {
            throw new DomainError('Sem acesso a esta conversa.', 403);
        }
        $this->db->run('UPDATE talk_conversations SET unread = 0 WHERE id = ?', [$convId]);
    }

    // ------------------------------------------------------------------ apoio

    /** Número do chamado para o cliente: o do Help Desk, quando houver; senão, o protocolo do Talk. */
    public static function ticketNumber(array $conv): string
    {
        return TicketBridge::number($conv);
    }

    /** Grava a mensagem de saída e coloca na fila de envio (usado também pelos agendamentos). */
    /** @param array{path:string, mime:string, name:string, size:int}|null $file */
    public function queueOutbound(array $conv, string $text, ?int $userId, string $type = 'text', int $typingSeconds = 0, ?array $file = null): int
    {
        return $this->store->queueOutbound($conv, $text, $userId, $type, $typingSeconds, $file);
    }

    public function get(int $id): array
    {
        return $this->store->get($id);
    }

    private function authorize(StaffMember $u, array $conv): void
    {
        if (!$this->policy->canAct($u, $conv)) {
            throw new DomainError('Você não tem acesso a esta conversa.', 403);
        }
    }
}
