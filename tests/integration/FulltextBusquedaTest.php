<?php

declare(strict_types=1);

// R-1 (D-56): búsqueda híbrida FULLTEXT + LIKE, contra la DB y la app. Cada caso crea sus filas
// (INSERT directo, con una marca aleatoria) y las borra al final; busca por HTTP como admin y lee
// "N resultados para «q»" del listado. Los términos de prueba son palabras inventadas con la
// marca, así no dependen del seed.

function requiereFulltext(): void
{
    requiereServidor();
    requiereDb();
    $n = (int) valor(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'solicitudes' AND index_name = 'ft_busqueda'"
    );
    if ($n === 0) {
        fail('falta el índice FULLTEXT ft_busqueda (R-1)');
    }
}

/** @return list<int> ids insertados */
function filasFt(array $asuntos): array
{
    $ids = [];
    $st = requiereDb()->prepare("INSERT INTO solicitudes (nombre, email, asunto, descripcion) VALUES ('Fulltext', 'fulltext@example.com', ?, 'Prueba R-1')");
    foreach ($asuntos as $a) {
        $st->execute([$a]);
        $ids[] = (int) requiereDb()->lastInsertId();
    }

    return $ids;
}

/** @param list<int> $ids */
function borrarFilas(array $ids): void
{
    $st = requiereDb()->prepare('DELETE FROM solicitudes WHERE id = ?');
    foreach ($ids as $id) {
        $st->execute([$id]);
    }
}

/** Resultados del listado para q: [cantidad, html]. */
function buscarPanel(Cliente $c, string $q, string $extra = ''): array
{
    $r = $c->get('/admin?q=' . rawurlencode($q) . $extra);
    assertSame(200, $r['code'], 'GET /admin?q=' . $q);
    if (preg_match('/(\d+) resultados? para/u', $r['body'], $m) !== 1) {
        fail('no encontré "N resultados para" en el listado');
    }

    return [(int) $m[1], $r['body']];
}

function marcaFt(): string
{
    return 'zq' . bin2hex(random_bytes(4)); // letras y dígitos: una sola palabra indexable
}

function adminFt(): Cliente
{
    $c = new Cliente();
    $r = $c->login(getenv('ADMIN_EMAIL') ?: 'admin@example.com', getenv('ADMIN_PASSWORD') ?: 'Demo-UTN-2026!');
    assertSame(302, $r['code'], 'login del admin demo');

    return $c;
}

test('el índice ft_busqueda es FULLTEXT sobre asunto, nombre, email y descripcion', function () {
    requiereFulltext();
    $st = requiereDb()->query(
        "SELECT column_name, index_type FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'solicitudes' AND index_name = 'ft_busqueda'
         ORDER BY seq_in_index"
    );
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    $cols = array_map(fn ($f) => strtolower((string) ($f['column_name'] ?? $f['COLUMN_NAME'])), $filas);
    sort($cols);
    assertSame(['asunto', 'descripcion', 'email', 'nombre'], $cols);
    assertSame('FULLTEXT', strtoupper((string) ($filas[0]['index_type'] ?? $filas[0]['INDEX_TYPE'])));
});

test('EXPLAIN de una búsqueda con palabras usa ft_busqueda', function () {
    requiereFulltext();
    $expr = \App\Models\Solicitud::fulltextQuery('campus virtual');
    assertTrue($expr !== null, 'hay palabras indexables');
    $st = requiereDb()->prepare(
        'EXPLAIN SELECT id FROM solicitudes WHERE MATCH(asunto, nombre, email, descripcion) AGAINST(? IN BOOLEAN MODE)'
    );
    $st->execute([$expr]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    $plan = array_change_key_case($plan ?: [], CASE_LOWER);
    assertSame('ft_busqueda', $plan['key'] ?? null, 'key del plan: ' . json_encode($plan));
    assertSame('fulltext', strtolower((string) ($plan['type'] ?? '')), 'type del plan');
});

test('prefijo: el comienzo de una palabra la encuentra', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["Aviso {$m}campus del aula"]);
    try {
        [$n] = buscarPanel(adminFt(), substr($m . 'campus', 0, 8));
        assertSame(1, $n);
    } finally {
        borrarFilas($ids);
    }
});

test('palabra completa y varias palabras (todas obligatorias)', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["{$m}uno {$m}dos", "{$m}uno solo"]);
    try {
        $c = adminFt();
        assertSame(2, buscarPanel($c, "{$m}uno")[0]);
        assertSame(1, buscarPanel($c, "{$m}uno {$m}dos")[0]);
    } finally {
        borrarFilas($ids);
    }
});

test('subcadena en medio de una palabra ya no encuentra (consecuencia aceptada de D-56)', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["Aviso {$m}campus"]);
    try {
        // "<marca sin las 2 primeras letras>campus": está dentro del asunto (LIKE encontraría) pero
        // no es prefijo de ningún token.
        [$n, $html] = buscarPanel(adminFt(), substr($m, 2) . 'campus');
        assertSame(0, $n);
        assertContains('No hay solicitudes que coincidan', $html);
    } finally {
        borrarFilas($ids);
    }
});

test('sin palabras indexables (%_, a_b) → fallback LIKE literal sigue funcionando', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["Comodines %_ {$m}", "Guion a_b {$m}", "Decoy aXb {$m}"]);
    try {
        $c = adminFt();
        [$n, $html] = buscarPanel($c, '%_');
        assertTrue($n >= 1, 'encuentra el literal %_');
        assertContains("Comodines %_ {$m}", $html);
        [, $html] = buscarPanel($c, 'a_b');
        assertContains("Guion a_b {$m}", $html);
        assertFalse(str_contains($html, "Decoy aXb {$m}"), '"_" no es comodín');
    } finally {
        borrarFilas($ids);
    }
});

