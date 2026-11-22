<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Unit;

use ConnectTalk\Jack\Scenarios;
use PHPUnit\Framework\TestCase;

/**
 * JV1.2: as frases fixas de triagem. Trava se a Jack piorar.
 * Ao melhorar o classificador, suba o mínimo abaixo para o novo resultado (php tools/jack-cenarios.php).
 */
final class JackScenariosTest extends TestCase
{
    private const MIN_TAXA = 0.70;          // acerto exato da Jack em 22/11/2026: 70,5%
    private const MIN_NO_SETOR_CERTO = 0.86; // onde a conversa termina, contando o setor padrão: 87,6%

    private static function scenarios(): array
    {
        return Scenarios::run(Scenarios::load(__DIR__ . '/../Fixtures/jack/triagem.csv'));
    }

    public function test_nenhum_erro_grave(): void
    {
        $graves = array_map(fn ($m) => "linha {$m['linha']}: \"{$m['frase']}\" era {$m['setor']}, foi {$m['jack']}", self::scenarios()['graves']);
        $this->assertSame([], $graves, 'A Jack mandou com certeza para o setor errado');
    }

    public function test_acerto_nao_cai(): void
    {
        $r = self::scenarios();
        $this->assertGreaterThanOrEqual(100, $r['total'], 'o arquivo de frases encolheu');
        $this->assertGreaterThanOrEqual(self::MIN_TAXA, $r['taxa']);
        $this->assertGreaterThanOrEqual(self::MIN_NO_SETOR_CERTO, $r['no_setor_certo']);
    }

    public function test_so_cumprimento_ou_pedido_vago_nunca_vai_com_certeza(): void
    {
        $this->assertSame(['total' => 20, 'acertos' => 20], self::scenarios()['por_setor']['padrao']);
    }

    public function test_setor_invalido_no_arquivo_e_recusado(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'tri');
        file_put_contents($f, "frase;setor\noi;juridico\n");
        $this->expectException(\InvalidArgumentException::class);
        Scenarios::load($f);
    }
}
