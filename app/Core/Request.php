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

    /**
     * Valor string de POST ('post') o de la query ('query'); null si falta o llegó como array
     * (estado[]=x). Así ningún array alcanza código tipado con string (TypeError → 500).
     */
    public static function string(string $key, string $source = 'post'): ?string
    {
        $value = self::source($source)[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** true si el parámetro vino, aunque sea vacío o array: distingue "ausente" de "inválido". */
    public static function has(string $key, string $source = 'post'): bool
    {
        return array_key_exists($key, self::source($source));
    }

    public static function input(string $key): ?string
    {
        return self::string($key, 'post');
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
        return self::string($key, 'query');
    }

    /** @return array<string, mixed> */
    private static function source(string $source): array
    {
        return match ($source) {
            'post' => $_POST,
            'query' => $_GET,
            default => throw new \InvalidArgumentException('Origen de request desconocido: ' . $source),
        };
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
