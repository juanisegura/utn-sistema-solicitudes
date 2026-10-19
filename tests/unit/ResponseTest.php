<?php

declare(strict_types=1);

use App\Core\Response;

// Response::redirect: solo paths locales (A2-03 hallazgo 4). Los rechazos lanzan antes de
// header()/exit, así que se prueban en proceso; el caso aceptado termina con exit → hijo.

test('redirect rechaza destinos que no son paths locales', function () {
    $malos = [
        'http://evil.example', 'https://evil.example/x', '//evil.example', '///evil.example',
        '/\\evil.example', '/\\\\evil.example', 'evil.example', '', 'javascript:alert(1)',
        "/ok\r\nSet-Cookie: x=1", "/ok\n", "/ok\0", "/ok\t", "/ok\x7F", "\t/ok",
    ];
    foreach ($malos as $destino) {
        assertThrows(InvalidArgumentException::class, fn () => Response::redirect($destino), exportar($destino));
    }
});

test('redirect a un path local → 302 y termina (proceso hijo)', function () {
    $r = enHijo(<<<'PHP'
        App\Core\Response::redirect('/admin/solicitudes/1?x=1');
        $GLOBALS['resultado'] = 'no terminó';
        PHP);
    assertSame(302, $r['http']);
    assertNull($r['resultado'], 'el código después de redirect no se ejecuta');
});

test('redirect con código explícito (303)', function () {
    $r = enHijo('App\Core\Response::redirect("/gracias", 303);');
    assertSame(303, $r['http']);
});
