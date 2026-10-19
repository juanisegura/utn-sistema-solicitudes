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
     * "Reservar y contar" (D-46): registra el intento ANTES de contar, así dos requests
     * simultáneas se ven entre sí (autocommit: cada INSERT es visible para el COUNT de la otra).
     * El conteo incluye la fila propia, por eso se compara con ">" límite: el 6.º intento de la
     * ventana es el primero bloqueado, igual que antes. Bajo carrera puede bloquear de más,
     * nunca de menos.
     *
     * Devuelve [id del intento, null] si se puede verificar, o [null, segundos] si está bloqueado;
     * en ese caso la fila propia ya se borró (los intentos bloqueados no se registran, D-39).
     * No mira la tabla admins: el resultado es el mismo exista o no el email (no enumera).
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function reservar(string $ip, string $email): array
    {
        $l = self::limites();
        $hash = LoginIntento::emailHash($email);
        $id = LoginIntento::registrar($ip, $hash);

        $porCuenta = LoginIntento::contarRecientesCuenta($ip, $hash, $l['ventana']) > $l['cuenta'];
        $porIp = LoginIntento::contarRecientesIp($ip, $l['ventana']) > $l['ip'];
        if (!$porCuenta && !$porIp) {
            return [$id, null];
        }

        LoginIntento::borrar($id);
        $esperas = [];
        if ($porCuenta) {
            $esperas[] = LoginIntento::segundosHastaLiberar($ip, $hash, $l['ventana']);
        }
        if ($porIp) {
            $esperas[] = LoginIntento::segundosHastaLiberar($ip, null, $l['ventana']);
        }

        return [null, max($esperas)];
    }

    /** Login exitoso: se retira el intento reservado y los fallos previos de esa IP + email. */
    public static function exito(int $id, string $ip, string $email): void
    {
        LoginIntento::borrar($id);
        LoginIntento::limpiarCuenta($ip, LoginIntento::emailHash($email));
    }

    /** Credenciales inválidas: la fila reservada queda como fallo; de paso se purga lo viejo. */
    public static function fallo(): void
    {
        LoginIntento::purgar();
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
