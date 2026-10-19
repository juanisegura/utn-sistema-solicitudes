<?php

declare(strict_types=1);

use App\Models\Admin;

// M-1 (D-46): límite de intentos exacto por "reservar y contar". Con 4 fallos previos en la
// ventana y N intentos simultáneos con password incorrecta, como mucho UNO llega a verificarse
// (401); el resto → 429. Se mide por dos lados, sin tocar app/:
//   - HTTP: cantidad de 401 vs 429 en la ráfaga.
//   - DB: filas de login_intentos de la cuenta al final = 4 + (401 de la ráfaga) ≤ 5, porque los
//     bloqueados borran su reserva (D-39) y los fallos verificados quedan.
// Para que haya concurrencia real el servidor tiene que atender en paralelo:
// PHP_CLI_SERVER_WORKERS=N php -S … (tests/run-all.sh usa 8). Con un php -S de un solo worker el test pasa
// pero no prueba la carrera (las requests se atienden en serie): correrlo así solo verifica el
// conteo, no la atomicidad.
// Limpia sus filas al terminar: no consume el presupuesto por IP del smoke.

const RAFAGA = 8;
const LIMITE_CUENTA = 5;

function hashCuenta(string $email): string
{
    return hash('sha256', Admin::normalizeEmail($email));
}

test('ráfaga de ' . RAFAGA . ' logins simultáneos con 4 fallos previos: como mucho 1 se verifica', function () {
    requiereServidor();
    requiereDb();
    if (!tablaExiste('login_intentos')) {
        fail('falta la tabla login_intentos (D-38)');
    }
    $email = 'atomic-' . bin2hex(random_bytes(4)) . '@example.com';
    $hash = hashCuenta($email);
    $previosIp = (int) valor('SELECT COUNT(*) FROM login_intentos WHERE created_at > NOW() - INTERVAL 15 MINUTE');
    if ($previosIp + 4 + RAFAGA >= 20) {
        skip("ya hay {$previosIp} fallos recientes: la ráfaga chocaría con el límite por IP (reinstalar la DB)");
    }

    try {
        $previo = new Cliente();
        $token = $previo->token();
        for ($i = 1; $i <= LIMITE_CUENTA - 1; $i++) {
            $r = $previo->post('/admin/login', ['csrf_token' => $token, 'email' => $email, 'password' => "mala-{$i}"]);
            assertSame(401, $r['code'], "fallo previo {$i}");
        }
        assertSame(LIMITE_CUENTA - 1, (int) valor('SELECT COUNT(*) FROM login_intentos WHERE email_hash = ?', [$hash]));

        // Cada intento con su propia sesión y token, preparados antes para que la ráfaga sea solo POST.
        $handles = [];
        for ($i = 0; $i < RAFAGA; $i++) {
            $c = new Cliente();
            $handles[] = $c->handle('POST', '/admin/login', ['csrf_token' => $c->token(), 'email' => $email, 'password' => "rafaga-{$i}"]);
        }
        $resultados = enParalelo($handles);

        $codigos = array_count_values(array_map(fn ($r) => $r['code'], $resultados));
        ksort($codigos);
        $n401 = $codigos[401] ?? 0;
        $n429 = $codigos[429] ?? 0;
        assertSame(RAFAGA, $n401 + $n429, 'solo 401 o 429: ' . json_encode($codigos));
        assertTrue($n401 <= 1, 'como mucho un intento verificado: ' . json_encode($codigos));

        $filas = (int) valor('SELECT COUNT(*) FROM login_intentos WHERE email_hash = ?', [$hash]);
        assertSame(LIMITE_CUENTA - 1 + $n401, $filas, 'filas finales = previas + verificados (los bloqueados borran su reserva)');
        assertTrue($filas <= LIMITE_CUENTA, "nunca más de " . LIMITE_CUENTA . " filas en la ventana ({$filas})");

        // Un intento más, ya en serie, tiene que estar bloqueado.
        $r = $previo->post('/admin/login', ['csrf_token' => $token, 'email' => $email, 'password' => 'mala-final']);
        assertSame(429, $r['code'], 'después de la ráfaga la cuenta queda bloqueada');

    } finally {
        requiereDb()->prepare('DELETE FROM login_intentos WHERE email_hash = ?')->execute([$hash]);
    }
});

test('los intentos con CSRF inválido no reservan fila (orden ruta → CSRF → límite)', function () {
    requiereServidor();
    requiereDb();
    $email = 'csrf-' . bin2hex(random_bytes(4)) . '@example.com';
    $hash = hashCuenta($email);
    try {
        $handles = [];
        for ($i = 0; $i < 4; $i++) {
            $handles[] = (new Cliente())->handle('POST', '/admin/login', ['csrf_token' => 'falso', 'email' => $email, 'password' => 'x']);
        }
        foreach (enParalelo($handles) as $r) {
            assertSame(419, $r['code']);
        }
        assertSame(0, (int) valor('SELECT COUNT(*) FROM login_intentos WHERE email_hash = ?', [$hash]));
    } finally {
        requiereDb()->prepare('DELETE FROM login_intentos WHERE email_hash = ?')->execute([$hash]);
    }
});

test('login exitoso no deja fila propia y limpia la cuenta (reserva borrada)', function () {
    requiereServidor();
    requiereDb();
    $admin = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
    $pass = getenv('ADMIN_PASSWORD') ?: 'Demo-UTN-2026!';
    $hash = hashCuenta($admin);
    $c = new Cliente();
    $token = $c->token();
    $r = $c->post('/admin/login', ['csrf_token' => $token, 'email' => $admin, 'password' => 'mala-' . bin2hex(random_bytes(3))]);
    assertSame(401, $r['code']);
    assertTrue((int) valor('SELECT COUNT(*) FROM login_intentos WHERE email_hash = ?', [$hash]) >= 1, 'el fallo quedó registrado');
    $r = $c->post('/admin/login', ['csrf_token' => $token, 'email' => $admin, 'password' => $pass]);
    assertSame(302, $r['code'], 'login correcto');
    assertSame(0, (int) valor('SELECT COUNT(*) FROM login_intentos WHERE email_hash = ?', [$hash]));
});
