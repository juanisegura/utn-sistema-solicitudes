<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Solicitud
{
    private const COLUMNS = 'id, nombre, email, asunto, descripcion, estado, created_at, updated_at';

    /** Recibe datos ya validados; devuelve el id generado. */
    public static function create(string $nombre, string $email, string $asunto, string $descripcion): int
    {
        Database::execute(
            'INSERT INTO solicitudes (nombre, email, asunto, descripcion) VALUES (?, ?, ?, ?)',
            [$nombre, $email, $asunto, $descripcion]
        );

        return Database::lastInsertId();
    }

    /** @return list<array<string, mixed>> más recientes primero; filtradas si se pasa estado. */
    public static function all(?Estado $estado = null): array
    {
        if ($estado === null) {
            return Database::fetchAll(
                'SELECT ' . self::COLUMNS . ' FROM solicitudes ORDER BY created_at DESC, id DESC'
            );
        }

        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM solicitudes WHERE estado = ? ORDER BY created_at DESC, id DESC',
            [$estado->value]
        );
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetchOne('SELECT ' . self::COLUMNS . ' FROM solicitudes WHERE id = ?', [$id]);
    }

    /** true si la solicitud existe (aunque ya tuviera ese estado), false si el id no existe. */
    public static function updateEstado(int $id, Estado $estado): bool
    {
        return Database::execute('UPDATE solicitudes SET estado = ? WHERE id = ?', [$estado->value, $id]) > 0;
    }
}
