<?php

declare(strict_types=1);

/*
 * Runner de tests sin dependencias (D-48, sin Composer por ADR-0001).
 *
 *   php tests/unit/run.php [filtro]          unit: puros, sin DB ni red
 *   php tests/integration/run.php [filtro]   integración: requieren DB de prueba y/o BASE_URL
 *
 * Cada *Test.php del directorio registra casos con test('nombre', fn). Un caso pasa si la
 * función termina; falla si lanza (aserción o cualquier error: los warnings se convierten en
 * excepción); queda PENDIENTE si llama a pending() (unidad del contrato aún no implementada en
 * una tarea posterior) y SKIP si llama a skip() (falta un requisito del entorno).
 * Exit code: 0 si no hubo FAIL, 1 si hubo alguno, 2 si no se encontró ningún test. Con la
 * variable SIN_SKIP=1 (CI) un SKIP cuenta como FAIL: en CI el entorno tiene que estar completo.
 * Los unitarios nunca cargan app/config/config.local.php: lo que una unidad necesite de config
 * se le pasa armado en el test. Los de integración usan la DB a través de App\Core\Database,
 * que lee la config de la app; el test no la lee ni la imprime.
 */

const TESTS_ROOT = __DIR__ . '/..';
const APP_DIR = __DIR__ . '/../../app';

require APP_DIR . '/autoload.php';
require APP_DIR . '/helpers.php';
require __DIR__ . '/child.php';

final class AssertionFailed extends Exception
{
}

final class TestPending extends Exception
{
}

final class TestSkipped extends Exception
{
}

/** @var list<array{0: string, 1: string, 2: callable}> */
$GLOBALS['__tests'] = [];
$GLOBALS['__file'] = '';

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__file'], $name, $fn];
}

function pending(string $motivo): never
{
    throw new TestPending($motivo);
}

function skip(string $motivo): never
{
    throw new TestSkipped($motivo);
}

function fail(string $mensaje): never
{
    throw new AssertionFailed($mensaje);
}

function exportar(mixed $v): string
{
    $s = str_replace("\n", ' ', var_export($v, true));
    // Controles y bytes no imprimibles visibles en el mensaje (p. ej. "\0", "\n").
    $s = (string) preg_replace_callback('/[\x00-\x1F\x7F]/', static fn ($m) => sprintf('\x%02X', ord($m[0])), $s);

    return strlen($s) > 300 ? substr($s, 0, 300) . '…' : $s;
}

function assertSame(mixed $esperado, mixed $obtenido, string $msg = ''): void
{
    if ($esperado !== $obtenido) {
        fail(($msg !== '' ? $msg . ': ' : '') . 'esperado ' . exportar($esperado) . ', obtenido ' . exportar($obtenido));
    }
}

function assertTrue(mixed $v, string $msg = ''): void
{
    assertSame(true, $v, $msg);
}

function assertFalse(mixed $v, string $msg = ''): void
{
    assertSame(false, $v, $msg);
}

function assertNull(mixed $v, string $msg = ''): void
{
    assertSame(null, $v, $msg);
}

function assertNotSame(mixed $noEsperado, mixed $obtenido, string $msg = ''): void
{
    if ($noEsperado === $obtenido) {
        fail(($msg !== '' ? $msg . ': ' : '') . 'no debía ser ' . exportar($obtenido));
    }
}

function assertContains(string $aguja, string $pajar, string $msg = ''): void
{
    if (!str_contains($pajar, $aguja)) {
        fail(($msg !== '' ? $msg . ': ' : '') . exportar($aguja) . ' no está en ' . exportar($pajar));
    }
}

function assertArrayHasKey(string|int $clave, array $arr, string $msg = ''): void
{
    if (!array_key_exists($clave, $arr)) {
        fail(($msg !== '' ? $msg . ': ' : '') . 'falta la clave ' . exportar($clave));
    }
}

/**
 * @param class-string<Throwable> $clase
 * @return Throwable la excepción capturada, para inspeccionar el mensaje
 */
