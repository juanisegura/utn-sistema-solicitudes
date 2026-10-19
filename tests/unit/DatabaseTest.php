<?php

declare(strict_types=1);

use App\Core\Database;

// R-3 (D-58): Database ya no lee la configuración por su cuenta. Sin configure() previo,
// pdo() → LogicException clara. Database tiene estado estático: cada caso corre en un proceso
// hijo limpio (en el runner otro test podría haberla configurado). Sin DB: ningún caso conecta a
// una base real. PENDIENTE mientras R-3 no esté entregado (app/bootstrap-cli.php ausente).

function requiereR3(): void
{
    if (!is_file(APP_DIR . '/bootstrap-cli.php')) {
        pending('R-3 no entregado: falta app/bootstrap-cli.php (A1-13)');
    }
}

test('Database ya no tiene lectura propia de config (sin loadConfig)', function () {
    requiereR3();
    assertFalse(method_exists(Database::class, 'loadConfig'));
});

test('pdo() sin configure() → LogicException, sin intentar conectar', function () {
    requiereR3();
    $r = enHijo(<<<'PHP'
        try {
            App\Core\Database::pdo();
            $GLOBALS['resultado'] = ['clase' => null];
        } catch (Throwable $e) {
            $GLOBALS['resultado'] = ['clase' => $e::class, 'mensaje' => $e->getMessage()];
        }
        PHP);
    assertSame(LogicException::class, $r['resultado']['clase'] ?? null);
    assertTrue(stripos($r['resultado']['mensaje'] ?? '', 'configur') !== false, 'el mensaje dice que falta configurar: ' . ($r['resultado']['mensaje'] ?? ''));
});

test('los helpers de consulta sin configure() también → LogicException', function () {
    requiereR3();
    $r = enHijo(<<<'PHP'
        $out = [];
        foreach (['fetchOne', 'fetchAll', 'execute'] as $m) {
            try {
                App\Core\Database::$m('SELECT 1');
                $out[$m] = null;
            } catch (Throwable $e) {
                $out[$m] = $e::class;
            }
        }
        $GLOBALS['resultado'] = $out;
        PHP);
    assertSame(['fetchOne' => LogicException::class, 'fetchAll' => LogicException::class, 'execute' => LogicException::class], $r['resultado']);
});

test('configure() con un servidor inexistente → error de conexión genérico, no LogicException', function () {
    requiereR3();
    $r = enHijo(<<<'PHP'
        App\Core\Database::configure(['host' => '127.0.0.1', 'port' => 1, 'name' => 'x', 'user' => 'usuario-de-prueba', 'pass' => 'clave-de-prueba', 'charset' => 'utf8mb4']);
        try {
            App\Core\Database::pdo();
            $GLOBALS['resultado'] = ['clase' => null];
        } catch (Throwable $e) {
            $GLOBALS['resultado'] = ['clase' => $e::class, 'mensaje' => $e->getMessage()];
        }
        PHP);
    assertSame(RuntimeException::class, $r['resultado']['clase'] ?? null);
    $msg = $r['resultado']['mensaje'] ?? '';
    assertFalse(str_contains($msg, 'usuario-de-prueba') || str_contains($msg, 'clave-de-prueba'), 'el mensaje no trae credenciales: ' . $msg);
});
