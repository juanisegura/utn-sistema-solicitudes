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
            // Tope en bytes antes de recortar (D-52): max:N caracteres ocupan como mucho 4N bytes en
            // UTF-8. Corta entradas enormes sin gastar CPU en el recorte ni en mb_strlen.
            $max = self::maxChars($fieldRules);
            if ($max !== null && strlen($raw) > 4 * $max) {
                $data[$field] = '';
                $errors[$field] = "El campo «{$label}» admite como máximo {$max} caracteres.";
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

    private const ESPACIOS = '[\s\p{Z}\x{200B}\x{FEFF}]';

    /**
     * trim() solo quita espacios ASCII: un nombre de solo NBSP o zero-width pasaría como "no vacío".
     * En dos pasos y sin alternancia (D-52): con `^…+|…+$` y PCRE sin JIT, una corrida larga de
     * espacios en el medio se reintentaba desde cada posición (cuadrático: 20 000 espacios ≈ 2,5 s).
     * El lookbehind hace que el patrón final solo pueda empezar donde arranca la corrida de espacios.
     */
    private static function trim(string $value): string
    {
        $value = (string) preg_replace('/^' . self::ESPACIOS . '+/u', '', $value);

        return (string) preg_replace('/(?<!' . self::ESPACIOS . ')' . self::ESPACIOS . '+$/u', '', $value);
    }

    /** N de la regla max:N, o null si el campo no tiene tope. */
    private static function maxChars(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'max:')) {
                return (int) substr($rule, 4);
            }
        }

        return null;
    }

    /**
     * Controles invisibles (NUL, ESC, DEL...) no tienen uso legítimo en estos campos y sirven
     * para ensuciar logs o falsear lo que ve el admin. \t, \n y \r solo en campos multilínea.
     */
    private static function hasControlChars(string $value, bool $multiline): bool
    {
        // Una línea: todo control C0 (incluido TAB, D-50) y DEL. Multilínea: se permiten \t, \n y \r.
        $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

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
