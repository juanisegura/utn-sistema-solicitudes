<?php

declare(strict_types=1);

// Contratos de la auditoría final para la configuración (A1-15, D-62): AU-1 (env=production
// fuerza debug=false) y AU-3 (toda la config se valida en un lugar: cada valor inválido → excepción
// al configurar). Se ejercita el camino real —config.php + Config vía app/bootstrap-cli.php— sobre
// una copia de la app con un config.local.php de prueba (tests/lib/arbol.php): no se toca ni se lee
// la config del entorno y nada conecta a una base.

/** @param array<string, mixed> $app */
function local(array $app): array
{
    return ['app' => $app + ['env' => 'local', 'debug' => false, 'base_url' => 'http://localhost:8080']];
}

test('config válida de base: se configura sin error', function () {
    $r = configurarCopia(local([]));
    assertNull($r['error']);
    assertSame('local', $r['app']['env'] ?? null);
});

test('AU-1: env=production con debug=true → debug queda en false', function () {
    $r = configurarCopia(local(['env' => 'production', 'debug' => true]));
    assertNull($r['error'], 'production + debug no es error: se fuerza');
    assertFalse($r['app']['debug'] ?? null, 'config efectiva');
    assertFalse($r['debugConfig'], 'Config::get(app.debug)');
});

test('AU-1: en local, debug=true se respeta', function () {
    $r = configurarCopia(local(['debug' => true]));
    assertTrue($r['debugConfig']);
});

test('AU-3: cada valor inválido → excepción al configurar que nombra la clave', function () {
    $malos = [
        'session_timeout'  => [59, 0, -1, 'abc'],
        'por_pagina'       => [0, 101, -1],
        'log_max_bytes'    => [1023, 0],
        'log_max_archivos' => [0, 51],
        'env'              => ['prod', 'desarrollo', ''],
    ];
    foreach ($malos as $clave => $valores) {
        foreach ($valores as $v) {
            $r = configurarCopia(local([$clave => $v]));
            assertTrue($r['error'] !== null, "app.{$clave} = " . exportar($v) . ' debía rechazarse');
            assertContains($clave, (string) $r['error'], 'el mensaje nombra la clave');
        }
    }
});

test('AU-3: los bordes válidos se aceptan', function () {
    $buenos = [
        ['session_timeout' => 60], ['por_pagina' => 1], ['por_pagina' => 100],
        ['log_max_bytes' => 1024], ['log_max_archivos' => 1], ['log_max_archivos' => 50],
        ['env' => 'production'],
    ];
    foreach ($buenos as $cfg) {
        $r = configurarCopia(local($cfg));
        assertNull($r['error'], json_encode($cfg));
    }
});

// F-1/F-3 (D-63): valores nuevos de config, validados como el resto (AU-3).
test('F-1/F-3: defaults del límite del formulario y del seguimiento', function () {
    $r = configurarCopia(local([]));
    assertNull($r['error']);
    foreach (['form_max_por_ip' => 5, 'form_ventana_seg' => 600, 'seguimiento_max_por_ip' => 10, 'seguimiento_ventana_seg' => 900] as $k => $v) {
        assertSame($v, (int) ($r['app'][$k] ?? -1), "app.{$k}");
    }
});

test('F-1/F-3: valores inválidos de los límites nuevos → excepción que nombra la clave', function () {
    foreach (['form_max_por_ip', 'form_ventana_seg', 'seguimiento_max_por_ip', 'seguimiento_ventana_seg'] as $clave) {
        foreach ([0, -1, 'abc'] as $v) {
            $r = configurarCopia(local([$clave => $v]));
            assertTrue($r['error'] !== null, "app.{$clave} = " . exportar($v) . ' debía rechazarse');
            assertContains($clave, (string) $r['error']);
        }
    }
});
