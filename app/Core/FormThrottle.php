<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\EnvioPublico;
use InvalidArgumentException;

/**
 * Límite de frecuencia por IP de los formularios públicos (F-1, ADR-0012). Genérico por acción:
 * 'solicitud' (todo envío que pasó el CSRF, válido o con errores) y 'seguimiento' (F-3: solo
 * cuentan los fallidos, el que acierta se libera).
 *
 * Mismo patrón "reservar y contar" que el login (D-46): el envío se registra antes de procesarlo
 * y se cuenta la ventana incluyendo la fila propia, así requests simultáneas se ven entre sí.
 * Si supera el límite, la fila propia se borra (los bloqueados no cuentan) y se informa la espera.
 */
final class FormThrottle
{
    /** Claves de configuración por acción: [máximo por IP, ventana en segundos]. */
    private const CONFIG = [
        'solicitud'   => ['app.form_max_por_ip', 'app.form_ventana_seg'],
        'seguimiento' => ['app.seguimiento_max_por_ip', 'app.seguimiento_ventana_seg'],
    ];

    /**
     * @return array{0: int|null, 1: int|null} [id, null] si se puede procesar; [null, segundos]
     *                                          si está bloqueado (para Retry-After)
     */
    public static function reservar(string $accion, string $ip): array
    {
        [$max, $ventana] = self::limites($accion);
        EnvioPublico::purgar();
        $id = EnvioPublico::registrar($ip, $accion);
        if (EnvioPublico::contarRecientes($ip, $accion, $ventana) <= $max) {
            return [$id, null];
        }
        EnvioPublico::borrar($id);

        return [null, EnvioPublico::segundosHastaLiberar($ip, $accion, $ventana)];
    }

    /** Retira un envío que no debe contar (F-3: consulta de seguimiento exitosa). */
    public static function liberar(int $id): void
    {
        EnvioPublico::borrar($id);
    }

    /** "N minutos" para el mensaje de 429: redondeo hacia arriba, mínimo 1, singular en 1. */
    public static function minutos(int $segundos): string
    {
        $minutos = max(1, (int) ceil($segundos / 60));

        return $minutos . ($minutos === 1 ? ' minuto' : ' minutos');
    }

    /** @return array{0: int, 1: int} */
    private static function limites(string $accion): array
    {
        if (!isset(self::CONFIG[$accion])) {
            throw new InvalidArgumentException('Acción de formulario desconocida: ' . $accion);
        }
        [$claveMax, $claveVentana] = self::CONFIG[$accion];

        return [(int) Config::get($claveMax), (int) Config::get($claveVentana)];
    }
}
