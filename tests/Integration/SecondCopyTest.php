<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Contracts\ChargeDirectory;
use ConnectTalk\Contracts\ContactDirectory;
use ConnectTalk\Erp\NullChargeDirectory;
use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\DomainError;
use ConnectTalk\Service\SecondCopy;

/** Botão "2ª via" (ADR 0005): boletos do ERP na conversa, enviados ao cliente pelo atendente. */
final class SecondCopyTest extends DbTestCase
{
    private object $erp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = $this->makeService(new class implements ContactDirectory {
            public function findByPhone(array $c): ?array
            {
                return in_array('5531999998888', $c, true) ? ['person_id' => 77, 'name' => 'João Morador', 'details' => []] : null;
            }
        });
        $this->erp = new class implements ChargeDirectory {
            public array $asked = [];
            public array $list = [[
                'id' => 501, 'condominium' => 'Residencial Jardins', 'unit' => 'A - 101', 'competence' => '2026-11', 'due_on' => '2026-11-10',
                'amount_cents' => 45090, 'digitable_line' => '23790.00000 00000.000000 00000.000000 1 00000000045090',
                'pix_copy_paste' => '00020126PIXFICTICIO', 'portal_url' => 'https://app.connect.lab/boletos/88',
            ]];
            public bool $down = false;
            public function openCharges(int $personId, string $phone): ?array
            {
                if ($this->down) {
                    throw new \RuntimeException('ERP respondeu 503');
                }
                $this->asked[] = [$personId, $phone];
                return $this->list;
            }
        };
    }

    private function copy(?ChargeDirectory $dir = null): SecondCopy
    {
        return new SecondCopy($this->db, new AccessPolicy(), $this->svc, $dir ?? $this->erp);
    }

    public function test_lista_e_envia_o_boleto_ao_cliente(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $ana = $this->agent(10, 'Financeiro');
        $r = $this->copy()->list($ana, $id);
        $this->assertTrue($r['available']);
        $this->assertSame('R$ 450,90', $r['charges'][0]['amount']);
        $this->assertSame('11/2026', $r['charges'][0]['reference']);
        $this->assertSame([77, '5531999998888'], $this->erp->asked[0]);

        $this->copy()->send($ana, $id, 501, 'Ana');
        $out = $this->lastOutbound($id);
        $this->assertStringStartsWith("*Ana:*\nSegue a 2ª via do boleto do Residencial Jardins, unidade A - 101:", $out);
        $this->assertStringContainsString("Vencimento: 10/11/2026\nValor: R$ 450,90", $out);
        $this->assertStringContainsString("*Linha digitável:*\n23790.00000", $out);
        $this->assertStringContainsString("*Pix copia e cola:*\n00020126PIXFICTICIO", $out);
        $this->assertSame('open', $this->conv($id)['status'], 'mandar a 2ª via aceita a conversa da fila');
    }

    public function test_boleto_que_saiu_do_erp_nao_e_enviado(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $this->erp->list = [];
        $this->expectException(DomainError::class);
        $this->expectExceptionMessage('não está mais em aberto');
        $this->copy()->send($this->agent(10, 'Financeiro'), $id, 501);
    }

    public function test_outro_setor_nao_ve_os_boletos(): void
    {
        $id = $this->routed('preciso da segunda via do boleto');
        $this->expectExceptionMessage('Conversa não encontrada');
        $this->copy()->list($this->agent(11, 'Manutenção'), $id);
    }

    public function test_sem_cadastro_sem_erp_ou_erp_fora_do_ar_explica(): void
    {
        $unknown = $this->routed('preciso da segunda via do boleto', '5511912345678');
        $this->assertStringContainsString('não identificado', $this->copy()->list($this->agent(10, 'Financeiro'), $unknown)['reason']);

        $id = $this->routed('boleto atrasado');
        $this->assertStringContainsString('ERP_API_URL', $this->copy(new NullChargeDirectory())->list($this->agent(10, 'Financeiro'), $id)['reason']);

        $this->erp->list = [];
        $this->assertStringContainsString('Nenhum boleto em aberto', $this->copy()->list($this->agent(10, 'Financeiro'), $id)['reason']);

        $this->erp->down = true;
        try {
            $this->copy()->list($this->agent(10, 'Financeiro'), $id);
            $this->fail('ERP fora do ar precisa avisar');
        } catch (DomainError $e) {
            $this->assertSame(503, $e->getCode());
        }
    }
}
