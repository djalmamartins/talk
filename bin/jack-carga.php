<?php
declare(strict_types=1);

// Carga de teste da Jack, só no laboratório (APP_ENV=lab). Clientes fictícios com número de teste (DDD 00):
// nada sai do Talk (o WhatsApp de verdade e o ERP recusam esses números) e tudo vai pelo número simulado.
//
//   php bin/jack-carga.php enviar [--mensagens=500] [--clientes=60]   manda a carga e mostra o placar
//   php bin/jack-carga.php reteste [rodada]                            o que ela errou volta, de outro cliente
//   php bin/jack-carga.php placar [rodada]                             placar de uma rodada (padrão: a última)
//
// No lab do Mac: docker exec dev-apache php /var/www/html/talk/bin/jack-carga.php enviar
// As frases vêm de tests/Fixtures/jack/ (com o setor certo de cada uma). Os atendimentos aparecem na tela
// Serviço da Jack com a marca "teste" e já encerrados (sem avisar ninguém).
use ConnectTalk\Jack\LoadTest;
use ConnectTalk\Jack\Scenarios;

$app = require __DIR__ . '/../bootstrap.php';
if ($app['env']('APP_ENV') !== 'lab') {
    fwrite(STDERR, "A carga de teste só roda no laboratório (APP_ENV=lab).\n");
    exit(1);
}
$cmd = $argv[1] ?? 'ajuda';
$opt = static function (string $name, int $default) use ($argv): int {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return max(1, (int) substr($a, strlen($name) + 3));
        }
    }
    return $default;
};
$arg = static fn (): ?string => isset($argv[2]) && !str_starts_with($argv[2], '--') ? $argv[2] : null;

try {
    $load = new LoadTest($app['db'], $app['conversations']);
} catch (\DomainException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$show = static function (?array $r): void {
    if ($r === null) {
        echo "Nenhuma rodada ainda. Rode: php bin/jack-carga.php enviar\n";
        return;
    }
    printf("\nRodada %s: %d atendimentos, %d mensagens, %d clientes\n", $r['rodada'], $r['atendimentos'], $r['mensagens'], $r['clientes']);
    printf("No setor certo: %d (%.0f%%) · com certeza e certo: %d · erros graves (certeza no setor errado): %d\n",
        $r['certos'], $r['taxa'] * 100, $r['exatos'], $r['graves']);
    if ($r['retestes_acertados'] > 0) {
        printf("Retestes que agora acertaram: %d\n", $r['retestes_acertados']);
    }
    foreach ($r['por_setor'] as $s => $p) {
        printf("  %-15s %3d de %3d\n", $s === 'padrao' ? 'sem assunto' : $s, $p['certos'], $p['total']);
    }
    if ($r['erros'] !== []) {
        echo "\nOnde ela errou (procure o protocolo na tela Serviço da Jack):\n";
        $seen = [];
        foreach ($r['erros'] as $e) {
            $key = $e['frase'];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > 1) {
                continue;
            }
            printf("  %-14s certo %-14s ficou %-14s \"%s\"\n", $e['protocolo'] ?? '-', $e['esperado'], $e['ficou'] ?? '-', mb_strimwidth(str_replace("\n", ' / ', $e['frase']), 0, 70, '…'));
        }
        echo "\nPara mandar de novo o que ela errou, com outros clientes: php bin/jack-carga.php reteste\n";
    }
};

switch ($cmd) {
    case 'enviar':
        $dir = __DIR__ . '/../tests/Fixtures/jack';
        $rows = [];
        foreach (['triagem.csv', 'triagem-conferencia.csv'] as $f) {
            if (is_file("$dir/$f")) {
                $rows = [...$rows, ...Scenarios::load("$dir/$f")];
            }
        }
        if ($rows === []) {
            fwrite(STDERR, "Não achei as frases em $dir.\n");
            exit(1);
        }
        $total = $opt('mensagens', 500);
        $clients = $opt('clientes', 60);
        printf("Mandando %d mensagens de %d clientes de teste (%d frases diferentes, com variações)…\n", $total, $clients, count($rows));
        $last = 0;
        $round = $load->run($rows, $total, $clients, static function (int $sent, int $all) use (&$last): void {
            if ($sent - $last >= 50 || $sent >= $all) {
                printf("  %d de %d\n", $sent, $all);
                $last = $sent;
            }
        });
        $show($load->report($round));
        break;
    case 'reteste':
        $r = $load->retest($arg());
        if ($r['rodada'] === null) {
            echo "Nada para retestar: ela não errou nenhuma frase (ou todas já foram retestadas).\n";
            break;
        }
        printf("%d frases que ela errou foram mandadas de novo, cada uma por outro cliente.\n", $r['frases']);
        $show($load->report($r['rodada']));
        break;
    case 'placar':
        $show($load->report($arg()));
        break;
    default:
        echo "Uso: php bin/jack-carga.php enviar [--mensagens=500] [--clientes=60] | reteste [rodada] | placar [rodada]\n";
}
