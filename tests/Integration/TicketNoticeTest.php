<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\Notifications;
use ConnectTalk\Service\AccessPolicy;

/**
 * Pedidos do Djalma em 22/11/2026:
 *  - só uma pessoa online para o setor → a conversa vai direto para ela;
 *  - a Jack conta ao cliente o número e o link do chamado do SAC, com texto natural;
 *  - o cliente manda o número do chamado no WhatsApp e a Jack responde como está;
 *  - o chamado no SAC leva os dados da conversa.
 */
final class TicketNoticeTest extends DbTestCase
{
    private object $hd;

    protected function setUp(): void
    {
        $this->announceTickets = true;
        parent::setUp();
        $this->hd = new class implements Helpdesk {
            public array $opened = [];
            public array $status = [];
            public function open(array $c, array $ct, ?string $s): ?array
            {
                $this->opened[] = $c + ['_sector' => $s, '_contact' => $ct];
                $n = count($this->opened);
                return ['id' => 700 + $n, 'protocol' => 'CH2611220A0' . $n, 'url' => 'https://erp.connect.lab/suporte/chamado/CH2611220A0' . $n . '?c=abc'];
            }
            public function transfer(int $t, string $s, int $u, ?string $n): void {}
            public function close(int $t, int $u, ?string $r): void {}
            public function status(int $t): ?array { return $this->status[$t] ?? null; }
        };
        $this->svc = $this->makeService(null, $this->hd);
    }

    private function staff(int $id, string $name, ?string $sector, string $role = 'agent', bool $online = true): void
    {
        $this->db->run('INSERT INTO talk_users (id, name, email, password_hash, last_seen_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $name, "u$id@x", 'x', $online ? date('Y-m-d H:i:s') : null]);
        $this->db->insert('talk_staff', ['user_id' => $id, 'sector_id' => $sector ? $this->sectors[$sector] : null, 'role' => $role]);
    }

    private function outs(int $convId): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id", [$convId]), 'body');
    }

