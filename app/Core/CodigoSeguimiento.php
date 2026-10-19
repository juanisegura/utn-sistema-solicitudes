<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Código de seguimiento público (F-3, ADR-0013). Clase pura: no toca la DB ni la sesión.
 *
 * 128 bits de random_bytes(16) en base32 de Crockford (sin I, L, O ni U): 26 caracteres en grupos
 * de 4 separados por guiones, p. ej. "7K3M-QX9D-...-R4". Al consultar se normaliza: mayúsculas, sin
 * espacios ni guiones, y O → 0, I/L → 1 (lo que una persona confunde al copiarlo a mano). En la DB
 * se guarda solo hash() = SHA-256 hex del normalizado.
 */
final class CodigoSeguimiento
{
    private const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const BYTES = 16;
    private const GRUPO = 4;

    /** Código nuevo, formateado para mostrar. */
    public static function generar(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(self::BYTES)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        // 128 bits → 26 símbolos de 5 bits; el último lleva 2 bits de relleno en 0 (como RFC 4648).
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 5) * 5, '0');
        $codigo = '';
        foreach (str_split($bits, 5) as $simbolo) {
            $codigo .= self::ALFABETO[bindec($simbolo)];
        }

        return self::formatear($codigo);
    }

    /** Forma canónica que se hashea. No valida: un código mal escrito simplemente no coincide. */
    public static function normalizar(string $codigo): string
    {
        // Sin flag /u: la entrada puede no ser UTF-8 válido y igual tiene que dar un string (que no coincide).
        $codigo = strtoupper((string) preg_replace('/[\s\-]+/', '', $codigo));

        return strtr($codigo, 'OIL', '011');
    }

    /** SHA-256 hex (CHAR(64)) del código normalizado. */
    public static function hash(string $codigo): string
    {
        return hash('sha256', self::normalizar($codigo));
    }

    /** Grupos de 4 separados por guiones, a partir de cualquier forma del código. */
    public static function formatear(string $codigo): string
    {
        return implode('-', str_split(self::normalizar($codigo), self::GRUPO));
    }
}
