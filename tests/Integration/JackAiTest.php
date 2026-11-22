<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Erp\NullContactDirectory;
use ConnectTalk\Erp\NullHelpdesk;
use ConnectTalk\Jack\AiClassifier;
use ConnectTalk\Jack\Jack;
use ConnectTalk\Jack\Monitor;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\ConversationService;
use ConnectTalk\Service\Texts;
use ConnectTalk\Tests\Fixtures\FakeHttpClient;

/** IA local da Jack: em sombra só registra; ligada decide quando tem certeza; caída, valem as regras. */
final class JackAiTest extends DbTestCase
{
    private FakeHttpClient $ai;

    private function useAi(string $mode): Jack
    {
        $this->ai = new FakeHttpClient();
        $jack = new Jack($this->db, announceTickets: false, ai: new AiClassifier($this->ai, 'http://jack-ia:8080', 'gemma3:1b'), aiMode: $mode);
        $this->svc = new ConversationService($this->db, new EventBus($this->db), new AccessPolicy(),
            new NullContactDirectory(), new NullHelpdesk(), new Texts(), $jack, $this->media);
        return $jack;
    }

    private function says(string $intent, string $certainty = 'alta'): void
    {
        $content = json_encode(['intencao' => $intent, 'certeza' => $certainty]);
        $this->ai->responses[] = ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => $content]]]])];
    }

    public function test_sombra_registra_a_ia_sem_mudar_a_decisao_e_aparece_na_tela(): void
    {
        $jack = $this->useAi('shadow');
        $this->says('financeiro'); // a IA acha que é Financeiro; as regras mandam para Manutenção
        $id = $this->routed('vazamento no banheiro');
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);

        $rows = $this->db->all('SELECT mode, source, model, intent, suggested_sector_id FROM talk_jack_decisions WHERE conversation_id = ? ORDER BY id', [$id]);
        $this->assertSame(['auto', 'regras'], [$rows[0]['mode'], $rows[0]['source']]);
        $this->assertSame(['shadow', 'ia', 'gemma3:1b', 'financeiro'], [$rows[1]['mode'], $rows[1]['source'], $rows[1]['model'], $rows[1]['intent']]);
        $this->assertSame($this->sectors['Financeiro'], (int) $rows[1]['suggested_sector_id']);

        // os acertos do Painel contam só as decisões reais
        $this->assertSame(1, $jack->stats()['acertos']);
        $o = (new Monitor($this->db, $jack))->overview(new StaffMember(10, true));
        $this->assertSame('shadow', $o['ia_modo']);
        $this->assertSame(1, $o['ia']['erraria']);
        $c = $o['conversas'][0];
        $this->assertSame('erraria', $c['ia']['situacao']);
        $this->assertStringContainsString('Não bate', $c['ia']['texto']);
        $this->assertSame('manutencao', $c['ideia']['intencao']); // a ideia mostrada é a das regras
    }

    public function test_ligada_decide_quando_tem_certeza_e_cai_para_as_regras_sem_ela(): void
    {
        $this->useAi('on');
        $this->says('financeiro');
        $a = $this->routed('fiz o pix e nao baixou', '5531911111111'); // regras: sem certeza
        $this->assertSame($this->sectors['Financeiro'], (int) $this->conv($a)['sector_id']);
        $d = $this->db->one('SELECT source, intent FROM talk_jack_decisions WHERE conversation_id = ?', [$a]);
        $this->assertSame(['ia', 'financeiro'], [$d['source'], $d['intent']]);

        $this->says('manutencao', 'baixa'); // sem certeza: valem as regras
        $b = $this->routed('quero reservar o salão de festas', '5531922222222');
        $this->assertSame($this->sectors['Administrativo'], (int) $this->conv($b)['sector_id']);

        $this->ai->responses[] = new \RuntimeException('timeout'); // IA fora do ar: regras
        $c = $this->routed('o elevador parou', '5531933333333');
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($c)['sector_id']);
    }

    public function test_so_cumprimento_nao_chama_a_ia(): void
    {
        $this->useAi('shadow');
        $this->inbound('bom dia');
        $this->triage(10);
        $this->assertSame([], $this->ai->calls);
    }

    public function test_ligada_nao_passa_por_cima_das_regras_com_certeza(): void
    {
        $this->useAi('on');
        $this->says('financeiro'); // a IA erra; as regras têm certeza de Manutenção
        $id = $this->routed('vazamento no banheiro');
        $this->assertSame($this->sectors['Manutenção'], (int) $this->conv($id)['sector_id']);
        $this->assertSame('regras', $this->db->one("SELECT source FROM talk_jack_decisions WHERE conversation_id = ? AND mode = 'auto'", [$id])['source']);
    }

    public function test_carga_de_teste_mostra_regras_mais_ia(): void
    {
        $this->useAi('shadow');
        $lab = $this->db->insert('talk_channels', ['name' => 'Lab', 'kind' => 'lab', 'purpose' => 'both', 'config_encrypted' => $this->crypto->encrypt([])]);
        for ($i = 0; $i < 20; $i++) {
            $this->says('financeiro'); // a IA sempre diz Financeiro
        }
        $load = new \ConnectTalk\Jack\LoadTest($this->db, $this->svc, $lab);
        $round = $load->run([['frase' => 'fiz o pix e nao baixou', 'setor' => 'financeiro'], ['frase' => 'vazamento no banheiro', 'setor' => 'manutencao']],
            messages: 8, clients: 4, seed: 3);
        $r = $load->report($round);
        $fin = $r['por_setor']['financeiro']['total'];
        $this->assertGreaterThan(0, $fin);
        $this->assertSame(0, $r['por_setor']['financeiro']['certos']); // as regras erram o Pix
        $this->assertEquals(['total' => $r['atendimentos'], 'sozinha_certos' => $fin, 'regras_ia_certos' => $r['atendimentos'], 'regras_ia_graves' => 0, 'consertaria' => $fin],
            array_intersect_key($r['ia'], array_flip(['total', 'sozinha_certos', 'regras_ia_certos', 'regras_ia_graves', 'consertaria'])));
    }
}
