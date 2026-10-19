<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Response
{
    /** Códigos con vista propia en app/Views/errors/; cualquier otro usa la de 500 (texto genérico). */
    private const ERROR_TITLES = [
        400 => 'Solicitud inválida',
        401 => 'Iniciá sesión para continuar',
        403 => 'Acceso denegado',
        404 => 'Página no encontrada',
        405 => 'Método no permitido',
        419 => 'El formulario expiró',
        500 => 'Error interno',
    ];

    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
    }

    /** Solo paths locales: evita open redirects si algún día el destino sale de la entrada. */
    public static function redirect(string $path, int $status = 302): never
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Redirect solo a paths locales.');
        }
        header('Location: ' . $path, true, $status);
        exit;
    }

    /** @param array<string, string> $headers p. ej. ['Allow' => 'POST'] para el 405 */
    public static function abort(int $status, array $headers = []): never
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        $view = isset(self::ERROR_TITLES[$status]) ? 'errors/' . $status : 'errors/500';
        $title = self::ERROR_TITLES[$status] ?? self::ERROR_TITLES[500];
        self::html(View::render($view, ['title' => $title]), $status);
        exit;
    }
}