function assertThrows(string $clase, callable $fn, string $msg = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof AssertionFailed || $e instanceof TestPending || $e instanceof TestSkipped) {
            throw $e;
        }
        if (!$e instanceof $clase) {
            fail(($msg !== '' ? $msg . ': ' : '') . "esperaba {$clase}, se lanzó " . $e::class . ': ' . $e->getMessage());
        }

        return $e;
    }
    fail(($msg !== '' ? $msg . ': ' : '') . "esperaba {$clase} y no se lanzó nada");
}

/** Invoca un método privado/protegido estático (Router::match, Router::allowHeader…). */
function llamarPrivado(string $clase, string $metodo, mixed ...$args): mixed
{
    $m = new ReflectionMethod($clase, $metodo);

    return $m->invoke(null, ...$args);
}

/** Directorio temporal propio del test; se borra al terminar la corrida. */
function dirTemporal(string $prefijo = 'utn-test'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefijo . '-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear ' . $dir);
    }
    $GLOBALS['__tmpdirs'][] = $dir;

    return $dir;
}

function borrarArbol(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Descubre, ejecuta y reporta. Devuelve el exit code. */
function correrSuite(string $dir, string $titulo, array $argv): int
{
    $filtro = $argv[1] ?? '';
    $archivos = glob(rtrim($dir, '/') . '/*Test.php') ?: [];
    sort($archivos);
    foreach ($archivos as $archivo) {
        $GLOBALS['__file'] = basename($archivo, '.php');
        require $archivo;
    }

    $tests = array_values(array_filter(
        $GLOBALS['__tests'],
        static fn (array $t) => $filtro === '' || stripos($t[0] . ' ' . $t[1], $filtro) !== false
    ));
    if ($tests === []) {
        fwrite(STDERR, "No se encontraron tests" . ($filtro !== '' ? " para «{$filtro}»" : '') . " en {$dir}\n");

        return 2;
    }

    set_error_handler(static function (int $nivel, string $msg, string $file, int $line): bool {
        if ((error_reporting() & $nivel) === 0) {
            return false;
        }
        throw new ErrorException($msg, 0, $nivel, $file, $line);
    });

    printf("%s — %d tests\n", $titulo, count($tests));
    $cuenta = ['PASS' => 0, 'FAIL' => 0, 'PEND' => 0, 'SKIP' => 0];
    $actual = '';
    foreach ($tests as [$archivo, $nombre, $fn]) {
        if ($archivo !== $actual) {
            printf("\n== %s ==\n", $archivo);
            $actual = $archivo;
        }
        $estado = 'PASS';
        $detalle = '';
        $nivelBuffer = ob_get_level();
        ob_start(); // lo que imprima la unidad (vistas, abort) no ensucia la salida del runner
        try {
            $fn();
        } catch (TestPending $e) {
            $estado = 'PEND';
            $detalle = $e->getMessage();
        } catch (TestSkipped $e) {
            $estado = 'SKIP';
            $detalle = $e->getMessage();
        } catch (Throwable $e) {
            $estado = 'FAIL';
            $detalle = $e instanceof AssertionFailed
                ? $e->getMessage()
                : $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        } finally {
            while (ob_get_level() > $nivelBuffer) {
                ob_end_clean();
            }
            $_SESSION = [];
        }
        $cuenta[$estado]++;
        printf("%-4s  %s%s\n", $estado, $nombre, $detalle !== '' ? "  — {$detalle}" : '');
    }

    foreach ($GLOBALS['__tmpdirs'] ?? [] as $d) {
        borrarArbol($d);
    }

    printf(
        "\nResumen: %d tests — %d PASS, %d FAIL, %d PENDIENTE, %d SKIP\n",
        count($tests), $cuenta['PASS'], $cuenta['FAIL'], $cuenta['PEND'], $cuenta['SKIP']
    );

    $sinSkip = getenv('SIN_SKIP') === '1';
    if ($sinSkip && $cuenta['SKIP'] > 0) {
        echo "SIN_SKIP=1: los SKIP cuentan como FAIL\n";
    }

    return $cuenta['FAIL'] > 0 || ($sinSkip && $cuenta['SKIP'] > 0) ? 1 : 0;
}
