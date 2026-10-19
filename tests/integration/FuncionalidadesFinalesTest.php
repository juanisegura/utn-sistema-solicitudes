<?php

declare(strict_types=1);

// Funcionalidades finales (PLAN §7, D-63), contra la app en BASE_URL y su DB:
//  - F-1: límite del formulario público atómico bajo concurrencia ("reservar y contar", como el
//    login): con max-1 envíos previos en la ventana y N simultáneos, como mucho 1 pasa. Un 422
//    también cuenta.
//  - F-3: en la DB solo se guarda SHA-256 del código normalizado; nunca el código.
//  - F-5: cambiar la contraseña invalida las otras sesiones (auth_at < password_changed_at).
//  - F-2: security.log sin datos personales en claro tras recorrer los flujos.
// Cada caso limpia lo que crea (filas propias, envíos de la IP de prueba) y deja la contraseña
// del admin demo como estaba. Requiere turno exclusivo de DB (D-42/D-60).

const STORAGE_LOGS = __DIR__ . '/../../storage/logs';

function adminEmail(): string
{
    return getenv('ADMIN_EMAIL') ?: 'admin@example.com';
}

function adminPass(): string
{
    return getenv('ADMIN_PASSWORD') ?: 'Demo-UTN-2026!';
}

function clienteAdmin(): Cliente
{
    $c = new Cliente();
    assertSame(302, $c->login(adminEmail(), adminPass())['code'], 'login del admin demo');

    return $c;
}

/** POST /solicitudes con sesión y token propios; $valido=false → email inválido (422). */
function enviarFormulario(Cliente $c, string $asunto, bool $valido = true, array $extra = []): array
{
    return $c->post('/solicitudes', [
        'csrf_token' => $c->token('/'), 'nombre' => 'Integración F', 'email' => $valido ? 'f-integracion@example.com' : 'no-es-email',
        'asunto' => $asunto, 'descripcion' => 'Prueba de funcionalidades finales',
    ] + $extra);
}

/** IP con la que la app ve a este cliente: la de la última fila de envios_publicos tras un envío. */
function ipDePrueba(): string
{
    $c = new Cliente();
    enviarFormulario($c, 'ip-' . bin2hex(random_bytes(3)), false);
    $ip = (string) valor('SELECT ip FROM envios_publicos ORDER BY id DESC LIMIT 1');
    assertTrue($ip !== '', 'la app registró el envío en envios_publicos');

    return $ip;
}

function limpiarEnvios(string $ip): void
{
    requiereDb()->prepare('DELETE FROM envios_publicos WHERE ip = ?')->execute([$ip]);
}

function requiereTabla(string $t): void
{
    requiereServidor();
    requiereDb();
    if (!tablaExiste($t)) {
        fail("falta la tabla {$t} (D-63)");
    }
}

test('F-1: con max-1 envíos previos, de 8 simultáneos como mucho 1 pasa (el resto 429)', function () {
    requiereTabla('envios_publicos');
    $max = (int) App\Core\Config::get('app.form_max_por_ip');
    $ip = ipDePrueba();
    $marca = 'f1atom-' . bin2hex(random_bytes(4));
    try {
        limpiarEnvios($ip);
        $ins = requiereDb()->prepare("INSERT INTO envios_publicos (ip, accion) VALUES (?, 'solicitud')");
        for ($i = 1; $i < $max; $i++) {
            $ins->execute([$ip]);
        }
        $handles = [];
        for ($i = 0; $i < 8; $i++) {
            $c = new Cliente();
            $handles[] = $c->handle('POST', '/solicitudes', [
                'csrf_token' => $c->token('/'), 'nombre' => 'Atómico', 'email' => 'atomico@example.com',
                'asunto' => "{$marca} {$i}", 'descripcion' => 'ráfaga',
            ]);
        }
        $codigos = array_count_values(array_map(fn ($r) => $r['code'], enParalelo($handles)));
        assertSame(8, ($codigos[302] ?? 0) + ($codigos[429] ?? 0), 'solo 302 o 429: ' . json_encode($codigos));
        assertTrue(($codigos[302] ?? 0) <= 1, 'como mucho un envío pasa: ' . json_encode($codigos));
        $filas = (int) valor("SELECT COUNT(*) FROM envios_publicos WHERE ip = ? AND accion = 'solicitud'", [$ip]);
        assertTrue($filas <= $max, "nunca más de {$max} envíos en la ventana ({$filas})");
        $creadas = (int) valor('SELECT COUNT(*) FROM solicitudes WHERE asunto LIKE ?', [$marca . '%']);
        assertSame($codigos[302] ?? 0, $creadas, 'solicitudes creadas = envíos que pasaron');
    } finally {
        requiereDb()->prepare('DELETE FROM solicitudes WHERE asunto LIKE ?')->execute([$marca . '%']);
        limpiarEnvios($ip);
    }
});

