<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Gateway\GatewayFactory;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Support\Db;

/**
 * Envia o que está na talk_outbox. Rode em loop (bin/talk-worker) ou pelo cron.
 * Usa SKIP LOCKED: dá para ter mais de um worker sem enviar duas vezes.
 */
final class OutboxWorker
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private Db $db, private GatewayFactory $gateways, private EventBus $events)
    {
    }

    /** Processa até $limit envios. Retorna quantos foram enviados com sucesso. */
    public function runOnce(int $limit = 20): int
    {
        $jobs = $this->db->transaction(function () use ($limit) {
            $rows = $this->db->all(
                "SELECT o.*, m.body, m.conversation_id FROM talk_outbox o JOIN talk_messages m ON m.id = o.message_id
                 WHERE o.status = 'queued' AND o.next_attempt_at <= NOW()
                 ORDER BY o.id LIMIT " . (int) $limit . ' FOR UPDATE SKIP LOCKED'
            );
            foreach ($rows as $r) {
                $this->db->run("UPDATE talk_outbox SET status = 'sending', attempts = attempts + 1 WHERE id = ?", [$r['id']]);
            }
            return $rows;
        });

        $sent = 0;
        foreach ($jobs as $job) {
            try {
                $result = $this->gateways->forChannel((int) $job['channel_id'])->sendText($job['to_phone'], (string) $job['body']);
                $this->db->run("UPDATE talk_outbox SET status = 'sent', last_error = NULL WHERE id = ?", [$job['id']]);
                $this->db->run("UPDATE talk_messages SET ack = 'sent', provider_message_id = ? WHERE id = ?", [$result->providerMessageId, $job['message_id']]);
                $this->ack((int) $job['conversation_id'], (int) $job['message_id'], 'sent');
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int) $job['attempts'] + 1;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $this->db->run("UPDATE talk_outbox SET status = 'failed', last_error = ? WHERE id = ?", [$e->getMessage(), $job['id']]);
                    $this->db->run("UPDATE talk_messages SET ack = 'failed' WHERE id = ?", [$job['message_id']]);
                    $this->ack((int) $job['conversation_id'], (int) $job['message_id'], 'failed');
                } else {
                    $delay = 2 ** $attempts * 15; // 30s, 60s, 120s, 240s
                    $this->db->run(
                        "UPDATE talk_outbox SET status = 'queued', last_error = ?, next_attempt_at = NOW() + INTERVAL ? SECOND WHERE id = ?",
                        [$e->getMessage(), $delay, $job['id']]
                    );
                }
            }
        }
        return $sent;
    }

    private function ack(int $convId, int $messageId, string $ack): void
    {
        $sector = $this->db->one('SELECT sector_id FROM talk_conversations WHERE id = ?', [$convId])['sector_id'] ?? null;
        $this->events->publish('message.ack', $convId, $sector !== null ? (int) $sector : null, null, ['message_id' => $messageId, 'ack' => $ack]);
    }
}
