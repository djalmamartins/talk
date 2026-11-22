<?php
declare(strict_types=1);

// Placar da triagem da Jack: regras x IA local (docs/jack/IA.md), nas frases fixas da JV1.2
// (tests/Fixtures/jack/) e nas sugestões com "setor certo" da tela Serviço da Jack.
// A comparação que vale é a das frases de CONFERÊNCIA: não foram usadas para ajustar as regras.
// (Detalhe dos erros das regras por linha: php tools/jack-cenarios.php.)
//
//   php bin/jack-cenarios.php                 regras (frases fixas + sugestões do banco)
//   php bin/jack-cenarios.php --sem-banco     só as frases fixas, sem precisar do banco
//   php bin/jack-cenarios.php --ia            também a IA local (JACK_AI_URL, JACK_AI_MODEL; padrão gemma3:1b)
//   php bin/jack-cenarios.php --frases=DIR    pasta com triagem.csv e triagem-conferencia.csv (no container do Pi)
//   ex. no Mac com Ollama: JACK_AI_URL=http://localhost:11434 php bin/jack-cenarios.php --sem-banco --ia
use ConnectTalk\Http\CurlHttpClient;
use ConnectTalk\Jack\AiClassifier;
use ConnectTalk\Jack\Scenarios;

$noDb = in_array('--sem-banco', $argv, true);
$withAi = in_array('--ia', $argv, true);
$dir = __DIR__ . '/../tests/Fixtures/jack';
foreach ($argv as $a) {
    if (str_starts_with($a, '--frases=')) {
        $dir = substr($a, 9);
    }
}
if ($noDb) {
    require __DIR__ . '/../src/autoload.php';
} else {
    $app = require __DIR__ . '/../bootstrap.php';
}

$sets = [];
foreach (['Conferência (não usadas no ajuste)' => 'triagem-conferencia.csv', 'Ajuste (as regras foram afinadas nelas)' => 'triagem.csv'] as $name => $file) {
    if (is_file("$dir/$file")) {
        $sets[$name] = Scenarios::load("$dir/$file");
    }
}
if ($sets === []) {
    fwrite(STDERR, "Não achei as frases em $dir. No container do Pi: docker compose cp ../tests/Fixtures/jack app:/tmp/jack e use --frases=/tmp/jack\n");
    exit(1);
}
if (!$noDb && ($fb = Scenarios::fromFeedback($app['db'])) !== []) {
    $sets['Sugestões com setor certo (tela Serviço da Jack)'] = $fb;
}

$line = static function (string $who, array $r): void {
    printf("  %-34s %3d de %3d exato (%3.0f%%) · setor certo %3.0f%% · erros graves %d\n",
        $who, $r['acertos'], $r['total'], $r['taxa'] * 100, $r['no_setor_certo'] * 100, $r['graves']);
};
$rules = Scenarios::rules();
$scorers = ['Regras' => $rules];

if ($withAi) {
    $url = getenv('JACK_AI_URL') ?: 'http://localhost:11434';
    $model = getenv('JACK_AI_MODEL') ?: 'gemma3:1b';
    $ai = new AiClassifier(new CurlHttpClient(max(1, (int) (getenv('JACK_AI_TIMEOUT') ?: 30))), $url, $model);
    $times = [];
    $failed = 0;
    $answers = [];
    $n = array_sum(array_map('count', $sets));
    echo "Perguntando à IA ($model em $url), $n frases";
    foreach ($sets as $rows) {
        foreach ($rows as $row) {
            $r = $ai->classify($row['frase']);
            echo $r === null ? 'x' : '.';
            if ($r === null) {
                $failed++;
            } else {
                $times[] = $r['ms'];
            }
            $answers[$row['frase']] = $r;
        }
    }
    echo "\n";
    if ($times === []) {
        fwrite(STDERR, "A IA não respondeu nenhuma vez. Confira se o servidor está no ar ($url) e o nome do modelo ($model).\n");
        exit(1);
    }
    sort($times);
    $iaOnly = static fn (string $t): ?string => ($a = $answers[$t] ?? null) !== null && $a['confident'] ? $a['sector'] : null;
    $scorers["IA sozinha ($model)"] = $iaOnly;
    $scorers['IA + regras (como JACK_AI=on)'] = static fn (string $t): ?string => $iaOnly($t) ?? $rules($t);
}

foreach ($sets as $name => $rows) {
    echo "\n$name\n";
    $results = [];
    foreach ($scorers as $who => $fn) {
        $line($who, $results[$who] = Scenarios::score($fn, $rows));
    }
    if ($withAi && str_starts_with($name, 'Conferência')) {
        echo "  Onde a IA (com regras de reserva) errou:\n";
        foreach (end($results)['erros'] as $e) {
            printf("    - \"%s\": certo %s, deu %s\n", mb_strimwidth(str_replace("\n", ' / ', $e['frase']), 0, 80, '…'), $e['esperado'], $e['obtido']);
        }
    }
}
if ($withAi) {
    printf("\nTempo da IA: mediana %d ms · máximo %d ms · sem resposta %d\n", $times[intdiv(count($times), 2)], end($times), $failed);
    echo "Erro grave = mandou com certeza para o setor errado (o cliente cai na fila errada). Ligar a IA só se não tiver mais graves que as regras.\n";
    echo "Lembrete: no Raspberry Pi a IA é mais lenta que no Mac; o tempo que vale é o medido lá.\n";
}