test('F-1: un envío inválido (422) también cuenta', function () {
    requiereTabla('envios_publicos');
    $max = (int) App\Core\Config::get('app.form_max_por_ip');
    $ip = ipDePrueba();
    try {
        limpiarEnvios($ip);
        $ins = requiereDb()->prepare("INSERT INTO envios_publicos (ip, accion) VALUES (?, 'solicitud')");
        for ($i = 1; $i < $max; $i++) {
            $ins->execute([$ip]);
        }
        $c = new Cliente();
        assertSame(422, enviarFormulario($c, 'f1-422', false)['code'], 'el envío n.º max (inválido) se procesa');
        assertSame(429, enviarFormulario($c, 'f1-despues', true)['code'], 'el siguiente, aunque sea válido, se bloquea');
    } finally {
        limpiarEnvios($ip);
    }
});

test('F-3: en la DB solo está el SHA-256 del código normalizado; el seed no tiene código', function () {
    requiereServidor();
    requiereDb();
    $cols = requiereDb()->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'solicitudes' AND COLUMN_NAME = 'seguimiento_hash'")->fetchAll();
    if ($cols === []) {
        pending('falta solicitudes.seguimiento_hash (F-3, A1-17)');
    }
    $c = new Cliente();
    $marca = 'f3db-' . bin2hex(random_bytes(4));
    assertSame(302, enviarFormulario($c, $marca)['code']);
    $g = $c->get('/gracias')['body'];
    if (preg_match('/[A-Z0-9]{4}(?:-[A-Z0-9]{1,4}){5,}/', $g, $m) !== 1) {
        fail('no encontré el código en /gracias');
    }
    $codigo = $m[0];
    $norm = strtoupper(preg_replace('/[\s-]+/', '', $codigo));
    try {
        $fila = requiereDb()->prepare('SELECT * FROM solicitudes WHERE asunto = ?');
        $fila->execute([$marca]);
        $f = $fila->fetch(PDO::FETCH_ASSOC);
        assertSame(hash('sha256', $norm), $f['seguimiento_hash']);
        foreach ($f as $col => $v) {
            $v = (string) $v;
            assertFalse(stripos($v, $norm) !== false || stripos($v, $codigo) !== false, "la columna {$col} no guarda el código");
        }
        assertSame(0, (int) valor("SELECT COUNT(*) FROM solicitudes WHERE id <= 8 AND seguimiento_hash IS NOT NULL"), 'seed sin código');
    } finally {
        requiereDb()->prepare('DELETE FROM solicitudes WHERE asunto = ?')->execute([$marca]);
    }
});

