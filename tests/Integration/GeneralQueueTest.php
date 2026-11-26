<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\InboxQuery;
use ConnectTalk\Realtime\EventBus;

/** 1.5.0 sem a Jack (pedido do Djalma em 26/11/2026): toda conversa nova cai na fila Geral, todos veem e cada um pega a sua. */
final class GeneralQueueTest extends DbTestCase
{
    protected bool $autoTicket = false;
    private int $general = 0;

    protected function makeService(?ContactDirectory $dir = null, ?Helpdesk $hd = null): ConversationService
    {
        $this->general = $this->general ?: $this->db->insert('talk_sectors', ['name' => 'Geral', 'jack_key' => 'geral', 'menu_key' => '0']);
        return new ConversationService(
            $this->db, new EventBus($this->db), new AccessPolicy($this->general),
            $dir ?? new \ConnectTalk\Erp\NullContactDirectory(), $hd ?? new NullHelpdesk(), new \ConnectTalk\Service\Texts(),
            new \ConnectTalk\Jack\Jack($this->db, mode: 'off'),
            $this->media, hours: new \ConnectTalk\Service\BusinessHours($this->db),
            autoAssign: false, autoTicket: false, entry: 'geral', generalSector: $this->general,
        );
    }

    public function test_cai_na_fila_geral_sem_mensagem_automatica_e_sem_distribuir(): void
    {
        // a Ana está online no Financeiro: mesmo sendo a única, não recebe sozinha
        $ana = $this->db->insert('talk_users', ['name' => 'Ana', 'email' => 'ana@x.com', 'password_hash' => 'x', 'last_seen_at' => date('Y-m-d H:i:s')]);
        $this->db->insert('talk_staff', ['user_id' => $ana, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $id = (int) $this->inbound('Preciso da segunda via do boleto');
        $this->triage();
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->general, (int) $c['sector_id']);
        $this->assertNull($c['assigned_user_id']);
        $this->assertNull($this->db->one("SELECT id FROM talk_messages WHERE conversation_id = ? AND direction = 'out'", [$id]), 'Sem menu e sem Jack.');
        $this->assertStringStartsWith('SAC', (string) $c['protocol']);
    }

    public function test_todos_veem_a_fila_geral_e_qualquer_um_pega(): void
    {
        $id = (int) $this->inbound('oi');
        $policy = new AccessPolicy($this->general);
        $q = new InboxQuery($this->db, $policy);
        foreach (['Financeiro', 'Manutenção'] as $sector) {
            $u = $this->agent(100 + $this->sectors[$sector], $sector);
            $this->assertTrue($policy->canView($u, $this->conv($id)), $sector);
            $this->assertTrue($policy->canAct($u, $this->conv($id)), $sector);
            $this->assertContains($id, array_map('intval', array_column($q->list($u, 'pending'), 'id')), $sector);
        }
        // pegou: é de quem pegou, some da fila dos outros
        $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = 900 WHERE id = ?", [$id]);
        $this->assertFalse($policy->canView($this->agent(101, 'Financeiro'), $this->conv($id)));
    }

    public function test_numero_dedicado_ao_setor_continua_direto(): void
    {
        $this->db->run('UPDATE talk_channels SET sector_id = ? WHERE id = ?', [$this->sectors['Financeiro'], $this->channelId]);
        $id = (int) $this->inbound('oi');
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
    }
}
