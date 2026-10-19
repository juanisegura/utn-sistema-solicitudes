<?php

declare(strict_types=1);

// F-3 (D-63): código de seguimiento de 128 bits (random_bytes(16)), legible (grupos de base32 sin
// ambiguos), normalizado (mayúsculas, sin guiones ni espacios) antes de hashear; en la DB solo
// SHA-256 del normalizado. La firma la fija A1-17: el adaptador busca entre los nombres posibles
// y deja PENDIENTE mientras no exista.

/** @return array{0: callable, 1: callable, 2: callable} [generar, normalizar, hash] */
function apiCodigo(): array
{
    $clases = ['App\\Models\\Seguimiento', 'App\\Core\\Seguimiento', 'App\\Core\\CodigoSeguimiento', 'App\\Models\\Solicitud'];
    $nombres = [
        'generar'    => ['generarCodigo', 'generar', 'nuevoCodigo', 'generarCodigoSeguimiento'],
        'normalizar' => ['normalizarCodigo', 'normalizar', 'normalizarCodigoSeguimiento'],
        'hash'       => ['hashCodigo', 'hash', 'hashSeguimiento', 'seguimientoHash'],
    ];
    foreach ($clases as $c) {
        if (!class_exists($c)) {
            continue;
        }
        $f = [];
        foreach ($nombres as $rol => $cands) {
            foreach ($cands as $m) {
                if (method_exists($c, $m)) {
                    $f[$rol] = static fn (...$a) => llamarPrivado($c, $m, ...$a);
                    break;
                }
            }
        }
        if (count($f) === 3) {
            return [$f['generar'], $f['normalizar'], $f['hash']];
        }
    }
    pending('API del código de seguimiento no implementada todavía (F-3, A1-17)');
}

test('formato legible: grupos de hasta 4 separados por guiones, ≥ 26 caracteres útiles (128 bits en base32)', function () {
    [$gen, $norm] = apiCodigo();
    $c = $gen();
    assertTrue(preg_match('/^[A-Z0-9]{4}(-[A-Z0-9]{1,4})+$/', $c) === 1, "formato: {$c}");
    assertTrue(strlen($norm($c)) >= 26, 'largo útil');
});

test('alfabeto sin ambiguos: nunca 0 junto con O, ni 1 junto con I o L', function () {
    [$gen, $norm] = apiCodigo();
    $vistos = '';
    for ($i = 0; $i < 300; $i++) {
        $vistos .= $norm($gen());
    }
    $hay = fn (string $ch) => str_contains($vistos, $ch);
    assertFalse($hay('0') && $hay('O'), '0 y O');
    assertFalse($hay('1') && ($hay('I') || $hay('L')), '1 e I/L');
    assertTrue(preg_match('/^[A-Z0-9]+$/', $vistos) === 1);
});

test('dos códigos nunca coinciden (300 generados)', function () {
    [$gen] = apiCodigo();
    $c = [];
    for ($i = 0; $i < 300; $i++) {
        $c[] = $gen();
    }
    assertSame(300, count(array_unique($c)));
});

test('normalización: minúsculas, guiones y espacios no importan', function () {
    [$gen, $norm] = apiCodigo();
    $c = $gen();
    $n = $norm($c);
    assertSame($n, $norm(strtolower($c)));
    assertSame($n, $norm(str_replace('-', '', $c)));
    assertSame($n, $norm('  ' . str_replace('-', ' ', $c) . "\t"));
    assertTrue(preg_match('/^[A-Z0-9]+$/', $n) === 1, 'sin separadores');
});

test('hash: SHA-256 hex del normalizado, distinto del código, igual para las variantes', function () {
    [$gen, $norm, $hash] = apiCodigo();
    $c = $gen();
    $h = $hash($c);
    assertTrue(preg_match('/^[0-9a-f]{64}$/', $h) === 1, 'CHAR(64)');
    assertSame(hash('sha256', $norm($c)), $h);
    assertNotSame($c, $h);
    assertFalse(str_contains($h, strtolower($norm($c))), 'el hash no contiene el código');
    assertSame($h, $hash(strtolower(str_replace('-', ' ', $c))));
});
