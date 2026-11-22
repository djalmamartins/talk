<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Support\Db;

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
 *
 * score() compara qualquer classificador nas mesmas frases (regras x IA local, bin/jack-cenarios.php --ia;
 * plano em docs/jack/IA.md). As sugestões com "setor certo" da tela Serviço da Jack entram via fromFeedback().
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

    /**
     * Placar de um classificador qualquer. $classify recebe a frase e devolve o setor com certeza, ou null
     * (sem certeza = setor padrão).
     * @param callable(string):?string $classify
     * @param list<array{frase:string, setor:string}> $rows
     * @return array{total:int, acertos:int, taxa:float, no_setor_certo:float, graves:int, erros:list<array{frase:string, esperado:string, obtido:string}>}
     */
    public static function score(callable $classify, array $rows): array
    {
        $final = static fn (string $s): string => $s === 'padrao' ? 'administrativo' : $s;
        $hits = $landed = $graves = 0;
        $misses = [];
        foreach ($rows as $r) {
            $got = $classify($r['frase']) ?? 'padrao';
            $landed += (int) ($final($got) === $final($r['setor']));
            if ($got === $r['setor']) {
                $hits++;
                continue;
            }
            $graves += (int) ($got !== 'padrao');
            $misses[] = ['frase' => $r['frase'], 'esperado' => $r['setor'], 'obtido' => $got];
        }
        $total = count($rows);
        return ['total' => $total, 'acertos' => $hits, 'taxa' => $total ? round($hits / $total, 3) : 0.0,
            'no_setor_certo' => $total ? round($landed / $total, 3) : 0.0, 'graves' => $graves, 'erros' => $misses];
    }

    /** As regras de hoje como classificador para score(): setor só quando confiante. */
    public static function rules(?Classifier $c = null): callable
    {
        $c ??= new Classifier();
        return static function (string $text) use ($c): ?string {
            $r = $c->classify($text);
            return $r['confident'] ? $r['sector'] : null;
        };
    }

    /**
     * Sugestões da tela Serviço da Jack com "setor certo": as primeiras mensagens do cliente na conversa e o
     * setor que deveria ter sido.
     * @return list<array{frase:string, setor:string, linha:int}>
     */
    public static function fromFeedback(Db $db): array
    {
        $out = [];
        foreach ($db->all(
            "SELECT f.conversation_id, s.jack_key, s.name FROM talk_jack_feedback f JOIN talk_sectors s ON s.id = f.expected_sector_id
              WHERE f.conversation_id IS NOT NULL AND f.status <> 'descartada' ORDER BY f.id"
        ) as $f) {
            $text = implode("\n", array_column($db->all(
                "SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'in' AND type = 'text' AND body IS NOT NULL ORDER BY id LIMIT 5",
                [$f['conversation_id']]
            ), 'body'));
            $setor = $f['jack_key'] ?: str_replace(' ', '', Classifier::normalize((string) $f['name']));
            if ($text !== '' && in_array($setor, self::SECTORS, true)) {
                $out[] = ['frase' => $text, 'setor' => $setor, 'linha' => 0];
            }
        }
        return $out;
    }
}
