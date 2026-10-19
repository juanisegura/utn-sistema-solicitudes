<?php

declare(strict_types=1);

// R-2 (D-57): Kernel::handle es el único punto que captura las excepciones de control y las emite
// (Response::send). Una excepción real NO se traga: sigue hacia el handler global (log + 500).
// En proceso hijo: send() fija el código HTTP y emite la vista, y la acción abre sesión.

/** Corre Kernel::handle con una ruta GET /x cuyo controller hace $DATOS['accion']. */
function kernelCon(string $accion): array
{
    if (!class_exists('App\\Core\\Kernel')) {
        pending('App\Core\Kernel no existe todavía (R-2)');
    }
    $r = enHijo(<<<'PHP'
        final class CtlKernel
        {
            public function x(): void
            {
                match ($GLOBALS['DATOS']['accion']) {
                    'abort404' => App\Core\Response::abort(404),
                    'redirect' => App\Core\Response::redirect('/destino'),
                    'error'    => throw new RuntimeException('error real de prueba'),
                    'ok'       => print('cuerpo-ok'),
                };
            }
        }
        $GLOBALS['DATOS'] = $DATOS;
        session_save_path(__DIR__);
        App\Core\Session::configure(1800, false);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            App\Core\Kernel::handle([['GET', '/x', [CtlKernel::class, 'x']]], 'GET', $DATOS['path']);
            $escapa = null;
        } catch (Throwable $e) {
            $escapa = $e::class . ': ' . $e->getMessage();
        }
        $GLOBALS['resultado'] = ['salida' => ob_get_clean(), 'escapa' => $escapa];
        PHP, ['accion' => $accion, 'path' => $accion === 'ruta404' ? '/no-existe' : '/x']);

    return $r;
}

test('abort(404) dentro de la acción → Kernel emite 404 con la vista de error, nada escapa', function () {
    $r = kernelCon('abort404');
    assertSame(404, $r['http']);
    assertNull($r['resultado']['escapa']);
    assertContains('404', $r['resultado']['salida']);
});

test('ruta inexistente → 404 emitido por Kernel', function () {
    $r = kernelCon('ruta404');
    assertSame(404, $r['http']);
    assertNull($r['resultado']['escapa']);
});

test('redirect → 302 sin cuerpo, nada escapa', function () {
    $r = kernelCon('redirect');
    assertSame(302, $r['http']);
    assertSame('', $r['resultado']['salida']);
    assertNull($r['resultado']['escapa']);
});

test('una excepción real no se traga como control de flujo', function () {
    $r = kernelCon('error');
    assertSame('RuntimeException: error real de prueba', $r['resultado']['escapa']);
    assertNotSame(404, $r['http']);
});

test('acción normal → su salida, sin código de error', function () {
    $r = kernelCon('ok');
    assertSame('cuerpo-ok', $r['resultado']['salida']);
    assertNull($r['resultado']['escapa']);
    assertTrue($r['http'] === false || $r['http'] === 200, 'código: ' . var_export($r['http'], true));
});
