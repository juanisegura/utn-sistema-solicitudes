<?php

declare(strict_types=1);

use App\Core\Response;
use App\Core\Router;

// R-2 (D-57): despacho sin exit. Response::abort()/redirect() lanzan una excepción de control
// (App\Core\HttpException con status y headers; el redirect puede ser RedirectException o la
// misma con Location) que un único punto captura y emite. Ningún exit/die en app/. Todo en
// proceso: sin Reflection ni proceso hijo. PENDIENTE mientras HttpException no exista.

test('abort(419) lanza control con status 419 y no emite nada', function () {
    ob_start();
    $c = controlDe(fn () => Response::abort(419));
    $salida = (string) ob_get_clean();
    assertSame(419, $c['status']);
    assertSame('', $salida, 'la vista la emite el punto único, no abort()');
});

test('abort con headers: 405 + Allow', function () {
    $c = controlDe(fn () => Response::abort(405, ['Allow' => 'POST']));
    assertSame(405, $c['status']);
    assertSame('POST', $c['headers']['Allow'] ?? null);
});

test('abort de cada código de error del PLAN conserva el status', function () {
    foreach ([400, 401, 403, 404, 405, 419, 422, 429, 500] as $codigo) {
        assertSame($codigo, controlDe(fn () => Response::abort($codigo))['status'], (string) $codigo);
    }
});

test('redirect("/x") lanza control 302 con Location /x', function () {
    $c = controlDe(fn () => Response::redirect('/x'));
    assertSame(302, $c['status']);
    assertSame('/x', $c['location']);
});

test('redirect con código explícito y query string', function () {
    $c = controlDe(fn () => Response::redirect('/admin/solicitudes/1?x=1', 303));
    assertSame(303, $c['status']);
    assertSame('/admin/solicitudes/1?x=1', $c['location']);
});

test('redirect sigue rechazando destinos externos, "\\" y controles (InvalidArgumentException, no control)', function () {
    if (!hayHttpException()) {
        pending('App\Core\HttpException no existe todavía (R-2, A1-13)');
    }
    foreach (['http://evil.example', '//evil.example', '/\\evil.example', "/ok\r\nX: y", "/ok\0", 'evil', ''] as $d) {
        assertThrows(InvalidArgumentException::class, fn () => Response::redirect($d), exportar($d));
    }
});

/** Router con rutas de prueba; nunca llega a una acción en estos casos (404/405). */
function routerPrueba(): Router
{
    return new Router([
        ['GET',  '/ver',     [stdClass::class, 'noSeLlama']],
        ['POST', '/guardar', [stdClass::class, 'noSeLlama']],
    ]);
}

test('dispatch: 404 lanza control y no abre sesión (M-5)', function () {
    $c = controlDe(fn () => routerPrueba()->dispatch('GET', '/no-existe'));
    assertSame(404, $c['status']);
    assertSame(PHP_SESSION_NONE, session_status());
});

test('dispatch: 405 lanza control con Allow y no abre sesión (M-5)', function () {
    $c = controlDe(fn () => routerPrueba()->dispatch('GET', '/guardar'));
    assertSame(405, $c['status']);
    assertSame('POST', $c['headers']['Allow'] ?? null);
    $c = controlDe(fn () => routerPrueba()->dispatch('POST', '/ver'));
    assertSame(['GET', 'HEAD'], array_map('trim', explode(',', $c['headers']['Allow'] ?? '')));
    assertSame(PHP_SESSION_NONE, session_status());
});

test('ningún exit/die en app/ (solo el front controller y los scripts CLI)', function () {
    if (!hayHttpException()) {
        pending('R-2 no entregado: hoy abort/redirect todavía terminan con exit');
    }
    $con = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        foreach (token_get_all((string) file_get_contents($f->getPathname())) as $tok) {
            if (is_array($tok) && $tok[0] === T_EXIT) {
                $con[] = substr($f->getPathname(), strlen(APP_DIR) + 1) . ':' . $tok[2];
            }
        }
    }
    assertSame([], $con, 'exit/die encontrados');
});
