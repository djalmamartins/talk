<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Domain\InboundMessage;
use ConnectTalk\Domain\StatusUpdate;
use ConnectTalk\Jack\Jack;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Support\Db;
use ConnectTalk\Support\Phone;

/**
 * O que chega do WhatsApp: mensagem do cliente (abre ou continua a conversa, identifica o contato
 * no ERP, responde a avaliação) e status de entrega das mensagens enviadas.
 */
final class InboundService
{
    public function __construct(
        private Db $db,
        private EventBus $events,
        private ConversationStore $store,
        private Triage $triage,
        private ContactDirectory $directory,
        private Texts $texts,
        private ?Jack $jack = null,
    ) {
    }

    /** Processa o que chegou pelo webhook. Retorna o id da conversa (ou null se já processado). */
    public function message(int $channelId, InboundMessage $in): ?int
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
            $conv = $this->store->get((int) $conv['id']);

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
        $conv = $this->store->get($convId);
        $this->events->publish('message.created', $convId, $this->store->sectorOf($conv), null, [
            'message_id' => $msgId, 'direction' => 'in', 'type' => 'rating', 'body' => $in->body,
        ]);
        $this->store->queueOutbound($conv, $this->texts->ratingThanks($score), null, 'rating_thanks');
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

    public function status(StatusUpdate $s): void
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
            'protocol' => $this->store->nextProtocol(),
            'channel_id' => $channelId,
            'contact_id' => $contact['id'],
            'status' => 'bot',
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->store->get($id);
    }
}
