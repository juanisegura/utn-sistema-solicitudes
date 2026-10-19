<?php

declare(strict_types=1);

/*
 * Ejecuta código de test en un proceso PHP aparte. Hace falta para lo que no puede convivir
 * con el runner: unidades que terminan con exit (Response::redirect/abort, Router::dispatch) y
 * sesiones (session_start falla si ya hubo salida, y solo puede haber una por proceso).
 *
 * El hijo carga autoload + helpers, recibe $DATOS (array) y deja lo que quiera devolver en
 * $GLOBALS['resultado']. Al terminar (también por exit) informa además el código HTTP que
 * quedó fijado, el estado de la sesión y su ID. La salida que haya producido la app se descarta.
 */

/**
 * @param array<string, mixed> $datos
 * @param bool $cargarApp false: el hijo no carga autoload ni helpers (los carga el código del test)
 * @return array{resultado: mixed, http: int|false, session: int, session_id: string, exit: int}
 */
function enHijo(string $codigo, array $datos = [], bool $cargarApp = true): array
{
    $dir = dirTemporal('utn-hijo');
    $script = $dir . '/hijo.php';
    $app = realpath(APP_DIR);
    // Sin $cargarApp el hijo no precarga autoload/helpers: lo hace el código del test (p. ej. al
    // requerir app/bootstrap-cli.php, que los carga por su cuenta y no debe encontrarlos repetidos).
    $carga = $cargarApp ? "require '{$app}/autoload.php';\nrequire '{$app}/helpers.php';" : '';
    $prologo = <<<PHP
        <?php
        declare(strict_types=1);
        ob_start();
        register_shutdown_function(static function (): void {
            \$error = error_get_last();
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo "\\n##RESULTADO##", json_encode([
                'resultado'  => \$GLOBALS['resultado'] ?? null,
                'http'       => http_response_code(),
                'session'    => session_status(),
                'session_id' => session_status() === PHP_SESSION_NONE ? '' : session_id(),
                'error'      => \$error !== null && in_array(\$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
                    ? \$error['message'] : null,
            ], JSON_INVALID_UTF8_SUBSTITUTE), "\\n";
        });
        {$carga}
        \$DATOS = json_decode((string) file_get_contents(__DIR__ . '/datos.json'), true);
        PHP;
    file_put_contents($dir . '/datos.json', json_encode($datos));
    file_put_contents($script, $prologo . "\n" . $codigo . "\n");

    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('No se pudo lanzar el proceso hijo');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    $pos = strrpos($stdout, "##RESULTADO##");
    if ($pos === false) {
        fail('el proceso hijo no informó resultado (exit ' . $exit . '): ' . trim($stderr . ' ' . $stdout));
    }
    $r = json_decode(trim(substr($stdout, $pos + strlen('##RESULTADO##'))), true);
    if (!is_array($r)) {
        fail('resultado del hijo ilegible');
    }
    if ($r['error'] !== null) {
        fail('error fatal en el hijo: ' . $r['error']);
    }
    if (trim($stderr) !== '') {
        // Warnings/notices de la unidad bajo prueba: también son fallas.
        fail('el hijo escribió en stderr: ' . trim($stderr));
    }
    $r['exit'] = $exit;

    return $r;
}
