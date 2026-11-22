<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

/**
 * Laboratório mínimo da Jack (JV1.2): frases fixas com o setor certo de cada uma, para medir a triagem
 * sem mandar nada para ninguém. Arquivo: tests/Fixtures/jack/triagem.csv ("frase;setor").
 * setor = financeiro | administrativo | manutencao | padrao (a Jack não deve ter certeza).
 *
 * Tipos de erro:
 *  - grave: ela mandou com certeza para o setor errado (o cliente cai na fila errada);
 *  - leve:  tinha assunto claro e ela não teve certeza (vai para o setor padrão e alguém transfere).
 * "no_setor_certo" conta onde a conversa termina: "padrao" cai no Administrativo, então um assunto
 * administrativo sem certeza ainda chega ao lugar certo.
 */
final class Scenarios
{
    public const SECTORS = ['financeiro', 'administrativo', 'manutencao', 'padrao'];

    /** @return list<array{frase:string, setor:string, linha:int}> */
    public static function load(string $file): array
    {
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || $i === 0) {
                continue;
            }
            $cut = strrpos($line, ';');
            $setor = $cut === false ? '' : trim(substr($line, $cut + 1));
            if (!in_array($setor, self::SECTORS, true)) {
                throw new \InvalidArgumentException("triagem.csv linha " . ($i + 1) . ": setor \"$setor\" inválido");
            }
            $rows[] = ['frase' => trim(substr($line, 0, $cut)), 'setor' => $setor, 'linha' => $i + 1];
        }
        return $rows;
    }

    /**
     * @param list<array{frase:string, setor:string, linha:int}> $rows
     * @return array{total:int, acertos:int, taxa:float, no_setor_certo:float, graves:list<array>, leves:list<array>, por_setor:array<string,array{total:int,acertos:int}>}
     */
    public static function run(array $rows, Classifier $classifier = new Classifier()): array
    {
        $graves = $leves = [];
        $hits = $landed = 0;
        $final = static fn (string $s): string => $s === 'padrao' ? 'administrativo' : $s;
        $per = array_fill_keys(self::SECTORS, ['total' => 0, 'acertos' => 0]);
        foreach ($rows as $r) {
            $c = $classifier->classify($r['frase']);
            $got = $c['confident'] && $c['sector'] !== null ? $c['sector'] : 'padrao';
            $per[$r['setor']]['total']++;
            $landed += (int) ($final($got) === $final($r['setor']));
            if ($got === $r['setor']) {
                $hits++;
                $per[$r['setor']]['acertos']++;
                continue;
            }
            $miss = $r + ['jack' => $got, 'termos' => $c['matched']];
            if ($got === 'padrao') {
                $leves[] = $miss;
            } else {
                $graves[] = $miss;
            }
        }
        $total = count($rows);
        return ['total' => $total, 'acertos' => $hits, 'taxa' => $total ? round($hits / $total, 3) : 0.0,
            'no_setor_certo' => $total ? round($landed / $total, 3) : 0.0,
            'graves' => $graves, 'leves' => $leves, 'por_setor' => $per];
    }
}
