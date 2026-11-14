<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Domain\StatusUpdate;
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

            if ($conv['status'] === 'bot') {
                $this->runMenu($conv, $in, $isNew, $contact);
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
            'INSERT INTO talk_protocol_seq (day, last_value) VALUES (?, 1) ON DUPLICATE KEY UPDATE last_value = last_value + 1',
            [$day]
        );
        $n = (int) $this->db->one('SELECT last_value FROM talk_protocol_seq WHERE day = ?', [$day])['last_value'];
        return date('Ymd') . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ menu / roteamento

    private function runMenu(array $conv, InboundMessage $in, bool $isNew, array $contact): void
    {
        $sectors = $this->db->all('SELECT id, name, menu_key, keywords FROM talk_sectors WHERE active = 1 ORDER BY menu_key');
        $answer = trim(mb_strtolower((string) $in->body));

        $chosen = null;
        foreach ($sectors as $s) {
            if ($answer !== '' && $answer === mb_strtolower($s['menu_key'])) {
                $chosen = $s;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = $this->guessSector($sectors, $answer);
        }

        if ($chosen !== null) {
            $this->route($conv, $chosen, $contact);
            return;
        }
        $first = $contact['name'] ? explode(' ', (string) $contact['name'])[0] : null;
        $text = $isNew
            ? $this->texts->welcome($first, $conv['protocol'], $sectors)
            : $this->texts->invalidOption($sectors);
        $this->queueOutbound($conv, $text, null);
    }

    /** Sugere o setor pelas palavras-chave, só se apenas um setor bater. */
    private function guessSector(array $sectors, string $text): ?array
    {
        if ($text === '') {
            return null;
        }
        $hits = [];
        foreach ($sectors as $s) {
            foreach (array_filter(array_map('trim', explode(',', (string) $s['keywords']))) as $kw) {
                if (preg_match('/\b' . preg_quote(mb_strtolower($kw), '/') . '/u', $text)) {
                    $hits[$s['id']] = $s;
                    break;
                }
            }
        }
        return count($hits) === 1 ? array_values($hits)[0] : null;
    }

    private function route(array $conv, array $sector, array $contact): void
    {
        $this->db->run("UPDATE talk_conversations SET sector_id = ?, status = 'pending' WHERE id = ?", [$sector['id'], $conv['id']]);
        $conv = $this->get((int) $conv['id']);
        $ticketId = $this->safeHelpdesk(fn () => $this->helpdesk->open($conv, $contact, $sector['name']));
        if ($ticketId !== null) {
            $this->db->run('UPDATE talk_conversations SET helpdesk_ticket_id = ? WHERE id = ?', [$ticketId, $conv['id']]);
        }
        $this->queueOutbound($conv, $this->texts->routed($sector['name'], $conv['protocol']), null);
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
            $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ?, unread = 0 WHERE id = ?", [$u->userId, $convId]);
            $conv = $this->get($convId);
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
                $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ? WHERE id = ?", [$u->userId, $convId]);
                $conv = $this->get($convId);
                $this->events->publish('conversation.updated', $convId, $this->sector($conv), $u->userId, $this->summary($conv));
            }
            $body = $signature ? "*{$signature}:*\n{$text}" : $text;
            return $this->queueOutbound($conv, $body, $u->userId);
        });
    }

    /** Transfere para outro setor com nota interna (o cliente não vê a nota). */
    public function transfer(int $convId, StaffMember $u, int $toSectorId, ?string $note = null): array
    {
        return $this->db->transaction(function () use ($convId, $u, $toSectorId, $note) {
            $conv = $this->lock($convId);
            $this->authorize($u, $conv);
            if ($conv['status'] === 'closed') {
                throw new DomainError('Conversa encerrada não pode ser transferida.');
            }
            $to = $this->db->one('SELECT id, name FROM talk_sectors WHERE id = ? AND active = 1', [$toSectorId]);
            if (!$to) {
                throw new DomainError('Setor de destino inválido.');
            }
            $from = $conv['sector_id'] ? $this->db->one('SELECT name FROM talk_sectors WHERE id = ?', [$conv['sector_id']]) : null;
            $fromSector = $this->sector($conv);

            $this->db->insert('talk_messages', [
                'conversation_id' => $convId,
                'direction' => 'internal',
                'author_user_id' => $u->userId,
                'type' => 'transfer',
                'body' => sprintf('Transferido de %s para %s.%s', $from['name'] ?? 'menu', $to['name'], $note ? "\nNota: " . trim($note) : ''),
                'ack' => 'received',
            ]);
            $this->db->run(
                "UPDATE talk_conversations SET sector_id = ?, status = 'pending', assigned_user_id = NULL WHERE id = ?",
                [$toSectorId, $convId]
            );
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
    public function queueOutbound(array $conv, string $text, ?int $userId): int
    {
        $msgId = $this->db->insert('talk_messages', [
            'conversation_id' => $conv['id'],
            'direction' => 'out',
            'author_user_id' => $userId,
            'type' => 'text',
            'body' => $text,
            'ack' => 'pending',
        ]);
        $phone = $this->db->one('SELECT phone FROM talk_contacts WHERE id = ?', [$conv['contact_id']])['phone'];
        $this->db->insert('talk_outbox', [
            'message_id' => $msgId,
            'channel_id' => $conv['channel_id'],
            'to_phone' => $phone,
        ]);
        $this->db->run('UPDATE talk_conversations SET last_message_at = NOW() WHERE id = ?', [$conv['id']]);
        $this->events->publish('message.created', (int) $conv['id'], $this->sector($conv), $userId, [
            'message_id' => $msgId, 'direction' => 'out', 'type' => 'text', 'body' => $text, 'ack' => 'pending',
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
