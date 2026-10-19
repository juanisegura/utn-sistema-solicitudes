<?php

declare(strict_types=1);

use App\Core\Request;

// Request::ip() con proxies de confianza (M-1, D-47, API D-49: Request::configure($app)).
// Reglas: sin proxies el header se ignora; con REMOTE_ADDR de confianza se lee
// X-Forwarded-For de derecha a izquierda y se devuelve la primera IP no confiable;
// (a) todas de confianza → la de más a la izquierda; (b) inválida antes de encontrar una no
// confiable → REMOTE_ADDR; (c) normalización de espacios, puertos, corchetes e IPv4-mapped
// (también en REMOTE_ADDR); (d) entrada inválida en trusted_proxies → InvalidArgumentException.

/** Configura proxies, arma $_SERVER y devuelve Request::ip(); restaura $_SERVER siempre. */
function ipCon(array $proxies, string $remote, ?string $xff = null): string
{
    $antes = $_SERVER;
    try {
        Request::configure(['trusted_proxies' => $proxies]);
        $_SERVER['REMOTE_ADDR'] = $remote;
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        if ($xff !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
        }

        return Request::ip();
    } finally {
        $_SERVER = $antes;
        Request::configure(['trusted_proxies' => []]);
    }
}

test('sin proxies configurados: X-Forwarded-For se ignora', function () {
    assertSame('198.51.100.7', ipCon([], '198.51.100.7', '203.0.113.5'));
});

test('sin clave trusted_proxies en la config: equivale a lista vacía', function () {
    $antes = $_SERVER;
    try {
        Request::configure([]);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5';
        assertSame('198.51.100.7', Request::ip());
    } finally {
        $_SERVER = $antes;
    }
});

test('proxy de confianza: devuelve el cliente que agregó', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1'], '10.0.0.1', '203.0.113.5'));
});

test('XFF desde una IP NO confiable (falsificado) → REMOTE_ADDR', function () {
    assertSame('198.51.100.7', ipCon(['10.0.0.1'], '198.51.100.7', '203.0.113.5'));
});

test('el cliente falsifica la izquierda del XFF: se lee de derecha a izquierda', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1'], '10.0.0.1', '6.6.6.6, 203.0.113.5'));
});

test('cadena de dos proxies de confianza', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1', '10.0.0.2'], '10.0.0.1', '6.6.6.6, 203.0.113.5, 10.0.0.2'));
});

test('proxy por CIDR IPv4', function () {
    assertSame('203.0.113.9', ipCon(['10.0.0.0/8'], '10.1.2.3', '203.0.113.9'));
    assertSame('11.0.0.1', ipCon(['10.0.0.0/8'], '11.0.0.1', '203.0.113.9'), 'fuera del CIDR');
});

test('CIDR /32 y /0 en los bordes', function () {
    assertSame('203.0.113.9', ipCon(['10.0.0.1/32'], '10.0.0.1', '203.0.113.9'));
    assertSame('10.0.0.2', ipCon(['10.0.0.1/32'], '10.0.0.2', '203.0.113.9'));
});

test('IPv6: proxy exacto (::1) y por CIDR', function () {
    assertSame('203.0.113.5', ipCon(['::1'], '::1', '203.0.113.5'));
    assertSame('2a00:1450::1', ipCon(['2001:db8::/32'], '2001:db8::10', '2a00:1450::1'));
    assertSame('2001:db9::1', ipCon(['2001:db8::/32'], '2001:db9::1', '2a00:1450::1'), 'fuera del /32');
});

test('IPv4 y IPv6 no se confunden entre sí', function () {
    assertSame('10.0.0.1', ipCon(['2001:db8::/32'], '10.0.0.1', '203.0.113.5'));
    assertSame('2001:db8::1', ipCon(['10.0.0.0/8'], '2001:db8::1', '203.0.113.5'));
});

