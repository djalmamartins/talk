<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Support\Db;

/**
 * Leitura e gravação básicas de uma conversa, compartilhadas pelas partes do atendimento
 * (entrada, triagem, ações do atendente): buscar, travar, resumo para o tempo real e
 * colocar mensagem de saída na fila de envio.
 */
final class ConversationStore
{
    public function __construct(private Db $db, private EventBus $events)
    {
    }

    public function get(int $id): array
    {
        $c = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$id]);
        if (!$c) {
            throw new DomainError('Conversa não encontrada.', 404);
        }
        return $c;
    }

    /** Busca e trava a linha até o fim da transação (dois atendentes não aceitam a mesma conversa). */
    public function lock(int $id): array
    {
        $c = $this->db->one('SELECT * FROM talk_conversations WHERE id = ? FOR UPDATE', [$id]);
        if (!$c) {
            throw new DomainError('Conversa não encontrada.', 404);
        }
        return $c;
    }

    public function sectorOf(array $conv): ?int
    {
        return $conv['sector_id'] !== null ? (int) $conv['sector_id'] : null;
    }

    /** O que vai no evento de tempo real para a lista de conversas se atualizar. */
    public function summary(array $conv): array
    {
        return [
            'id' => (int) $conv['id'],
            'protocol' => $conv['protocol'],
            'ticket' => TicketBridge::number($conv),
            'status' => $conv['status'],
            'stage' => $conv['stage'] ?? null,
            'sector_id' => $this->sectorOf($conv),
            'assigned_user_id' => $conv['assigned_user_id'] !== null ? (int) $conv['assigned_user_id'] : null,
            'unread' => (int) $conv['unread'],
            'last_message_at' => $conv['last_message_at'],
        ];
    }

    /** Textos que o cliente mandou, em ordem (legenda de foto/vídeo também conta). @return list<string> */
    public function inboundTexts(int $convId): array
    {
        return array_column($this->db->all(
            "SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'in' AND type IN ('text','image','video') AND body IS NOT NULL ORDER BY id",
            [$convId]
        ), 'body');
    }

    /**
     * Grava a mensagem de saída e coloca na fila de envio.
     *
     * @param array{path:string, mime:string, name:string, size:int}|null $file
     */
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
        $this->events->publish('message.created', (int) $conv['id'], $this->sectorOf($conv), $userId, [
            'message_id' => $msgId, 'direction' => 'out', 'type' => $type, 'body' => $text, 'ack' => 'pending',
        ]);
        return $msgId;
    }
}
