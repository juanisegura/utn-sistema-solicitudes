<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Despacho: resuelve la ruta (RouteMatcher) y aplica, en este orden, lo que no se puede olvidar
 * en una ruta nueva: 404 / 405 + Allow → sesión → autenticación → CSRF → controller (D-16).
 * Los cortes (404, 405, 419, redirect al login…) se lanzan como HttpException; los emite Kernel.
 */
final class Router
{
    private readonly RouteMatcher $matcher;

    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3?: array<string, bool>}> $routes */
    public function __construct(array $routes)
    {
        $this->matcher = new RouteMatcher($routes);
    }

    public function dispatch(string $method, string $path): void
    {
        $match = $this->matcher->match($method, $path);
        if ($match->status === 405) {
            Response::abort(405, ['Allow' => implode(', ', $match->allow)]);
        }
        if ($match->status === 404) {
            Response::abort(404);
        }

        // M-5: la sesión se abre recién acá; 404 y 405 no crean sesión ni mandan Set-Cookie.
        Session::start();
        $this->applyMiddleware($match->method, $match->options);
        [$class, $action] = $match->handler;
        (new $class())->$action(...$match->params);
    }

    /** @param array<string, bool> $options */
    private function applyMiddleware(string $verb, array $options): void
    {
        // Auth antes que CSRF: sin sesión se redirige al login en vez de responder 419.
        if ($options['auth'] ?? false) {
            Auth::requireAdmin();
        }
        // Seguro por defecto: todo POST valida CSRF salvo que la ruta lo desactive explícitamente.
        if (($options['csrf'] ?? $verb === 'POST') && !Csrf::verify(Request::input(Csrf::FIELD))) {
            Response::abort(419);
        }
    }
}
