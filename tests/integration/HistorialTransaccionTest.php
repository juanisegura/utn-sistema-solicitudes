<?php

declare(strict_types=1);

// M-2 (A1-10): historial de cambios de estado en transacción. Contrato (PLAN §5):
// SELECT estado … FOR UPDATE; igual al nuevo → no-op sin evento (sigue 302 + flash); distinto →
// UPDATE + INSERT del evento (admin_id, estado_anterior, estado_nuevo); id inexistente → 404.
// FK solicitud ON DELETE CASCADE, FK admin ON DELETE RESTRICT.
// PENDIENTE mientras no exista la tabla solicitud_eventos. Cada caso trabaja sobre una solicitud
// propia (INSERT directo) y la borra al final: no altera el seed.

function requiereHistorial(): void
{
    requiereServidor();
    requiereDb();
    if (!tablaExiste('solicitud_eventos')) {
        pending('tabla solicitud_eventos no existe todavía (M-2, A1-10)');
    }
}

function adminLogueado(): Cliente
{
    $c = new Cliente();
    $r = $c->login(getenv('ADMIN_EMAIL') ?: 'admin@example.com', getenv('ADMIN_PASSWORD') ?: 'Demo-UTN-2026!');
    assertSame(302, $r['code'], 'login del admin demo (¿bloqueado por el smoke? reinstalar la DB)');

    return $c;
}

function solicitudPropia(): int
{
    requiereDb()->prepare("INSERT INTO solicitudes (nombre, email, asunto, descripcion) VALUES ('Integración', 'integracion@example.com', ?, 'Prueba M-2')")
        ->execute(['m2-' . bin2hex(random_bytes(4))]);

    return (int) requiereDb()->lastInsertId();
}

function borrarSolicitud(int $id): void
{
    requiereDb()->prepare('DELETE FROM solicitudes WHERE id = ?')->execute([$id]);
}

/** @return list<array{estado_anterior: string, estado_nuevo: string, admin_id: int}> en orden de inserción */
function eventos(int $id): array
{
    $st = requiereDb()->prepare('SELECT estado_anterior, estado_nuevo, admin_id FROM solicitud_eventos WHERE solicitud_id = ? ORDER BY id');
    $st->execute([$id]);

    return array_map(fn ($e) => ['estado_anterior' => $e['estado_anterior'], 'estado_nuevo' => $e['estado_nuevo'], 'admin_id' => (int) $e['admin_id']], $st->fetchAll(PDO::FETCH_ASSOC));
}

function cambiarEstado(Cliente $c, int $id, string $estado): array
{
    return $c->post("/admin/solicitudes/{$id}/estado", ['csrf_token' => $c->token("/admin/solicitudes/{$id}"), 'estado' => $estado]);
}

test('el alta no genera evento', function () {
    requiereHistorial();
    $id = solicitudPropia();
    try {
        assertSame([], eventos($id));
    } finally {
        borrarSolicitud($id);
    }
});

test('cambio real → 302 y un evento con anterior, nuevo y admin', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    try {
        $r = cambiarEstado($c, $id, 'en_proceso');
        assertSame(302, $r['code']);
        $adminId = (int) valor('SELECT id FROM admins WHERE email = ?', [getenv('ADMIN_EMAIL') ?: 'admin@example.com']);
        assertSame([['estado_anterior' => 'pendiente', 'estado_nuevo' => 'en_proceso', 'admin_id' => $adminId]], eventos($id));
        assertSame('en_proceso', valor('SELECT estado FROM solicitudes WHERE id = ?', [$id]));
    } finally {
        borrarSolicitud($id);
    }
});

test('mismo estado → no-op: 302 + flash pero sin evento', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    try {
        $r = cambiarEstado($c, $id, 'pendiente');
        assertSame(302, $r['code']);
        assertContains('Estado actualizado', $c->get("/admin/solicitudes/{$id}")['body']);
        assertSame([], eventos($id));
    } finally {
        borrarSolicitud($id);
    }
});

