<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Validación server-side. Reglas: required, email, max:N, multiline. Antes de cualquier regla
 * exige UTF-8 válido, recorta espacios (también Unicode: NBSP, zero-width, BOM) y rechaza
 * caracteres de control; los saltos de línea y tabs solo se aceptan con 'multiline'.
 * Devuelve [datos limpios, errores por campo] (un error por campo, el primero).
 */
final class Validator
{
    /**
     * @param array<string, string>       $input  normalmente Request::only([...])
     * @param array<string, list<string>> $rules  ['email' => ['required', 'email', 'max:254']]
     * @param array<string, string>       $labels nombre visible de cada campo para los mensajes
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function validate(array $input, array $rules, array $labels = []): array
    {
        $data = [];
        $errors = [];
        foreach ($rules as $field => $fieldRules) {
            $label = $labels[$field] ?? $field;
            $raw = $input[$field] ?? '';
            // Primero el encoding: con bytes inválidos fallan mb_strlen/preg (/u) y la DB los rechaza (→ 500).
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $data[$field] = '';
                $errors[$field] = "El campo «{$label}» contiene caracteres no válidos.";
                continue;
            }
            $value = self::trim($raw);
            if (self::hasControlChars($value, in_array('multiline', $fieldRules, true))) {
                $data[$field] = ''; // no se re-muestra: los controles no vuelven al HTML
                $errors[$field] = "El campo «{$label}» contiene caracteres no válidos.";
                continue;
            }
            $data[$field] = $value;
            $error = self::firstError($value, $fieldRules, $label);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return [$data, $errors];
    }

    /** trim() solo quita espacios ASCII: un nombre de solo NBSP o zero-width pasaría como "no vacío". */
    private static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', $value);
    }

    /**
     * Controles invisibles (NUL, ESC, DEL...) no tienen uso legítimo en estos campos y sirven
     * para ensuciar logs o falsear lo que ve el admin. \t, \n y \r solo en campos multilínea.
     */
    private static function hasControlChars(string $value, bool $multiline): bool
    {
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x08\x0A-\x1F\x7F]/';

        return preg_match($pattern, $value) === 1;
    }

    /** @param list<string> $rules */
    private static function firstError(string $value, array $rules, string $label): ?string
    {
        if ($value === '') {
            return in_array('required', $rules, true) ? "El campo «{$label}» es obligatorio." : null;
        }
        foreach ($rules as $rule) {
            $error = self::check($value, $rule, $label);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private static function check(string $value, string $rule, string $label): ?string
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($name) {
            'required', 'multiline' => null, // resueltos en firstError / hasControlChars
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? "El campo «{$label}» debe ser un email válido."
                : null,
            'max' => mb_strlen($value) > (int) $arg
                ? "El campo «{$label}» admite como máximo {$arg} caracteres."
                : null,
            default => throw new InvalidArgumentException("Regla de validación desconocida: {$name}"),
        };
    }
}
