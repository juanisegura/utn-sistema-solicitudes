<?php

declare(strict_types=1);

use App\Core\CodigoSeguimiento;

// F-3 (D-63, ADR-0013): App\Core\CodigoSeguimiento — 128 bits de random_bytes(16) en base32 de
// Crockford (sin I, L, O ni U), 26 caracteres en grupos de 4 con guiones. normalizar(): mayúsculas,
// sin espacios ni guiones, O → 0 e I/L → 1. hash(): SHA-256 hex del normalizado (lo único que se
// guarda). formatear(): grupos de 4 a partir de cualquier forma.

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

test('formato: 26 caracteres de Crockford en grupos de 4 (el último de 2)', function () {
    $c = CodigoSeguimiento::generar();
    assertTrue(preg_match('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){5}-[0-9A-HJKMNP-TV-Z]{2}$/', $c) === 1, "formato: {$c}");
    assertSame(26, strlen(CodigoSeguimiento::normalizar($c)));
});

test('alfabeto: nunca I, L, O ni U (300 códigos)', function () {
    $todo = '';
    for ($i = 0; $i < 300; $i++) {
        $todo .= CodigoSeguimiento::normalizar(CodigoSeguimiento::generar());
    }
    assertSame(strlen($todo), strspn($todo, CROCKFORD), 'solo caracteres del alfabeto');
    foreach (['I', 'L', 'O', 'U'] as $ch) {
        assertFalse(str_contains($todo, $ch), $ch);
    }
});

test('128 bits = 25 símbolos + 3 bits: el último lleva 2 bits de relleno en 0 (0, 4, 8, C, G, M, R o W)', function () {
    for ($i = 0; $i < 200; $i++) {
        $n = CodigoSeguimiento::normalizar(CodigoSeguimiento::generar());
        assertTrue(in_array($n[25], ['0', '4', '8', 'C', 'G', 'M', 'R', 'W'], true), "último símbolo {$n[25]}");
    }
});

test('300 códigos distintos', function () {
    $c = [];
    for ($i = 0; $i < 300; $i++) {
        $c[] = CodigoSeguimiento::generar();
    }
    assertSame(300, count(array_unique($c)));
});

test('normalización: minúsculas, guiones, espacios y tabs no importan', function () {
    $c = CodigoSeguimiento::generar();
    $n = CodigoSeguimiento::normalizar($c);
    foreach ([strtolower($c), str_replace('-', '', $c), '  ' . str_replace('-', ' ', $c) . "\t", str_replace('-', ' - ', $c)] as $v) {
        assertSame($n, CodigoSeguimiento::normalizar($v), exportar($v));
    }
});

test('normalización de lo que se confunde al copiar: O → 0, I y L → 1 (también en minúscula)', function () {
    assertSame('01101', CodigoSeguimiento::normalizar('OIL0l'));
    assertSame('0', CodigoSeguimiento::normalizar('o'));
    $c = CodigoSeguimiento::normalizar(CodigoSeguimiento::generar());
    $conLetras = strtr($c, ['0' => 'O', '1' => 'l']);
    assertSame(CodigoSeguimiento::hash($c), CodigoSeguimiento::hash($conLetras), 'mismo hash con O/l en lugar de 0/1');
});

test('dos códigos válidos distintos nunca colisionan al normalizar (normalizar es la identidad sobre el alfabeto)', function () {
    for ($i = 0; $i < 100; $i++) {
        $n = CodigoSeguimiento::normalizar(CodigoSeguimiento::generar());
        assertSame($n, CodigoSeguimiento::normalizar($n));
    }
});

test('hash: SHA-256 hex del normalizado, distinto del código, igual para las variantes', function () {
    $c = CodigoSeguimiento::generar();
    $h = CodigoSeguimiento::hash($c);
    assertTrue(preg_match('/^[0-9a-f]{64}$/', $h) === 1);
    assertSame(hash('sha256', CodigoSeguimiento::normalizar($c)), $h);
    assertFalse(str_contains($h, strtolower(CodigoSeguimiento::normalizar($c))));
    assertSame($h, CodigoSeguimiento::hash(strtolower(str_replace('-', ' ', $c))));
});

test('formatear: cualquier forma vuelve al formato de grupos', function () {
    $c = CodigoSeguimiento::generar();
    assertSame($c, CodigoSeguimiento::formatear(strtolower(str_replace('-', '', $c))));
});

test('entradas raras no rompen: UTF-8 inválido, NUL, larguísimas → un hash de 64 hex (que no coincide)', function () {
    foreach (["\xFF\xFE", "ABC\0DEF", str_repeat('Z', 10000), '', 'ñandú'] as $v) {
        assertTrue(preg_match('/^[0-9a-f]{64}$/', CodigoSeguimiento::hash($v)) === 1, exportar(substr($v, 0, 20)));
    }
});
