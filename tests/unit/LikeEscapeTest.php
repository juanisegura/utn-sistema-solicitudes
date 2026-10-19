<?php

declare(strict_types=1);

use App\Models\Solicitud;

// Escape de LIKE para la búsqueda del listado (M-3, A1-10): %, _ y \ del texto escapados para
// usarse con ESCAPE (bindeado, D-53).

function escapeLike(string $texto): string
{
    return Solicitud::escapeLike($texto);
}

test('texto sin comodines queda igual', function () {
    assertSame('impresora rota', escapeLike('impresora rota'));
});

test('% y _ se escapan', function () {
    assertSame('100\\% real', escapeLike('100% real'));
    assertSame('a\\_b', escapeLike('a_b'));
});

test('la barra se escapa primero (no se duplica el escape de % y _)', function () {
    assertSame('c:\\\\temp\\%', escapeLike('c:\\temp%'));
    assertSame('\\\\\\_', escapeLike('\\_'));
});

test('multibyte intacto', function () {
    assertSame('ñandú\\_ü', escapeLike('ñandú_ü'));
});
