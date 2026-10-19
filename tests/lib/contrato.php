<?php

declare(strict_types=1);

/*
 * Helpers de los tests de la segunda fase de mejoras (PLAN §6): RouteMatcher (R-2), excepciones
 * de control (R-2, D-57/D-59) y términos de FULLTEXT (R-1).
 */

use App\Core\HttpException;
use App\Core\RouteMatch;
use App\Core\RouteMatcher;

/** @param list<array> $rutas */
function rmMatch(array $rutas, string $metodo, string $path): RouteMatch
{
    return (new RouteMatcher($rutas))->match($metodo, $path);
}

/**
 * Corre $fn esperando una excepción de control y devuelve su status, headers y Location. Falla si
 * no lanza nada o si lanza otra cosa. El redirect es la misma HttpException con Location (D-59).
 *
 * @return array{status: int, headers: array<string, string>, location: ?string}
 */
function controlDe(callable $fn): array
{
    try {
        $fn();
    } catch (HttpException $e) {
        return ['status' => $e->status, 'headers' => $e->headers, 'location' => $e->headers['Location'] ?? null];
    } catch (Throwable $e) {
        fail('se esperaba HttpException y se lanzó ' . $e::class . ': ' . $e->getMessage());
    }
    fail('no se lanzó la excepción de control');
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
