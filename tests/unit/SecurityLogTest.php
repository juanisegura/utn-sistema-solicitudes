<?php

declare(strict_types=1);

use App\Core\Log;
use App\Core\Request;
use App\Core\SecurityLog;

// F-2 (D-63): registro de eventos de seguridad. Firma de A1-16: SecurityLog::evento($evento,
// $detalle = '', $adminId = null) escribe en el canal 'security' de Log (storage/logs/security.log,
// mismo directorio y rotación que app.log). Línea:
//   "AAAA-MM-DD HH:MM:SS | evento | ip | MÉTODO /path | admin_id o - | detalle"
// Sin datos personales en claro, sin query string y con saltos de línea y separadores del detalle
// neutralizados. Todo en un directorio temporal.

/** Configura Log en un dir temporal, arma la request y devuelve las líneas de security.log. */
function eventoEn(array $server, callable $fn): array
{
    $dir = dirTemporal();
    $antes = $_SERVER;
    try {
        Log::configure($dir . '/app.log', 1048576, 5);
        Request::configure(['trusted_proxies' => []]);
        $_SERVER = $server + $_SERVER;
        $fn();
    } finally {
        $_SERVER = $antes;
    }
    $f = $dir . '/security.log';

    return is_file($f) ? explode("\n", rtrim((string) file_get_contents($f), "\n")) : [];
}

const SRV = ['REMOTE_ADDR' => '203.0.113.9', 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login?email=x@example.com&token=secreto'];

test('una línea con los 6 campos en el orden del contrato', function () {
    $l = eventoEn(SRV, fn () => SecurityLog::evento('login_fallido', 'email=0123456789ab', 7));
    assertSame(1, count($l));
    $c = explode(' | ', $l[0]);
    assertSame(6, count($c), $l[0]);
    assertTrue(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $c[0]) === 1, 'fecha: ' . $c[0]);
    assertSame(['login_fallido', '203.0.113.9', 'POST /admin/login', '7', 'email=0123456789ab'], array_slice($c, 1));
});

test('sin admin → "-"; sin detalle → campo vacío o "-" (el contrato no lo fija)', function () {
    $l = eventoEn(SRV, fn () => SecurityLog::evento('csrf_invalido'));
    $c = explode(' | ', $l[0]);
    assertSame('-', $c[4]);
    assertTrue(in_array(trim($c[5] ?? ''), ['', '-'], true), 'detalle: ' . exportar($c[5] ?? null));
});

test('el path va sin query string (puede traer datos personales o tokens)', function () {
    $l = eventoEn(SRV, fn () => SecurityLog::evento('acceso_sin_sesion', '302'));
    assertFalse(str_contains($l[0], '?'), $l[0]);
    assertFalse(str_contains($l[0], 'secreto'));
    assertFalse(str_contains($l[0], 'x@example.com'));
});

test('saltos de línea, CR y separadores del detalle neutralizados: una sola línea de 6 campos', function () {
    $malicioso = "ok\n2026-01-01 00:00:00 | login_ok | 1.2.3.4 | GET / | 1 | falso\r | extra |x";
    $l = eventoEn(SRV, fn () => SecurityLog::evento('login_fallido', $malicioso, null));
    assertSame(1, count($l), 'no se partió en dos líneas');
    assertSame(6, count(explode(' | ', $l[0])), 'el detalle no agrega campos: ' . $l[0]);
    assertFalse(str_contains($l[0], "\r"));
});

test('caracteres de control del detalle no llegan crudos al archivo', function () {
    $l = eventoEn(SRV, fn () => SecurityLog::evento('honeypot', "a\x1B[31mrojo\x00\x07"));
    assertSame(0, preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $l[0]), exportar($l[0]));
});

test('el evento y el método/path también se neutralizan (no inyectan campos)', function () {
    $l = eventoEn(['REQUEST_METHOD' => "GET | x", 'REQUEST_URI' => "/a | b\nc"] + SRV, fn () => SecurityLog::evento("evento | raro\n"));
    assertSame(1, count($l));
    assertSame(6, count(explode(' | ', $l[0])), $l[0]);
});

test('emailRef: 12 hex del SHA-256 del email normalizado (trim + minúsculas)', function () {
    $esperado = substr(hash('sha256', 'admin@example.com'), 0, 12);
    assertSame($esperado, SecurityLog::emailRef('  ADMIN@Example.COM '));
    assertSame($esperado, SecurityLog::emailRef('admin@example.com'));
    assertTrue(preg_match('/^[0-9a-f]{12}$/', SecurityLog::emailRef('otra@example.com')) === 1);
    assertNotSame($esperado, SecurityLog::emailRef('otra@example.com'));
});

test('canal: security.log al lado de app.log; Log::write sin canal sigue en app.log (API D-49)', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 1048576, 5);
    Log::write('linea-app');
    Log::write('linea-seg', 'security');
    assertSame("linea-app\n", (string) file_get_contents($dir . '/app.log'));
    assertSame("linea-seg\n", (string) file_get_contents($dir . '/security.log'));
});

test('el canal security rota con los mismos límites que app.log', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 5, 2);
    for ($i = 1; $i <= 4; $i++) {
        Log::write("seg-{$i}", 'security');
    }
    assertTrue(is_file($dir . '/security.log.1') && is_file($dir . '/security.log.2'), 'rotó');
    assertFalse(is_file($dir . '/security.log.3'), 'tope de archivos');
    assertFalse(is_file($dir . '/app.log'), 'no tocó app.log');
});
