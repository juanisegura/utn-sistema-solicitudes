<?php

declare(strict_types=1);

/*
 * Copia de la app con una configuración local DE PRUEBA, para ejercitar el camino real de la
 * config (config.php → Config → bootstrap-cli, y el guard de scripts/instalar-db.php) sin tocar la
 * del entorno. Se copian app/, scripts/ y database/ a un directorio temporal y se escribe
 * app/config/config.local.php con $local. El config.local.php real se excluye por nombre: nunca se
 * lee ni se copia (AGENTS.md). La DB de prueba apunta a 127.0.0.1:1, donde no escucha nada: si algo
 * intenta conectar, falla de inmediato sin tocar ninguna base.
 */

const DB_INALCANZABLE = [
    'host' => '127.0.0.1', 'port' => 1, 'name' => 'no_existe', 'user' => 'prueba', 'pass' => 'prueba', 'charset' => 'utf8mb4',
];

/** @param array<string, mixed> $local contenido de config.local.php (se le agrega 'db' si falta) */
function arbolConConfig(array $local): string
{
    $raiz = dirTemporal('utn-arbol');
    $origen = realpath(APP_DIR . '/..');
    foreach (['app', 'scripts', 'database'] as $dir) {
        copiarArbol($origen . '/' . $dir, $raiz . '/' . $dir);
    }
    $local += ['db' => DB_INALCANZABLE];
    file_put_contents($raiz . '/app/config/config.local.php', "<?php\nreturn " . var_export($local, true) . ";\n");

    return $raiz;
}

function copiarArbol(string $desde, string $hasta): void
{
    mkdir($hasta, 0700, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($desde, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($desde) + 1);
        if ($f->getFilename() === 'config.local.php') {
            continue; // credenciales reales: ni se leen ni se copian
        }
        $f->isDir() ? @mkdir($hasta . '/' . $rel, 0700, true) : copy($f->getPathname(), $hasta . '/' . $rel);
    }
}

/**
 * Carga app/bootstrap-cli.php de la copia en un proceso hijo y devuelve la config efectiva de
 * 'app' (o la excepción). Nunca se imprime la sección db.
 *
 * @return array{app: ?array<string, mixed>, error: ?string}
 */
function configurarCopia(array $local): array
{
    $raiz = arbolConConfig($local);
    $r = enHijo(<<<PHP
        try {
            \$cfg = require '{$raiz}/app/bootstrap-cli.php';
            \$GLOBALS['resultado'] = ['app' => \$cfg['app'] ?? null, 'debugConfig' => App\\Core\\Config::get('app.debug'), 'error' => null];
        } catch (Throwable \$e) {
            \$GLOBALS['resultado'] = ['app' => null, 'debugConfig' => null, 'error' => \$e::class . ': ' . \$e->getMessage()];
        }
        PHP, [], false);

    return $r['resultado'];
}

/**
 * Corre scripts/instalar-db.php de la copia con $args y devuelve [exit, stdout+stderr].
 *
 * @param list<string> $args
 * @return array{0: int, 1: string}
 */
function instalarDbEnCopia(array $local, array $args): array
{
    $raiz = arbolConConfig($local);
    $proc = proc_open(
        array_merge([PHP_BINARY, $raiz . '/scripts/instalar-db.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $raiz
    );
    fclose($pipes[0]);
    $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $salida];
}
