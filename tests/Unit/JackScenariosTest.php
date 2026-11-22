<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Unit;

use ConnectTalk\Jack\Scenarios;
use PHPUnit\Framework\TestCase;

/** Cenários fixos de triagem (IA1): régua para as regras de hoje e para a IA depois. */
final class JackScenariosTest extends TestCase
{
    /** Regras em 22/11/2026: 36 de 44 (82%). Subir quando melhorar; nunca baixar sem decidir. */
    private const MINIMO_DAS_REGRAS = 0.80;

    public function test_regras_acertam_pelo_menos_o_minimo_combinado(): void
    {
        $r = Scenarios::score(Scenarios::rules());
        $detalhe = implode("\n", array_map(fn ($e) => sprintf('"%s": esperado %s, regras %s', $e['frase'], $e['esperado'] ?? 'sem certeza', $e['obtido'] ?? 'sem certeza'), $r['erros']));
        $this->assertGreaterThanOrEqual(self::MINIMO_DAS_REGRAS, $r['taxa'], $detalhe);
    }

    public function test_cenarios_usam_so_setores_da_lista_fechada(): void
    {
        foreach (Scenarios::CASES as [$text, $sector]) {
            $this->assertContains($sector, [null, 'financeiro', 'administrativo', 'manutencao'], $text);
        }
        $this->assertGreaterThanOrEqual(40, count(Scenarios::CASES));
    }

    public function test_placar_conta_acertos_e_erros(): void
    {
        $r = Scenarios::score(fn (string $t) => 'financeiro', [['a', 'financeiro'], ['b', null]]);
        $this->assertSame(1, $r['acertos']);
        $this->assertSame(0.5, $r['taxa']);
        $this->assertSame('b', $r['erros'][0]['frase']);
    }
}