test('F-5: cambiar la contraseña deja afuera a las otras sesiones y registra password_changed_at', function () {
    requiereServidor();
    requiereDb();
    $a = clienteAdmin();
    $b = clienteAdmin();
    assertSame(200, $b->get('/admin')['code']);
    $nueva = 'Integracion-' . bin2hex(random_bytes(4));
    $antes = valor('SELECT password_changed_at FROM admins WHERE email = ?', [adminEmail()]);
    $r = $a->post('/admin/cuenta/password', [
        'csrf_token' => $a->token('/admin/cuenta/password'), 'password_actual' => adminPass(),
        'password_nueva' => $nueva, 'password_confirmacion' => $nueva,
    ]);
    try {
        assertSame(302, $r['code'], 'cambio aceptado');
        assertNotSame($antes, valor('SELECT password_changed_at FROM admins WHERE email = ?', [adminEmail()]));
        $rb = $b->get('/admin');
        assertSame(302, $rb['code'], 'la sesión B quedó invalidada');
        assertSame(200, $a->get('/admin')['code'], 'la sesión que cambió sigue');
    } finally {
        $r = $a->post('/admin/cuenta/password', [
            'csrf_token' => $a->token('/admin/cuenta/password'), 'password_actual' => $nueva,
            'password_nueva' => adminPass(), 'password_confirmacion' => adminPass(),
        ]);
        assertSame(302, $r['code'], 'contraseña demo restaurada');
    }
});

test('F-2: security.log sin contraseñas, códigos, emails en claro ni query strings tras los flujos', function () {
    requiereServidor();
    requiereDb();
    $log = STORAGE_LOGS . '/security.log';
    clearstatcache();
    $desde = is_file($log) ? filesize($log) : 0;
    $secretos = [];
    // login fallido (password marcada) y login ok
    $passMala = 'PassMala-' . bin2hex(random_bytes(4));
    $secretos[] = $passMala;
    $c = new Cliente();
    $c->login(adminEmail(), $passMala);
    $adm = clienteAdmin();
    // csrf inválido, acceso sin sesión, honeypot con email propio en el cuerpo
    (new Cliente())->post('/solicitudes', ['csrf_token' => 'falso']);
    (new Cliente())->get('/admin?token=secreto-de-query');
    $secretos[] = 'secreto-de-query';
    $emailHp = 'hp-' . bin2hex(random_bytes(3)) . '@example.com';
    $secretos[] = $emailHp;
    $hp = new Cliente();
    enviarFormulario($hp, 'hp-' . bin2hex(random_bytes(3)), true, ['sitio_web' => 'https://bot.example', 'email' => $emailHp]);
    // cambio de contraseña fallido (actual incorrecta) y exitoso + restauración
    $actualMala = 'ActualMala-' . bin2hex(random_bytes(4));
    $nueva = 'NuevaLog-' . bin2hex(random_bytes(4));
    array_push($secretos, $actualMala, $nueva, adminPass(), adminEmail());
    $tok = fn () => $adm->token('/admin/cuenta/password');
    $adm->post('/admin/cuenta/password', ['csrf_token' => $tok(), 'password_actual' => $actualMala, 'password_nueva' => $nueva, 'password_confirmacion' => $nueva]);
    $adm->post('/admin/cuenta/password', ['csrf_token' => $tok(), 'password_actual' => adminPass(), 'password_nueva' => $nueva, 'password_confirmacion' => $nueva]);
    $r = $adm->post('/admin/cuenta/password', ['csrf_token' => $tok(), 'password_actual' => $nueva, 'password_nueva' => adminPass(), 'password_confirmacion' => adminPass()]);
    assertSame(302, $r['code'], 'contraseña demo restaurada');

    clearstatcache();
    $nuevo = is_file($log) ? substr((string) file_get_contents($log), $desde) : '';
    $lineas = array_values(array_filter(explode("\n", $nuevo)));
    assertTrue(count($lineas) >= 6, 'se registraron los eventos: ' . count($lineas));
    foreach ($lineas as $l) {
        assertSame(6, count(explode(' | ', $l)), "6 campos: {$l}");
        foreach ($secretos as $s) {
            assertFalse(stripos($l, $s) !== false, 'dato sensible en claro: ' . $l);
        }
        assertFalse(str_contains(explode(' | ', $l)[3] ?? '', '?'), 'path sin query: ' . $l);
    }
    $eventos = array_map(fn ($l) => explode(' | ', $l)[1] ?? '', $lineas);
    foreach (['login_fallido', 'login_ok', 'csrf_invalido', 'acceso_sin_sesion', 'honeypot', 'password_fallida', 'password_cambiada'] as $ev) {
        assertTrue(in_array($ev, $eventos, true), "evento {$ev} registrado");
    }
    assertTrue(str_contains($nuevo, App\Core\SecurityLog::emailRef(adminEmail())), 'el email se correlaciona por su referencia');
});