test('estado inválido (422) e id inexistente (404) no generan evento', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    try {
        assertSame(422, cambiarEstado($c, $id, 'cerrada')['code']);
        assertSame([], eventos($id));
        $antes = (int) valor('SELECT COUNT(*) FROM solicitud_eventos');
        $r = $c->post('/admin/solicitudes/999999999/estado', ['csrf_token' => $c->token("/admin/solicitudes/{$id}"), 'estado' => 'resuelta']);
        assertSame(404, $r['code']);
        assertSame($antes, (int) valor('SELECT COUNT(*) FROM solicitud_eventos'));
    } finally {
        borrarSolicitud($id);
    }
});

test('cambios simultáneos: el historial queda encadenado y coincide con el estado final', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    try {
        $token = $c->token("/admin/solicitudes/{$id}");
        $handles = [];
        foreach (['en_proceso', 'resuelta', 'pendiente', 'resuelta', 'en_proceso', 'resuelta', 'pendiente', 'en_proceso'] as $estado) {
            $handles[] = $c->handle('POST', "/admin/solicitudes/{$id}/estado", ['csrf_token' => $token, 'estado' => $estado]);
        }
        foreach (enParalelo($handles) as $r) {
            assertSame(302, $r['code']);
        }
        $ev = eventos($id);
        assertTrue($ev !== [], 'al menos un evento');
        $previo = 'pendiente';
        foreach ($ev as $i => $e) {
            assertSame($previo, $e['estado_anterior'], "evento {$i}: anterior = nuevo del evento previo (sin lost update)");
            assertNotSame($e['estado_anterior'], $e['estado_nuevo'], "evento {$i}: nunca un evento sin cambio");
            $previo = $e['estado_nuevo'];
        }
        assertSame($previo, valor('SELECT estado FROM solicitudes WHERE id = ?', [$id]), 'estado final = último evento');
    } finally {
        borrarSolicitud($id);
    }
});

test('FK: borrar la solicitud borra su historial (CASCADE)', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    cambiarEstado($c, $id, 'resuelta');
    assertSame(1, count(eventos($id)));
    borrarSolicitud($id);
    assertSame(0, (int) valor('SELECT COUNT(*) FROM solicitud_eventos WHERE solicitud_id = ?', [$id]));
});

test('FK: un admin con eventos no se puede borrar (RESTRICT)', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    $pdo = requiereDb();
    try {
        cambiarEstado($c, $id, 'resuelta');
        $adminId = (int) valor('SELECT admin_id FROM solicitud_eventos WHERE solicitud_id = ?', [$id]);
        $pdo->beginTransaction();
        try {
            assertThrows(PDOException::class, fn () => $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$adminId]));
        } finally {
            $pdo->rollBack();
        }
        assertSame(1, (int) valor('SELECT COUNT(*) FROM admins WHERE id = ?', [$adminId]));
    } finally {
        borrarSolicitud($id);
    }
});

// El orden (más reciente primero) se verifica en A2-08 contra el markup real de A1-10.
test('el detalle muestra "Sin cambios…" y después los cambios', function () {
    requiereHistorial();
    $c = adminLogueado();
    $id = solicitudPropia();
    try {
        $sinCambios = $c->get("/admin/solicitudes/{$id}")['body'];
        assertContains('Sin cambios de estado todavía.', $sinCambios);
        cambiarEstado($c, $id, 'en_proceso');
        cambiarEstado($c, $id, 'resuelta');
        $html = $c->get("/admin/solicitudes/{$id}")['body'];
        $posResuelta = strpos($html, 'Resuelta', (int) strpos($html, 'Historial'));
        $posProceso = strpos($html, 'En proceso', (int) strpos($html, 'Historial'));
        assertTrue($posResuelta !== false && $posProceso !== false, 'ambos cambios visibles');
    } finally {
        borrarSolicitud($id);
    }
});
