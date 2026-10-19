<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use InvalidArgumentException;

final class Admin
{
    public const MAX_PASSWORD_BYTES = 72;

    /**
     * Incluye password_hash y activo: Auth decide (verifica hash y estado) para poder
     * responder igual ante email inexistente, password errónea o admin inactivo.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        return Database::fetchOne(
            'SELECT id, nombre, email, password_hash, activo FROM admins WHERE email = ?',
            [$email]
        );
    }

    /**
     * Para re-verificar en cada request admin que la cuenta siga existiendo y activa.
     * No devuelve el hash: no hace falta fuera del login.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, nombre, email, activo, last_login_at FROM admins WHERE id = ?',
            [$id]
        );
    }

    /** Hashea acá para que ningún llamador pueda guardar la password en claro por error. */
    public static function create(string $nombre, string $email, string $password): int
    {
        Database::execute(
            'INSERT INTO admins (nombre, email, password_hash) VALUES (?, ?, ?)',
            [$nombre, $email, self::hash($password)]
        );

        return Database::lastInsertId();
    }

    /** Usado al loguear cuando password_needs_rehash() indica un algoritmo/costo viejo. */
    public static function updatePasswordHash(int $id, string $password): void
    {
        Database::execute(
            'UPDATE admins SET password_hash = ? WHERE id = ?',
            [self::hash($password), $id]
        );
    }

    public static function touchLastLogin(int $id): void
    {
        Database::execute('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    private static function hash(string $password): string
    {
        // bcrypt ignora lo que pasa de 72 bytes: se rechaza en vez de truncar en silencio.
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('La contraseña supera ' . self::MAX_PASSWORD_BYTES . ' bytes.');
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }
}
