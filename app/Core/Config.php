<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

/**
 * Configuración efectiva de la request, cargada una sola vez por bootstrap. Las clases de núcleo
 * la consultan acá en vez de leer config.php por su cuenta.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @param array<string, mixed> $config resultado de app/config/config.php */
    public static function configure(array $config): void
    {
        self::$data = $config;
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
