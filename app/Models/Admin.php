<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use InvalidArgumentException;

final class Admin
{
    public const MAX_PASSWORD_BYTES = 72;

    /**
     * Algoritmo y costo fijos (D-35): PASSWORD_DEFAULT pasó de costo 10 a 12 en PHP 8.4 y podría
     * cambiar de algoritmo. Si el hash real y el ficticio de Auth difirieran, el tiempo del login
     * revelaría qué emails existen. Auth::DUMMY_HASH debe tener este mismo algoritmo y costo.
     */
    public const HASH_ALGO = PASSWORD_BCRYPT;
    public const HASH_OPTIONS = ['cost' => 12];

    /**
     * bcrypt trunca a 72 bytes y corta en el primer byte NUL: en ambos casos aceptaría una
     * password distinta de la escrita, así que esas passwords no son válidas en ningún flujo.
     */
    public static function passwordAcceptable(#[\SensitiveParameter] string $password): bool
    {
        return strlen($password) <= self::MAX_PASSWORD_BYTES && !str_contains($password, "\0");
    }

    /**
     * Forma canónica del email (D-41): la usan el alta, la búsqueda del login y el hash del
     * límite de intentos, así una misma cuenta tiene un único contador.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::HASH_ALGO, self::HASH_OPTIONS);
    }

    /**
     * Incluye password_hash y activo: Auth decide (verifica hash y estado) para poder
     * responder igual ante email inexistente, password errónea o admin inactivo.
     *
     * Comparación binaria contra el email normalizado (D-41): con la collation _ci de la
     * columna, "ádmin@…" encontraría a "admin@…" con otro contador de intentos (bypass del
     * límite). La primera condición usa el índice UNIQUE (_ci); la segunda exige igualdad exacta.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        $email = self::normalizeEmail($email);

        return Database::fetchOne(
            'SELECT id, nombre, email, password_hash, activo FROM admins
             WHERE email = ? AND email COLLATE utf8mb4_bin = ?',
            [$email, $email]
        );
    }

    /**
     * Para re-verificar en cada request admin que la cuenta siga existiendo y activa, y que la
     * sesión sea posterior al último cambio de contraseña (F-5). No devuelve el hash.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, nombre, email, activo, last_login_at, password_changed_at FROM admins WHERE id = ?',
            [$id]
        );
    }

    /** Hash guardado, para verificar la contraseña actual al cambiarla (F-5). */
    public static function passwordHash(int $id): ?string
    {
        $fila = Database::fetchOne('SELECT password_hash FROM admins WHERE id = ?', [$id]);

        return $fila === null ? null : (string) $fila['password_hash'];
    }

    /**
     * Cambio de contraseña pedido por el admin (F-5): nuevo hash y password_changed_at = NOW(6).
     * Devuelve ese instante (con microsegundos, reloj de la DB): las sesiones con auth_at anterior
     * quedan invalidadas. El rehash automático del login usa updatePasswordHash(), que no invalida.
     */
    public static function cambiarPassword(int $id, #[\SensitiveParameter] string $password): string
    {
        $ahora = self::ahora();
        Database::execute(
            'UPDATE admins SET password_hash = ?, password_changed_at = ? WHERE id = ?',
            [self::hash($password), $ahora, $id]
        );

        return $ahora;
    }

    /** Hashea acá para que ningún llamador pueda guardar la password en claro por error. */
    public static function create(string $nombre, string $email, #[\SensitiveParameter] string $password): int
    {
        Database::execute(
            'INSERT INTO admins (nombre, email, password_hash) VALUES (?, ?, ?)',
            [$nombre, self::normalizeEmail($email), self::hash($password)]
        );

        return Database::lastInsertId();
    }

    /** Usado al loguear cuando needsRehash() indica un algoritmo/costo distinto del fijado. */
    public static function updatePasswordHash(int $id, #[\SensitiveParameter] string $password): void
    {
        Database::execute(
            'UPDATE admins SET password_hash = ? WHERE id = ?',
            [self::hash($password), $id]
        );
    }

    /**
     * Registra el login y devuelve su instante (NOW(6), reloj de la DB), que la sesión guarda como
     * auth_at para compararlo con password_changed_at sin mezclar relojes ni perder el orden
     * dentro del mismo segundo (F-5).
     */
    public static function touchLastLogin(int $id): string
    {
        $ahora = self::ahora();
        Database::execute('UPDATE admins SET last_login_at = ? WHERE id = ?', [substr($ahora, 0, 19), $id]);

        return $ahora;
    }

    /** "AAAA-MM-DD HH:MM:SS.uuuuuu" de la DB: mismo formato que password_changed_at (comparable como texto). */
    private static function ahora(): string
    {
        return (string) Database::fetchOne('SELECT NOW(6) AS ahora')['ahora'];
    }

    private static function hash(#[\SensitiveParameter] string $password): string
    {
        // Se rechaza en vez de dejar que bcrypt trunque en silencio (o falle con un NUL).
        if (!self::passwordAcceptable($password)) {
            throw new InvalidArgumentException(
                'La contraseña supera ' . self::MAX_PASSWORD_BYTES . ' bytes o contiene un byte nulo.'
            );
        }

        return password_hash($password, self::HASH_ALGO, self::HASH_OPTIONS);
    }
}
