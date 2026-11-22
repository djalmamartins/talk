<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Contracts\Helpdesk;
use ConnectTalk\Erp\TestSafeDirectory;
use ConnectTalk\Erp\TestSafeHelpdesk;
use ConnectTalk\Gateway\GatewayException;
use ConnectTalk\Jack\LoadTest;
use ConnectTalk\Service\OutboxWorker;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Support\Phone;

/** Carga de teste da Jack: só número simulado e número de teste; nada sai pelo WhatsApp nem vai ao ERP. */
final class JackLoadTest extends DbTestCase
{
    private const ROWS = [
        ['frase' => 'o elevador parou', 'setor' => 'manutencao'],
        ['frase' => 'preciso da segunda via do boleto', 'setor' => 'financeiro'],
        ['frase' => 'quero reservar o salão de festas', 'setor' => 'administrativo'],
        ['frase' => 'tô com um problema aqui', 'setor' => 'padrao'],
        ['frase' => 'a grade da varanda soltou', 'setor' => 'manutencao'], // as regras não entendem: vai para o padrão
    ];

    private function labChannel(): int
    {
        return $this->db->insert('talk_channels', ['name' => 'Laboratório', 'kind' => 'lab', 'purpose' => 'both',
            'config_encrypted' => $this->crypto->encrypt([])]);
    }

    public function test_numero_de_teste_e_ddd_00(): void
    {
        $this->assertTrue(Phone::isTest(LoadTest::phone(1)));
        $this->assertSame('5500900000060', LoadTest::phone(60));
        $this->assertFalse(Phone::isTest('5531999998888'));
        $this->assertFalse(Phone::isTest('(31) 99999-8888'));
    }

    public function test_so_roda_em_numero_simulado(): void
    {
        $this->expectException(\DomainException::class);
        new LoadTest($this->db, $this->svc, $this->channelId); // canal de QR Code de verdade
    }

    public function test_carga_confere_encerra_e_reteste_usa_outro_cliente(): void
    {
        $load = new LoadTest($this->db, $this->svc, $this->labChannel());
        $round = $load->run(self::ROWS, messages: 30, clients: 6, seed: 7);
        $r = $load->report($round);

        $this->assertGreaterThanOrEqual(15, $r['atendimentos']);
        $this->assertSame(30, $r['mensagens']);
        $this->assertSame(6, $r['clientes']);
        $this->assertSame(0, $r['graves']);
        $this->assertGreaterThan(0, count($r['erros'])); // a frase da varanda
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM talk_conversations WHERE status <> 'closed'")['n']);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM talk_contacts WHERE phone NOT LIKE '5500%'")['n']);

        $phones = array_column($this->db->all('SELECT DISTINCT phone FROM talk_jack_carga WHERE rodada = ?', [$round]), 'phone');
        $re = $load->retest($round);
        $this->assertSame(count($r['erros']), $re['frases']);
        $new = array_column($this->db->all('SELECT phone FROM talk_jack_carga WHERE rodada = ?', [$re['rodada']]), 'phone');
        $this->assertSame([], array_intersect($phones, $new), 'reteste vem de outro cliente');
        $this->assertCount(count($new), array_unique($new));
        $this->assertSame(0, $load->retest($round)['frases'], 'não retesta duas vezes a mesma frase');
    }

    public function test_whatsapp_de_verdade_recusa_numero_de_teste(): void
    {
        // mesmo que uma mensagem de teste caia num número de QR Code de verdade, ela não sai
        $id = $this->inbound('o elevador parou', LoadTest::phone(1));
        $this->triage();
        $this->svc->queueOutbound($this->conv($id), 'teste', null);
        (new OutboxWorker($this->db, $this->gateways(), new EventBus($this->db)))->runOnce();
        $this->assertSame([], $this->http->calls, 'nada foi mandado à Evolution');
        $this->assertSame('failed', $this->db->one("SELECT status FROM talk_outbox ORDER BY id DESC LIMIT 1")['status']);

        $this->expectException(GatewayException::class);
        $this->gateways()->forChannel($this->channelId)->sendText(LoadTest::phone(2), 'oi');
    }

    public function test_erp_nao_recebe_numero_de_teste(): void
    {
        $hd = new class implements Helpdesk {
            public int $opened = 0;
            public function open(array $conversation, array $contact, ?string $sectorName): ?array { $this->opened++; return ['id' => 1, 'protocol' => 'X']; }
            public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void {}
            public function close(int $ticketId, int $byUserId, ?string $resolution): void {}
            public function status(int $ticketId): ?array { return null; }
        };
        $dir = new class implements ContactDirectory {
            public int $asked = 0;
            public function findByPhone(array $phoneCandidates): ?array { $this->asked++; return null; }
        };
        $safe = new TestSafeHelpdesk($hd);
        $this->assertNull($safe->open([], ['phone' => LoadTest::phone(1)], 'Manutenção'));
        $this->assertSame(['id' => 1, 'protocol' => 'X'], $safe->open([], ['phone' => '5531999998888'], 'Manutenção'));
        $this->assertSame(1, $hd->opened);
        $this->assertNull((new TestSafeDirectory($dir))->findByPhone(Phone::candidates(LoadTest::phone(1))));
        $this->assertSame(0, $dir->asked);
    }

    public function test_carga_roda_mesmo_com_a_empresa_fechada(): void
    {
        // makeService() já usa BusinessHours: fechado hoje, abre só amanhã
        $now = new \DateTimeImmutable($this->db->one('SELECT NOW() n')['n']);
        $week = array_fill_keys(array_map('strval', range(0, 6)), []);
        $week[$now->modify('+1 day')->format('w')] = [['08:00', '18:00']];
        (new \ConnectTalk\Service\BusinessHours($this->db))->save(new \ConnectTalk\Domain\StaffMember(1, true), ['enabled' => true, 'week' => $week]);
        $load = new LoadTest($this->db, $this->svc, $this->labChannel());
        $r = $load->report($load->run([self::ROWS[0]], messages: 3, clients: 3, seed: 1));
        $this->assertGreaterThan(0, $r['atendimentos']);
        $this->assertSame($r['atendimentos'], $r['certos']);
    }
}
