<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resuelve método + path contra la tabla de rutas (ADR-0001, D-57). Pura: no lee $_SERVER, no
 * escribe salida ni abre la sesión; eso queda en el Router.
 *
 * Ruta: [método, patrón, [Controller::class, 'acción'], opciones]
 *   patrón: '/admin/solicitudes/{id}' — {id} solo acepta enteros positivos y llega como int.
 */
final class RouteMatcher
{
    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3?: array<string, bool>}> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    public function match(string $method, string $path): RouteMatch
    {
        // HEAD se atiende como GET (PHP descarta el cuerpo).
        $method = strtoupper($method);
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            [$verb, $pattern, $handler] = $route;
            $params = self::matchPattern($pattern, $path);
            if ($params === null) {
                continue;
            }
            if ($verb !== $method) {
                $allowed[] = $verb;
                continue;
            }

            return RouteMatch::found($method, $handler, $params, $route[3] ?? []);
        }

        return $allowed === []
            ? RouteMatch::notFound($method)
            : RouteMatch::methodNotAllowed($method, self::allow($allowed));
    }

    /** @return list<int>|null parámetros {id} si el path coincide, null si no */
    private static function matchPattern(string $pattern, string $path): ?array
    {
        // D: "$" no acepta un "\n" final. {id} sin ceros a la izquierda ni 0 (ids AUTO_INCREMENT empiezan en 1).
        $regex = '#^' . str_replace('\{id\}', '([1-9][0-9]{0,9})', preg_quote($pattern, '#')) . '$#D';
        if (preg_match($regex, $path, $matches) !== 1) {
            return null;
        }

        return array_map('intval', array_slice($matches, 1));
    }

    /**
     * @param list<string> $methods
     * @return list<string>
     */
    private static function allow(array $methods): array
    {
        $methods = array_values(array_unique($methods));
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }

        return $methods;
    }
}
