<?php
declare(strict_types=1);

// Placar da triagem da Jack nos cenários fixos (src/Jack/Scenarios.php) e nas sugestões com "setor certo"
// da tela Serviço da Jack. É a régua para comparar as regras de hoje com a IA local (docs/jack/IA.md).
//
//   php bin/jack-cenarios.php                 regras (cenários + sugestões do banco)
//   php bin/jack-cenarios.php --sem-banco     só os cenários fixos, sem precisar do banco
//   php bin/jack-cenarios.php --ia            também a IA local (JACK_AI_URL, JACK_AI_MODEL; padrão gemma3:1b)
//   ex. no Mac com Ollama: JACK_AI_URL=http://localhost:11434 php bin/jack-cenarios.php --sem-banco --ia
use ConnectTalk\Http\CurlHttpClient;
use ConnectTalk\Jack\AiClassifier;
use ConnectTalk\Jack\Scenarios;

$noDb = in_array('--sem-banco', $argv, true);
$withAi = in_array('--ia', $argv, true);
if ($noDb) {
    require __DIR__ . '/../src/autoload.php';
} else {
    $app = require __DIR__ . '/../bootstrap.php';
}

$show = static function (string $title, array $r): void {
    printf("\n%s: %d de %d (%.0f%%)\n", $title, $r['acertos'], $r['total'], $r['taxa'] * 100);
    foreach ($r['erros'] as $e) {
        printf("  - \"%s\": esperado %s, obtido %s\n", str_replace("\n", ' / ', mb_strimwidth($e['frase'], 0, 90, '…')),
            $e['esperado'] ?? 'sem certeza', $e['obtido'] ?? 'sem certeza');
    }
};
$cases = Scenarios::CASES;
if (!$noDb) {
    $fb = Scenarios::fromFeedback($app['db']);
    printf("Cenários fixos: %d · sugestões com setor certo: %d\n", count($cases), count($fb));
    $cases = [...$cases, ...$fb];
}
$rules = Scenarios::rules();
$show('Regras', Scenarios::score($rules, $cases));

if ($withAi) {
    $url = getenv('JACK_AI_URL') ?: 'http://localhost:11434';
    $model = getenv('JACK_AI_MODEL') ?: 'gemma3:1b';
    $ai = new AiClassifier(new CurlHttpClient(max(1, (int) (getenv('JACK_AI_TIMEOUT') ?: 30))), $url, $model);
    $times = [];
    $failed = 0;
    $answers = [];
    echo "\nPerguntando à IA ($model em $url)";
    foreach ($cases as [$text]) {
        $r = $ai->classify($text);
        echo $r === null ? 'x' : '.';
        if ($r === null) {
            $failed++;
        } else {
            $times[] = $r['ms'];
        }
        $answers[$text] = $r;
    }
    echo "\n";
    if ($times === []) {
        fwrite(STDERR, "A IA não respondeu nenhuma vez. Confira se o servidor está no ar ($url) e o nome do modelo ($model).\n");
        exit(1);
    }
    sort($times);
    $iaOnly = static fn (string $t): ?string => ($a = $answers[$t] ?? null) !== null && $a['confident'] ? $a['sector'] : null;
    $iaThenRules = static fn (string $t): ?string => $iaOnly($t) ?? $rules($t);
    $show("IA sozinha ($model)", Scenarios::score($iaOnly, $cases));
    $show('IA com as regras de reserva (como ficaria com JACK_AI=on)', Scenarios::score($iaThenRules, $cases));
    printf("\nTempo da IA: mediana %d ms · máximo %d ms · sem resposta %d\n", $times[intdiv(count($times), 2)], end($times), $failed);
    echo "Lembrete: no Raspberry Pi a IA é mais lenta que no Mac; o tempo que vale é o medido lá.\n";
}
