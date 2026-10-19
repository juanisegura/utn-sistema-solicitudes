<?php

declare(strict_types=1);

// R-1 (D-56): extracción de palabras indexables para el prefiltro FULLTEXT. Contrato: de q se
// toman las secuencias de letras/dígitos Unicode de ≥ 3 caracteres que no son stopwords por
// defecto de InnoDB; cada una va como "+palabra*" (prefijo, todas obligatorias); los operadores
// booleanos que escriba el usuario (+ - < > ( ) ~ * " @) no pasan; si no queda ninguna → null
// (fallback a LIKE). Se compara el conjunto de términos (sin orden ni mayúsculas: el contrato no
// los fija). PENDIENTE mientras Solicitud::fulltextQuery no exista.

/** @param list<string> $palabras */
function esperado(array $palabras): array
{
    return terminosFt(implode(' ', array_map(fn ($p) => '+' . $p . '*', $palabras)));
}

function ft(string $q): array
{
    return terminosFt(fulltextQuery($q));
}

test('una palabra → +palabra*', function () {
    assertSame(esperado(['campus']), ft('campus'));
});

test('varias palabras → todas obligatorias con prefijo', function () {
    assertSame(esperado(['campus', 'virtual']), ft('campus virtual'));
});

test('el prefijo corto de 3 caracteres alcanza (búsqueda por prefijo)', function () {
    assertSame(esperado(['cam']), ft('cam'));
});

test('tokens de menos de 3 caracteres se descartan', function () {
    assertSame(esperado(['aula']), ft('el aula 20 a'));
});

test('dígitos cuentan como palabra (≥ 3)', function () {
    assertSame(esperado(['aula', '204']), ft('aula 204'));
    assertSame(esperado(['abc123']), ft('abc123'));
});

test('Unicode: acentos, ñ y ü son letras (y cuentan caracteres, no bytes)', function () {
    assertSame(esperado(['ñandú', 'pingüino']), ft('ñandú pingüino'));
    assertSame(esperado(['josé']), ft('José'));
    assertNull(fulltextQuery('ñú'), '2 caracteres (4 bytes) no alcanzan');
});

test('stopwords por defecto de InnoDB se excluyen (también en mayúsculas)', function () {
    assertSame(esperado(['campus']), ft('the campus'));
    assertSame(esperado(['example']), ft('example com'));
    assertSame(esperado(['aula']), ft('THE aula WITH'));
    assertSame(esperado(['acceso', 'campus']), ft('acceso de la campus en'));
});

test('operadores booleanos del usuario neutralizados: + - < > ( ) ~ * " @', function () {
    assertSame(esperado(['campus', 'virtual']), ft('+campus -virtual'));
    assertSame(esperado(['campus', 'virtual']), ft('"campus virtual"'));
    assertSame(esperado(['campus', 'virtual', 'aula', 'wifi']), ft('<campus >virtual (aula) ~wifi'));
    assertSame(esperado(['camp']), ft('camp*'));
    assertSame(esperado(['campus']), ft('campus @3'));
    assertSame(esperado(['campus']), ft('***campus***'));
});

test('el resultado nunca trae operadores sueltos ni dobles', function () {
    foreach (['+campus -virtual', '"a" (b) ~c', 'camp**', '-- campus --', '@@@ wifi'] as $q) {
        $expr = (string) fulltextQuery($q);
        foreach (preg_split('/\s+/', trim($expr), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            assertTrue(preg_match('/^\+[\p{L}\p{N}]{3,}\*$/u', $t) === 1, "término «{$t}» de «{$q}»");
        }
    }
});

test('signos como separadores: punto, arroba, guion, barra, coma', function () {
    assertSame(esperado(['ana', 'ejemplo', 'example']), ft('ana.ejemplo@example.com'));
    assertSame(esperado(['mail', 'aula']), ft('e-mail/aula,'));
});

test('sin palabras indexables → null (fallback a LIKE)', function () {
    foreach (['%', 'a_b', 'de la', 'de', '', '   ', 'the com', '!!!', '%_', '12', 'a b c', '+-*'] as $q) {
        assertNull(fulltextQuery($q), "«{$q}»");
    }
});

// "foo_bar": el contrato dice "secuencias de letras/dígitos" (el "_" separaría foo y bar), pero
// InnoDB trata "_" como carácter de palabra e indexa "foo_bar" como un solo token: "+bar*" no lo
// encontraría y el resultado diferiría del LIKE. No se fija acá: lo decide la integración
// (FulltextBusquedaTest, "guion bajo dentro de una palabra"), que compara contra LIKE.
