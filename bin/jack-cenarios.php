<?php
declare(strict_types=1);

// Placar da triagem da Jack nos cenários fixos (src/Jack/Scenarios.php) e nas sugestões com "setor certo"
// da tela Serviço da Jack. Uso: php bin/jack-cenarios.php  (sem banco: php bin/jack-cenarios.php --sem-banco)
// É a régua para comparar as regras de hoje com a IA (docs/jack/IA.md).
use ConnectTalk\Jack\Scenarios;

$noDb = in_array('--sem-banco', $argv, true);
if ($noDb) {
    require __DIR__ . '/../src/autoload.php';
} else {
    $app = require __DIR__ . '/../bootstrap.php';
}

$show = static function (string $title, array $r): void {
    printf("%s: %d de %d (%.0f%%)\n", $title, $r['acertos'], $r['total'], $r['taxa'] * 100);
    foreach ($r['erros'] as $e) {
        printf("  - \"%s\": esperado %s, obtido %s\n", str_replace("\n", ' / ', mb_strimwidth($e['frase'], 0, 90, '…')),
            $e['esperado'] ?? 'sem certeza', $e['obtido'] ?? 'sem certeza');
    }
};
$rules = Scenarios::rules();
$show('Regras nos cenários fixos', Scenarios::score($rules));
if (!$noDb) {
    $fb = Scenarios::fromFeedback($app['db']);
    $fb === [] ? print("Sugestões com setor certo: nenhuma ainda.\n") : $show('Regras nas sugestões com setor certo', Scenarios::score($rules, $fb));
}
