<?php

declare(strict_types=1);

// R-2 (D-57): las excepciones de control (abort/redirect) se emiten en un único punto y NO se
// loguean como errores ni pasan por la vista 500. R-3 (D-58): app/bootstrap-cli.php deja la DB
// lista sin sesión, headers ni handlers HTTP. Contra la app en BASE_URL y su storage/logs.

const APP_LOG_TEST = __DIR__ . '/../../storage/logs/app.log';

function tamLog(): int
{
    clearstatcache(true, APP_LOG_TEST);

    return is_file(APP_LOG_TEST) ? (int) filesize(APP_LOG_TEST) : 0;
}

test('404, 405, 419, 401, 400 y redirects no escriben en app.log ni muestran la vista 500', function () {
    requiereServidor();
    if (!hayHttpException()) {
        pending('R-2 no entregado (HttpException)');
    }
    $antes = tamLog();
    $c = new Cliente();
    $esperado = [
        ['GET', '/no-existe-r2', [], 404],
        ['GET', '/solicitudes', [], 405],
        ['POST', '/solicitudes', ['csrf_token' => 'falso'], 419],
        ['POST', '/admin/logout', [], 401],
        ['GET', '/admin', [], 302],
        ['GET', '/gracias', [], 302],
    ];
    foreach ($esperado as [$metodo, $path, $campos, $codigo]) {
        $r = $metodo === 'GET' ? $c->get($path) : $c->post($path, $campos);
        assertSame($codigo, $r['code'], "{$metodo} {$path}");
        assertFalse(str_contains($r['body'], 'Ocurrió un error interno'), "{$metodo} {$path} no es la vista 500");
    }
    assertSame($antes, tamLog(), 'app.log no creció');
});

test('405 conserva el header Allow tras el cambio a excepciones', function () {
    requiereServidor();
    $h = (new Cliente())->handle('GET', '/solicitudes');
    curl_setopt($h, CURLOPT_HEADER, true);
    $raw = (string) curl_exec($h);
    assertSame(405, (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE));
    assertTrue(preg_match('/^Allow:\s*POST\s*$/mi', $raw) === 1, 'Allow: POST');
});

test('bootstrap-cli: Database lista, sin sesión ni salida (proceso hijo limpio)', function () {
    if (!is_file(APP_DIR . '/bootstrap-cli.php')) {
        pending('R-3 no entregado: falta app/bootstrap-cli.php');
    }
    requiereDb(); // SKIP si no hay DB de prueba
    $cli = realpath(APP_DIR . '/bootstrap-cli.php');
    $r = enHijo(<<<PHP
        ob_start();
        require '{$cli}';
        \$salida = ob_get_clean();
        \$uno = (int) App\\Core\\Database::fetchOne('SELECT 1 AS n')['n'];
        \$GLOBALS['resultado'] = [
            'salida'  => \$salida,
            'uno'     => \$uno,
            'sesion'  => session_status(),
            'handler' => set_exception_handler(null) !== null,
        ];
        PHP, [], false);
    assertSame('', $r['resultado']['salida'], 'no imprime nada (tampoco la config)');
    assertSame(1, $r['resultado']['uno'], 'Database configurada desde Config');
    assertSame(PHP_SESSION_NONE, $r['resultado']['sesion']);
    assertFalse($r['resultado']['handler'], 'sin exception handler HTTP');
});
