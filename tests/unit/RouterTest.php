<?php

declare(strict_types=1);

use App\Core\Router;

// Router: matching de {id} (A2-03 hallazgo 7: $D y [1-9][0-9]{0,9}), 404/405 + Allow y M-5.
// Fase R-2 (D-57): con RouteMatcher y HttpException, los casos de Reflection y los de 404/405 en
// proceso hijo dejan de registrarse (los reemplazan RouteMatcherTest.php y DespachoTest.php). Los
// que verifican la sesión DENTRO de la acción siguen en hijo: session_start() exige que no haya
// habido salida y solo una sesión por proceso, y el runner ya imprimió.
// (la sesión se inicia recién después de resolver ruta y método; D-49: Session::configure en
// bootstrap y Session::start() sin argumentos desde el Router).
//
// match() y allowHeader() son privados y puros: se prueban por Reflection. dispatch() termina
// con exit en 404/405 y su header Allow no es observable en CLI (headers_list() vacío): se
// corre en un proceso hijo y se verifica el código; la composición de Allow la cubre
// allowHeader(). Refactor mínimo que lo haría testeable sin Reflection ni hijo: extraer
// match/allowHeader a una clase pública (RouteMatcher) y que dispatch devuelva un resultado
// (ruta + params | 404 | 405 + allow) que Response aplica afuera.

// Reflection sobre match()/allowHeader() privados: solo hasta que exista RouteMatcher (R-2);
// con la clase, estos casos los reemplaza RouteMatcherTest.php.
if (!hayRouteMatcher()) {
    function rutaMatch(string $patron, string $path): ?array
    {
        return llamarPrivado(Router::class, 'match', $patron, $path);
    }

    test('match: ruta fija exacta', function () {
        assertSame([], rutaMatch('/admin', '/admin'));
        assertNull(rutaMatch('/admin', '/admin/x'));
        assertNull(rutaMatch('/admin', '/administrador'));
        assertNull(rutaMatch('/admin', '/Admin'), 'case-sensitive');
    });

    test('match: {id} entero positivo llega como int', function () {
        assertSame([1], rutaMatch('/admin/solicitudes/{id}', '/admin/solicitudes/1'));
        assertSame([4294967295], rutaMatch('/admin/solicitudes/{id}', '/admin/solicitudes/4294967295'));
        assertSame([7], rutaMatch('/admin/solicitudes/{id}/estado', '/admin/solicitudes/7/estado'));
    });

    test('match: {id} rechaza 0, ceros a la izquierda, signos, no numéricos y > 10 dígitos', function () {
        foreach (['0', '01', '007', '-1', '+1', '1.0', '1e3', 'abc', '1a', '', '12345678901', ' 1', '%31'] as $id) {
            assertNull(rutaMatch('/admin/solicitudes/{id}', '/admin/solicitudes/' . $id), "id «{$id}»");
        }
    });

    test('match: $D — un "\n" final no matchea', function () {
        assertNull(rutaMatch('/gracias', "/gracias\n"));
        assertNull(rutaMatch('/admin/solicitudes/{id}', "/admin/solicitudes/1\n"));
    });

    test('match: el patrón se escapa (un "." en el patrón no es comodín)', function () {
        assertNull(rutaMatch('/a.b', '/axb'));
        assertSame([], rutaMatch('/a.b', '/a.b'));
    });

    test('allowHeader: agrega HEAD cuando hay GET y deduplica', function () {
        assertSame('POST', llamarPrivado(Router::class, 'allowHeader', ['POST']));
        assertSame('GET, HEAD', llamarPrivado(Router::class, 'allowHeader', ['GET']));
        assertSame('GET, POST, HEAD', llamarPrivado(Router::class, 'allowHeader', ['GET', 'POST', 'GET']));
    });
}

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

if (!hayHttpException()) { // con R-2 abort lanza: lo cubre DespachoTest.php en proceso
    test('dispatch: ruta inexistente → 404 sin ejecutar acciones', function () {
        $r = despachar('GET', '/no-existe');
        assertSame(404, $r['http']);
        assertNull($r['resultado']);
    });
}

if (!hayHttpException()) { // con R-2 abort lanza: lo cubre DespachoTest.php en proceso
    test('dispatch: método no permitido → 405 sin ejecutar acciones', function () {
        $r = despachar('GET', '/guardar');
        assertSame(405, $r['http']);
        assertNull($r['resultado']);
        $r = despachar('DELETE', '/item/3');
        assertSame(405, $r['http'], 'DELETE sobre ruta GET+POST');
    });
}

test('dispatch: GET con {id} llega como int a la acción', function () {
    $r = despachar('GET', '/item/42');
    assertSame(['accion' => 'ver', 'ids' => [42]], array_intersect_key($r['resultado'] ?? [], ['accion' => 1, 'ids' => 1]));
});

test('dispatch: HEAD se atiende con la ruta GET', function () {
    $r = despachar('HEAD', '/ver');
    assertSame('ver', $r['resultado']['accion'] ?? null);
});

if (!hayHttpException()) { // con R-2 abort lanza: lo cubre DespachoTest.php en proceso
    test('M-5: 404 no inicia sesión', function () {
        $r = despachar('GET', '/no-existe');
        assertSame(PHP_SESSION_NONE, $r['session']);
    });
}

if (!hayHttpException()) { // con R-2 abort lanza: lo cubre DespachoTest.php en proceso
    test('M-5: 405 no inicia sesión', function () {
        $r = despachar('POST', '/ver');
        assertSame(405, $r['http']);
        assertSame(PHP_SESSION_NONE, $r['session']);
    });
}

test('M-5: con ruta y método resueltos, la acción ya corre con sesión activa', function () {
    $r = despachar('GET', '/ver');
    assertSame(PHP_SESSION_ACTIVE, $r['resultado']['sesion'] ?? null, 'el Router debe llamar Session::start() (D-49)');
});

test('M-5: también en POST (el CSRF necesita la sesión)', function () {
    $r = despachar('POST', '/guardar');
    assertSame(PHP_SESSION_ACTIVE, $r['resultado']['sesion'] ?? null);
});
