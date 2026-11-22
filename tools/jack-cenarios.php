<?php
declare(strict_types=1);

// Mede a triagem da Jack nas frases fixas (JV1.2). Não usa banco nem WhatsApp.
//   php tools/jack-cenarios.php [arquivo.csv]
require __DIR__ . '/../src/autoload.php';

use ConnectTalk\Jack\Scenarios;

$file = $argv[1] ?? __DIR__ . '/../tests/Fixtures/jack/triagem.csv';
$r = Scenarios::run(Scenarios::load($file));
printf("Frases: %d · acertos da Jack: %d (%.1f%%) · conversa no setor certo: %.1f%% · erros graves: %d · erros leves: %d\n\n",
    $r['total'], $r['acertos'], 100 * $r['taxa'], 100 * $r['no_setor_certo'], count($r['graves']), count($r['leves']));
foreach ($r['por_setor'] as $s => $p) {
    printf("  %-15s %3d de %3d\n", $s, $p['acertos'], $p['total']);
}
$show = static function (string $title, array $list): void {
    if ($list === []) {
        return;
    }
    echo "\n$title\n";
    foreach ($list as $m) {
        printf("  linha %-4d %-15s -> %-15s %s%s\n", $m['linha'], $m['setor'], $m['jack'], $m['frase'],
            $m['termos'] ? '  [' . implode(', ', $m['termos']) . ']' : '');
    }
};
$show('ERROS GRAVES (mandou com certeza para o setor errado):', $r['graves']);
$show('Erros leves (não teve certeza; vai para o setor padrão):', $r['leves']);
