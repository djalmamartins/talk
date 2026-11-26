<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\DomainError;
use ConnectTalk\Service\InboxQuery;
use ConnectTalk\Service\AccessPolicy;

/**
 * Pedido do Djalma em 22/11/2026 (escolha no cartão: "Só quando clicar Abrir chamado"):
 *  - protocolo sem "SAC" na frente, o mesmo no Talk e no SAC;
 *  - conversa comum fica só no Talk; o chamado vai para o SAC pelo botão, com título, descrição e setor;
 *  - encerrar a conversa não encerra o chamado aberto pelo botão.
 */
final class ProtocolTicketTest extends DbTestCase
{
    private object $hd;

    protected function setUp(): void
    {
        $this->autoTicket = false;
        parent::setUp();
        $this->hd = new class implements Helpdesk {
            public array $opened = [];
            public array $closed = [];
            public function open(array $c, array $ct, ?string $s): ?array
            {
                $this->opened[] = $c + ['_sector' => $s];
                return ['id' => 900 + count($this->opened), 'protocol' => $c['protocol'], 'url' => null];
            }
            public function transfer(int $t, string $s, int $u, ?string $n): void {}
            public function close(int $t, int $u, ?string $r): void { $this->closed[] = $t; }
            public function status(int $t): ?array { return null; }
        };
        $this->svc = $this->makeService(null, $this->hd);
    }

    public function test_protocolo_no_formato_do_sac(): void
    {
        $c = $this->conv($this->routed('o portão da garagem quebrou'));
        $this->assertMatchesRegularExpression('/^SAC' . date('Ymd') . '\d{4}$/', $c['protocol']);
        $this->assertSame('SAC202611220403', ConversationService::canonicalProtocol('26112200403'));
        $this->assertSame('SAC202611220403', ConversationService::canonicalProtocol('SAC26112200403'));
        $this->assertSame('SAC202611221234', ConversationService::canonicalProtocol('sac 2026 1122 1234'));
        $this->assertSame('SAC2026112212345', ConversationService::canonicalProtocol('26112212345'), 'Depois de 9999 no dia, 5 dígitos.');
        $this->assertSame('CH2611220A01', ConversationService::canonicalProtocol('ch2611220a01'));
    }

    public function test_sequencia_passa_de_9999_com_5_digitos(): void
    {
        $this->db->run('INSERT INTO talk_protocol_seq (day, `last_value`) VALUES (?, 9999) ON DUPLICATE KEY UPDATE `last_value` = 9999', [date('Y-m-d')]);
        $this->assertSame('SAC' . date('Ymd') . '10000', $this->svc->nextProtocol());
    }

    public function test_conversa_comum_nao_vai_para_o_sac_e_encerra_so_no_talk(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $this->assertSame([], $this->hd->opened, 'sem o botão, nada no SAC');
        $this->assertNull($this->conv($id)['helpdesk_ticket_id']);
        $this->svc->close($id, new StaffMember(1, true));
        $this->assertSame([], $this->hd->closed);
    }

    public function test_botao_abre_com_titulo_descricao_e_setor_e_o_mesmo_protocolo(): void
    {
        $id = $this->routed('o portão da garagem quebrou'); // Manutenção
        $sup = new StaffMember(1, true);
        $c = $this->svc->openTicketNow($id, $sup, 'Portão da garagem', 'O portão não fecha desde ontem à noite.', $this->sectors['Administrativo']);
        $o = $this->hd->opened[0];
        $this->assertSame('Administrativo', $o['_sector'], 'vai para o setor escolhido no diálogo');
        $this->assertSame('Portão da garagem', $o['subject']);
        $this->assertSame('O portão não fecha desde ontem à noite.', $o['ticket_message']);
        $this->assertSame($c['protocol'], $c['helpdesk_protocol'], 'mesmo número no Talk e no SAC');
        $this->assertSame(1, (int) $c['helpdesk_opened_by']);
        $this->assertSame((int) $this->sectors['Manutenção'], (int) $c['sector_id'], 'a conversa continua no setor dela');
        $notes = array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal'", [$id]), 'body');
        $this->assertContains('Chamado aberto no SAC para Administrativo, protocolo ' . $c['protocol'] . ': Portão da garagem', $notes);

        $this->svc->close($id, $sup, 'Avisei o cliente');
        $this->assertSame([], $this->hd->closed, 'o chamado aberto pelo botão continua no SAC');
    }

    public function test_botao_pede_descricao_e_setor_valido(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $sup = new StaffMember(1, true);
        try {
            $this->svc->openTicketNow($id, $sup, 'Portão', 'curta', null);
            $this->fail('descrição curta');
        } catch (DomainError $e) {
            $this->assertStringContainsString('pelo menos 10', $e->getMessage());
        }
        $this->expectException(DomainError::class);
        $this->svc->openTicketNow($id, $sup, 'Portão', 'O portão não fecha mais.', 99999);
    }

    public function test_codigo_aceita_com_ou_sem_sac_e_nao_confunde_telefone(): void
    {
        $this->assertSame('SAC202611220403', ConversationService::ticketCode('meu protocolo é SAC202611220403'));
        $this->assertSame('SAC202611220403', ConversationService::ticketCode('sac 2026 1122 0403'));
        $this->assertSame('SAC202611220403', ConversationService::ticketCode('o antigo 26112200403'));
        $this->assertSame('SAC202611220403', ConversationService::ticketCode('SAC26112200403'));
        $this->assertNull(ConversationService::ticketCode('meu telefone é 31999998888'));
        $this->assertNull(ConversationService::ticketCode('11987654321'));
        $this->assertTrue(ConversationService::onlyTicketCode('protocolo 26112200403'));
        $this->assertTrue(ConversationService::onlyTicketCode('SAC202611220403'));
    }

    public function test_busca_pelo_numero_novo_ou_antigo(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $this->db->run('UPDATE talk_conversations SET protocol = ?, legacy_protocol = ? WHERE id = ?', ['SAC202611220403', '26112200403', $id]);
        $q = new InboxQuery($this->db, new AccessPolicy());
        foreach (['SAC202611220403', 'sac 2026 1122 0403', '26112200403', 'SAC26112200403'] as $term) {
            $found = $q->search(new StaffMember(1, true), ['q' => $term]);
            $this->assertContains($id, array_map('intval', array_column($found['items'] ?? $found, 'conversation_id')), "Busca por $term.");
        }
    }

    public function test_migracao_028_converte_o_formato_antigo(): void
    {
        $id = $this->routed('portão');
        $sql = (string) file_get_contents(__DIR__ . '/../../migrations/028_protocolo_sac_aaaammdd.sql');
        foreach ([['26112200403', 'SAC202611220403', '26112200403'], ['SAC26112212345', 'SAC2026112212345', 'SAC26112212345'],
                  ['SAC202611220007', 'SAC202611220007', null]] as [$old, $new, $legacy]) {
            $this->db->run('UPDATE talk_conversations SET protocol = ?, legacy_protocol = NULL WHERE id = ?', [$old, $id]);
            $this->db->pdo()->exec($sql);
            $row = $this->db->one('SELECT protocol, legacy_protocol FROM talk_conversations WHERE id = ?', [$id]);
            $this->assertSame([$new, $legacy], [$row['protocol'], $row['legacy_protocol']], "Migração de $old.");
        }
    }
}
