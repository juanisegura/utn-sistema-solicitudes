<?php

declare(strict_types=1);

// R-2 (D-57): App\Core\RouteMatcher puro — sin $_SERVER, sin salida, sin exit. Reemplaza los
// casos de Reflection de RouterTest. Contrato: match($method, $path) → RouteMatch inmutable con
// status 200 | 404 | 405 y, según el caso, handler, params (ints), options o allow (con HEAD si
// hay GET). PENDIENTE mientras la clase no exista.

final class CtlRm
{
}

const RUTAS_RM = [
    ['GET',  '/',                  [CtlRm::class, 'inicio']],
    ['GET',  '/ver',               [CtlRm::class, 'ver']],
    ['POST', '/guardar',           [CtlRm::class, 'guardar']],
    ['GET',  '/item/{id}',         [CtlRm::class, 'item'], ['auth' => true]],
    ['POST', '/item/{id}',         [CtlRm::class, 'guardarItem'], ['auth' => true]],
    ['POST', '/item/{id}/estado',  [CtlRm::class, 'estado'], ['auth' => true, 'csrf' => true]],
    ['GET',  '/a.b',               [CtlRm::class, 'punto']],
];

function rm(string $metodo, string $path): object
{
    return rmMatch(RUTAS_RM, $metodo, $path);
}

/** @param list<string> $l */
function ordenada(array $l): array
{
    sort($l);

    return $l;
}

test('200: handler, params int y options de la ruta', function () {
    $m = rm('GET', '/item/42');
    assertSame(200, $m->status);
    assertSame([CtlRm::class, 'item'], $m->handler);
    assertSame([42], $m->params);
    assertSame(['auth' => true], $m->options);
});

test('200: ruta sin parámetros → params [] y options [] por defecto', function () {
    $m = rm('GET', '/ver');
    assertSame(200, $m->status);
    assertSame([], $m->params);
    assertSame([], $m->options);
});

test('200: el {id} en medio del patrón', function () {
    $m = rm('POST', '/item/7/estado');
    assertSame([CtlRm::class, 'estado'], $m->handler);
    assertSame([7], $m->params);
});

test('200: ids grandes de hasta 10 dígitos llegan como int', function () {
    assertSame([4294967295], rm('GET', '/item/4294967295')->params);
    assertSame([9999999999], rm('GET', '/item/9999999999')->params);
});

test('HEAD se resuelve con la ruta GET', function () {
    $m = rm('HEAD', '/ver');
    assertSame(200, $m->status);
    assertSame([CtlRm::class, 'ver'], $m->handler);
});

test('404: ruta inexistente', function () {
    foreach (['/nada', '/ver/x', '/item', '/item/', '/Ver', '/axb'] as $p) {
        assertSame(404, rm('GET', $p)->status, $p);
    }
});

test('404: {id} con 0, ceros a la izquierda, > 10 dígitos, signos o no numérico', function () {
    foreach (['0', '01', '007', '12345678901', '-1', '+1', '1.0', '1e3', 'abc', '1a', ' 1'] as $id) {
        assertSame(404, rm('GET', '/item/' . $id)->status, "id «{$id}»");
    }
});

test('404: "\n" final ($D) y %0A codificado no matchean', function () {
    assertSame(404, rm('GET', "/item/1\n")->status);
    assertSame(404, rm('GET', '/item/1%0A')->status);
    assertSame(404, rm('GET', "/ver\n")->status);
});

test('404: el "." del patrón es literal', function () {
    assertSame(404, rm('GET', '/axb')->status);
    assertSame(200, rm('GET', '/a.b')->status);
});

test('405: solo POST → allow [POST], sin handler', function () {
    $m = rm('GET', '/guardar');
    assertSame(405, $m->status);
    assertSame(['POST'], array_values($m->allow));
    assertNull($m->handler ?? null);
});

test('405: con GET disponible, allow incluye HEAD', function () {
    $m = rm('POST', '/ver');
    assertSame(405, $m->status);
    assertSame(['GET', 'HEAD'], ordenada($m->allow));
});

test('405: varias rutas del mismo path → allow con todos los métodos, sin repetir', function () {
    $m = rm('DELETE', '/item/3');
    assertSame(405, $m->status);
    assertSame(['GET', 'HEAD', 'POST'], ordenada($m->allow));
    assertTrue(array_is_list($m->allow), 'allow es una lista');
});

test('405 también con {id} inválido? no: id inválido es 404 aunque otro método exista', function () {
    assertSame(404, rm('DELETE', '/item/0')->status);
});

test('puro: no lee $_SERVER (el método del argumento manda)', function () {
    $antes = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/guardar';
        assertSame(200, rm('GET', '/ver')->status);
        assertSame([CtlRm::class, 'ver'], rm('GET', '/ver')->handler);
    } finally {
        $_SERVER = $antes;
    }
});

test('puro: no produce salida ni fija código HTTP', function () {
    ob_start();
    rm('GET', '/nada');
    rm('POST', '/ver');
    rm('GET', '/ver');
    $salida = (string) ob_get_clean();
    assertSame('', $salida);
    assertFalse(http_response_code(), 'no tocó el código de respuesta');
});

test('RouteMatch es inmutable (readonly)', function () {
    $m = rm('GET', '/ver');
    assertThrows(Error::class, function () use ($m) {
        $m->status = 500;
    });
});

test('la tabla real de rutas (app/routes.php) resuelve lo esperado', function () {
    $rutas = require APP_DIR . '/routes.php';
    $esperado = [
        ['GET', '/', 200], ['POST', '/solicitudes', 200], ['GET', '/gracias', 200],
        ['GET', '/admin/login', 200], ['POST', '/admin/login', 200], ['POST', '/admin/logout', 200],
        ['GET', '/admin', 200], ['GET', '/admin/solicitudes/1', 200], ['POST', '/admin/solicitudes/1/estado', 200],
        ['GET', '/solicitudes', 405], ['GET', '/admin/logout', 405], ['GET', '/admin/solicitudes/1/estado', 405],
        ['GET', '/admin/solicitudes/0', 404], ['GET', '/index.php', 404], ['GET', '/.htaccess', 404],
    ];
    foreach ($esperado as [$metodo, $path, $status]) {
        assertSame($status, rmMatch($rutas, $metodo, $path)->status, "{$metodo} {$path}");
    }
    $logout = rmMatch($rutas, 'POST', '/admin/logout');
    assertTrue(($logout->options['auth'] ?? false) === true, 'logout exige auth');
});
