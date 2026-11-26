<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Jack\Jack;
use ConnectTalk\Jack\Monitor;

/**
 * Tela Serviço da Jack (pedido do Djalma em 22/11/2026): o que ela está fazendo, o que não fez,
 * as respostas, a ideia e o procedimento de cada conversa.
 */
final class JackMonitorTest extends DbTestCase
{
    private object $hd;
    private StaffMember $boss;

    protected function setUp(): void
    {
        $this->announceTickets = true;
        parent::setUp();
        $this->hd = new class implements Helpdesk {
            public bool $down = false;
            private int $n = 0;
            public function open(array $c, array $ct, ?string $s): ?array
            {
                if ($this->down) {
                    throw new \RuntimeException('ERP fora do ar');
                }
                $this->n++;
                return ['id' => 900 + $this->n, 'protocol' => 'CH2611220B0' . $this->n, 'url' => 'https://erp.connect.lab/suporte/chamado/CH2611220B0' . $this->n];
            }
            public function transfer(int $t, string $s, int $u, ?string $n): void {}
            public function close(int $t, int $u, ?string $r): void {}
            public function status(int $t): ?array { return null; }
        };
        $this->svc = $this->makeService(null, $this->hd);
        $this->boss = new StaffMember(1, true);
    }

    private function monitor(bool $sacOn = true): Monitor
    {
        return new Monitor($this->db, new Jack($this->db), $sacOn);
    }