    private function notes(int $convId): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'internal' ORDER BY id", [$convId]), 'body');
    }

    // ------------------------------------------------------------ distribuição automática

    public function test_so_uma_pessoa_online_recebe_a_conversa_direto(): void
    {
        $this->staff(100, 'Djalma Martins', null, 'supervisor');
        $this->staff(101, 'Ana Souza', 'Administrativo', 'agent', online: false);

        $id = $this->routed('quero reservar o salão de festas para sábado'); // Administrativo
        $c = $this->conv($id);
        $this->assertSame('open', $c['status']);
        $this->assertSame(100, (int) $c['assigned_user_id'], 'o supervisor era o único online');
        $this->assertNotNull($c['accepted_at']);
        $this->assertSame(1, (int) $c['transfer_pending'], 'aparece no sino dele');
        $this->assertStringContainsString('Distribuída para Djalma Martins (única pessoa online para Administrativo).', implode("\n", $this->notes($id)));

        $bell = (new Notifications($this->db, new AccessPolicy()))->for(new StaffMember(100, true));
        $this->assertContains('transfer', array_column($bell['items'], 'kind'));
    }

    public function test_duas_pessoas_online_a_conversa_espera_na_fila(): void
    {
        $this->staff(100, 'Djalma Martins', null, 'supervisor');
        $this->staff(101, 'Ana Souza', 'Administrativo');
        $id = $this->routed('quero reservar o salão de festas para sábado');
        $this->assertSame('pending', $this->conv($id)['status']);
        $this->assertNull($this->conv($id)['assigned_user_id']);
    }

    public function test_quem_entra_depois_pega_a_fila_parada(): void
    {
        $this->staff(101, 'Ana Souza', 'Administrativo', 'agent', online: false);
        $id = $this->routed('quero reservar o salão de festas para sábado');
        $this->assertSame('pending', $this->conv($id)['status'], 'ninguém online');

        $this->db->run('UPDATE talk_users SET last_seen_at = NOW() WHERE id = 101'); // Ana abriu o Talk
        $this->svc->triage();
        $this->assertSame(101, (int) $this->conv($id)['assigned_user_id']);
    }

    public function test_quem_transferiu_para_o_setor_nao_recebe_de_volta(): void
    {
        $this->staff(100, 'Djalma Martins', null, 'supervisor');
        $id = $this->routed('quero reservar o salão de festas para sábado');
        $this->assertSame(100, (int) $this->conv($id)['assigned_user_id']);

        $this->svc->transfer($id, new StaffMember(100, true), $this->sectors['Manutenção'], 'é com a manutenção');
        $this->svc->triage();
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status'], 'ele mandou para a fila da Manutenção de propósito');
        $this->assertNull($c['assigned_user_id']);
    }

    public function test_distribuicao_automatica_desligada(): void
    {
        $this->staff(100, 'Djalma Martins', null, 'supervisor');
        $this->svc = new ConversationService($this->db, new \ConnectTalk\Realtime\EventBus($this->db), new AccessPolicy(),
            new \ConnectTalk\Erp\NullContactDirectory(), $this->hd, new \ConnectTalk\Service\Texts(),
            new \ConnectTalk\Jack\Jack($this->db), autoAssign: false);
        $id = $this->routed('quero reservar o salão de festas para sábado');
        $this->assertSame('pending', $this->conv($id)['status']);
    }

    // ------------------------------------------------------------ Jack avisa o chamado

    public function test_jack_conta_o_numero_e_o_link_do_chamado(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $c = $this->conv($id);
        $this->assertSame('CH2611220A01', $c['helpdesk_protocol']);
        $this->assertSame('https://erp.connect.lab/suporte/chamado/CH2611220A01?c=abc', $c['helpdesk_url']);

        $out = $this->outs($id);
        $this->assertCount(1, $out);
        $this->assertStringStartsWith('*Jack:*', $out[0]);
        $this->assertMatchesRegularExpression('/^\*Jack:\*\n(Bom dia|Boa tarde|Boa noite), Maria!/', $out[0]);
        $this->assertStringContainsString('*CH2611220A01*', $out[0]);
        $this->assertStringContainsString('*Manutenção*', $out[0]);
        $this->assertStringContainsString('https://erp.connect.lab/suporte/chamado/CH2611220A01?c=abc', $out[0]);
        $this->assertStringContainsString('me mandar o número do chamado', $out[0]);
        $this->assertStringContainsString('Jack encaminhou para Manutenção.', implode("\n", $this->notes($id)));
    }

    public function test_texto_varia_e_diz_quem_vai_atender(): void
    {
        $t = new \ConnectTalk\Service\Texts();
        $a = $t->jackTicketOpened('Maria', 'CH1', 'Financeiro', null, null, 0, 9);
        $b = $t->jackTicketOpened('Maria', 'CH1', 'Financeiro', null, null, 1, 15);
        $c = $t->jackTicketOpened(null, 'CH1', 'Financeiro', null, 'Ana', 2, 20);
        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('Bom dia, Maria!', $a);
        $this->assertStringContainsString('Boa tarde, Maria!', $b);
        $this->assertStringContainsString('Boa noite!', $c);
        $this->assertStringContainsString('*Ana* já está com a sua conversa', $c);
        $this->assertStringNotContainsString('link', $a, 'sem link quando o SAC não mandou');
    }

    public function test_quando_cai_direto_para_alguem_a_jack_diz_o_nome(): void
    {
        $this->staff(100, 'Djalma Martins', null, 'supervisor');
        $id = $this->routed('o portão da garagem quebrou');
        $this->assertStringContainsString('*Djalma* já está com a sua conversa', $this->outs($id)[0]);
    }

    public function test_chamado_leva_os_dados_e_a_conversa(): void
    {
        $id = (int) $this->inbound('bom dia');
        $this->inbound('o portão da garagem quebrou e não fecha');
        $this->triage();
        $o = $this->hd->opened[0];
        $this->assertSame('Manutenção', $o['_sector']);
        $this->assertStringContainsString('Cliente: bom dia', $o['transcript']);
        $this->assertStringContainsString('Cliente: o portão da garagem quebrou e não fecha', $o['transcript']);
        $this->assertSame((int) $id, (int) $o['id']);
    }

    // ------------------------------------------------------------ consulta pelo número

    public function test_cliente_manda_o_numero_e_a_jack_responde_o_status(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $this->svc->close($id, new StaffMember(1, true), 'Técnico agendado', notifyCustomer: false);
        $this->hd->status[701] = ['status' => 'in_progress', 'label' => 'em atendimento', 'last_reply_at' => date('Y-m-d') . ' 10:15:00', 'url' => 'https://erp.connect.lab/suporte/chamado/CH2611220A01?c=abc'];

        $q = (int) $this->inbound('ch2611220a01');
        $this->assertNotSame($id, $q, 'conversa nova só para a consulta');
        $out = $this->outs($q);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('o chamado *CH2611220A01* está *em atendimento* com a equipe de *Manutenção*', $out[0]);
        $this->assertStringContainsString('hoje às 10:15', $out[0]);
        $this->assertStringContainsString('https://erp.connect.lab/suporte/chamado/CH2611220A01?c=abc', $out[0]);
        $this->assertSame('closed', $this->conv($q)['status'], 'a consulta não entra na fila de ninguém');
        $this->assertCount(1, $this->hd->opened, 'consulta não abre outro chamado');
    }

    public function test_numero_de_outra_pessoa_nao_mostra_nada(): void
    {
        $id = $this->routed('o portão da garagem quebrou', '5531911112222');
        $this->hd->status[701] = ['status' => 'open', 'label' => 'aberto', 'last_reply_at' => null, 'url' => 'https://erp.connect.lab/x'];
        $q = (int) $this->inbound('status do chamado CH2611220A01?', '5531933334444');
        $out = $this->outs($q);
        $this->assertStringContainsString('Não encontrei o chamado *CH2611220A01*', $out[0]);
        $this->assertStringNotContainsString('erp.connect.lab', $out[0]);
        $this->assertSame('closed', $this->conv($q)['status']);
        $this->assertNotSame($id, $q);
    }

    public function test_sem_sac_usa_a_situacao_do_talk(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $proto = $this->conv($id)['protocol'];
        $this->inbound($proto); // mesma conversa, ainda na fila
        $out = $this->outs($id);
        $this->assertStringContainsString('está *na fila, aguardando um atendente* com a equipe de *Manutenção*', end($out));
        $this->assertSame('pending', $this->conv($id)['status'], 'conversa em andamento continua');
    }

    public function test_codigo_no_meio_de_outro_assunto_segue_para_a_fila(): void
    {
        $this->assertTrue(ConversationService::onlyTicketCode('Qual o status do chamado CH2611220A01?'));
        $this->assertTrue(ConversationService::onlyTicketCode('SAC26112200004'));
        $this->assertFalse(ConversationService::onlyTicketCode('CH2611220A01 o vazamento voltou no banheiro'));
        $this->assertNull(ConversationService::ticketCode('meu telefone é 31999998888'));
        $this->assertSame('CH2611220A01', ConversationService::ticketCode('chamado ch2611220a01'));
    }
}
