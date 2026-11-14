<?php
declare(strict_types=1);

// Autoload sem Composer (para rodar direto no Apache do laboratório/ERP).
spl_autoload_register(static function (string $class): void {
    $prefix = 'ConnectTalk\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
