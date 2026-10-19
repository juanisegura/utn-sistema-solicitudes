<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Validación server-side. Reglas: required, email, max:N. Recorta espacios antes de
 * validar y devuelve [datos limpios, errores por campo] (un error por campo, el primero).
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
            $value = trim($input[$field] ?? '');
            $data[$field] = $value;
            $error = self::firstError($value, $fieldRules, $labels[$field] ?? $field);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return [$data, $errors];
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
            'required' => null, // ya resuelto en firstError
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
