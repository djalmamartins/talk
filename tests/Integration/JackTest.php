<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Erp\NullContactDirectory;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Jack\Jack;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\Texts;

final class JackTest extends DbTestCase
{
    private Jack $jack;

    private function withJack(string $mode): void
    {
        $this->jack = new Jack($this->db, mode: $mode);
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(), new NullContactDirectory(), new NullHelpdesk(), new Texts(), $this->jack);
    }

    private function notes(int $convId): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND type = 'jack' ORDER BY id", [$convId]), 'body');
    }

    public function test_modo_sombra_so_sugere_em_nota_interna_e_nao_fala_com_o_cliente(): void
    {
        $this->withJack('shadow');
        $id = $this->inbound('Passei no corredor e tem água no chão');
        $c = $this->conv($id);
        $this->assertSame('bot', $c['status'], 'no modo sombra quem decide é o menu');
        $this->assertNull($c['sector_id']);
        $this->assertCount(1, $this->notes($id));
        $this->assertStringContainsString('Sugestão: Manutenção', $this->notes($id)[0]);
        $this->assertStringContainsString('Seu protocolo', $this->lastOutbound($id), 'cliente só recebe o menu de sempre');
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM talk_messages WHERE conversation_id = ? AND direction = 'out' AND body LIKE '%Jack%'", [$id])['n']);
    }

    public function test_nao_repete_a_mesma_sugestao(): void
    {
        $this->withJack('shadow');
        $id = $this->inbound('o elevador parou');
        $this->inbound('o elevador continua parado');
        $this->assertCount(1, $this->notes($id));
    }

    public function test_modo_automatico_direciona_em_silencio(): void
    {
        $this->withJack('auto');
        $id = $this->inbound('Bom dia, estava precisando do meu boleto');
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Financeiro'], (int) $c['sector_id']);
        $this->assertStringContainsString('Direcionado para Financeiro', $this->notes($id)[0]);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM talk_outbox o JOIN talk_messages m ON m.id = o.message_id WHERE m.conversation_id = ?", [$id])['n'], 'nada enviado ao cliente');
    }

    public function test_modo_automatico_sem_confianca_segue_o_menu(): void
    {
        $this->withJack('auto');
        $id = $this->inbound('tô tendo problema aqui no condomínio');
        $this->assertSame('bot', $this->conv($id)['status']);
        $this->assertSame([], $this->notes($id));
        $this->assertStringContainsString('Seu protocolo', $this->lastOutbound($id));
    }

    public function test_acertos_usam_o_setor_final_inclusive_depois_de_transferencia(): void
    {
        $this->withJack('shadow');
        $a = $this->inbound('o portão da garagem quebrou', '5531911111111');
        // palavra-chave do setor já direcionou para Manutenção (acertou)
        $b = $this->inbound('quero um orçamento para o condomínio', '5531922222222');
        $this->inbound('3', '5531922222222'); // cliente escolheu Manutenção…
        $this->svc->transfer($b, $this->agent(9, 'Manutenção'), $this->sectors['Administrativo'], 'é cotação'); // …e o humano corrigiu
        $this->inbound('tem uma infiltração no teto', '5531944444444');
        $this->inbound('1', '5531944444444'); // cliente escolheu Financeiro e ninguém corrigiu: conta como erro
        $this->inbound('o interfone não toca', '5531933333333'); // ainda sem setor final: fora da conta

        $s = $this->jack->stats();
        $this->assertSame(4, $s['com_sugestao']);
        $this->assertSame(2, $s['acertos']);
        $this->assertSame(1, $s['erros']);
        $this->assertSame(0.667, $s['taxa']);
        $this->assertNotNull($a);
    }

    public function test_desligada_nao_faz_nada(): void
    {
        $this->withJack('off');
        $id = $this->inbound('vazamento no teto');
        $this->assertSame([], $this->notes($id));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM talk_jack_decisions')['n']);
    }

    private function outs(int $convId): array
    {
        return array_column($this->db->all("SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'out' ORDER BY id", [$convId]), 'body');
    }

    public function test_perguntou_se_e_robo_ela_se_apresenta_e_depois_passa_o_que_nao_sabe(): void
    {
        $this->withJack('shadow');
        $id = $this->inbound('oi');
        $this->inbound('vc é um robô?');
        $outs = $this->outs($id);
        $this->assertStringContainsString('Eu sou a inteligência artificial do time da Connect', end($outs));
        $this->assertStringStartsWith('*Jaqueline:*', end($outs));

        $this->inbound('e quem é o síndico do meu prédio agora?');
        $outs = $this->outs($id);
        $this->assertStringContainsString('fora do meu escopo de treinamento', end($outs));
        $this->assertStringContainsString('*Administrativo*', end($outs));
        $c = $this->conv($id);
        $this->assertSame('pending', $c['status']);
        $this->assertSame($this->sectors['Administrativo'], (int) $c['sector_id']);

        // não repete
        $this->inbound('você é robô mesmo?');
        $this->inbound('outra pergunta qualquer');
        $this->assertCount(3, $this->outs($id), 'menu, apresentação, fora do escopo e nada mais');
    }

    public function test_depois_da_apresentacao_assunto_claro_segue_o_fluxo_normal(): void
    {
        $this->withJack('shadow');
        $id = $this->inbound('é uma pessoa de verdade?');
        $this->assertStringContainsString('Jaqueline', $this->outs($id)[0]);
        $this->assertStringContainsString('Seu protocolo', $this->outs($id)[1], 'primeira mensagem também recebe protocolo e menu');
        $this->inbound('preciso da segunda via do boleto');
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($id)['sector_id']);
        $this->assertStringNotContainsString('escopo', implode(' ', $this->outs($id)));
    }

    public function test_com_atendente_humano_ela_nao_responde(): void
    {
        $this->withJack('shadow');
        $id = $this->inbound('preciso da segunda via do boleto');
        $this->svc->accept($id, $this->agent(5, 'Financeiro'));
        $this->inbound('você é um robô?');
        $this->assertStringNotContainsString('inteligência artificial', implode(' ', $this->outs($id)));
    }

    public function test_reconhece_perguntas_sobre_robo(): void
    {
        foreach (['Você é um robô?', 'isso é bot?', 'estou falando com uma pessoa?', 'vc é IA?', 'é humano?', 'é inteligência artificial?'] as $t) {
            $this->assertTrue(Jack::asksIfBot($t), $t);
        }
        $this->assertFalse(Jack::asksIfBot('eu ia pagar amanhã'));
        $this->assertFalse(Jack::asksIfBot('preciso de uma pessoa para consertar o portão'));
    }
}
