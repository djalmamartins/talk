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
        ContactDirectory $directory,
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
        $this->inbound = new InboundService($db, $events, $this->store, $this->triage, $directory, $texts, $jack);
    }

    private TicketBridge $tickets;
    private ConversationStore $store;
    private Triage $triage;
    private InboundService $inbound;

    // ------------------------------------------------------------------ entrada: ver InboundService

    /** Processa o que chegou pelo webhook. Retorna o id da conversa (ou null se já processado). */
    public function handleInbound(int $channelId, InboundMessage $in): ?int
    {
        return $this->inbound->message($channelId, $in);
    }

    public function handleStatus(StatusUpdate $s): void
    {
        $this->inbound->status($s);
    }

    /** "5", "4 - foi rápido", "⭐⭐⭐⭐", "ótimo"... → [nota, comentário]. Outra coisa → null. */
    public static function parseRating(string $text): ?array
    {
        return InboundService::parseRating($text);
    }

    public function nextProtocol(): string
    {
        return $this->store->nextProtocol();
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
            'protocol' => $this->store->nextProtocol(),
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
