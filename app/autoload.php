<?php

declare(strict_types=1);

// Autoloader mínimo: App\Core\Database → app/Core/Database.php. Sin Composer (ADR-0001).
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    // Solo nombres de clase válidos: impide que "App\\..\\x" se convierta en un path fuera de app/.
    if (!str_starts_with($class, $prefix) || preg_match('/^App(\\\\[A-Z][A-Za-z0-9]*)+$/', $class) !== 1) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
