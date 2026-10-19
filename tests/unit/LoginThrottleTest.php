<?php

declare(strict_types=1);

use App\Core\LoginThrottle;

// AU-7 (Q-11): LoginThrottle::configure valida los rangos de app.login_* (D-53): enteros > 0. Un
// límite mal configurado (0, negativo, texto) tiene que fallar al arrancar, no en producción con
// el límite apagado (fail-open) o bloqueando a todos.

const LIMITES_OK = ['login_max_por_cuenta' => 5, 'login_max_por_ip' => 20, 'login_ventana_seg' => 900];

test('configure acepta los valores por defecto y enteros como texto', function () {
    LoginThrottle::configure(LIMITES_OK);
    LoginThrottle::configure(['login_max_por_cuenta' => '5', 'login_max_por_ip' => '20', 'login_ventana_seg' => '900']);
    LoginThrottle::configure(['login_max_por_cuenta' => 1, 'login_max_por_ip' => 1, 'login_ventana_seg' => 1]);
    assertTrue(true);
});

test('configure rechaza 0, negativos, decimales, texto, vacío y null en cada clave', function () {
    foreach (array_keys(LIMITES_OK) as $clave) {
        foreach ([0, -1, '0', '-5', 1.5, '1.5', 'abc', '', null, [5]] as $malo) {
            $cfg = [$clave => $malo] + LIMITES_OK;
            $e = assertThrows(InvalidArgumentException::class, fn () => LoginThrottle::configure($cfg), "{$clave} = " . exportar($malo));
            assertContains($clave, $e->getMessage(), 'el mensaje nombra la clave');
        }
    }
    LoginThrottle::configure(LIMITES_OK);
});

test('configure rechaza claves faltantes', function () {
    foreach (array_keys(LIMITES_OK) as $clave) {
        $cfg = LIMITES_OK;
        unset($cfg[$clave]);
        assertThrows(InvalidArgumentException::class, fn () => LoginThrottle::configure($cfg), "sin {$clave}");
    }
    LoginThrottle::configure(LIMITES_OK);
});
