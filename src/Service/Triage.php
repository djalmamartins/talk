<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Jack\Jack;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Support\Db;

/**
 * Triagem: leva a conversa da Jack (status "bot") para a fila de um setor. Decide o setor
 * (número dedicado, resposta a envio em massa ou a Jack lendo o texto), abre o chamado e,
 * fora do horário, avisa o cliente quando a equipe volta (ADR 0004).
 */
final class Triage
{
    public function __construct(
        private Db $db,
        private EventBus $events,
        private ConversationStore $store,
        private TicketBridge $tickets,
        private ?Jack $jack = null,
        private ?BusinessHours $hours = null,
    ) {
    }

    /**
     * Encaminha as conversas que estão na triagem (status "bot") quando a pessoa parou de escrever.
     * Roda no worker (e no fim de cada requisição no laboratório). $clockShift só para testes.
     * @return int quantas conversas foram encaminhadas
     */
    public function run(int $clockShift = 0): int
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
            // assunto já claro ("estamos sem água") vai para a fila na hora; senão espera a pessoa terminar
            if (!$this->jack->isDue((int) $r['quiet'], (int) $r['age']) && !$this->jack->isClear(implode("\n", $this->store->inboundTexts((int) $r['id'])))) {
                continue;
            }
            $routed += (int) $this->db->transaction(function () use ($r): bool {
                $conv = $this->store->lock((int) $r['id']);
                if ($conv['status'] !== 'bot') {
                    return false; // outro processo já encaminhou
                }
                $texts = $this->store->inboundTexts((int) $conv['id']);
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
                    : $this->jack->decide($conv, implode("\n", $texts), (int) $r['quiet'], (int) $r['age']);
                if ($d === null) {
                    return false; // já perguntou e a pessoa ainda não disse o assunto: espera
                }
                $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]);
                $this->route($conv, $d['sector'], $contact);
                // ADR 0004: encaminhar é em silêncio. Só fora do horário a Jack avisa quando a equipe volta.
                $this->sayOffHours($conv, $d['sector'], $contact);
                return true;
            });
        }
        return $routed;
    }

    /** Mensagem da Jaqueline: entra na fila com "digitando…" antes, como uma pessoa. */
    public function jackSay(array $conv, string $text, string $type): int
    {
        return $this->store->queueOutbound($conv, $text, null, $type, $this->jack?->typingSeconds($text) ?? 0);
    }

    /** Texto de fora do horário (uma vez por conversa), ou null se o setor está atendendo. */
    private function offHours(array $conv, array $sector, ?string $first): ?string
    {
        if ($this->hours === null || !$this->hours->enabled()) {
            return null;
        }
        $conv = $this->store->get((int) $conv['id']); // já com o número do chamado do Help Desk
        $text = $this->hours->offHoursMessage((int) $sector['id'], (string) $sector['name'], $first, TicketBridge::number($conv));
        if ($text === null) {
            return null;
        }
        $n = $this->db->run('UPDATE talk_conversations SET off_hours_at = NOW() WHERE id = ? AND off_hours_at IS NULL', [$conv['id']])->rowCount();
        return $n > 0 ? $text : null;
    }

    /** Aviso de fora do horário, se for o caso (uma vez por conversa). */
    public function sayOffHours(array $conv, array $sector, array $contact): void
    {
        $first = $contact['name'] ? explode(' ', trim((string) $contact['name']))[0] : null;
        $off = $this->offHours($conv, $sector, $first);
        if ($off !== null) {
            $this->jackSay($this->store->get((int) $conv['id']), $off, 'off_hours');
        }
    }

    public function fallbackSector(): ?array
    {
        return $this->jack?->fallbackSector()
            ?? $this->db->one("SELECT id, name FROM talk_sectors WHERE active = 1 ORDER BY menu_key LIMIT 1");
    }

    /** Coloca a conversa na fila do setor e abre o chamado. Não manda nada para o cliente. */
    public function route(array $conv, array $sector, array $contact): void
    {
        $this->db->run("UPDATE talk_conversations SET sector_id = ?, status = 'pending', queued_at = NOW() WHERE id = ?", [$sector['id'], $conv['id']]);
        $conv = $this->store->get((int) $conv['id']);
        $this->tickets->openAuto($conv, $contact, $sector['name']);
        $this->jack?->settle((int) $conv['id'], (int) $sector['id']);
        $this->events->publish('conversation.updated', (int) $conv['id'], (int) $sector['id'], null, $this->store->summary($this->store->get((int) $conv['id'])));
    }
}
