<?php

declare(strict_types=1);

namespace App\Core;

/** Acceso tipado a la request: solo devuelve strings, nunca arrays inyectados (campo[]=x). */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Path sin query string ni barra final ("/admin/" → "/admin"). No se decodifica: las rutas son ASCII. */
    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? rtrim($path, '/') : '';

        return $path === '' ? '/' : $path;
    }

    public static function input(string $key): ?string
    {
        $value = $_POST[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, string> campo ausente o no-string → ''
     */
    public static function only(array $keys): array
    {
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = self::input($key) ?? '';
        }

        return $data;
    }

    public static function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Sin proxy delante: no se confía en X-Forwarded-Proto (lo puede mandar cualquiera). */
    public static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return ($https !== '' && strtolower((string) $https) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
