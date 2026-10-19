<?php

declare(strict_types=1);

// Front controller: única entrada PHP expuesta. Todo lo demás vive en app/ (ADR-0001).

// Con `php -S ... public_html/index.php` este archivo es también el router del servidor:
// los archivos estáticos reales (CSS) los sirve PHP directamente.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

$router = new App\Core\Router(require dirname(__DIR__) . '/app/routes.php');
$router->dispatch(App\Core\Request::method(), App\Core\Request::path());
