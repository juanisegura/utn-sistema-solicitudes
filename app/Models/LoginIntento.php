<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Intentos de login fallidos (ADR-0009). Las ventanas se calculan con el reloj de la DB
 * (NOW() y created_at DEFAULT CURRENT_TIMESTAMP), así no dependen de la zona horaria de PHP.
 */
final class LoginIntento
{
    private const RETENCION_SEG = 86400;

    /** El email nunca se guarda en claro: solo el SHA-256 de su forma canónica (Admin::normalizeEmail). */
    public static function emailHash(string $email): string
    {
        return hash('sha256', Admin::normalizeEmail($email));
    }

    /** Registra un intento y devuelve su id (para poder retirarlo si queda bloqueado o tiene éxito). */
    public static function registrar(string $ip, string $emailHash): int
    {
        Database::execute('INSERT INTO login_intentos (ip, email_hash) VALUES (?, ?)', [$ip, $emailHash]);

        return Database::lastInsertId();
    }

    public static function borrar(int $id): void
    {
        Database::execute('DELETE FROM login_intentos WHERE id = ?', [$id]);
    }

    public static function contarRecientesCuenta(string $ip, string $emailHash, int $ventanaSeg): int
    {
        $fila = Database::fetchOne(
            'SELECT COUNT(*) AS n FROM login_intentos
             WHERE ip = ? AND email_hash = ? AND created_at > NOW() - INTERVAL ? SECOND',
            [$ip, $emailHash, $ventanaSeg]
        );

        return (int) $fila['n'];
    }

    public static function contarRecientesIp(string $ip, int $ventanaSeg): int
    {
        $fila = Database::fetchOne(
            'SELECT COUNT(*) AS n FROM login_intentos WHERE ip = ? AND created_at > NOW() - INTERVAL ? SECOND',
            [$ip, $ventanaSeg]
        );

        return (int) $fila['n'];
    }

    /**
     * Segundos hasta que vence el intento más viejo de la ventana (por cuenta si se pasa
     * $emailHash, si no por IP). Como los intentos bloqueados no se registran, al vencer ese
     * intento el contador baja del límite y se libera. Mínimo 1.
     */
    public static function segundosHastaLiberar(string $ip, ?string $emailHash, int $ventanaSeg): int
    {
        $sql = 'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND) AS espera
                FROM login_intentos WHERE ip = ? AND created_at > NOW() - INTERVAL ? SECOND';
        $params = [$ventanaSeg, $ip, $ventanaSeg];
        if ($emailHash !== null) {
            $sql .= ' AND email_hash = ?';
            $params[] = $emailHash;
        }
        $fila = Database::fetchOne($sql, $params);

        return max(1, (int) ($fila['espera'] ?? 1));
    }

    public static function limpiarCuenta(string $ip, string $emailHash): void
    {
        Database::execute('DELETE FROM login_intentos WHERE ip = ? AND email_hash = ?', [$ip, $emailHash]);
    }

    /** Borra registros de más de 24 h (se llama al registrar un fallo: la tabla no crece sin límite). */
    public static function purgar(): int
    {
        return Database::execute(
            'DELETE FROM login_intentos WHERE created_at < NOW() - INTERVAL ? SECOND',
            [self::RETENCION_SEG]
        );
    }
}
