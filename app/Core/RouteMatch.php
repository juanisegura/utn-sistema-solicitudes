<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resultado de RouteMatcher::match(): 200 con handler y parámetros, 404, o 405 con los métodos
 * permitidos (para el header Allow). Inmutable.
 */
final class RouteMatch
{
    /**
     * @param array{0: class-string, 1: string}|null $handler
     * @param list<int>                               $params  valores de {id}
     * @param array<string, bool>                     $options 'auth', 'csrf'
     * @param list<string>                            $allow   solo en 405; incluye HEAD si hay GET
     */
    private function __construct(
        public readonly int $status,
        public readonly string $method,
        public readonly ?array $handler = null,
        public readonly array $params = [],
        public readonly array $options = [],
        public readonly array $allow = [],
    ) {
    }

    /** @param array{0: class-string, 1: string} $handler @param list<int> $params @param array<string, bool> $options */
    public static function found(string $method, array $handler, array $params, array $options): self
    {
        return new self(200, $method, $handler, $params, $options);
    }

    public static function notFound(string $method): self
    {
        return new self(404, $method);
    }

    /** @param list<string> $allow */
    public static function methodNotAllowed(string $method, array $allow): self
    {
        return new self(405, $method, allow: $allow);
    }
}
