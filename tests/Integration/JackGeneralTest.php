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
use ConnectTalk\Service\Texts;

/** Setor Geral (16/11/2026): toda conversa nova espera no Geral e a Jack distribui depois de 1 minuto. */
final class JackGeneralTest extends DbTestCase
{
    private int $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->general = $this->db->insert('talk_sectors', ['name' => 'Geral', 'jack_key' => 'geral', 'menu_key' => '0']);
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(),
            new NullHelpdesk(), new Texts(), new Jack($this->db, mode: 'auto', generalSeconds: 60));
    }

    private function outs(int $convId): int
    {
        return (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE conversation_id = ? AND direction = 'out'", [$convId])['n'];
    }

    public function test_mensagem_nova_cai_na_fila_do_geral_e_a_jack_distribui_depois_de_um_minuto(): void
    {
        $id = (int) $this->inbound('o portão da garagem quebrou');
        $c = $this->conv($id);
        $this->assertSame($this->general, (int) $c['sector_id']);
        $this->assertSame('pending', $c['status']);
        $this->assertSame(1, (int) $c['jack_pending']);
        $queuedAt = $c['queued_at'];
        // quem atende o Geral já vê a conversa
        $this->assertTrue((new AccessPolicy())->canView(new StaffMember(50, false, [$this->general]), $c));

        $this->assertSame(0, $this->triage(10)); // assunto claro, mas ainda não deu 1 minuto
        $this->assertSame($this->general, (int) $this->conv($id)['sector_id']);

        $this->assertSame(1, $this->triage(65));
        $c = $this->conv($id);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
        $this->assertSame('pending', $c['status']);
        $this->assertSame(0, (int) $c['jack_pending']);
        $this->assertSame($queuedAt, $c['queued_at']); // a espera conta desde que chegou
        $this->assertSame(0, $this->outs($id));        // encaminha calada
    }

    public function test_so_cumprimentou_ou_assunto_desconhecido_vai_para_o_administrativo(): void
    {
        $a = (int) $this->inbound('bom dia', '5531911111111');
        $b = (int) $this->inbound('preciso falar com vocês', '5531922222222');
        $this->assertSame(2, $this->triage(65));
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($a)['sector_id']);
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($b)['sector_id']);
    }

    public function test_atendente_que_pega_no_geral_fica_com_a_conversa(): void
    {
        $id = (int) $this->inbound('segunda via do boleto');
        $this->svc->accept($id, new StaffMember(50, false, [$this->general]));
        $this->assertSame(0, $this->triage(65));
        $c = $this->conv($id);
        $this->assertSame('open', $c['status']);
        $this->assertSame($this->general, (int) $c['sector_id']);
        $this->assertSame(0, (int) $c['jack_pending']);
    }

    public function test_transferida_do_geral_antes_da_jack_nao_volta_para_a_triagem(): void
    {
        $id = (int) $this->inbound('segunda via do boleto');
        $this->svc->transfer($id, new StaffMember(1, true), $this->sectors['Manutenção'], 'teste');
        $this->assertSame(0, $this->triage(65));
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
    }

    public function test_numero_de_um_setor_nao_passa_pelo_geral(): void
    {
        $this->db->run('UPDATE talk_channels SET sector_id = ? WHERE id = ?', [$this->sectors['Financeiro'], $this->channelId]);
        $id = (int) $this->inbound('o portão quebrou');
        $this->assertSame('bot', $this->conv($id)['status']);
        $this->triage(10);
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
    }

    public function test_jack_desligada_nao_usa_o_geral(): void
    {
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(),
            new NullHelpdesk(), new Texts(), new Jack($this->db, mode: 'off'));
        $id = (int) $this->inbound('o portão quebrou');
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($id)['sector_id']);
    }

    public function test_fora_do_horario_a_jack_desliga_e_distribui_quando_abre(): void
    {
        $hours = new \ConnectTalk\Service\BusinessHours($this->db);
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(),
            new NullHelpdesk(), new Texts(), new Jack($this->db, mode: 'auto', generalSeconds: 60), hours: $hours);
        // empresa fechada o dia todo hoje; abre só amanhã das 8h às 18h
        $now = new \DateTimeImmutable($this->db->one('SELECT NOW() n')['n']);
        $week = array_fill_keys(array_map('strval', range(0, 6)), []);
        $week[$now->modify('+1 day')->format('w')] = [['08:00', '18:00']];
        $hours->save(new StaffMember(1, true), ['enabled' => true, 'week' => $week]);

        $id = (int) $this->inbound('o portão da garagem quebrou');
        $this->inbound('vc é um robô?'); // desligada: não se apresenta
        $this->assertSame(0, $this->triage(600));            // passou 10 min e ela não distribuiu
        $c = $this->conv($id);
        $this->assertSame($this->general, (int) $c['sector_id']);
        $this->assertSame(1, (int) $c['jack_pending']);
        $outs = $this->db->all("SELECT type FROM talk_messages WHERE conversation_id = ? AND direction = 'out'", [$id]);
        $this->assertSame(['off_hours'], array_column($outs, 'type')); // só o aviso de fora do horário, uma vez
        $this->triage(700);
        $this->assertSame(1, $this->outs($id));

        // amanhã às 9h a empresa abre: ela distribui o que chegou durante a noite, calada
        $tomorrow9 = $now->modify('+1 day')->setTime(9, 0);
        $shift = $tomorrow9->getTimestamp() - $now->getTimestamp();
        $this->assertSame(1, $this->triage($shift));
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
        $this->assertSame(1, $this->outs($id));
    }

    public function test_com_o_controle_de_horario_desligado_ela_trabalha_sempre(): void
    {
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(),
            new NullHelpdesk(), new Texts(), new Jack($this->db, mode: 'auto', generalSeconds: 60),
            hours: new \ConnectTalk\Service\BusinessHours($this->db));
        $id = (int) $this->inbound('o portão quebrou');
        $this->assertSame(1, $this->triage(65));
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
    }
}
