<?php

declare(strict_types=1);

namespace App\Core;

/** Punto único donde se despacha la request y se emiten los cortes de flujo (HttpException). */
final class Kernel
{
    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3?: array<string, bool>}> $routes */
    public static function handle(array $routes, string $method, string $path): void
    {
        try {
            (new Router($routes))->dispatch($method, $path);
        } catch (HttpException $e) {
            Response::send($e);
        }
    }
}
