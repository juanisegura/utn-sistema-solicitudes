<?php

declare(strict_types=1);

/*
 * Adaptador al contrato de la segunda fase de mejoras (PLAN §6, D-55..D-58). Concentra acá los
 * nombres que el PLAN no fija del todo, para que un cambio de firma de A1-13 se corrija en un
 * solo lugar. Firmas propuestas (orquestador, A2-10):
 *   - App\Core\RouteMatcher (new RouteMatcher($routes))->match($method, $path): RouteMatch con
 *     propiedades públicas readonly status, handler, params, options, allow.
 *   - App\Core\HttpException con status y headers (A1-13: propiedades públicas readonly; la
 *     propuesta era getStatus()/getHeaders(), se aceptan ambas); el redirect es la misma
 *     HttpException con header Location (isRedirect()).
 *   - App\Models\Solicitud::fulltextQuery(string $q): ?string
 *   - app/bootstrap-cli.php
 * Mientras una pieza no exista, los tests que la usan quedan PENDIENTE (no FAIL).
 */

const CLASE_ROUTE_MATCHER = 'App\\Core\\RouteMatcher';
const CLASE_HTTP_EXCEPTION = 'App\\Core\\HttpException';
const CLASE_REDIRECT_EXCEPTION = 'App\\Core\\RedirectException';

function hayRouteMatcher(): bool
{
    return class_exists(CLASE_ROUTE_MATCHER);
}

function hayHttpException(): bool
{
    return class_exists(CLASE_HTTP_EXCEPTION);
}

/** @param list<array> $rutas */
function rmMatch(array $rutas, string $metodo, string $path): object
{
    if (!hayRouteMatcher()) {
        pending('App\Core\RouteMatcher no existe todavía (R-2, A1-13)');
    }
    $clase = CLASE_ROUTE_MATCHER;

    return (new $clase($rutas))->match($metodo, $path);
}

/**
 * Corre $fn esperando una excepción de control (R-2) y devuelve status, headers y Location.
 * Falla si no lanza nada o si lanza otra cosa.
 *
 * @return array{status: int, headers: array<string, string>, location: ?string, clase: string}
 */
function controlDe(callable $fn): array
{
    if (!hayHttpException()) {
        pending('App\Core\HttpException no existe todavía (R-2, A1-13)');
    }
    try {
        $fn();
    } catch (Throwable $e) {
        $esControl = is_a($e, CLASE_HTTP_EXCEPTION) || (class_exists(CLASE_REDIRECT_EXCEPTION) && is_a($e, CLASE_REDIRECT_EXCEPTION));
        if (!$esControl) {
            fail('se esperaba una excepción de control y se lanzó ' . $e::class . ': ' . $e->getMessage());
        }
        // Firma real de A1-13: propiedades públicas readonly $status y $headers; se aceptan también
        // los getters propuestos en A2-10 (getStatus/getHeaders) por si cambian.
        $status = method_exists($e, 'getStatus') ? (int) $e->getStatus() : (property_exists($e, 'status') ? (int) $e->status : (int) $e->getCode());
        $headers = method_exists($e, 'getHeaders') ? (array) $e->getHeaders() : (property_exists($e, 'headers') ? (array) $e->headers : []);
        $location = method_exists($e, 'getLocation') ? $e->getLocation() : ($headers['Location'] ?? null);

        return ['status' => $status, 'headers' => $headers, 'location' => $location, 'clase' => $e::class];
    }
    fail('no se lanzó la excepción de control');
}

/** Solicitud::fulltextQuery (R-1, D-56) o PENDIENTE si todavía no existe. */
function fulltextQuery(string $q): ?string
{
    foreach (['fulltextQuery', 'fulltextBooleano', 'terminosFulltext'] as $m) {
        if (method_exists(App\Models\Solicitud::class, $m)) {
            return llamarPrivado(App\Models\Solicitud::class, $m, $q);
        }
    }
    pending('Solicitud::fulltextQuery no existe todavía (R-1, A1-13)');
}

/**
 * Términos de una expresión booleana de FULLTEXT, en minúsculas y sin repetir, para comparar sin
 * depender del orden ni de mayúsculas (el contrato no los fija).
 *
 * @return list<string>
 */
function terminosFt(?string $expr): array
{
    if ($expr === null) {
        return [];
    }
    $t = preg_split('/\s+/u', trim($expr), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $t = array_values(array_unique(array_map(static fn (string $x): string => mb_strtolower($x, 'UTF-8'), $t)));
    sort($t);

    return $t;
}
