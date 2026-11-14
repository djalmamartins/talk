<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Service\DomainError;

final class ConversationFlowTest extends DbTestCase
{
    public function test_primeira_mensagem_cria_conversa_com_protocolo_e_envia_menu(): void
    {
        $id = $this->inbound('oi');
        $c = $this->conv($id);

        $this->assertMatchesRegularExpression('/^\d{8}-00001$/', $c['protocol']);
        $this->assertSame('bot', $c['status']);
        $menu = $this->lastOutbound($id);
        $this->assertStringContainsString($c['protocol'], $menu);
        $this->assertStringContainsString('*3* - Manutenção', $menu);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM talk_outbox')['n']);
    }

    public function test_protocolos_sao_sequenciais_por_dia(): void
    {
        $a = $this->conv($this->inbound('oi', '5531911111111'));
        $b = $this->conv($this->inbound('oi', '5531922222222'));
        $this->assertSame(substr($a['protocol'], 0, 9) . '00002', $b['protocol']);
    }

    public function test_webhook_repetido_e_ignorado(): void
    {
        $this->assertNotNull($this->inbound('oi', id: 'DUP'));
        $this->assertNull($this->inbound('oi', id: 'DUP'));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE direction='in'")['n']);
    }

    public function test_opcao_do_menu_direciona_para_o_setor(): void
    {
        $id = $this->inbound('oi');
        $this->inbound('3');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
        $this->assertStringContainsString('fila do *Manutenção*', $this->lastOutbound($id));
    }

    public function test_palavra_chave_na_primeira_mensagem_ja_direciona(): void
    {
        $id = $this->inbound('Bom dia, preciso da segunda via do boleto');
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
    }

    public function test_palavras_de_dois_setores_nao_adivinha_e_mostra_menu(): void
    {
        $id = $this->inbound('o boleto da taxa e o vazamento do portão');
        $this->assertSame('bot', $this->conv($id)['status']);
    }

    public function test_opcao_invalida_repete_menu(): void
    {
        $id = $this->inbound('oi');
        $this->inbound('banana');
        $this->assertSame('bot', $this->conv($id)['status']);
        $this->assertStringContainsString('Não entendi', $this->lastOutbound($id));
    }

    public function test_identifica_morador_pelo_telefone_sem_o_nono_digito(): void
    {
        $dir = new class implements ContactDirectory {
            public array $asked = [];
            public function findByPhone(array $c): ?array
            {
                $this->asked = $c;
                return in_array('3199998888', $c, true)
                    ? ['person_id' => 77, 'name' => 'João Morador', 'details' => ['condominio' => 'Res. Jardins', 'unidade' => 'A-101']]
                    : null;
            }
        };
        $this->svc = $this->makeService($dir);
        $id = $this->inbound('oi', '5531999998888');
        $contact = $this->db->one('SELECT * FROM talk_contacts WHERE id = ?', [$this->conv($id)['contact_id']]);
        $this->assertSame(77, (int) $contact['erp_person_id']);
        $this->assertSame('João Morador', $contact['name']);
        $this->assertStringContainsString('Jardins', $contact['erp_snapshot']);
        $this->assertStringStartsWith('Olá, João!', $this->lastOutbound($id));
    }

    public function test_atendente_aceita_responde_e_encerra(): void
    {
        $id = $this->inbound('preciso pagar o boleto');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->accept($id, $ana);
        $this->assertSame('open', $this->conv($id)['status']);

        $this->svc->reply($id, $ana, 'Claro! Segue o link.', 'Ana');
        $this->assertSame("*Ana:*\nClaro! Segue o link.", $this->lastOutbound($id));

        $this->svc->close($id, $ana, 'Enviada 2ª via');
        $c = $this->conv($id);
        $this->assertSame('closed', $c['status']);
        $this->assertStringContainsString($c['protocol'], $this->lastOutbound($id));

        // nova mensagem depois de encerrar = novo protocolo
        $novo = $this->inbound('obrigada!');
        $this->assertNotSame($id, $novo);
    }

    public function test_dois_atendentes_nao_aceitam_a_mesma_conversa(): void
    {
        $id = $this->inbound('boleto');
        $this->svc->accept($id, $this->agent(10, 'Financeiro'));
        $this->expectException(DomainError::class);
        $this->svc->accept($id, $this->agent(11, 'Financeiro'));
    }

    public function test_setor_nao_mexe_em_conversa_de_outro_setor(): void
    {
        $id = $this->inbound('vazamento no banheiro');
        $this->expectException(DomainError::class);
        $this->svc->accept($id, $this->agent(10, 'Financeiro'));
    }

    public function test_transferencia_com_nota_interna(): void
    {
        $hd = new class implements Helpdesk {
            public array $log = [];
            public function open(array $c, array $ct, ?string $s): ?int { $this->log[] = "open:$s"; return 500; }
            public function transfer(int $t, string $s, int $u, ?string $n): void { $this->log[] = "transfer:$t:$s:$n"; }
            public function close(int $t, int $u, ?string $r): void { $this->log[] = "close:$t"; }
        };
        $this->svc = $this->makeService(null, $hd);

        $id = $this->inbound('boleto e também tem vazamento');  // dois setores → menu
        $this->inbound('1');
        $ana = $this->agent(10, 'Financeiro');
        $this->svc->accept($id, $ana);
        $this->svc->transfer($id, $ana, $this->sectors['Manutenção'], 'Morador relata vazamento no 101');

        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertNull($c['assigned_user_id']);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
        $note = $this->db->one("SELECT body FROM talk_messages WHERE conversation_id = ? AND type = 'transfer'", [$id]);
        $this->assertStringContainsString('Financeiro para Manutenção', $note['body']);
        $this->assertStringContainsString('vazamento no 101', $note['body']);
        $this->assertSame(['open:Financeiro', 'transfer:500:Manutenção:Morador relata vazamento no 101'], $hd->log);

        // Ana (financeiro) não age mais; manutenção aceita
        try {
            $this->svc->reply($id, $ana, 'oi');
            $this->fail('Financeiro não deveria responder depois de transferir');
        } catch (DomainError) {
        }
        $this->svc->accept($id, $this->agent(20, 'Manutenção'));
        $this->assertSame(20, (int) $this->conv($id)['assigned_user_id']);
    }

    public function test_supervisor_e_gerente_podem_agir(): void
    {
        $id = $this->inbound('elevador parado');
        $this->svc->accept($id, $this->agent(20, 'Manutenção'));
        $gerente = new StaffMember(30, false, [], [$this->sectors['Manutenção']]);
        $this->svc->addNote($id, $gerente, 'Acompanhar com a empresa do elevador');
        $master = new StaffMember(1, true);
        $this->svc->close($id, $master, 'Técnico enviado');
        $this->assertSame('closed', $this->conv($id)['status']);
    }

    public function test_ack_nao_regride(): void
    {
        $id = $this->inbound('boleto');
        $msg = $this->db->one("SELECT id FROM talk_messages WHERE conversation_id = ? AND direction='out' ORDER BY id DESC LIMIT 1", [$id]);
        $this->db->run("UPDATE talk_messages SET provider_message_id = 'P1' WHERE id = ?", [$msg['id']]);
        $this->svc->handleStatus(new \ConnectTalk\Domain\StatusUpdate('P1', 'read'));
        $this->svc->handleStatus(new \ConnectTalk\Domain\StatusUpdate('P1', 'delivered'));
        $this->assertSame('read', $this->db->one('SELECT ack FROM talk_messages WHERE id = ?', [$msg['id']])['ack']);
    }
}
