<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Renderiza vistas PHP de app/Views con buffer de salida. Las vistas escapan todo con e(). */
final class View
{
    private const DIR = __DIR__ . '/../Views/';

    /** @param array<string, mixed> $data variables disponibles en la vista y en el layout */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/public'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, ['content' => $content] + $data);
    }

    private static function capture(string $view, array $data): string
    {
        $file = self::file($view);
        ob_start();
        try {
            // Closure estática: la vista solo ve sus datos, no $this ni variables de este método.
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    private static function file(string $view): string
    {
        // Nombres fijos del código, pero se valida igual para que nunca se pueda subir de directorio.
        if (preg_match('#^[a-z0-9_]+(/[a-z0-9_]+)*$#', $view) !== 1) {
            throw new InvalidArgumentException('Nombre de vista inválido.');
        }
        $file = self::DIR . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Vista inexistente: ' . $view);
        }

        return $file;
    }
}
