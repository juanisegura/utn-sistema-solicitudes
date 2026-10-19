<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use LogicException;

/**
 * Configuración efectiva de la request, cargada una sola vez por bootstrap. Las clases de núcleo
 * la consultan acá en vez de leer config.php por su cuenta.
 *
 * configure() es el único lugar donde se valida y normaliza la configuración (AU-1, AU-3): un
 * valor inválido es un error de arranque (InvalidArgumentException → log + 500 genérica), nunca
 * una corrección silenciosa. Con env = production, debug queda forzado a false.
 */
final class Config
{
    /** Enteros de 'app' con su rango válido [mínimo, máximo|null]. */
    private const ENTEROS_APP = [
        'session_timeout'      => [60, null],
        'login_max_por_cuenta' => [1, null],
        'login_max_por_ip'     => [1, null],
        'login_ventana_seg'    => [1, null],
        'log_max_bytes'        => [1024, null],
        'log_max_archivos'     => [1, 50],
        'por_pagina'           => [1, 100],
        'form_max_por_ip'         => [1, null],
        'form_ventana_seg'        => [1, null],
        'seguimiento_max_por_ip'  => [1, null],
        'seguimiento_ventana_seg' => [1, null],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @param array<string, mixed> $config resultado de app/config/config.php */
    public static function configure(array $config): void
    {
        self::$data = self::validar($config);
    }

    /** @return array<string, mixed> la configuración ya validada (la devuelve bootstrap-cli.php) */
    public static function all(): array
    {
        if (self::$data === null) {
            throw new LogicException('Config no inicializada: bootstrap debe llamar a Config::configure().');
        }

        return self::$data;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function validar(array $config): array
    {
        $app = $config['app'] ?? null;
        $db = $config['db'] ?? null;
        if (!is_array($app) || !is_array($db)) {
            throw self::invalida('la configuración debe tener las secciones app y db');
        }

        if (!in_array($app['env'] ?? null, ['local', 'production'], true)) {
            throw self::invalida("app.env debe ser 'local' o 'production'");
        }
        $debug = filter_var($app['debug'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($debug === null) {
            throw self::invalida('app.debug debe ser true o false');
        }
        // AU-1: en producción nunca se muestran detalles internos, diga lo que diga config.local.
        $app['debug'] = $app['env'] === 'production' ? false : $debug;

        foreach (self::ENTEROS_APP as $clave => [$min, $max]) {
            $app[$clave] = self::entero($app[$clave] ?? null, "app.{$clave}", $min, $max);
        }
        // El formato de cada IP/CIDR lo valida Request::configure() en el mismo arranque.
        if (!is_array($app['trusted_proxies'] ?? null) || !array_is_list($app['trusted_proxies'])) {
            throw self::invalida('app.trusted_proxies debe ser una lista de IPs o rangos CIDR');
        }
        if (!is_string($app['base_url'] ?? null)) {
            throw self::invalida('app.base_url debe ser un texto');
        }

        foreach (['host', 'name', 'user', 'charset'] as $clave) {
            if (!is_string($db[$clave] ?? null) || $db[$clave] === '') {
                throw self::invalida("db.{$clave} debe ser un texto no vacío");
            }
        }
        if (!is_string($db['pass'] ?? null)) {
            throw self::invalida('db.pass debe ser un texto');
        }
        $db['port'] = self::entero($db['port'] ?? null, 'db.port', 1, 65535);

        return ['app' => $app, 'db' => $db] + $config;
    }

    private static function entero(mixed $valor, string $clave, int $min, ?int $max): int
    {
        $opciones = ['min_range' => $min] + ($max === null ? [] : ['max_range' => $max]);
        $entero = is_bool($valor) ? false : filter_var($valor, FILTER_VALIDATE_INT, ['options' => $opciones]);
        if ($entero === false) {
            $rango = $max === null ? "≥ {$min}" : "entre {$min} y {$max}";
            throw self::invalida("{$clave} debe ser un entero {$rango}");
        }

        return $entero;
    }

    private static function invalida(string $detalle): InvalidArgumentException
    {
        return new InvalidArgumentException('Configuración inválida: ' . $detalle . '.');
    }

    /**
     * Valor por clave con notación de puntos ('app.por_pagina'). Clave inexistente → $default.
     * Sin configure() previo es un error de arranque, no un valor por defecto silencioso.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new LogicException("Config no inicializada: bootstrap debe llamar a Config::configure() antes de leer '{$key}'.");
        }
        $valor = self::$data;
        foreach (explode('.', $key) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $default;
            }
            $valor = $valor[$parte];
        }

        return $valor;
    }
}
