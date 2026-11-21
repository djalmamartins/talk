<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Lab\Simulator;
use ConnectTalk\Service\InboxQuery;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\StaffRepository;
use ConnectTalk\Service\LoginGuard;
use ConnectTalk\Service\OutboxWorker;
use ConnectTalk\Realtime\EventBus;

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

    public function test_login_confere_email_senha_e_ativo(): void
    {
        $id = $this->db->insert('talk_users', ['name' => 'Ana', 'email' => 'ana@exemplo.test', 'password_hash' => password_hash('certa', PASSWORD_DEFAULT)]);
        $this->db->insert('talk_users', ['name' => 'Bia', 'email' => 'bia@exemplo.test', 'password_hash' => password_hash('certa', PASSWORD_DEFAULT), 'active' => 0]);
        $g = new LoginGuard($this->db);

        $this->assertSame($id, (int) $g->check(' ANA@exemplo.test ', 'certa')['id']);
        $this->assertNull($g->check('ana@exemplo.test', 'errada'));
        $this->assertNull($g->check('bia@exemplo.test', 'certa'), 'inativo não entra');
        $this->assertSame('ana@exemplo.test', (new StaffRepository($this->db))->email($id));
    }

    public function test_sinal_de_vida_do_worker(): void
    {
        $w = new OutboxWorker($this->db, $this->gateways(), new EventBus($this->db));
        $this->assertNull($w->secondsSinceHeartbeat());
        $this->assertFalse(OutboxWorker::isAlive(null));

        $w->heartbeat();

        $this->assertTrue(OutboxWorker::isAlive($w->secondsSinceHeartbeat()));
        $this->assertFalse(OutboxWorker::isAlive(91));
    }

    public function test_ultimo_evento_do_tempo_real(): void
    {
        $bus = new EventBus($this->db);
        $this->assertSame(0, $bus->lastId());
        $id = $bus->publish('teste', null, null, null);
        $this->assertSame($id, $bus->lastId());
    }
}
