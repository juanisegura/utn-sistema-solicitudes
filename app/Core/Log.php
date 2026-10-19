<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Log de la app con rotación por tamaño (M-6, ADR-0008). El formato de cada línea lo arma
 * bootstrap; acá solo se escribe y se rota. Defaults válidos antes de leer la config, para que
 * un error al cargarla también quede registrado.
 */
final class Log
{
    private static ?string $file = null;
    private static int $maxBytes = 1048576;
    private static int $maxArchivos = 5;

    public static function configure(string $file, int $maxBytes, int $maxArchivos): void
    {
        self::$file = $file;
        self::$maxBytes = max(1, $maxBytes);
        self::$maxArchivos = max(0, $maxArchivos);
    }

    /**
     * Agrega la línea (+ "\n"). Si el archivo ya supera maxBytes, antes rota: .N-1 → .N … .1 → .2,
     * actual → .1, y el más viejo (.N) se descarta. Un lock en "<archivo>.lock" (no en el log, que
     * se renombra) serializa rotación y escritura entre requests simultáneas. Si no se puede
     * escribir, la línea va al log del servidor (error_log) para no perderla.
     */
    public static function write(string $line): void
    {
        $file = self::$file ?? dirname(__DIR__, 2) . '/storage/logs/app.log';
        $line = rtrim($line, "\n") . "\n";
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            error_log($line);

            return;
        }
        try {
            try {
                self::rotateIfNeeded($file);
            } catch (\Throwable) {
                // D-53: write() corre dentro del exception handler; si la rotación falla (permisos,
                // disco, un directorio donde iría .1) se sigue escribiendo en el archivo actual.
                // Lanzar acá perdería el error original y la página 500.
            }
            if (@file_put_contents($file, $line, FILE_APPEND) === false) {
                error_log($line);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function rotateIfNeeded(string $file): void
    {
        clearstatcache(true, $file);
        if (!is_file($file) || filesize($file) <= self::$maxBytes) {
            return;
        }
        if (self::$maxArchivos === 0) {
            @unlink($file); // sin archivos viejos: se empieza de cero
            return;
        }
        @unlink($file . '.' . self::$maxArchivos);
        for ($i = self::$maxArchivos - 1; $i >= 1; $i--) {
            if (is_file($file . '.' . $i)) {
                rename($file . '.' . $i, $file . '.' . ($i + 1));
            }
        }
        rename($file, $file . '.1');
    }
}