    private function find(array $overview, int $id): ?array
    {
        foreach ($overview['conversas'] as $c) {
            if ($c['conversation_id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    public function test_conversa_encaminhada_mostra_ideia_procedimento_e_resposta(): void
    {
        $id = $this->routed('o portão da garagem quebrou');
        $c = $this->find($this->monitor()->overview($this->boss), $id);

        $this->assertNotNull($c);
        $this->assertSame('feito', $c['situacao']);
        $this->assertSame('manutencao', $c['ideia']['intencao']);
        $this->assertTrue($c['ideia']['confiante']);
        $this->assertContains('portao', $c['ideia']['termos']);
        $this->assertStringContainsString('mandou para Manutenção', $c['ideia']['explicacao']);
        $this->assertContains('Encaminhou para Manutenção', $c['procedimento']);
        $this->assertContains('Abriu o chamado no SAC', $c['procedimento']);
        $this->assertContains('Contou ao cliente o número do chamado', $c['procedimento']);
        $this->assertStringContainsString('CH2611220B01', $c['ultima_resposta']['texto']);
        $this->assertSame('CH2611220B01', $c['chamado']['numero']);
        $this->assertSame([], $c['pendencias']);
        $this->assertTrue($c['encaminhou']);
    }

    public function test_so_cumprimentou_aparece_como_fazendo_agora(): void
    {
        $id = (int) $this->inbound('bom dia');
        $o = $this->monitor()->overview($this->boss, 1, 'now');
        $c = $this->find($o, $id);

        $this->assertSame('agora', $c['situacao']);
        $this->assertStringContainsString('só cumprimentou', $c['fazendo']);
        $this->assertSame(1, $o['resumo']['agora']);
        $this->assertSame([], $c['pendencias']);
    }

    public function test_assunto_desconhecido_vira_aviso_de_que_nao_reconheceu(): void
    {
        $id = (int) $this->inbound('preciso falar com vocês');
        $this->triage(40); // passou do tempo máximo: setor padrão
        $c = $this->find($this->monitor()->overview($this->boss, 1, 'problems'), $id);

        $this->assertSame('aviso', $c['situacao']);
        $this->assertStringContainsString('Não reconheceu o assunto', $c['ideia']['explicacao']);
        $this->assertStringContainsString('setor padrão (Administrativo)', $c['pendencias'][0]['texto']);
    }

    public function test_chamado_que_nao_abriu_no_sac_e_mensagem_que_falhou_sao_problemas(): void
    {
        $this->hd->down = true;
        $id = $this->routed('vazamento no banheiro');
        $this->db->run("UPDATE talk_messages SET ack = 'failed' WHERE conversation_id = ? AND direction = 'out'", [$id]);

        $c = $this->find($this->monitor()->overview($this->boss, 1, 'problems'), $id);
        $this->assertSame('erro', $c['situacao']);
        $texts = implode(' | ', array_column($c['pendencias'], 'texto'));
        $this->assertStringContainsString('não chegou ao cliente', $texts);
        $this->assertStringContainsString('não abriu no SAC', $texts);
        $this->assertNull($c['chamado']);

        // sem o SAC ligado não é problema (o protocolo do Talk é o registro)
        $c = $this->find($this->monitor(false)->overview($this->boss), $id);
        $this->assertStringNotContainsString('não abriu no SAC', implode(' ', array_column($c['pendencias'], 'texto')));
    }

    public function test_transferencia_da_equipe_mostra_que_a_jack_errou_o_setor(): void
    {
        $id = $this->routed('quero a segunda via do boleto');
        $this->svc->transfer($id, $this->boss, $this->sectors['Manutenção'], 'era outra coisa');

        $c = $this->find($this->monitor()->overview($this->boss), $id);
        $this->assertSame('aviso', $c['situacao']);
        $this->assertStringContainsString('A equipe transferiu para Manutenção', $c['pendencias'][0]['texto']);
        $this->assertStringContainsString('Financeiro', $c['pendencias'][0]['texto']);
    }

    public function test_passo_a_passo_em_ordem_e_gerente_so_ve_os_setores_dele(): void
    {
        $id = $this->routed('o elevador parou');
        $d = $this->monitor()->detail($this->boss, $id);

        $kinds = array_column($d['passos'], 'tipo');
        $this->assertSame('cliente', $kinds[0]);
        $this->assertContains('ideia', $kinds);
        $this->assertContains('procedimento', $kinds);
        $this->assertContains('resposta', $kinds);
        $this->assertLessThan(array_search('resposta', $kinds, true), array_search('ideia', $kinds, true));
        $texts = array_column($d['passos'], 'texto');
        $this->assertContains('Abriu o chamado CH2611220B01 no SAC.', $texts);

        $financeManager = new StaffMember(5, false, [], [$this->sectors['Financeiro']]);
        $this->assertNull($this->monitor()->detail($financeManager, $id));
        $this->assertNull($this->find($this->monitor()->overview($financeManager), $id));
        $maintenanceManager = new StaffMember(6, false, [], [$this->sectors['Manutenção']]);
        $this->assertNotNull($this->monitor()->detail($maintenanceManager, $id));
    }

    public function test_busca_por_protocolo_e_respostas(): void
    {
        $a = $this->routed('vazamento na garagem', '5531911111111');
        $b = (int) $this->inbound('oi', '5531922222222');
        $protocol = $this->conv($a)['protocol'];

        $o = $this->monitor()->overview($this->boss, 1, 'all', $protocol);
        $this->assertSame([$a], array_column($o['conversas'], 'conversation_id'));
        $o = $this->monitor()->overview($this->boss, 1, 'replies');
        $this->assertSame([$a], array_column($o['conversas'], 'conversation_id'));
        $this->assertSame(2, $o['contagem']['all']);
        $this->assertNotNull($this->find($this->monitor()->overview($this->boss), $b));
    }

    public function test_sugestao_fica_na_conversa_e_o_supervisor_marca_aplicada(): void
    {
        $this->db->run("INSERT INTO talk_users (id, name, email, password_hash) VALUES (10, 'Djalma (Supervisor)', 'sup@x', 'x'), (11, 'Gil Gerente', 'g@x', 'x')");
        $this->boss = new StaffMember(10, true);
        $id = $this->routed('a cerca elétrica desligou');
        $m = $this->monitor();
        $f = $m->suggest($this->boss, $id, 'ideia', 'Cerca elétrica é Manutenção.', $this->sectors['Manutenção']);
        $this->assertSame('nova', $f['situacao']);
        $this->assertSame('Manutenção', $f['setor_certo']);
        $this->assertSame('Djalma', $f['autor']);
        $this->assertSame([$f['id']], array_column($m->detail($this->boss, $id)['sugestoes'], 'id'));
        $this->assertSame(1, $m->overview($this->boss)['sugestoes_novas']);

        $this->assertSame('aplicada', $m->review($this->boss, $f['id'], 'aplicada')['situacao']);
        $this->assertSame([], $m->suggestions($this->boss, 'nova'));
        $this->assertCount(1, $m->suggestions($this->boss, 'aplicada'));

        // gerente de outro setor não sugere nem marca; texto vazio é recusado
        $finance = new StaffMember(11, false, [], [$this->sectors['Financeiro']]);
        try {
            $m->suggest($finance, $id, 'ideia', 'x');
            $this->fail('gerente de outro setor sugeriu');
        } catch (\DomainException) {
        }
        try {
            $m->review($finance, $f['id'], 'descartada');
            $this->fail('gerente marcou sugestão');
        } catch (\DomainException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $m->suggest($this->boss, $id, 'ideia', '   ');
    }
}
