<?php

declare(strict_types=1);

use App\Models\Estado;

// Auditoría final (D-62), contra la DB real configurada por bootstrap-cli.php:
//  - AU-7: los tres ENUM de estado (solicitudes.estado, solicitud_eventos.estado_anterior y
//    estado_nuevo) coinciden con Estado::cases() en valores y orden (schema.sql ya lo verifica
//    unitariamente; acá se mira el esquema instalado).
//  - AU-4: la zona horaria de la sesión SQL coincide con la de PHP (D-24): NOW() de la DB y date()
//    de PHP difieren a lo sumo 2 s. Sin esto, created_at (DEFAULT CURRENT_TIMESTAMP) y las fechas
//    que muestra el panel o el log quedarían corridas en un hosting con la DB en UTC.

test('AU-7: los tres ENUM instalados coinciden con Estado::cases()', function () {
    requiereDb();
    $esperado = "enum('" . implode("','", array_map(fn (Estado $e) => $e->value, Estado::cases())) . "')";
    foreach ([['solicitudes', 'estado'], ['solicitud_eventos', 'estado_anterior'], ['solicitud_eventos', 'estado_nuevo']] as [$tabla, $col]) {
        $tipo = valor(
            'SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$tabla, $col]
        );
        assertSame($esperado, strtolower((string) $tipo), "{$tabla}.{$col}");
    }
});

test('AU-4: NOW() de la DB coincide con date() de PHP (±2 s)', function () {
    requiereDb();
    $antes = time();
    $db = (string) valor('SELECT DATE_FORMAT(NOW(), "%Y-%m-%d %H:%i:%s")');
    $despues = time();
    $tsDb = (new DateTimeImmutable($db))->getTimestamp(); // interpretado en la zona de PHP
    $desvio = min(abs($tsDb - $antes), abs($tsDb - $despues));
    assertTrue($desvio <= 2, sprintf('DB %s vs PHP %s (%s): desvío %d s', $db, date('Y-m-d H:i:s', $antes), date_default_timezone_get(), $desvio));
});

test('AU-4: created_at de una fila nueva queda en la hora local de PHP (±2 s)', function () {
    requiereDb();
    $pdo = requiereDb();
    $pdo->prepare("INSERT INTO solicitudes (nombre, email, asunto, descripcion) VALUES ('Hora', 'hora@example.com', 'AU-4', 'zona horaria')")->execute();
    $id = (int) $pdo->lastInsertId();
    try {
        $creada = (string) valor('SELECT created_at FROM solicitudes WHERE id = ?', [$id]);
        $desvio = abs((new DateTimeImmutable($creada))->getTimestamp() - time());
        assertTrue($desvio <= 2, "created_at {$creada} vs " . date('Y-m-d H:i:s') . ": desvío {$desvio} s");
    } finally {
        $pdo->prepare('DELETE FROM solicitudes WHERE id = ?')->execute([$id]);
    }
});
