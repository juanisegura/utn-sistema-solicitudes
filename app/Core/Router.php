<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tabla de rutas → controller. Concentra en un solo lugar lo que no se puede olvidar en una
 * ruta nueva: método permitido (405 + Allow), autenticación y CSRF.
 *
 * Ruta: [método, patrón, [Controller::class, 'acción'], opciones]
 *   patrón: '/admin/solicitudes/{id}' — {id} solo acepta enteros positivos y llega a la acción como int.
 *   opciones: 'auth' => true exige admin; 'csrf' (default true en POST) valida el token.
 */
final class Router
{
    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3?: array<string, bool>}> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    public function dispatch(string $method, string $path): void
    {
        // HEAD se atiende como GET (PHP descarta el cuerpo).
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            [$verb, $pattern, $handler] = $route;
            $params = self::match($pattern, $path);
            if ($params === null) {
                continue;
            }
            if ($verb !== $method) {
                $allowed[] = $verb;
                continue;
            }

            $this->applyMiddleware($verb, $route[3] ?? []);
            [$class, $action] = $handler;
            (new $class())->$action(...$params);

            return;
        }

        if ($allowed !== []) {
            Response::abort(405, ['Allow' => self::allowHeader($allowed)]);
        }
        Response::abort(404);
    }

    /** @param array<string, bool> $options */
    private function applyMiddleware(string $verb, array $options): void
    {
        // Auth antes que CSRF: sin sesión se redirige al login en vez de responder 419.
        if ($options['auth'] ?? false) {
            Auth::requireAdmin(); // App\Core\Auth llega en A1-03
        }
        // Seguro por defecto: todo POST valida CSRF salvo que la ruta lo desactive explícitamente.
        if (($options['csrf'] ?? $verb === 'POST') && !Csrf::verify(Request::input(Csrf::FIELD))) {
            Response::abort(419);
        }
    }

    /** @return list<int>|null parámetros {id} si el path coincide, null si no */
    private static function match(string $pattern, string $path): ?array
    {
        // D: "$" no acepta un "\n" final. {id} sin ceros a la izquierda ni 0 (ids AUTO_INCREMENT empiezan en 1).
        $regex = '#^' . str_replace('\{id\}', '([1-9][0-9]{0,9})', preg_quote($pattern, '#')) . '$#D';
        if (preg_match($regex, $path, $matches) !== 1) {
            return null;
        }

        return array_map('intval', array_slice($matches, 1));
    }

    /** @param list<string> $methods */
    private static function allowHeader(array $methods): string
    {
        $methods = array_values(array_unique($methods));
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }

        return implode(', ', $methods);
    }
}
