<?php

declare(strict_types=1);

// Crea (o recrea) las tablas y carga los datos de prueba usando la config de la app.
// Uso: php scripts/instalar-db.php [--sin-datos] [--force]
// ATENCIÓN: schema.sql borra las tablas existentes. Con app.env = production se niega salvo
// --force; en producción usar --sin-datos y crear el admin con scripts/crear-admin.php
// (el seed trae un admin con password demo publicada en el README).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/autoload.php';

use App\Core\Database;

const TABLAS = ['admins', 'solicitudes'];

/**
 * Parte un archivo .sql en sentencias. Alcanza para nuestros archivos: comentarios de
 * línea completa con "--" y cada sentencia termina con ";" al final de una línea.
 * Limitación conocida (B4): un ";" al final de una línea DENTRO de un string partiría la
 * sentencia. No es inyectable (los .sql son del repo, no entrada de usuario); si un seed
 * futuro lo necesita, escribir ese valor en una sola línea.
 *
 * @return list<string>
 */
function sentencias(string $archivo): array
{
    $sql = file_get_contents($archivo);
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer ' . basename($archivo));
    }
    $sinComentarios = preg_replace('/^\s*--.*$/m', '', $sql);
    $partes = preg_split('/;\s*$/m', $sinComentarios);

    return array_values(array_filter(array_map('trim', $partes), static fn (string $s): bool => $s !== ''));
}

function ejecutarArchivo(string $archivo): int
{
    $lista = sentencias($archivo);
    foreach ($lista as $sentencia) {
        Database::execute($sentencia);
    }

    return count($lista);
}

$args = array_slice($argv, 1);
$desconocidos = array_diff($args, ['--sin-datos', '--force']);
if ($desconocidos !== []) {
    fwrite(STDERR, "Uso: php scripts/instalar-db.php [--sin-datos] [--force]\n");
    exit(2);
}
$conDatos = !in_array('--sin-datos', $args, true);

try {
    $config = require dirname(__DIR__) . '/app/config/config.php';
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($config['app']['env'] === 'production' && !in_array('--force', $args, true)) {
    fwrite(STDERR, "Abortado: app.env es 'production' y este script BORRA las tablas.\n"
        . "En producción: php scripts/instalar-db.php --sin-datos --force y después\n"
        . "php scripts/crear-admin.php (nunca el seed: su admin tiene password demo pública).\n");
    exit(1);
}
Database::configure($config['db']);

$dirDb = dirname(__DIR__) . '/database';

try {
    $n = ejecutarArchivo($dirDb . '/schema.sql');
    echo "schema.sql: {$n} sentencias ejecutadas\n";

    if ($conDatos) {
        $n = ejecutarArchivo($dirDb . '/seed.sql');
        echo "seed.sql:   {$n} sentencias ejecutadas\n";
    } else {
        echo "seed.sql:   omitido (--sin-datos)\n";
    }

    echo "\nFilas por tabla:\n";
    foreach (TABLAS as $tabla) {
        // $tabla viene de la constante TABLAS, no de la entrada: los identificadores no se pueden parametrizar.
        $fila = Database::fetchOne("SELECT COUNT(*) AS total FROM {$tabla}");
        printf("  %-12s %d\n", $tabla, $fila['total']);
    }

    if ($conDatos) {
        echo "\nSolicitudes por estado:\n";
        foreach (Database::fetchAll('SELECT estado, COUNT(*) AS total FROM solicitudes GROUP BY estado ORDER BY estado') as $fila) {
            printf("  %-12s %d\n", $fila['estado'], $fila['total']);
        }
    }
} catch (Throwable $e) {
    // Solo el mensaje: Database ya oculta los datos de conexión; nunca se imprime la config.
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
