<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Erp\NullContactDirectory;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Jack\Jack;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\DomainError;
use ConnectTalk\Service\QueueQuery;
use ConnectTalk\Service\TeamService;
use ConnectTalk\Service\Texts;

/** Jack cadastrada como atendente (JV1.8): aparece na equipe, assina as mensagens dela, não entra nem recebe transferência. */
final class JackStaffTest extends DbTestCase
{
    private int $jackId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jackId = (int) $this->db->one('SELECT id FROM talk_users WHERE is_bot = 1')['id']; // criada pela migration 021
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(),
            new NullHelpdesk(), new Texts(), new Jack($this->db, mode: 'auto', announceTickets: false));
    }

    public function test_migration_cria_a_jack_sem_senha_valida(): void
    {
        $u = $this->db->one('SELECT * FROM talk_users WHERE id = ?', [$this->jackId]);
        $this->assertSame('Jaqueline (Jack)', $u['name']);
        $this->assertFalse(password_verify('', $u['password_hash']));
        $this->assertFalse(password_verify('!sem-login', $u['password_hash']));
    }

    public function test_mensagem_da_jack_sai_com_o_nome_dela(): void
    {
        $id = (int) $this->inbound('vc é um robô?');
        $m = $this->db->one("SELECT author_user_id FROM talk_messages WHERE conversation_id = ? AND direction = 'out'", [$id]);
        $this->assertSame($this->jackId, (int) $m['author_user_id']);
    }

    public function test_aparece_na_equipe_mas_nao_edita_nem_desativa(): void
    {
        $team = new TeamService($this->db, new EventBus($this->db), '10.0.0.1');
        $sup = new StaffMember(999, true);
        $jack = array_values(array_filter($team->users($sup), fn ($u) => $u['bot']));
        $this->assertCount(1, $jack);
        $this->expectException(DomainError::class);
        $team->setActive($sup, $this->jackId, false);
    }

    public function test_nao_edita_a_jack(): void
    {
        $this->expectException(DomainError::class);
        (new TeamService($this->db, new EventBus($this->db), '10.0.0.1'))->saveUser(new StaffMember(999, true), [
            'name' => 'Outra', 'email' => 'x@x.com', 'role' => 'agent', 'sector_ids' => [$this->sectors['Financeiro']]], $this->jackId);
    }

    public function test_nao_recebe_transferencia_nem_aparece_como_atendente_online(): void
    {
        $this->db->insert('talk_staff', ['user_id' => $this->jackId, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->db->run('UPDATE talk_users SET last_seen_at = NOW() WHERE id = ?', [$this->jackId]);
        $id = $this->routed('segunda via do boleto');
        $this->assertNotContains($this->jackId, array_column((new QueueQuery($this->db, new AccessPolicy()))->attendants(new StaffMember(999, true)), 'id'));
        $this->expectException(DomainError::class);
        $this->svc->transfer($id, new StaffMember(999, true), $this->sectors['Financeiro'], null, $this->jackId);
    }
}