test('header ausente o vacío con proxy de confianza → REMOTE_ADDR', function () {
    assertSame('10.0.0.1', ipCon(['10.0.0.1'], '10.0.0.1', null));
    assertSame('10.0.0.1', ipCon(['10.0.0.1'], '10.0.0.1', ''));
    assertSame('10.0.0.1', ipCon(['10.0.0.1'], '10.0.0.1', ' , '));
});

test('header inválido → REMOTE_ADDR', function () {
    assertSame('10.0.0.1', ipCon(['10.0.0.1'], '10.0.0.1', 'basura'));
    assertSame('10.0.0.1', ipCon(['10.0.0.1'], '10.0.0.1', '999.1.1.1'));
});

test('(a) todas las entradas son de confianza → la de más a la izquierda', function () {
    assertSame('10.0.0.3', ipCon(['10.0.0.0/8'], '10.0.0.1', '10.0.0.3, 10.0.0.2'));
});

test('(b) inválida antes de una no confiable (derecha a izquierda) → REMOTE_ADDR', function () {
    assertSame('10.0.0.1', ipCon(['10.0.0.1'], '10.0.0.1', '203.0.113.5, basura'));
    assertSame('10.0.0.1', ipCon(['10.0.0.0/8'], '10.0.0.1', '203.0.113.5, basura, 10.0.0.2'));
});

test('(b) una inválida más a la izquierda de la no confiable no importa', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1'], '10.0.0.1', 'basura, 203.0.113.5'));
});

test('(c) espacios alrededor de cada entrada', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1'], '10.0.0.1', "  203.0.113.5\t"));
});

test('(c) puerto en IPv4 y corchetes/puerto en IPv6', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1'], '10.0.0.1', '203.0.113.5:5678'));
    assertSame('2001:db8::1', ipCon(['10.0.0.1'], '10.0.0.1', '[2001:db8::1]:443'));
    assertSame('2001:db8::1', ipCon(['10.0.0.1'], '10.0.0.1', '[2001:db8::1]'));
});

test('(c) proxy con puerto en el XFF también se reconoce como de confianza', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.2'], '10.0.0.2', '203.0.113.5, 10.0.0.2:8080'));
});

test('(c) IPv4-mapped en XFF: se devuelve como IPv4', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.1'], '10.0.0.1', '::ffff:203.0.113.5'));
});

test('(c) IPv4-mapped en REMOTE_ADDR matchea un CIDR IPv4 (y se devuelve como IPv4)', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.0/8'], '::ffff:10.0.0.1', '203.0.113.5'));
    assertSame('198.51.100.7', ipCon([], '::ffff:198.51.100.7'));
});

test('(c) entrada IPv4-mapped de un proxy confiable se saltea', function () {
    assertSame('203.0.113.5', ipCon(['10.0.0.0/8'], '10.0.0.1', '203.0.113.5, ::ffff:10.0.0.2'));
});

test('(d) entrada inválida en trusted_proxies → InvalidArgumentException', function () {
    foreach (['foo', '10.0.0.0/33', '::1/129', '10.0.0.0/-1', '10.0.0.0/', '/8', '10.0.0.0/8/8', ''] as $malo) {
        assertThrows(
            InvalidArgumentException::class,
            fn () => Request::configure(['trusted_proxies' => [$malo]]),
            "«{$malo}»"
        );
    }
    Request::configure(['trusted_proxies' => []]);
});

test('(d) trusted_proxies que no es lista de strings → InvalidArgumentException', function () {
    foreach (['10.0.0.1', [10], [['10.0.0.1']]] as $malo) {
        assertThrows(Throwable::class, fn () => Request::configure(['trusted_proxies' => $malo]), exportar($malo));
    }
    Request::configure(['trusted_proxies' => []]);
});

test('sin REMOTE_ADDR → cadena vacía (CLI), sin warnings', function () {
    $antes = $_SERVER;
    try {
        Request::configure(['trusted_proxies' => []]);
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        assertSame('', Request::ip());
    } finally {
        $_SERVER = $antes;
    }
});
