<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Admin;

/**
 * Eventos de seguridad (F-2, ADR-0012) en storage/logs/security.log, con la rotación de Log.
 * Una línea por evento: "fecha | evento | ip | MÉTODO /path | admin_id o - | detalle".
 *
 * Nunca se registran contraseñas, códigos, cuerpos ni query strings: los llamadores pasan solo
 * detalles cortos y fijos (retry=N, email=<ref>). La IP sí, porque es el dato que permite
 * investigar un abuso. Cada campo se neutraliza: sin saltos de línea, controles ni "|".
 */
final class SecurityLog
{
    public static function evento(string $evento, string $detalle = '', ?int $adminId = null): void
    {
        $adminId ??= self::adminDeLaSesion();
        $campos = [
            date('Y-m-d H:i:s'),
            $evento,
            Request::ip(),
            Request::method() . ' ' . Request::path(), // path sin query string
            $adminId === null ? '-' : (string) $adminId,
            $detalle === '' ? '-' : $detalle,
        ];
        Log::write(implode(' | ', array_map(self::neutralizar(...), $campos)), 'security');
    }

    /** Referencia estable y no reversible al email: 12 hex del SHA-256 del email normalizado. */
    public static function emailRef(string $email): string
    {
        return substr(hash('sha256', Admin::normalizeEmail($email)), 0, 12);
    }

    /** Controles (incluidos \r y \n) → espacio; "|" → "/" para no partir columnas. Bytes inválidos → "?". */
    private static function neutralizar(string $valor): string
    {
        if (!mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
        }
        $valor = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $valor);

        return str_replace('|', '/', $valor);
    }

    private static function adminDeLaSesion(): ?int
    {
        $id = session_status() === PHP_SESSION_ACTIVE ? Session::get('admin_id') : null;

        return is_int($id) ? $id : null;
    }
}
