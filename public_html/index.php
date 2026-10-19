<?php

declare(strict_types=1);

// Front controller: única entrada PHP expuesta. Todo lo demás vive en app/ (ADR-0001).

// Con `php -S ... public_html/index.php` este archivo es también el router del servidor:
// los archivos estáticos reales (CSS) los sirve PHP directamente.
if (PHP_SAPI === 'cli-server') {
    $base = realpath(__DIR__);
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = is_string($path) ? realpath(__DIR__ . $path) : false;
    // Solo archivos reales dentro de public_html/ (realpath resuelve "../"), ni .php ni dotfiles
    // en ningún tramo del path. Todo lo demás va al router (→ 404).
    if (
        $file !== false && is_file($file)
        && str_starts_with($file, $base . DIRECTORY_SEPARATOR)
        && !str_ends_with($file, '.php')
        && !str_contains(substr($file, strlen($base)), DIRECTORY_SEPARATOR . '.')
    ) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\Kernel::handle(require dirname(__DIR__) . '/app/routes.php', App\Core\Request::method(), App\Core\Request::path());
