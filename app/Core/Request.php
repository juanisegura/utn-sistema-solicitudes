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

    /** @var list<array{0: string, 1: int}> proxies de confianza como [dirección binaria, bits de prefijo] */
    private static array $trustedProxies = [];

    /**
     * Lee app.trusted_proxies: IPs o rangos CIDR (IPv4/IPv6) de los proxies propios. Vacío = no se
     * confía en X-Forwarded-For. Una entrada inválida es un error de configuración: se rechaza.
     *
     * @param array<string, mixed> $app sección 'app' de la config
     */
    public static function configure(array $app): void
    {
        $proxies = $app['trusted_proxies'] ?? [];
        if (!is_array($proxies)) {
            throw new \InvalidArgumentException('trusted_proxies debe ser una lista.');
        }
        self::$trustedProxies = array_values(array_map(self::parseCidr(...), $proxies));
    }

    /**
     * IP del cliente (D-47, D-49). REMOTE_ADDR, salvo que venga de un proxy de confianza: entonces
     * se recorre X-Forwarded-For de derecha a izquierda (el final lo agregan nuestros proxies; el
     * principio lo puede escribir cualquiera) y se devuelve la primera IP que no sea un proxy propio.
     * - Entrada inválida antes de encontrarla → REMOTE_ADDR (no se salta basura del header).
     * - Todas las entradas son proxies propios → la de más a la izquierda (origen más lejano).
     * Todas las direcciones se normalizan (puerto, corchetes, IPv4-mapped).
     */
    public static function ip(): string
    {
        $remote = self::normalizeIp((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (self::$trustedProxies === [] || !self::isTrustedProxy($remote)) {
            return $remote;
        }
        $header = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($header) || trim($header) === '') {
            return $remote;
        }
        $ultima = $remote;
        foreach (array_reverse(explode(',', $header)) as $entrada) {
            $ip = self::normalizeIp($entrada);
            if ($ip === null) {
                return $remote;
            }
            if (!self::isTrustedProxy($ip)) {
                return $ip;
            }
            $ultima = $ip;
        }

        return $ultima;
    }

    public static function isTrustedProxy(string $ip): bool
    {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return false;
        }
        foreach (self::$trustedProxies as $rango) {
            if (self::ipInRange($ip, $rango)) {
                return true;
            }
        }

        return false;
    }

    /**
     * " 1.2.3.4:5678 " → "1.2.3.4"; "[2001:db8::1]:443" → "2001:db8::1"; "::ffff:10.0.0.1" →
     * "10.0.0.1". null si no es una IP válida.
     */
    public static function normalizeIp(string $valor): ?string
    {
        $v = trim($valor);
        if (preg_match('/^\[([^\]]+)\](?::\d{1,5})?$/', $v, $m) === 1) {
            $v = $m[1];                                   // IPv6 entre corchetes, con o sin puerto
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $v, $m) === 1) {
            $v = $m[1];                                   // IPv4 con puerto
        }
        if (filter_var($v, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($v);
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($bin, 12));           // IPv4-mapped → IPv4
        }

        return inet_ntop($bin);                           // forma canónica (IPv6 comprimida)
    }

    /** "10.0.0.0/8", "2001:db8::/32" o una IP sola (prefijo completo) → [binario, bits]. */
    private static function parseCidr(mixed $cidr): array
    {
        if (!is_string($cidr)) {
            throw new \InvalidArgumentException('trusted_proxies: cada entrada debe ser un string.');
        }
        [$ip, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $normal = self::normalizeIp((string) $ip);
        if ($normal === null) {
            throw new \InvalidArgumentException('trusted_proxies: dirección inválida: ' . $cidr);
        }
        $bin = inet_pton($normal);
        $max = strlen($bin) * 8;
        if ($bits === null) {
            return [$bin, $max];
        }
        if (!ctype_digit($bits) || (int) $bits > $max) {
            throw new \InvalidArgumentException('trusted_proxies: prefijo inválido: ' . $cidr);
        }

        return [$bin, (int) $bits];
    }

    /** @param array{0: string, 1: int} $rango  $ip ya normalizada */
    private static function ipInRange(string $ip, array $rango): bool
    {
        [$red, $bits] = $rango;
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== strlen($red)) {
            return false; // IPv4 nunca coincide con un rango IPv6 ni al revés
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($bin, $red, $bytes) !== 0) {
            return false;
        }
        $resto = $bits % 8;
        if ($resto === 0) {
            return true;
        }
        $mascara = (0xFF << (8 - $resto)) & 0xFF;

        return (ord($bin[$bytes]) & $mascara) === (ord($red[$bytes]) & $mascara);
    }
}
