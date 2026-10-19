<?php

declare(strict_types=1);

use App\Models\Admin;

// Admin::normalizeEmail (D-41) y passwordAcceptable (A2-04: 72 bytes y NUL). Sin DB.

test('normalizeEmail: recorta y pasa a minúsculas', function () {
    assertSame('admin@example.com', Admin::normalizeEmail("  ADMIN@Example.COM \n"));
});

test('normalizeEmail: minúsculas multibyte (mb_strtolower, no strtolower)', function () {
    assertSame('ádmin@exámple.com', Admin::normalizeEmail('ÁDMIN@EXÁMPLE.COM'));
    assertSame('ñandú@example.com', Admin::normalizeEmail('ÑANDÚ@example.com'));
});

test('normalizeEmail: no quita acentos (ádmin ≠ admin, D-41)', function () {
    assertNotSame(Admin::normalizeEmail('admin@example.com'), Admin::normalizeEmail('ádmin@example.com'));
});

test('normalizeEmail: idempotente', function () {
    $una = Admin::normalizeEmail(' Ánä@Example.com ');
    assertSame($una, Admin::normalizeEmail($una));
});

test('passwordAcceptable: hasta 72 bytes sí, 73 no', function () {
    assertTrue(Admin::passwordAcceptable(str_repeat('a', 72)));
    assertFalse(Admin::passwordAcceptable(str_repeat('a', 73)));
});

test('passwordAcceptable: cuenta bytes, no caracteres (36 "ñ" = 72 bytes)', function () {
    assertTrue(Admin::passwordAcceptable(str_repeat('ñ', 36)));
    assertFalse(Admin::passwordAcceptable(str_repeat('ñ', 36) . 'a'));
});

test('passwordAcceptable: NUL en cualquier posición → false', function () {
    foreach (["\0", "Demo\0", "\0Demo", "De\0mo"] as $p) {
        assertFalse(Admin::passwordAcceptable($p), exportar($p));
    }
});

test('passwordAcceptable: password común → true', function () {
    assertTrue(Admin::passwordAcceptable('Demo-UTN-2026!'));
});

test('hash fijo: bcrypt costo 12 (D-35, D-37), independiente de PASSWORD_DEFAULT', function () {
    assertSame(PASSWORD_BCRYPT, Admin::HASH_ALGO);
    assertSame(['cost' => 12], Admin::HASH_OPTIONS);
    assertFalse(Admin::needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 12])));
    assertTrue(Admin::needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 10])), 'costo 10 (default de PHP 8.2/8.3)');
});

test('el hash ficticio de Auth tiene el mismo algoritmo y costo que los reales', function () {
    $dummy = (new ReflectionClassConstant(App\Core\Auth::class, 'DUMMY_HASH'))->getValue();
    $info = password_get_info($dummy);
    assertSame('bcrypt', $info['algoName']);
    assertSame(12, $info['options']['cost'] ?? null);
});
