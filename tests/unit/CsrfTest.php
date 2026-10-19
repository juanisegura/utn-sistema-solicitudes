<?php

declare(strict_types=1);

use App\Core\Csrf;

// Csrf::verify con valores no-string, vacíos, de otra sesión y correctos (ADR-0005).
// verify/rotate solo leen $_SESSION: se prueban sin sesión real (el runner vacía $_SESSION).

const TOKEN_OK = 'a3f1c0de9b8e7d6c5b4a39281706f5e4d3c2b1a0918273645546372819a0b1c2';

test('token() sin sesión iniciada → LogicException', function () {
    assertSame(PHP_SESSION_NONE, session_status());
    assertThrows(LogicException::class, fn () => Csrf::token());
});

test('verify: token correcto → true', function () {
    $_SESSION['_csrf_token'] = TOKEN_OK;
    assertTrue(Csrf::verify(TOKEN_OK));
});

test('verify: null, vacío y otro token → false', function () {
    $_SESSION['_csrf_token'] = TOKEN_OK;
    assertFalse(Csrf::verify(null), 'null');
    assertFalse(Csrf::verify(''), 'vacío');
    assertFalse(Csrf::verify(strrev(TOKEN_OK)), 'otro token');
    assertFalse(Csrf::verify(substr(TOKEN_OK, 0, 63)), 'prefijo');
    assertFalse(Csrf::verify(TOKEN_OK . 'x'), 'con sufijo');
    assertFalse(Csrf::verify(strtoupper(TOKEN_OK)), 'mayúsculas');
});

test('verify: sin token en la sesión nunca valida (ni el vacío)', function () {
    unset($_SESSION['_csrf_token']);
    assertFalse(Csrf::verify(''));
    assertFalse(Csrf::verify(TOKEN_OK));
});

test('verify: token guardado no-string (sesión corrupta) → false', function () {
    foreach ([123, ['x'], true, null] as $raro) {
        $_SESSION['_csrf_token'] = $raro;
        assertFalse(Csrf::verify('123'), 'guardado ' . gettype($raro));
    }
});

test('verify: el parámetro tipado rechaza arrays (TypeError, no true)', function () {
    $_SESSION['_csrf_token'] = TOKEN_OK;
    assertThrows(TypeError::class, fn () => Csrf::verify([TOKEN_OK]));
});

test('rotate: el token anterior deja de valer', function () {
    $_SESSION['_csrf_token'] = TOKEN_OK;
    Csrf::rotate();
    assertFalse(Csrf::verify(TOKEN_OK));
});

test('token(): 64 hex, estable dentro de la sesión y nuevo tras rotate (en proceso hijo)', function () {
    $r = enHijo(<<<'PHP'
        session_save_path(__DIR__);
        session_start();
        $a = App\Core\Csrf::token();
        $b = App\Core\Csrf::token();
        App\Core\Csrf::rotate();
        $c = App\Core\Csrf::token();
        $GLOBALS['resultado'] = [$a, $b, $c, App\Core\Csrf::field()];
        PHP);
    [$a, $b, $c, $field] = $r['resultado'];
    assertTrue(preg_match('/^[0-9a-f]{64}$/', $a) === 1, 'formato');
    assertSame($a, $b, 'estable');
    assertNotSame($a, $c, 'nuevo tras rotate');
    assertSame('<input type="hidden" name="csrf_token" value="' . $c . '">', $field);
});
