<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Envíos de formularios públicos para el límite de frecuencia por IP (F-1, ADR-0012). Las
 * ventanas se calculan con el reloj de la DB, como en LoginIntento.
 */
final class EnvioPublico
{
    public const ACCIONES = ['solicitud', 'seguimiento'];
    private const RETENCION_SEG = 86400;

    public static function registrar(string $ip, string $accion): int
    {
        Database::execute('INSERT INTO envios_publicos (ip, accion) VALUES (?, ?)', [$ip, $accion]);

        return Database::lastInsertId();
    }

    public static function borrar(int $id): void
    {
        Database::execute('DELETE FROM envios_publicos WHERE id = ?', [$id]);
    }

    public static function contarRecientes(string $ip, string $accion, int $ventanaSeg): int
    {
        $fila = Database::fetchOne(
            'SELECT COUNT(*) AS n FROM envios_publicos
             WHERE accion = ? AND ip = ? AND created_at > NOW() - INTERVAL ? SECOND',
            [$accion, $ip, $ventanaSeg]
        );

        return (int) $fila['n'];
    }

    /** Segundos hasta que vence el envío más viejo de la ventana (mínimo 1). */
    public static function segundosHastaLiberar(string $ip, string $accion, int $ventanaSeg): int
    {
        $fila = Database::fetchOne(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND) AS espera
             FROM envios_publicos WHERE accion = ? AND ip = ? AND created_at > NOW() - INTERVAL ? SECOND',
            [$ventanaSeg, $accion, $ip, $ventanaSeg]
        );

        return max(1, (int) ($fila['espera'] ?? 1));
    }

    /** Borra registros de más de 24 h (se llama al reservar: la tabla no crece sin límite). */
    public static function purgar(): int
    {
        return Database::execute(
            'DELETE FROM envios_publicos WHERE created_at < NOW() - INTERVAL ? SECOND',
            [self::RETENCION_SEG]
        );
    }
}
