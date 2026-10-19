<?php

declare(strict_types=1);

// AU-2 (A1-15, D-62): en producción scripts/instalar-db.php nunca carga el seed (trae un admin con
// contraseña pública). Se corre el script real sobre una copia de la app con un config.local.php de
// prueba cuya DB es 127.0.0.1:1 (no escucha nada): la guardia tiene que cortar ANTES de conectar, y
// cuando deja pasar, lo que sigue es un error de conexión, no una instalación.

const PROD = ['app' => ['env' => 'production', 'debug' => false, 'base_url' => 'https://example.com']];

function noConecto(string $salida): bool
{
    return stripos($salida, 'conectar') === false;
}

test('producción sin --force → aborta sin conectar (comportamiento previo, M2 de A2-02)', function () {
    [$exit, $salida] = instalarDbEnCopia(PROD, []);
    assertNotSame(0, $exit);
    assertTrue(noConecto($salida), 'cortó antes de conectar: ' . trim($salida));
});

test('AU-2: producción con --force pero sin --sin-datos → aborta sin conectar (no carga el seed)', function () {
    [$exit, $salida] = instalarDbEnCopia(PROD, ['--force']);
    assertNotSame(0, $exit);
    assertTrue(noConecto($salida), 'cortó antes de conectar: ' . trim($salida));
    assertContains('--sin-datos', $salida, 'indica el camino correcto');
});

test('producción con --force --sin-datos → pasa la guardia (falla recién al conectar)', function () {
    [$exit, $salida] = instalarDbEnCopia(PROD, ['--force', '--sin-datos']);
    assertNotSame(0, $exit, 'no hay DB en 127.0.0.1:1');
    assertFalse(noConecto($salida), 'el error es de conexión, no de la guardia: ' . trim($salida));
});

test('local sin opciones → pasa la guardia (falla recién al conectar)', function () {
    [$exit, $salida] = instalarDbEnCopia(['app' => ['env' => 'local', 'debug' => false]], []);
    assertNotSame(0, $exit);
    assertFalse(noConecto($salida), trim($salida));
});

test('opción desconocida → uso y exit ≠ 0', function () {
    [$exit, $salida] = instalarDbEnCopia(['app' => ['env' => 'local', 'debug' => false]], ['--borrar-todo']);
    assertNotSame(0, $exit);
    assertContains('Uso:', $salida);
});
