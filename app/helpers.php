<?php

declare(strict_types=1);

/** Escape para contexto HTML (texto y atributos entre comillas). Toda salida de las vistas pasa por acá. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
