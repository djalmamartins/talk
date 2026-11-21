<?php
declare(strict_types=1);

namespace ConnectTalk\Lab;

use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Support\Db;
use ConnectTalk\Support\Phone;

/** Simulador do laboratório: faz o papel do cliente no WhatsApp. Só existe com APP_ENV=lab. */
final class Simulator
{
    public function __construct(private Db $db, private ConversationService $conversations)
    {
    }

    /**
     * Mensagem chegando de um cliente simulado.
     *
     * @param array{path:string,mime:string,name:string,size:int,type:string}|null $file já gravado no MediaStore
     */
    public function inbound(int $channelId, string $phone, string $text, ?string $name, ?array $file): ?int
    {
        // número de destino: o simulado escolhido (ex.: o do plantão da Manutenção) ou o primeiro simulado
        $channel = (int) ($this->db->one("SELECT id FROM talk_channels WHERE kind = 'lab' AND id = ?", [$channelId])
            ?? $this->db->one("SELECT id FROM talk_channels WHERE kind = 'lab' ORDER BY is_default DESC, id LIMIT 1"))['id'];
        $pid = 'SIM-' . bin2hex(random_bytes(8));
        $type = $file['type'] ?? 'text';
        $id = $this->conversations->handleInbound($channel, new InboundMessage(
            $pid, $phone, $type,
            $type === 'document' ? $file['name'] : ($text !== '' ? $text : null), $name
        ));
        if ($file !== null) { // no laboratório o arquivo já está aqui: grava direto, sem "baixar do WhatsApp"
            $this->db->run('UPDATE talk_messages SET media_path = ?, media_mime = ?, media_name = ?, media_size = ? WHERE provider_message_id = ?',
                [$file['path'], $file['mime'], $file['name'], $file['size'], $pid]);
        }
        return $id;
    }

    /** O que o cliente simulado vê no celular dele. */
    public function customerView(string $phone): array
    {
        $phone = Phone::normalize(preg_replace('/\D+/', '', $phone));
        $rows = $this->db->all(
            "SELECT m.id, m.direction, m.type, m.body, m.media_path, m.media_name, m.ack, m.created_at, c.protocol FROM talk_messages m
             JOIN talk_conversations c ON c.id = m.conversation_id JOIN talk_contacts ct ON ct.id = c.contact_id
             WHERE ct.phone = ? AND m.direction <> 'internal' ORDER BY m.id DESC LIMIT 40",
            [$phone]
        );
        // o cliente só vê o que já foi enviado; "digitando…" enquanto houver mensagem na fila
        $rows = array_values(array_filter(array_reverse($rows), fn ($r) => $r['direction'] !== 'out' || $r['ack'] !== 'pending'));
        $typing = (bool) $this->db->one(
            "SELECT o.id FROM talk_outbox o WHERE o.to_phone = ? AND o.status IN ('queued','sending')
               AND (o.typing_seconds = 0 AND o.next_attempt_at > NOW()) LIMIT 1",
            [$phone]
        );
        return ['messages' => $rows, 'typing' => $typing];
    }
}
