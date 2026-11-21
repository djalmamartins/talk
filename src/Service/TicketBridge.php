<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Support\Db;

/**
 * Ponte entre a conversa e o chamado no Help Desk do ERP.
 *
 * Nos passos automáticos (abrir ao entrar na fila, transferir, encerrar), o ERP fora do ar não pode
 * travar o atendimento: o erro vai para o log e o atendimento segue. No botão "Abrir chamado"
 * (openNow), o erro aparece para o atendente.
 */
final class TicketBridge
{
    public function __construct(private Db $db, private Helpdesk $helpdesk)
    {
    }

    /** Abre o chamado sozinho (conversa entrou na fila de um setor) e guarda o id e o número dele. */
    public function openAuto(array $conv, array $contact, ?string $sectorName): void
    {
        $contact = $this->withDetails($contact);
        $t = $this->quietly(fn () => $this->helpdesk->open($conv, $contact, $sectorName));
        if (is_array($t) && isset($t['id'])) {
            $this->db->run(
                'UPDATE talk_conversations SET helpdesk_ticket_id = ?, helpdesk_protocol = ? WHERE id = ?',
                [(int) $t['id'], $t['protocol'] ?? null, $conv['id']]
            );
        }
    }

    /** Botão "Abrir chamado": abre com o assunto e a descrição do atendente; erro vira mensagem na tela. */
    public function openNow(array $conv, string $subject, string $description): void
    {
        $contact = $this->withDetails($this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$conv['contact_id']]));
        $sector = $conv['sector_id'] ? $this->db->one('SELECT name FROM talk_sectors WHERE id = ?', [$conv['sector_id']]) : null;
        try {
            $t = $this->helpdesk->open($conv + ['subject' => $subject, 'ticket_message' => $description], $contact, $sector['name'] ?? null);
        } catch (\Throwable $e) {
            error_log('[talk] abrir chamado: ' . $e->getMessage());
            throw new DomainError('O Help Desk do ERP não respondeu. Tente de novo em instantes.', 502);
        }
        if (!is_array($t) || !isset($t['id'])) {
            throw new DomainError('O Help Desk do ERP ainda não está ligado ao Talk (falta ERP_API_URL no .env).', 409);
        }
        $this->db->run(
            'UPDATE talk_conversations SET helpdesk_ticket_id = ?, helpdesk_protocol = ?, subject = ? WHERE id = ?',
            [(int) $t['id'], $t['protocol'] ?? null, $subject, $conv['id']]
        );
    }

    public function transfer(array $conv, string $toSectorName, int $userId, ?string $note): void
    {
        if ($conv['helpdesk_ticket_id']) {
            $this->quietly(fn () => $this->helpdesk->transfer((int) $conv['helpdesk_ticket_id'], $toSectorName, $userId, $note));
        }
    }

    public function close(array $conv, int $userId, ?string $resolution): void
    {
        if ($conv['helpdesk_ticket_id']) {
            $this->quietly(fn () => $this->helpdesk->close((int) $conv['helpdesk_ticket_id'], $userId, $resolution));
        }
    }

    /** Número do chamado para o cliente: o do Help Desk, quando houver; senão, o protocolo do Talk. */
    public static function number(array $conv): string
    {
        return (string) (($conv['helpdesk_protocol'] ?? null) ?: $conv['protocol']);
    }

    /** Contato com o que veio do cadastro do ERP (condomínio, unidade) em "details", para o chamado. */
    private function withDetails(array $contact): array
    {
        if (!is_array($contact['details'] ?? null)) {
            $contact['details'] = json_decode((string) ($contact['erp_snapshot'] ?? ''), true) ?: [];
        }
        return $contact;
    }

    private function quietly(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[talk] helpdesk do ERP falhou: ' . $e->getMessage());
            return null;
        }
    }
}
