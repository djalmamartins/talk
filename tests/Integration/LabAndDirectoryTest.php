<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Lab\Simulator;
use ConnectTalk\Service\InboxQuery;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\StaffRepository;

/** Consultas que saíram do public/index.php (etapa 1 da revisão de arquitetura). */
final class LabAndDirectoryTest extends DbTestCase
{
    public function test_diretorio_da_equipe_para_o_api_me(): void
    {
        $ana = $this->db->insert('talk_users', ['name' => 'Ana (Financeiro)', 'email' => 'ana@exemplo.test', 'password_hash' => 'x']);
        $off = $this->db->insert('talk_users', ['name' => 'Bia', 'email' => 'bia@exemplo.test', 'password_hash' => 'x', 'active' => 0]);
        $this->db->insert('talk_staff', ['user_id' => $ana, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->db->insert('talk_staff', ['user_id' => $off, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);

        $d = (new StaffRepository($this->db))->directory();

        $this->assertSame(['Financeiro', 'Administrativo', 'Manutenção'], array_column($d['sectors'], 'name'));
        $this->assertSame('Ana (Financeiro)', $d['users'][$ana]);
        $this->assertSame('Bia', $d['users'][$off], 'nomes antigos continuam aparecendo no histórico');
        $this->assertSame([$ana], array_map('intval', array_column($d['staff'], 'user_id')), 'inativo não aparece em "Trocar atendente"');
    }

    public function test_simulador_recebe_e_mostra_o_que_o_cliente_ve(): void
    {
        $lab = $this->db->insert('talk_channels', ['name' => 'Simulado', 'kind' => 'lab', 'purpose' => 'attendance',
            'config_encrypted' => $this->crypto->encrypt([])]);
        $sim = new Simulator($this->db, $this->svc);

        $id = $sim->inbound(999, '(31) 98888-7777', 'Oi, preciso da segunda via', 'Carlos', null);

        $this->assertNotNull($id);
        $this->assertSame($lab, (int) $this->conv($id)['channel_id'], 'canal inexistente cai no primeiro simulado');
        $contact = (new InboxQuery($this->db, new AccessPolicy()))->contact((int) $this->conv($id)['contact_id']);
        $this->assertSame('5531988887777', $contact['phone']);

        $view = $sim->customerView('5531988887777');
        $this->assertSame('Oi, preciso da segunda via', $view['messages'][0]['body']);
        $this->assertSame('in', $view['messages'][0]['direction']);
    }
}
