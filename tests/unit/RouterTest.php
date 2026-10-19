<?php

declare(strict_types=1);

use App\Core\Router;

// Router::dispatch con rutas de prueba, en un proceso hijo: el controller falso anota el estado de
// la sesión dentro de la acción (M-5: el Router abre la sesión recién después de resolver ruta y
// método). Tiene que ser un proceso aparte porque session_start() exige que no haya habido salida
// y solo una sesión por proceso, y el runner ya imprimió. El matching puro está en
// RouteMatcherTest.php y los 404/405 en proceso, en DespachoTest.php.

/**
 * Corre Router::dispatch en un hijo con rutas de prueba y un controller falso que anota el
 * estado de la sesión al ejecutarse. Session::configure (D-49) se llama si existe.
 */
function despachar(string $metodo, string $path): array
{
    return enHijo(<<<'PHP'
        final class CtlPrueba
        {
            public function ver(int ...$ids): void
            {
                $GLOBALS['resultado'] = ['accion' => 'ver', 'ids' => $ids, 'sesion' => session_status()];
            }
            public function guardar(): void
            {
                $GLOBALS['resultado'] = ['accion' => 'guardar', 'sesion' => session_status()];
            }
        }
        session_save_path(__DIR__);
        if (method_exists(App\Core\Session::class, 'configure')) {
            App\Core\Session::configure(1800, false);
        }
        $_SERVER['REQUEST_METHOD'] = $DATOS['m'];
        $router = new App\Core\Router([
            ['GET',  '/ver',            [CtlPrueba::class, 'ver']],
            ['GET',  '/item/{id}',      [CtlPrueba::class, 'ver']],
            ['POST', '/guardar',        [CtlPrueba::class, 'guardar'], ['csrf' => false]],
            ['POST', '/item/{id}',      [CtlPrueba::class, 'guardar'], ['csrf' => false]],
        ]);
        $router->dispatch($DATOS['m'], $DATOS['p']);
        PHP, ['m' => $metodo, 'p' => $path]);
}

test('dispatch: GET con {id} llega como int a la acción', function () {
    $r = despachar('GET', '/item/42');
    assertSame(['accion' => 'ver', 'ids' => [42]], array_intersect_key($r['resultado'] ?? [], ['accion' => 1, 'ids' => 1]));
});

test('dispatch: HEAD se atiende con la ruta GET', function () {
    $r = despachar('HEAD', '/ver');
    assertSame('ver', $r['resultado']['accion'] ?? null);
});

test('M-5: con ruta y método resueltos, la acción ya corre con sesión activa', function () {
    $r = despachar('GET', '/ver');
    assertSame(PHP_SESSION_ACTIVE, $r['resultado']['sesion'] ?? null, 'el Router debe llamar Session::start() (D-49)');
});

test('M-5: también en POST (el CSRF necesita la sesión)', function () {
    $r = despachar('POST', '/guardar');
    assertSame(PHP_SESSION_ACTIVE, $r['resultado']['sesion'] ?? null);
});
