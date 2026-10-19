<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Historial de cambios de estado (ADR-0010). Las escrituras ocurren dentro de la transacción de Solicitud::updateEstado. */
final class SolicitudEvento
{
    public static function registrar(int $solicitudId, int $adminId, Estado $anterior, Estado $nuevo): void
    {
        Database::execute(
            'INSERT INTO solicitud_eventos (solicitud_id, admin_id, estado_anterior, estado_nuevo) VALUES (?, ?, ?, ?)',
            [$solicitudId, $adminId, $anterior->value, $nuevo->value]
        );
    }

    /**
     * Más reciente primero. LEFT JOIN: si el admin no existiera (no debería, la FK es RESTRICT),
     * el evento igual se muestra con admin_nombre null.
     *
     * @return list<array<string, mixed>>
     */
    public static function porSolicitud(int $solicitudId): array
    {
        return Database::fetchAll(
            'SELECT e.id, e.estado_anterior, e.estado_nuevo, e.created_at, a.nombre AS admin_nombre
             FROM solicitud_eventos e
             LEFT JOIN admins a ON a.id = e.admin_id
             WHERE e.solicitud_id = ?
             ORDER BY e.created_at DESC, e.id DESC',
            [$solicitudId]
        );
    }
}
