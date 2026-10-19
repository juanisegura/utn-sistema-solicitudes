<?php

declare(strict_types=1);

namespace App\Core;

/** Sesión endurecida + flash messages. Un único punto de configuración de la cookie. */
final class Session
{
    public const NAME = 'utn_sesion';

    private const LAST_ACTIVITY = '_last_activity';
    private const FLASH = '_flash';

    private static int $idleTimeout = 1800;
    private static bool $forceSecure = false;

    /**
     * Parámetros que usará start(); los fija bootstrap con la config, sin abrir la sesión (M-5:
     * la abre el Router recién cuando hay ruta y método válidos).
     *
     * @param int  $idleTimeout segundos sin actividad tras los que la sesión se vacía (app.session_timeout)
     * @param bool $forceSecure cookie Secure aunque no se detecte HTTPS (producción detrás de un proxy/CDN)
     */
    public static function configure(int $idleTimeout, bool $forceSecure = false): void
    {
        self::$idleTimeout = $idleTimeout;
        self::$forceSecure = $forceSecure;
    }

    /** Abre la sesión. Los parámetros, si se pasan, reemplazan a los de configure(). */
    public static function start(?int $idleTimeout = null, ?bool $forceSecure = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $idleTimeout ??= self::$idleTimeout;
        $forceSecure ??= self::$forceSecure;
        ini_set('session.use_strict_mode', '1');   // rechaza IDs que el servidor no emitió (fixation)
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        // El GC de PHP borra sesiones tras gc_maxlifetime (default 1440 s): nunca antes del timeout propio.
        ini_set('session.gc_maxlifetime', (string) max($idleTimeout, 1440));
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $forceSecure || Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        self::$idleTimeout = $idleTimeout;
        self::expireIfIdle();
    }

    /** Llamar al cambiar de privilegio (login): nuevo ID, mismos datos, el viejo se borra. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(self::NAME, '', [
                'expires'  => time() - 3600,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH][$key] = $value;
    }

    /** Lee y borra: el mensaje se muestra una sola vez. */
    public static function pullFlash(string $key): mixed
    {
        $value = $_SESSION[self::FLASH][$key] ?? null;
        unset($_SESSION[self::FLASH][$key]);

        return $value;
    }

    public static function peekFlash(string $key): mixed
    {
        return $_SESSION[self::FLASH][$key] ?? null;
    }

    private static function expireIfIdle(): void
    {
        $now = time();
        $last = $_SESSION[self::LAST_ACTIVITY] ?? null;
        if (is_int($last) && $now - $last > self::$idleTimeout) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION[self::LAST_ACTIVITY] = $now;
    }
}
