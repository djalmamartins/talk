<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Unit;

use ConnectTalk\Erp\ErpApi;
use ConnectTalk\Erp\HttpContactDirectory;
use ConnectTalk\Erp\HttpHelpdesk;
use ConnectTalk\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ErpApiTest extends TestCase
{
    private FakeHttpClient $http;
    private ErpApi $api;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->api = new ErpApi($this->http, 'https://erp.connect.lab/api/', 'TOKEN');
    }

    public function test_busca_pessoa_pelo_telefone(): void
    {
        $this->http->responses[] = ['status' => 200, 'body' => json_encode(['data' => [[
            'id' => 77, 'name' => 'João Morador', 'relationship' => 'owner',
            'condominium' => ['id' => 3, 'name' => 'Res. Jardins'], 'unit' => ['id' => 9, 'label' => 'Bloco A 101'],
        ], ['id' => 80, 'name' => 'João Morador', 'relationship' => 'tenant', 'condominium' => ['id' => 4, 'name' => 'Ed. Sol'], 'unit' => null]]])];
        $p = (new HttpContactDirectory($this->api))->findByPhone(['5531999998888', '31999998888']);
        $this->assertSame(77, $p['person_id']);
        $this->assertSame('Res. Jardins', $p['details']['condominio']);
        $this->assertSame(2, $p['details']['cadastros']);
        $call = $this->http->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('https://erp.connect.lab/api/talk/people?phones=5531999998888%2C31999998888', $call['url']);
        $this->assertSame('Bearer TOKEN', $call['headers']['Authorization']);
    }

    public function test_pessoa_nao_encontrada_ou_erp_fora_do_ar_nao_quebra(): void
    {
        $this->http->responses[] = ['status' => 404, 'body' => '{"error":{"code":"not_found","message":"Pessoa não encontrada."}}'];
        $this->http->responses[] = new \RuntimeException('timeout');
        $logs = [];
        $dir = new HttpContactDirectory($this->api, function ($m) use (&$logs) { $logs[] = $m; });
        $this->assertNull($dir->findByPhone(['1']));
        $this->assertNull($dir->findByPhone(['1']));
        $this->assertCount(1, $logs);
    }

    public function test_abre_transfere_e_encerra_chamado(): void
    {
        $this->http->responses[] = ['status' => 201, 'body' => '{"data":{"id":500,"protocol":"TK2611140001"}}'];
        $this->http->responses[] = ['status' => 204, 'body' => ''];
        $this->http->responses[] = ['status' => 204, 'body' => ''];
        $hd = new HttpHelpdesk($this->api, 'https://talk.connect.com.br');
        $id = $hd->open(['id' => 12, 'protocol' => '20261114-00001'], ['erp_person_id' => '77', 'phone' => '5531999998888', 'name' => 'João', 'details' => ['condominio_id' => 3, 'unidade_id' => 9]], 'Manutenção');
        $hd->transfer(500, 'Financeiro', 2, 'cobrança do reparo');
        $hd->close(500, 2, 'resolvido');

        $this->assertSame(['id' => 500, 'protocol' => 'TK2611140001', 'url' => null], $id);
        [$open, $tr, $cl] = $this->http->calls;
        $this->assertSame('https://erp.connect.lab/api/talk/tickets', $open['url']);
        $this->assertSame('20261114-00001', $open['body']['talk_protocol']);
        $this->assertSame('technical', $open['body']['area']);
        $this->assertSame(77, $open['body']['resident_id']);
        $this->assertSame(3, $open['body']['condominium_id']);
        $this->assertSame(9, $open['body']['unit_id']);
        $this->assertSame('https://talk.connect.com.br/?c=12', $open['body']['conversation_url']);
        $this->assertSame('https://erp.connect.lab/api/talk/tickets/500/transfer', $tr['url']);
        $this->assertSame('financial', $tr['body']['area']);
        $this->assertSame('https://erp.connect.lab/api/talk/tickets/500/close', $cl['url']);
        $this->assertSame('resolvido', $cl['body']['resolution']);
    }

    public function test_chamado_leva_quem_e_e_a_conversa_e_devolve_o_link(): void
    {
        $this->http->responses[] = ['status' => 201, 'body' => '{"data":{"id":501,"protocol":"CH2611220A0B","tracking_url":"https://erp.connect.lab/suporte/chamado/CH2611220A0B?c=ab12"}}'];
        $hd = new HttpHelpdesk($this->api, 'https://talk.connect.com.br');
        $t = $hd->open(['id' => 12, 'protocol' => 'SAC26112200001', 'opened_at' => '2026-11-22 09:05:00', 'transcript' => "[22/11 09:05] Cliente: o portão quebrou"],
            ['erp_person_id' => '77', 'phone' => '5531999998888', 'name' => 'João', 'details' => ['condominio' => 'Res. Jardins', 'unidade' => 'Bloco A 101']], 'Manutenção');
        $this->assertSame('https://erp.connect.lab/suporte/chamado/CH2611220A0B?c=ab12', $t['url']);
        $msg = $this->http->calls[0]['body']['message'];
        $this->assertStringContainsString('Contato: João (5531999998888)', $msg);
        $this->assertStringContainsString('Condomínio: Res. Jardins, unidade Bloco A 101', $msg);
        $this->assertStringContainsString('Setor no Talk: Manutenção', $msg);
        $this->assertStringContainsString('Início da conversa: 22/11/2026 09:05', $msg);
        $this->assertStringContainsString("Conversa até agora:\n[22/11 09:05] Cliente: o portão quebrou", $msg);

        // link que não é http(s) não vai para o cliente
        $this->http->responses[] = ['status' => 201, 'body' => '{"data":{"id":502,"protocol":"CH2611220A0C","tracking_url":"javascript:alert(1)"}}'];
        $this->assertNull($hd->open(['id' => 13, 'protocol' => 'SAC26112200002'], ['erp_person_id' => null, 'phone' => '1', 'name' => ''], null)['url']);
    }

    public function test_situacao_do_chamado_com_a_ultima_resposta_da_equipe(): void
    {
        $this->http->responses[] = ['status' => 200, 'body' => json_encode(['data' => ['id' => 501, 'protocol' => 'CH2611220A0B', 'status' => 'in_progress',
            'tracking_url' => 'https://erp.connect.lab/suporte/chamado/CH2611220A0B?c=ab12',
            'messages' => [['from_talk' => true, 'created_at' => '2026-11-22 09:05:00'], ['from_talk' => false, 'created_at' => '2026-11-22 10:15:00'], ['from_talk' => true, 'created_at' => '2026-11-22 10:20:00']]]])];
        $this->http->responses[] = ['status' => 404, 'body' => '{"error":{"code":"not_found"}}'];
        $hd = new HttpHelpdesk($this->api, '');
        $s = $hd->status(501);
        $this->assertSame('GET', $this->http->calls[0]['method']);
        $this->assertSame('https://erp.connect.lab/api/talk/tickets/501', $this->http->calls[0]['url']);
        $this->assertSame(['status' => 'in_progress', 'label' => 'em atendimento', 'last_reply_at' => '2026-11-22 10:15:00',
            'url' => 'https://erp.connect.lab/suporte/chamado/CH2611220A0B?c=ab12'], $s);
        $this->assertNull($hd->status(999));
    }

    public function test_erro_do_erp_vira_excecao(): void
    {
        $this->http->responses[] = ['status' => 422, 'body' => '{"error":{"code":"validation_failed","message":"Área inválida."}}'];
        $this->expectExceptionMessage('Área inválida.');
        (new HttpHelpdesk($this->api, ''))->transfer(1, 'X', 1, null);
    }

    public function test_segunda_via_pelo_erp(): void
    {
        $this->http->responses[] = ['status' => 200, 'body' => json_encode(['data' => [[
            'id' => 501, 'condominium' => 'Res. Jardins', 'unit' => 'A-101', 'competence' => '2026-11', 'due_on' => '2026-11-10',
            'amount_cents' => 45090, 'digitable_line' => '', 'pix_copy_paste' => '0002PIX', 'portal_url' => 'https://app/boletos/1',
        ]]])];
        $list = (new \ConnectTalk\Erp\HttpChargeDirectory($this->api))->openCharges(77, '+55 (31) 99999-8888');
        $this->assertSame('https://erp.connect.lab/api/talk/charges?resident_id=77&phone=5531999998888', $this->http->calls[0]['url']);
        $this->assertSame(501, $list[0]['id']);
        $this->assertNull($list[0]['digitable_line'], 'linha vazia vira null');
        $this->assertSame('0002PIX', $list[0]['pix_copy_paste']);

        $this->http->responses[] = ['status' => 404, 'body' => json_encode(['error' => ['code' => 'not_found']])];
        $this->assertSame([], (new \ConnectTalk\Erp\HttpChargeDirectory($this->api))->openCharges(77, '31999998888'), '404 do ERP = sem boleto, sem motivo');
    }
}
