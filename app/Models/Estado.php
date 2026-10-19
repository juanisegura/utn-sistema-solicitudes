<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Estados de una solicitud. Es la lista blanca de la app: ningún valor llega al SQL
 * sin pasar por acá (ADR-0006). Los valores coinciden con el ENUM de la tabla.
 */
enum Estado: string
{
    case Pendiente = 'pendiente';
    case EnProceso = 'en_proceso';
    case Resuelta = 'resuelta';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnProceso => 'En proceso',
            self::Resuelta => 'Resuelta',
        };
    }

    /** Convierte entrada del usuario (query string, POST) en Estado o null si no es válida. */
    public static function fromInput(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(trim($value));
    }
}
