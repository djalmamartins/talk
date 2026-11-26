<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Realtime\EventBus;

/** 1.0 sem a Jack (escolha do Djalma, 22/11/2026): menu numerado de setores no WhatsApp. */
final class SectorMenuTest extends DbTestCase
{
    protected bool $autoTicket = false;

    protected function makeService(?ContactDirectory $dir = null, ?Helpdesk $hd = null): ConversationService
    {
        return new ConversationService(
            $this->db, new EventBus($this->db), new AccessPolicy(),
            $dir ?? new \ConnectTalk\Erp\NullContactDirectory(), $hd ?? new NullHelpdesk(), new \ConnectTalk\Service\Texts(),
            new \ConnectTalk\Jack\Jack($this->db, mode: 'off'),
            $this->media, hours: new \ConnectTalk\Service\BusinessHours($this->db),
            autoTicket: false, entry: 'menu', menuTimeoutSeconds: 600,
        );
    }

    private function outs(int $conv): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id", [$conv]), 'body');
    }

    public function test_manda_o_menu_e_encaminha_pelo_numero(): void
    {
        $id = (int) $this->inbound('Bom dia, preciso de ajuda');
        $c = $this->conv($id);
        $this->assertSame('bot', $c['status'], 'Espera a escolha, fora das filas.');
        $menu = $this->outs($id)[0];
        $this->assertStringContainsString('Oi, Maria!', $menu);
        $this->assertStringContainsString("*1* - Financeiro\n*2* - Administrativo\n*3* - Manutenção", $menu);
        $this->assertStringNotContainsString('Geral', $menu);
        $this->assertStringNotContainsString('Jack', $menu);
        $this->triage(); // o worker não encaminha quem está escolhendo
        $this->assertSame('bot', $this->conv($id)['status']);

        $this->inbound('3');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Manutenção'], (int) $c['sector_id']);
    }

    public function test_aceita_o_nome_e_o_emoji_e_repete_uma_vez(): void
    {
        $id = (int) $this->inbound('oi');
        $this->inbound('quero falar com alguém');
        $this->assertCount(2, $this->outs($id));
        $this->assertStringStartsWith('Não entendi', $this->outs($id)[1]);
        $this->inbound('financeiro');
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);

        $b = (int) $this->inbound('oi', '5531922222222');
        $this->inbound("2\u{FE0F}\u{20E3}", '5531922222222');
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($b)['sector_id']);
    }

    public function test_sem_escolher_vai_para_o_setor_padrao(): void
    {
        $id = (int) $this->inbound('oi');
        $this->inbound('???');
        $this->inbound('não sei');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status'], 'Depois de 2 menus não insiste.');
        $this->assertSame($this->sectors['Administrativo'], (int) $c['sector_id']);

        $quiet = (int) $this->inbound('oi', '5531933333333');
        $this->assertSame(0, $this->svc->triage(60));
        $this->assertSame('bot', $this->conv($quiet)['status']);
        $this->svc->triage(601);
        $this->assertSame('pending', $this->conv($quiet)['status'], 'Calado 10 minutos: não fica esquecido.');
    }

    public function test_numero_dedicado_ao_setor_nao_passa_pelo_menu(): void
    {
        $this->db->run('UPDATE talk_channels SET sector_id = ? WHERE id = ?', [$this->sectors['Financeiro'], $this->channelId]);
        $id = (int) $this->inbound('oi');
        $this->assertSame([], $this->outs($id));
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
    }

    public function test_menu_pick(): void
    {
        $s = [['id' => 1, 'name' => 'Financeiro', 'menu_key' => '1'], ['id' => 3, 'name' => 'Manutenção', 'menu_key' => '3']];
        $this->assertSame(1, ConversationService::menuPick(' 1 ', $s)['id']);
        $this->assertSame(3, ConversationService::menuPick('Opção 3', $s)['id']);
        $this->assertSame(3, ConversationService::menuPick('MANUTENCAO', $s)['id']);
        $this->assertNull(ConversationService::menuPick('2', $s));
        $this->assertNull(ConversationService::menuPick('1 boleto atrasado', $s), 'Frase com número não é escolha.');
    }
}
