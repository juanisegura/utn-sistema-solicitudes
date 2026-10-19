<?php

declare(strict_types=1);

// M-8: timeout de inactividad de la sesión (D-21), automatizado en CLI. Cada caso corre en un
// proceso hijo con session.save_path temporal propio. API D-49: Session::configure(timeout,
// forceSecure) + Session::start(). Se reabre la misma sesión con session_id($id) entre
// session_write_close() y el siguiente start, como haría la cookie en la request siguiente.

const ESCENARIO_TIMEOUT = <<<'PHP'
    use App\Core\Session;

    session_save_path(__DIR__);
    Session::configure($DATOS['timeout'], false);

    // 1.ª request: login simulado
    Session::start();
    Session::set('admin_id', 1);
    $id1 = session_id();
    if ($DATOS['inactivo'] !== null) {
        $_SESSION['_last_activity'] = time() - $DATOS['inactivo'];
    }
    session_write_close();

    if ($DATOS['dormir'] > 0) {
        sleep($DATOS['dormir']);
    }

    // 2.ª request con la misma cookie
    session_id($id1);
    Session::start();
    $GLOBALS['resultado'] = [
        'id1'          => $id1,
        'id2'          => session_id(),
        'admin_id'     => Session::get('admin_id'),
        'last'         => $_SESSION['_last_activity'] ?? null,
        'viejo_existe' => is_file(__DIR__ . '/sess_' . $id1),
    ];
    PHP;

function escenarioTimeout(int $timeout, ?int $inactivo, int $dormir = 0): array
{
    $r = enHijo(ESCENARIO_TIMEOUT, ['timeout' => $timeout, 'inactivo' => $inactivo, 'dormir' => $dormir]);

    return $r['resultado'] ?? fail('el escenario no devolvió resultado');
}

test('antes de vencer (59 s de 60): conserva datos e ID', function () {
    $r = escenarioTimeout(60, 59);
    assertSame(1, $r['admin_id']);
    assertSame($r['id1'], $r['id2']);
});

test('después de vencer (61 s de 60): se vacía y cambia el ID', function () {
    $r = escenarioTimeout(60, 61);
    assertNull($r['admin_id'], 'datos vaciados');
    assertNotSame($r['id1'], $r['id2'], 'ID regenerado');
    assertFalse($r['viejo_existe'], 'la sesión vieja se borra del almacenamiento');
});

test('después de vencer, la actividad se vuelve a registrar (la sesión nueva no nace vencida)', function () {
    $r = escenarioTimeout(60, 61);
    assertTrue(is_int($r['last']) && abs(time() - $r['last']) <= 5, '_last_activity actual');
});

test('cada request renueva la actividad (ventana deslizante)', function () {
    $r = escenarioTimeout(60, 30);
    assertSame(1, $r['admin_id']);
    assertTrue(abs(time() - $r['last']) <= 5, '_last_activity actualizado en la 2.ª request');
});

test('tiempo real: timeout 1 s y 2 s de espera → vencida', function () {
    $r = escenarioTimeout(1, null, 2);
    assertNull($r['admin_id']);
    assertNotSame($r['id1'], $r['id2']);
});

test('tiempo real: timeout 5 s sin espera → vigente', function () {
    $r = escenarioTimeout(5, null, 0);
    assertSame(1, $r['admin_id']);
    assertSame($r['id1'], $r['id2']);
});

test('gc_maxlifetime ≥ timeout (D-36): el GC no corta antes', function () {
    $r = enHijo(<<<'PHP'
        session_save_path(__DIR__);
        App\Core\Session::configure(3600, false);
        App\Core\Session::start();
        $GLOBALS['resultado'] = (int) ini_get('session.gc_maxlifetime');
        PHP);
    assertTrue($r['resultado'] >= 3600, 'gc_maxlifetime = ' . $r['resultado']);
});
