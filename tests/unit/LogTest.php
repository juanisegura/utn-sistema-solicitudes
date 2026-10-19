<?php

declare(strict_types=1);

use App\Core\Log;

// Rotación de logs (M-6, API D-49): Log::configure($file, $maxBytes, $maxArchivos) y
// Log::write($line) — escribe la línea + "\n"; si el archivo YA supera $maxBytes, rota antes de
// escribir (app.log → .1 → .2 … hasta .$maxArchivos, el más viejo se descarta), con flock.
// Todo en un directorio temporal propio.

/**
 * Solo app.log y app.log.N: un archivo auxiliar para el flock (p. ej. app.log.lock) es un detalle
 * de implementación que el contrato no prohíbe.
 *
 * @return array<string, string> nombre → contenido
 */
function archivosLog(string $dir): array
{
    $out = [];
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (preg_match('/^app\.log(\.\d+)?$/', basename($f)) !== 1) {
            continue;
        }
        $out[basename($f)] = (string) file_get_contents($f);
    }
    ksort($out);

    return $out;
}

/** Línea de exactamente 10 bytes con el "\n" que agrega write(). */
function linea(int $n): string
{
    return sprintf('linea-%03d', $n);
}

test('write agrega la línea con "\n" y crea el archivo', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 1000, 3);
    Log::write('hola');
    Log::write('chau');
    assertSame(['app.log' => "hola\nchau\n"], archivosLog($dir));
});

test('no rota mientras no SUPERA el máximo (igual al máximo no rota)', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 50, 3);
    for ($i = 1; $i <= 6; $i++) {
        Log::write(linea($i)); // tras la 5.ª: 50 bytes = máximo, no lo supera → la 6.ª no rota
    }
    $f = archivosLog($dir);
    assertSame(['app.log'], array_keys($f));
    assertSame(60, strlen($f['app.log']));
});

test('rota antes de escribir cuando el archivo supera el máximo', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 50, 3);
    for ($i = 1; $i <= 7; $i++) {
        Log::write(linea($i)); // la 7.ª encuentra 60 bytes > 50 → rota y escribe en uno nuevo
    }
    $f = archivosLog($dir);
    assertSame(['app.log', 'app.log.1'], array_keys($f));
    assertSame(linea(7) . "\n", $f['app.log']);
    assertSame(60, strlen($f['app.log.1']));
    assertContains(linea(1), $f['app.log.1']);
});

test('corrimiento .1 → .2 … y descarte del más viejo (maxArchivos = 2)', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 5, 2); // cada línea (10 B) ya supera: rota en cada write
    for ($i = 1; $i <= 6; $i++) {
        Log::write(linea($i));
    }
    $f = archivosLog($dir);
    assertSame(['app.log', 'app.log.1', 'app.log.2'], array_keys($f), 'nunca .3');
    assertSame(linea(6) . "\n", $f['app.log']);
    assertSame(linea(5) . "\n", $f['app.log.1']);
    assertSame(linea(4) . "\n", $f['app.log.2']);
});

test('con maxArchivos = 5 no aparece app.log.6', function () {
    $dir = dirTemporal();
    Log::configure($dir . '/app.log', 5, 5);
    for ($i = 1; $i <= 12; $i++) {
        Log::write(linea($i));
    }
    $f = archivosLog($dir);
    assertSame(['app.log', 'app.log.1', 'app.log.2', 'app.log.3', 'app.log.4', 'app.log.5'], array_keys($f));
    assertSame(linea(7) . "\n", $f['app.log.5']);
});

test('dos procesos escribiendo a la vez: sin líneas perdidas, duplicadas ni mezcladas (flock)', function () {
    $dir = dirTemporal();
    $archivo = $dir . '/app.log';
    $app = realpath(APP_DIR);
    $hijo = $dir . '/escritor.php';
    file_put_contents($hijo, <<<PHP
        <?php
        declare(strict_types=1);
        require '{$app}/autoload.php';
        App\Core\Log::configure(\$argv[1], 2000, 100);
        for (\$i = 0; \$i < 300; \$i++) {
            App\Core\Log::write(sprintf('%s-%03d-%s', \$argv[2], \$i, str_repeat('x', 20)));
        }
        PHP);
    $procs = [];
    foreach (['A', 'B'] as $quien) {
        $procs[$quien] = proc_open([PHP_BINARY, $hijo, $archivo, $quien], [2 => ['pipe', 'w']], $pipes);
        $errs[$quien] = $pipes[2];
    }
    foreach ($procs as $quien => $p) {
        $err = stream_get_contents($errs[$quien]);
        fclose($errs[$quien]);
        assertSame(0, proc_close($p), "escritor {$quien}: " . $err);
    }
    $lineas = [];
    foreach (archivosLog($dir) as $nombre => $contenido) {
        foreach (explode("\n", rtrim($contenido, "\n")) as $l) {
            $lineas[] = $l;
        }
    }
    $malformadas = array_filter($lineas, fn ($l) => preg_match('/^[AB]-\d{3}-x{20}$/', $l) !== 1);
    assertSame([], array_values($malformadas), 'líneas mezcladas o cortadas');
    assertSame(600, count($lineas), 'total de líneas');
    assertSame(600, count(array_unique($lineas)), 'sin duplicados');
    assertTrue(count(archivosLog($dir)) >= 4, 'hubo rotaciones durante la escritura concurrente');
});
