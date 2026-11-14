<?php
declare(strict_types=1);

// Aplica migrations/*.sql. Uso: php bin/migrate.php [--lab]
// Roda comando por comando (assim um erro nunca passa em silêncio) e confere no fim se as
// tabelas existem. As migrations são idempotentes (CREATE TABLE IF NOT EXISTS): se uma foi
// registrada mas a tabela sumiu ou não foi criada, ela roda de novo.
$app = require __DIR__ . '/../bootstrap.php';
$pdo = $app['db']->pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS talk_migrations (name VARCHAR(190) PRIMARY KEY, ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');

$tables = static fn (): array => $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$statements = static function (string $sql): array {
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);          // comentários de linha inteira
    $sql = (string) preg_replace('/\s--\s[^\n\']*$/m', '', $sql);    // comentários no fim da linha
    return array_values(array_filter(array_map('trim', explode(";\n", $sql . "\n")), fn ($s) => $s !== '' && $s !== ';'));
};

$done = $pdo->query('SELECT name FROM talk_migrations')->fetchAll(PDO::FETCH_COLUMN);
$existing = $tables();
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
echo "Banco: $version\n";

foreach (glob(__DIR__ . '/../migrations/*.sql') as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $sql, $m);
    $missing = array_diff($m[1], $existing);
    if (in_array($name, $done, true) && $missing === []) {
        continue;
    }
    echo "-> $name\n";
    foreach ($statements($sql) as $i => $stmt) {
        try {
            $pdo->exec(rtrim($stmt, ';'));
        } catch (PDOException $e) {
            fwrite(STDERR, "ERRO em $name, comando " . ($i + 1) . ': ' . $e->getMessage() . "\n" . mb_substr($stmt, 0, 300) . "\n");
            exit(1);
        }
    }
    $existing = $tables();
    $still = array_diff($m[1], $existing);
    if ($still !== []) {
        fwrite(STDERR, "ERRO: $name rodou mas estas tabelas não existem: " . implode(', ', $still) . "\n");
        exit(1);
    }
    $pdo->prepare('INSERT IGNORE INTO talk_migrations (name) VALUES (?)')->execute([$name]);
}
echo 'migrations ok (' . count(array_filter($tables(), fn ($t) => str_starts_with($t, 'talk_'))) . " tabelas talk_)\n";
