<?php

declare(strict_types=1);

use App\Core\Validator;

// Validator: encoding, recorte Unicode, controles, multiline, max, email (A2-03, D-31).

const REGLAS_LINEA = ['nombre' => ['required', 'max:5']];

function validar(array $input, array $reglas): array
{
    return Validator::validate($input, $reglas, ['nombre' => 'Nombre', 'desc' => 'Descripción', 'email' => 'Email']);
}

test('UTF-8 inválido → error "no válidos" y el valor no se re-muestra', function () {
    [$data, $err] = validar(['nombre' => "\xFFAna"], REGLAS_LINEA);
    assertSame('', $data['nombre']);
    assertContains('caracteres no válidos', $err['nombre'] ?? '');
});

test('UTF-8 truncado (\xC3\x28) también se rechaza', function () {
    [, $err] = validar(['nombre' => "\xC3\x28"], REGLAS_LINEA);
    assertContains('no válidos', $err['nombre'] ?? '');
});

test('recorta espacios Unicode (NBSP, ZWSP, BOM, U+3000) en los extremos', function () {
    [$data, $err] = validar(['nombre' => "\u{00A0}\u{200B}Ana\u{FEFF}\u{3000} "], REGLAS_LINEA);
    assertSame('Ana', $data['nombre']);
    assertSame([], $err);
});

test('no recorta espacios internos', function () {
    [$data] = validar(['nombre' => " A\u{00A0}B "], REGLAS_LINEA);
    assertSame("A\u{00A0}B", $data['nombre']);
});

test('solo espacios Unicode con required → obligatorio', function () {
    foreach (["\u{00A0}", "\u{200B}", "\u{FEFF}", " \t "] as $v) {
        [, $err] = validar(['nombre' => $v], REGLAS_LINEA);
        assertContains('obligatorio', $err['nombre'] ?? '', 'valor ' . bin2hex($v));
    }
});

test('campo ausente con required → obligatorio', function () {
    [$data, $err] = validar([], REGLAS_LINEA);
    assertSame('', $data['nombre']);
    assertContains('obligatorio', $err['nombre'] ?? '');
});

test('vacío sin required → sin error', function () {
    [, $err] = validar(['nombre' => ''], ['nombre' => ['max:5']]);
    assertSame([], $err);
});

test('controles (NUL, ESC, DEL, BEL) se rechazan en una línea', function () {
    foreach (["A\x00B", "A\x1BB", "A\x7FB", "A\x07B"] as $v) {
        [$data, $err] = validar(['nombre' => $v], REGLAS_LINEA);
        assertContains('no válidos', $err['nombre'] ?? '', 'valor ' . bin2hex($v));
        assertSame('', $data['nombre'], 'el valor con controles no vuelve al HTML');
    }
});

// TAB en una línea hoy se acepta (el contrato de A1-03d solo exige CR/LF); el docblock de
// Validator dice lo contrario: reportado en A2-07, no se fija acá hasta que se decida.
test('CR y LF internos se rechazan en un campo de una línea', function () {
    foreach (["A\nB", "A\rB", "A\r\nB"] as $v) {
        [, $err] = validar(['nombre' => $v], REGLAS_LINEA);
        assertContains('no válidos', $err['nombre'] ?? '', 'valor ' . bin2hex($v));
    }
});

test('multiline acepta \n, \r\n y \t internos', function () {
    [$data, $err] = validar(['desc' => "línea 1\r\nlínea 2\n\tsangría"], ['desc' => ['required', 'multiline', 'max:100']]);
    assertSame([], $err);
    assertSame("línea 1\r\nlínea 2\n\tsangría", $data['desc']);
});

test('multiline sigue rechazando NUL, VT, FF y ESC', function () {
    foreach (["a\x00b", "a\x0Bb", "a\x0Cb", "a\x1Bb"] as $v) {
        [, $err] = validar(['desc' => $v], ['desc' => ['required', 'multiline']]);
        assertContains('no válidos', $err['desc'] ?? '', 'valor ' . bin2hex($v));
    }
});

test('max cuenta caracteres, no bytes (ñ y emoji)', function () {
    [, $err] = validar(['nombre' => 'ñññññ'], REGLAS_LINEA);
    assertSame([], $err, '5 ñ = 10 bytes pero 5 caracteres');
    [, $err] = validar(['nombre' => 'ññññññ'], REGLAS_LINEA);
    assertContains('como máximo 5', $err['nombre'] ?? '');
    [, $err] = validar(['nombre' => '😀😀😀😀😀'], REGLAS_LINEA);
    assertSame([], $err);
});

test('max se aplica después del recorte', function () {
    [, $err] = validar(['nombre' => "  Ana  \u{00A0}"], ['nombre' => ['max:3']]);
    assertSame([], $err);
});

test('email válido e inválido', function () {
    $reglas = ['email' => ['required', 'email', 'max:254']];
    [, $err] = validar(['email' => 'ana@example.com'], $reglas);
    assertSame([], $err);
    foreach (['ana', 'ana@', '@example.com', 'ana@@example.com', 'ana example@example.com'] as $v) {
        [, $err] = validar(['email' => $v], $reglas);
        assertContains('email válido', $err['email'] ?? '', "«{$v}»");
    }
});

test('un error por campo: el primero de la lista de reglas', function () {
    [, $err] = validar(['email' => str_repeat('a', 300)], ['email' => ['email', 'max:254']]);
    assertContains('email válido', $err['email'] ?? '');
});

test('regla desconocida → InvalidArgumentException (error de programación)', function () {
    assertThrows(InvalidArgumentException::class, fn () => validar(['nombre' => 'x'], ['nombre' => ['numerico']]));
});

test('solo se devuelven los campos con reglas', function () {
    [$data] = validar(['nombre' => 'Ana', 'extra' => 'x'], REGLAS_LINEA);
    assertSame(['nombre'], array_keys($data));
});

// A2-08 (hallazgo): el recorte es O(n²) con una corrida larga de espacios que no está al final
// (la alternativa "[…]+$" se reintenta desde cada posición). Con pcre.jit=0 —como en este
// equipo— 20 000 espacios internos tardan ~2,4 s por request y 40 000 ~10 s, sin login (form
// público). Se fuerza jit=0 para que el caso no dependa del php.ini.
test('recorte lineal: 20 000 espacios internos sin pcre.jit en < 0,5 s', function () {
    $antes = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    try {
        $t = hrtime(true);
        [, $err] = validar(['desc' => 'a' . str_repeat(' ', 20000) . 'a'], ['desc' => ['required', 'multiline', 'max:5000']]);
        $seg = (hrtime(true) - $t) / 1e9;
    } finally {
        ini_set('pcre.jit', (string) $antes);
    }
    assertContains('como máximo 5000', $err['desc'] ?? '', 'igual se rechaza por largo');
    assertTrue($seg < 0.5, sprintf('tardó %.2f s', $seg));
});
