<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Emisión de respuestas: HTML con status y headers, y los cortes de flujo (abort/redirect), que
 * se lanzan como HttpException y emite send() desde Kernel (D-57).
 */
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

    /** @param array<string, string> $headers p. ej. ['Retry-After' => '840'] para el 429 */
    public static function html(string $html, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $html;
    }

    /**
     * Redirect a un path local. Lanza HttpException (D-57): el flujo se corta igual que con exit,
     * pero lo que sigue (sesión, buffers) termina normalmente y el despacho se puede testear.
     * HttpException valida el status 3xx y que el path sea local (InvalidArgumentException si no).
     */
    public static function redirect(string $path, int $status = 302): never
    {
        throw new HttpException($status, ['Location' => $path]);
    }

    /** @param array<string, string> $headers p. ej. ['Allow' => 'POST'] para el 405 */
    public static function abort(int $status, array $headers = []): never
    {
        throw new HttpException($status, $headers);
    }

    /** Emite un corte de flujo: redirect (sin cuerpo) o la vista de error del status. */
    public static function send(HttpException $e): void
    {
        if ($e->isRedirect()) {
            header('Location: ' . $e->getLocation(), true, $e->status);

            return;
        }
        $view = isset(self::ERROR_TITLES[$e->status]) ? 'errors/' . $e->status : 'errors/500';
        $title = self::ERROR_TITLES[$e->status] ?? self::ERROR_TITLES[500];
        self::html(View::render($view, ['title' => $title]), $e->status, $e->headers);
    }
}
