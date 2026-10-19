<?php

declare(strict_types=1);

// Tests de integración: php tests/integration/run.php [filtro]. Runner y aserciones en tests/lib/runner.php.
// Requieren la app en BASE_URL (default http://localhost:8080) y su DB de prueba: la conexión la
// arma App\Core\Database con la config de la app (el test nunca lee ni imprime credenciales).
// Turno exclusivo de DB/servidor (D-42). Con SIN_SKIP=1 (así lo corre tests/run-all.sh) un SKIP cuenta como FAIL.
require __DIR__ . '/../lib/runner.php';
require __DIR__ . '/../lib/http.php';

exit(correrSuite(__DIR__, 'Tests de integración', $argv));
