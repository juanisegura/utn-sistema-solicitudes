<?php

declare(strict_types=1);

use App\Core\Response;

// Response::redirect: solo paths locales (A2-03 hallazgo 4). El caso aceptado (HttpException con
// Location) está en DespachoTest.php.

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
