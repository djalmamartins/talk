<?php
declare(strict_types=1);

// Aplica migrations/*.sql que ainda não rodaram. Uso: php bin/migrate.php [--lab]
$app = require __DIR__ . '/../bootstrap.php';
$pdo = $app['db']->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS talk_migrations (name VARCHAR(190) PRIMARY KEY, ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$done = $pdo->query('SELECT name FROM talk_migrations')->fetchAll(PDO::FETCH_COLUMN);
$lab = in_array('--lab', $argv, true);
foreach (glob(__DIR__ . '/../migrations/*.sql') as $file) {
    $name = basename($file);
    if (in_array($name, $done, true) ) {
        continue;
    }
    echo "-> $name\n";
    $pdo->exec((string) file_get_contents($file));
    $pdo->prepare('INSERT INTO talk_migrations (name) VALUES (?)')->execute([$name]);
}
echo "migrations ok\n";
