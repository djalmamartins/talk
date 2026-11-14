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
    ) {
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
                'UPDATE talk_conversations SET unread = unread + 1, last_message_at = NOW() WHERE id = ?',
                [$conv['id']]
            );
            $conv = $this->get((int) $conv['id']);

            if ($isNew) {
                $this->events->publish('conversation.created', (int) $conv['id'], $this->sector($conv), null, $this->summary($conv));
            }
            $this->events->publish('message.created', (int) $conv['id'], $this->sector($conv), null, [
                'message_id' => $msgId, 'direction' => 'in', 'type' => $in->type, 'body' => $in->body,
            ]);

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
                    if ($conv['status'] === 'bot') {
                        $this->jack->record($conv, $talk['sector'], 'scope', null);
                        // o texto de "fora do escopo" já avisa a transferência
                        $this->route($conv, $talk['sector'], $contact);
                    }
                    return (int) $conv['id'];
                }
            }
            if ($conv['status'] === 'bot' && ($this->jack === null || $this->jack->mode() === 'off')) {
                $fallback = $this->fallbackSector();
                if ($fallback !== null) {
                    $this->route($conv, $fallback, $contact);
                }
            }
            return (int) $conv['id'];
        });
    }

    public function handleStatus(StatusUpdate $s): void
    {
        $msg = $this->db->one('SELECT m.id, m.conversation_id, c.sector_id FROM talk_messages m JOIN talk_conversations c ON c.id = m.conversation_id WHERE m.provider_message_id = ?', [$s->providerMessageId]);
        if (!$msg) {
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

    /** Protocolo AAAAMMDD-NNNNN, sequencial por dia e sem colisão entre processos. */
    public function nextProtocol(): string
    {
        $day = date('Y-m-d');
        $this->db->run(
            'INSERT INTO talk_protocol_seq (day, `last_value`) VALUES (?, 1) ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1',
            [$day]
        );
        $n = (int) $this->db->one('SELECT `last_value` FROM talk_protocol_seq WHERE day = ?', [$day])['last_value'];
        return date('Ymd') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ triagem (Jack)

    /**
     * Encaminha as conversas que estão na triagem (status "bot") quando a pessoa parou de escrever.
     * Roda no worker (e no fim de cada requisição no laboratório). $clockShift só para testes.
     * @return int quantas conversas foram encaminhadas
     */
    public function triage(int $clockShift = 0): int
    {
        if ($this->jack === null) {
            return 0;
        }
        $rows = $this->db->all(
            "SELECT c.id,
                    TIMESTAMPDIFF(SECOND, MAX(m.created_at), NOW() + INTERVAL ? SECOND) AS quiet,
                    TIMESTAMPDIFF(SECOND, c.opened_at, NOW() + INTERVAL ? SECOND) AS age
               FROM talk_conversations c
               JOIN talk_messages m ON m.conversation_id = c.id AND m.direction = 'in'
              WHERE c.status = 'bot'
              GROUP BY c.id, c.opened_at",
            [$clockShift, $clockShift]
        );
        $routed = 0;
        foreach ($rows as $r) {
            if (!$this->jack->isDue((int) $r['quiet'], (int) $r['age'])) {
                continue;
            }
            $routed += (int) $this->db->transaction(function () use ($r): bool {
                $conv = $this->lock((int) $r['id']);
                if ($conv['status'] !== 'bot') {
                    return false; // outro processo já encaminhou
                }
                $texts = array_column($this->db->all(
                    "SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'in' AND type IN ('text','image','video') AND body IS NOT NULL ORDER BY id", // legenda de foto/vídeo também conta
                    [$conv['id']]
                ), 'body');
                $d = $this->jack->decide($conv, implode("\n", $texts), (int) $r['quiet'], (int) $r['age']);
                if ($d === null) {
                    return false; // já perguntou e a pessoa ainda não disse o assunto: espera
                }
                $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                $first = $contact['name'] ? explode(' ', trim((string) $contact['name']))[0] : null;
                if ($d['action'] === 'ask') {
                    $this->jackSay($conv, $this->texts->jackAsk($first), 'jack_ask');
                    return false;
                }
                $this->route($conv, $d['sector'], $contact);
                $this->jackSay($this->get((int) $conv['id']), $this->texts->jackRouted($first, $conv['protocol'], $d['sector']['name']), 'jack_routed');
                return true;
            });
        }
        return $routed;
    }

    /** Mensagem da Jaqueline: entra na fila com "digitando…" antes, como uma pessoa. */
    private function jackSay(array $conv, string $text, string $type): int
    {
        return $this->queueOutbound($conv, $text, null, $type, $this->jack?->typingSeconds($text) ?? 0);
    }

    private function fallbackSector(): ?array
    {
        return $this->jack?->fallbackSector()
            ?? $this->db->one("SELECT id, name FROM talk_sectors WHERE active = 1 ORDER BY menu_key LIMIT 1");
    }

    /** Coloca a conversa na fila do setor e abre o chamado. Não manda nada para o cliente. */
    private function route(array $conv, array $sector, array $contact): void
    {
        $this->db->run("UPDATE talk_conversations SET sector_id = ?, status = 'pending', queued_at = NOW() WHERE id = ?", [$sector['id'], $conv['id']]);
        $conv = $this->get((int) $conv['id']);
        $ticketId = $this->safeHelpdesk(fn () => $this->helpdesk->open($conv, $contact, $sector['name']));
        if ($ticketId !== null) {
            $this->db->run('UPDATE talk_conversations SET helpdesk_ticket_id = ? WHERE id = ?', [$ticketId, $conv['id']]);
        }
        $this->jack?->settle((int) $conv['id'], (int) $sector['id']);
        $this->events->publish('conversation.updated', (int) $conv['id'], (int) $sector['id'], null, $this->summary($this->get((int) $conv['id'])));
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
            $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, unread = 0, accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?", [$u->userId, $convId]);
            $conv = $this->get($convId);
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
                'UPDATE talk_conversations SET sector_id = ?, status = ?, assigned_user_id = ?, history_from_message_id = ?,
                        queued_at = IF(? = \'pending\', NOW(), queued_at) WHERE id = ?',
                [$toSectorId, $toUser ? 'open' : 'pending', $toUser['id'] ?? null, $keepHistory ? $conv['history_from_message_id'] : $noteId, $toUser ? 'open' : 'pending', $convId]
            );
            $this->jack?->settle($convId, $toSectorId);
            if ($conv['helpdesk_ticket_id']) {
                $this->safeHelpdesk(fn () => $this->helpdesk->transfer((int) $conv['helpdesk_ticket_id'], $to['name'], $u->userId, $note));
            }
            $conv = $this->get($convId);
            $this->queueOutbound($conv, $this->texts->transferred($to['name']), null);
            // avisa o setor de origem (some da lista) e o de destino (aparece na fila)
            $this->events->publish('conversation.transferred', $convId, $fromSector, $u->userId, $this->summary($conv) + ['from_sector_id' => $fromSector]);
            $this->events->publish('conversation.updated', $convId, $toSectorId, $u->userId, $this->summary($conv));
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
                "UPDATE talk_conversations SET status = 'closed', closed_at = NOW(), closed_by = ?, unread = 0 WHERE id = ?",
                [$u->userId, $convId]
            );
            if ($conv['helpdesk_ticket_id']) {
                $this->safeHelpdesk(fn () => $this->helpdesk->close((int) $conv['helpdesk_ticket_id'], $u->userId, $resolution));
            }
            $conv = $this->get($convId);
            if ($notifyCustomer) {
                $this->queueOutbound($conv, $this->texts->closed($conv['protocol']), $u->userId);
            }
            $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            return $conv;
        });
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
        $this->db->run('UPDATE talk_conversations SET unread = 0 WHERE id = ?', [$convId]);
    }

    // ------------------------------------------------------------------ apoio

    /** O ERP fora do ar não pode travar o atendimento: registra o erro e segue. */
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
            'status' => $conv['status'],
            'sector_id' => $this->sector($conv),
            'assigned_user_id' => $conv['assigned_user_id'] !== null ? (int) $conv['assigned_user_id'] : null,
            'unread' => (int) $conv['unread'],
            'last_message_at' => $conv['last_message_at'],
        ];
    }
}