test('confirmación LIKE: las palabras en otro orden o separadas no matchean la frase', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["{$m}alfa {$m}beta", "{$m}beta y {$m}alfa"]);
    try {
        assertSame(1, buscarPanel(adminFt(), "{$m}alfa {$m}beta")[0], 'solo la frase literal');
    } finally {
        borrarFilas($ids);
    }
});

test('guion bajo dentro de una palabra: mismo resultado que LIKE', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["Archivo {$m}_cola.txt"]);
    try {
        assertSame(1, buscarPanel(adminFt(), "{$m}_cola")[0], 'el literal tipeado completo tiene que encontrarse');
    } finally {
        borrarFilas($ids);
    }
});

test('email completo como búsqueda (puntos, arroba y "com" como stopword)', function () {
    requiereFulltext();
    $m = marcaFt();
    $st = requiereDb()->prepare("INSERT INTO solicitudes (nombre, email, asunto, descripcion) VALUES ('Fulltext', ?, 'Prueba de email', 'R-1')");
    $email = "{$m}.persona@example.com";
    $st->execute([$email]);
    $ids = [(int) requiereDb()->lastInsertId()];
    try {
        assertSame(1, buscarPanel(adminFt(), $email)[0]);
    } finally {
        borrarFilas($ids);
    }
});

/** Contadores del listado: ['total' => n, 'pendiente' => n, 'en_proceso' => n, 'resuelta' => n]. */
function contadores(string $html): array
{
    preg_match_all('#<div class="stat stat-([a-z_]+)">\s*<dt>[^<]*</dt>\s*<dd>(\d+)</dd>#u', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $estado, $n]) {
        $out[$estado] = (int) $n;
    }

    return $out;
}

test('contadores con q (M-4): Total y los tres estados, con uno en cero, coherentes con el listado', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["{$m}contador uno", "{$m}contador dos", "{$m}contador tres"]);
    // Estados conocidos: 2 pendientes, 0 en proceso, 1 resuelta.
    requiereDb()->prepare("UPDATE solicitudes SET estado = 'resuelta' WHERE id = ?")->execute([$ids[0]]);
    try {
        $c = adminFt();
        [$n, $html] = buscarPanel($c, "{$m}contador");
        assertSame(3, $n);
        assertSame(['total' => 3, 'pendiente' => 2, 'en_proceso' => 0, 'resuelta' => 1], contadores($html));
        // Con filtro de estado: el listado se filtra, los contadores no (respetan q, no estado).
        [$nResuelta, $htmlResuelta] = buscarPanel($c, "{$m}contador", '&estado=resuelta');
        assertSame(1, $nResuelta);
        assertSame(['total' => 3, 'pendiente' => 2, 'en_proceso' => 0, 'resuelta' => 1], contadores($htmlResuelta));
        assertSame(0, buscarPanel($c, "{$m}contador", '&estado=en_proceso')[0], 'el estado en cero no lista filas');
    } finally {
        borrarFilas($ids);
    }
});

// D-61 (hallazgo 1 de A2-11, limitación documentada en ADR-0011): InnoDB no indexa tokens de más
// de innodb_ft_max_token_size (84) caracteres. Si la fila tiene una "palabra" más larga (hash, JWT,
// tramo de URL, base64) y q es su comienzo, el MATCH no la ve: el híbrido da 0 aunque el LIKE la
// encuentre. Se acepta (reintentar con LIKE volvería inconsistente la semántica de D-56). El caso
// fija ese comportamiento: si algún día cambia, falla y obliga a revisar la decisión.
test('token > 84: limitación documentada (D-61): el híbrido no lo encuentra; el LIKE sí', function () {
    requiereFulltext();
    $m = marcaFt();
    $largo = $m . str_repeat('ab12', 25);              // 108 caracteres sin separadores
    $ids = filasFt(["Ver https://example.com/{$largo} en el navegador"]);
    try {
        $q = substr($largo, 0, 16);
        $patron = '%' . App\Models\Solicitud::escapeLike($q) . '%';
        $like = (int) valor('SELECT COUNT(*) FROM solicitudes WHERE asunto LIKE ? ESCAPE ?', [$patron, '\\']);
        assertSame(1, $like, 'el LIKE puro sí encuentra la fila');
        assertSame(0, buscarPanel(adminFt(), $q)[0], 'el híbrido no (token no indexado): si esto cambia, actualizar D-61/ADR-0011');
    } finally {
        borrarFilas($ids);
    }
});

test('palabra de exactamente 84 caracteres: su prefijo sí la encuentra (borde indexado)', function () {
    requiereFulltext();
    $m = marcaFt();
    $tok = $m . str_repeat('k', 84 - strlen($m));
    $ids = filasFt(["Hash {$tok}"]);
    try {
        assertSame(1, buscarPanel(adminFt(), substr($tok, 0, 16))[0]);
    } finally {
        borrarFilas($ids);
    }
});

// A2-11 (hallazgo): el descarte de prefijos de stopwords compara con acentos, pero el índice usa
// utf8mb4_unicode_ci (sin acentos): "cóm"/"thé" quedan como términos obligatorios y la stopword
// "com"/"the" no está indexada → 0 filas, mientras el LIKE (también sin acentos) las encuentra.
// FALLA hoy.
test('variante acentuada de una stopword ("cóm") no excluye filas que el LIKE encuentra', function () {
    requiereFulltext();
    $m = marcaFt();
    $ids = filasFt(["Dominio .com {$m}"]);
    try {
        assertSame(1, buscarPanel(adminFt(), "cóm {$m}")[0]);
    } finally {
        borrarFilas($ids);
    }
});
