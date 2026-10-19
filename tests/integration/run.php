<?php

declare(strict_types=1);

// Tests de integración: php tests/integration/run.php [filtro]. Runner y aserciones en tests/lib/runner.php.
// Requieren la app en BASE_URL (default http://localhost:8080) y su DB de prueba: la conexión la
// arma App\Core\Database con la config de la app (el test nunca lee ni imprime credenciales).
// Turno exclusivo de DB/servidor (D-42). Con SIN_SKIP=1 (así lo corre tests/run-all.sh) un SKIP cuenta como FAIL.
// R-3 (D-58): la DB se configura con el bootstrap mínimo de CLI, antes que el runner (que hace
// require_once de autoload/helpers y así no los redeclara). Si falta el archivo o la config, no se
// corta acá: requiereDb() lo informa como SKIP en cada caso que use la DB.
$GLOBALS['__bootstrap_cli'] = null;
$__cli = __DIR__ . '/../../app/bootstrap-cli.php';
if (is_file($__cli)) {
    try {
        require $__cli;
        $GLOBALS['__bootstrap_cli'] = 'ok';
    } catch (Throwable $e) {
        $GLOBALS['__bootstrap_cli'] = 'app/bootstrap-cli.php falló: ' . $e::class . ': ' . $e->getMessage();
    }
}
require __DIR__ . '/../lib/runner.php';
require __DIR__ . '/../lib/http.php';

exit(correrSuite(__DIR__, 'Tests de integración', $argv));
