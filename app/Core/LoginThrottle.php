<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\LoginIntento;

/**
 * Política del límite de intentos de login (ADR-0009): N fallos por IP + email y M por IP sola
 * dentro de una ventana. Separada de Auth: Auth decide si las credenciales son válidas; esto
 * decide si se permite intentarlo. Los límites salen de app.login_* en la config.
 */
final class LoginThrottle
{
    /** @var array{cuenta: int, ip: int, ventana: int}|null */
    private static ?array $limites = null;

    /** @param array<string, mixed> $app sección 'app' de la config (si no, se lee config.php) */
    public static function configure(array $app): void
    {
        self::$limites = [
            'cuenta'  => (int) $app['login_max_por_cuenta'],
            'ip'      => (int) $app['login_max_por_ip'],
            'ventana' => (int) $app['login_ventana_seg'],
        ];
    }

    /**
     * null si se puede intentar; si no, segundos hasta poder volver a intentar (Retry-After).
     * No mira la tabla admins: el resultado es el mismo exista o no el email (no enumera).
     */
    public static function segundosBloqueado(string $ip, string $email): ?int
    {
        $l = self::limites();
        $hash = LoginIntento::emailHash($email);
        $esperas = [];
        if (LoginIntento::contarRecientesCuenta($ip, $hash, $l['ventana']) >= $l['cuenta']) {
            $esperas[] = LoginIntento::segundosHastaLiberar($ip, $hash, $l['ventana']);
        }
        if (LoginIntento::contarRecientesIp($ip, $l['ventana']) >= $l['ip']) {
            $esperas[] = LoginIntento::segundosHastaLiberar($ip, null, $l['ventana']);
        }

        return $esperas === [] ? null : max($esperas);
    }

    public static function registrarFallo(string $ip, string $email): void
    {
        LoginIntento::registrarFallo($ip, LoginIntento::emailHash($email));
        LoginIntento::purgar();
    }

    /** Tras un login exitoso: los fallos previos de esa IP + email dejan de contar. */
    public static function limpiar(string $ip, string $email): void
    {
        LoginIntento::limpiarCuenta($ip, LoginIntento::emailHash($email));
    }

    /** @return array{cuenta: int, ip: int, ventana: int} */
    private static function limites(): array
    {
        if (self::$limites === null) {
            $config = require dirname(__DIR__) . '/config/config.php';
            self::configure($config['app']);
        }

        return self::$limites;
    }
}
