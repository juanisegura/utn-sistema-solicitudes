<?php

declare(strict_types=1);

use App\Models\Estado;

// Estado: lista blanca de la app (ADR-0006).

test('fromInput: los tres valores válidos', function () {
    assertSame(Estado::Pendiente, Estado::fromInput('pendiente'));
    assertSame(Estado::EnProceso, Estado::fromInput('en_proceso'));
    assertSame(Estado::Resuelta, Estado::fromInput('resuelta'));
});

test('fromInput: null y vacío → null', function () {
    assertNull(Estado::fromInput(null));
    assertNull(Estado::fromInput(''));
});

test('fromInput: recorta espacios', function () {
    assertSame(Estado::Resuelta, Estado::fromInput(" resuelta\n"));
});

test('fromInput: mayúsculas, etiquetas y valores fuera de lista → null', function () {
    foreach (['Pendiente', 'PENDIENTE', 'En proceso', 'en-proceso', 'cerrada', "' OR 1=1 --", 'pendiente;'] as $v) {
        assertNull(Estado::fromInput($v), "«{$v}»");
    }
});

test('los valores del enum coinciden con el ENUM de la tabla', function () {
    $sql = (string) file_get_contents(APP_DIR . '/../database/schema.sql');
    assertTrue(
        preg_match("/estado\s+ENUM\('pendiente','en_proceso','resuelta'\)/i", (string) preg_replace('/\s*,\s*/', ',', $sql)) === 1,
        'schema.sql declara el mismo ENUM'
    );
    assertSame(['pendiente', 'en_proceso', 'resuelta'], array_map(fn (Estado $e) => $e->value, Estado::cases()));
});

test('label() legible para cada caso', function () {
    assertSame(['Pendiente', 'En proceso', 'Resuelta'], array_map(fn (Estado $e) => $e->label(), Estado::cases()));
});

// AU-7 (Q-11): los tres ENUM de estado del esquema (solicitudes.estado y
// solicitud_eventos.estado_anterior/estado_nuevo) tienen los mismos valores, en el mismo orden,
// que el enum PHP. Contra la DB real lo verifica integration/EsquemaTest.php.
test('los tres ENUM de estado de schema.sql coinciden con Estado::cases()', function () {
    $sql = (string) file_get_contents(APP_DIR . '/../database/schema.sql');
    $esperado = array_map(fn (Estado $e) => $e->value, Estado::cases());
    foreach (['estado', 'estado_anterior', 'estado_nuevo'] as $col) {
        if (preg_match('/\b' . $col . '\s+ENUM\s*\(([^)]*)\)/i', $sql, $m) !== 1) {
            fail("schema.sql no declara la columna ENUM {$col}");
        }
        preg_match_all("/'([^']*)'/", $m[1], $v);
        assertSame($esperado, $v[1], "ENUM de {$col}");
    }
});
