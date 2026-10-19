<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

/** Token sincronizador por sesión (ADR-0005). Se verifica en un único punto: el Router. */
final class Csrf
{
    public const FIELD = 'csrf_token';
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('CSRF requiere una sesión iniciada.');
        }

        return $_SESSION[self::KEY] ??= bin2hex(random_bytes(32));
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;

        // hash_equals: comparación en tiempo constante, no filtra el token por timing.
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /** Nuevo token al cambiar de privilegio (login/logout): uno filtrado antes deja de servir. */
    public static function rotate(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