// A2-16 (hallazgo): carrera en la invalidación de F-5. Auth::attempt verifica la contraseña (bcrypt,
// ~250 ms) y recién después toma auth_at = NOW(6) en touchLastLogin. Si el cambio de contraseña se
// confirma en ese intervalo, la sesión iniciada con la contraseña VIEJA queda con auth_at posterior
// a password_changed_at y sobrevive al cambio. Los logins exitosos no cuentan en el límite, así que
// quien tenga la contraseña robada puede reintentar hasta caer en la ventana. Se reproduce
// escalonando logins con la contraseña vieja detrás del cambio. FALLA hasta el fix (tomar auth_at en
// el mismo SELECT que lee el hash).
test('F-5: ninguna sesión iniciada con la contraseña vieja sobrevive al cambio (carrera)', function () {
    requiereServidor();
    requiereDb();
    $sobrevivientes = 0;
    $aceptadas = 0;
    for ($ronda = 0; $ronda < 2; $ronda++) {
        $a = clienteAdmin();
        $nueva = 'Carrera-' . bin2hex(random_bytes(4));
        $cambio = $a->handle('POST', '/admin/cuenta/password', [
            'csrf_token' => $a->token('/admin/cuenta/password'), 'password_actual' => adminPass(),
            'password_nueva' => $nueva, 'password_confirmacion' => $nueva,
        ]);
        $intrusos = [];
        $handles = [];
        for ($i = 0; $i < 6; $i++) {
            $c = new Cliente();
            $intrusos[] = $c;
            $handles[] = $c->handle('POST', '/admin/login', ['csrf_token' => $c->token('/admin/login'), 'email' => adminEmail(), 'password' => adminPass()]);
        }
        // El cambio sale primero; los logins, escalonados entre 150 y 525 ms después.
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $cambio);
        $t0 = microtime(true);
        $pend = $handles;
        $k = 0;
        do {
            curl_multi_exec($mh, $activos);
            while ($pend !== [] && microtime(true) - $t0 >= 0.15 + 0.075 * $k) {
                curl_multi_add_handle($mh, array_shift($pend));
                $k++;
                curl_multi_exec($mh, $activos);
            }
            if ($activos > 0 || $pend !== []) {
                curl_multi_select($mh, 0.01);
            }
        } while ($activos > 0 || $pend !== []);
        $codigos = array_map(fn ($h) => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
        $okCambio = (int) curl_getinfo($cambio, CURLINFO_RESPONSE_CODE);
        foreach (array_merge([$cambio], $handles) as $h) {
            curl_multi_remove_handle($mh, $h);
        }
        // El cookie jar de curl se escribe al destruir el handle: liberarlos antes de reusar los clientes.
        unset($h, $cambio, $handles, $mh);
        gc_collect_cycles();
        try {
            assertSame(302, $okCambio, 'el cambio de contraseña se aplicó');
            foreach ($intrusos as $i => $c) {
                if ($codigos[$i] === 302) {
                    $aceptadas++;
                    if ($c->get('/admin')['code'] === 200) {
                        $sobrevivientes++;
                    }
                }
            }
        } finally {
            $r = clienteConPass($nueva);
            $r->post('/admin/cuenta/password', [
                'csrf_token' => $r->token('/admin/cuenta/password'), 'password_actual' => $nueva,
                'password_nueva' => adminPass(), 'password_confirmacion' => adminPass(),
            ]);
        }
    }
    assertSame(0, $sobrevivientes, "de {$aceptadas} logins con la contraseña vieja aceptados, {$sobrevivientes} siguen con sesión válida");
});

function clienteConPass(string $pass): Cliente
{
    $c = new Cliente();
    assertSame(302, $c->login(adminEmail(), $pass)['code'], 'login con la contraseña nueva');

    return $c;
}
