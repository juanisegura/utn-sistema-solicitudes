<?php

declare(strict_types=1);

// Arranque de cada request web: errores, log, config y headers de seguridad. La sesión la abre
// el Router cuando la ruta existe (M-5). Los scripts CLI no pasan por acá: usan solo app/autoload.php.

use App\Core\Config;
use App\Core\Database;
use App\Core\Log;
use App\Core\LoginThrottle;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

require __DIR__ . '/autoload.php';
require __DIR__ . '/helpers.php';

const APP_ROOT = __DIR__ . '/..';
const APP_LOG = APP_ROOT . '/storage/logs/app.log';

// Hasta leer la config se asume producción: si falla la carga, no se muestra nada interno.
$debug = false;
$bufferBase = ob_get_level();

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // el log lo escribe app_log() con un formato propio
date_default_timezone_set('America/Argentina/Buenos_Aires'); // D-24: log y panel en hora local

/** Una línea por error: fecha, método y path (sin query ni cuerpo: pueden traer datos personales). */
function app_log(Throwable $e): void
{
    $root = realpath(APP_ROOT) . '/';
    $line = sprintf(
        "[%s] %s %s | %s: %s en %s:%d",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        strtok($_SERVER['REQUEST_URI'] ?? '-', '?'),
        $e::class,
        $e->getMessage(),
        str_replace($root, '', $e->getFile()),
        $e->getLine()
    );
    if ($e->getPrevious() !== null) {
        $line .= ' | causa: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage();
    }
    Log::write(str_replace(["\r", "\n"], ' ', $line)); // rota por tamaño (M-6)
}

/** Vista 500 genérica. Solo en debug agrega clase, mensaje y archivo:línea (nunca la config). */
function app_render_500(Throwable $e, bool $debug, int $bufferBase): void
{
    while (ob_get_level() > $bufferBase) {
        ob_end_clean(); // descarta la vista a medio renderizar
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $detalle = $debug
        ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine())
        : null;
    try {
        echo View::render('errors/500', ['title' => 'Error interno', 'detalle' => $detalle]);
    } catch (Throwable) {
        echo 'Error interno.';
    }
}

// Warnings y notices se vuelven excepciones: nada queda a medias ni se imprime crudo.
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if ((error_reporting() & $level) === 0) {
        return false;
    }
    // Una deprecación (p. ej. al actualizar PHP) se registra pero no tumba la request.
    if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
        app_log(new ErrorException($message, 0, $level, $file, $line));

        return true;
    }
    throw new ErrorException($message, 0, $level, $file, $line);
});

set_exception_handler(static function (Throwable $e) use (&$debug, $bufferBase): void {
    app_log($e);
    app_render_500($e, $debug, $bufferBase);
});

// Errores fatales (memoria, parse en un include) no pasan por el exception handler.
register_shutdown_function(static function () use (&$debug, $bufferBase): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $e = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
    app_log($e);
    app_render_500($e, $debug, $bufferBase);
});

// Headers antes de la config: también protegen la página 500 si la config falla.
header_remove('X-Powered-By');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$config = require __DIR__ . '/config/config.php';
Config::configure($config);
$debug = $config['app']['debug'];
ini_set('display_errors', $debug ? '1' : '0');

Database::configure($config['db']);

Log::configure(APP_LOG, (int) $config['app']['log_max_bytes'], (int) $config['app']['log_max_archivos']);
Request::configure($config['app']);
LoginThrottle::configure($config['app']);
Session::configure((int) $config['app']['session_timeout'], $config['app']['env'] === 'production');

return $config;
