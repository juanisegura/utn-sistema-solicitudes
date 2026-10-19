# ADR-0011 — Búsqueda FULLTEXT híbrida

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-13, A1-13b), revisado e integrado por el orquestador.

## Contexto

La búsqueda del panel (M-3) era `LIKE '%texto%'` sobre asunto, nombre, email y
descripción: correcta (subcadena literal, `%` y `_` sin comodín), pero recorría toda la
tabla en cada consulta (`EXPLAIN type=ALL`). Un índice FULLTEXT es rápido, pero tiene otra
semántica: busca por palabras, ignora tokens cortos y stopwords, no ve signos y no encuentra
subcadenas dentro de una palabra. Reemplazar el LIKE por FULLTEXT rompería el contrato de
M-3 y los casos B del smoke (D-55, D-56).

## Decisión

- Índice `FULLTEXT ft_busqueda (asunto, nombre, email, descripcion)` en `solicitudes`.
- `Solicitud::fulltextQuery($q)` (función pura) extrae del texto las **palabras
  indexables**: secuencias de letras, marcas y dígitos, de modo que los operadores booleanos
  del usuario nunca llegan a la expresión. Descarta las de menos de 3 o más de 84
  caracteres (`innodb_ft_min_token_size` / `max_token_size`) y las que son prefijo de una
  stopword (`abo` no está indexado dentro de `about`). La comparación con las stopwords pliega
  mayúsculas y acentos igual que `utf8mb4_unicode_ci` (`cóm`, `THÉ` o `wás` también se
  descartan): con la extensión intl usa `Normalizer` (NFD sin marcas); sin intl, una tabla
  de letras latinas acentuadas.
- Si el texto buscado contiene `_` o un apóstrofe (`'` o `’`), `fulltextQuery()` devuelve
  `null` y la búsqueda usa solo el `LIKE` (D-59). InnoDB trata el `_` como parte de la
  palabra (`foo_bar` es un único token) y el comportamiento del apóstrofe varía entre
  servidores, así que partir el texto ahí podría generar palabras que el índice no tiene y
  excluir filas válidas. El costo es un recorrido completo solo para esas búsquedas.
- Si queda al menos una palabra, el WHERE es `MATCH(…) AGAINST(? IN BOOLEAN MODE)` con
  `+palabra*` por cada una (obligatoria, por prefijo) **y** el `LIKE … ESCAPE ?` de siempre
  como confirmación exacta. Si no queda ninguna (`%`, `a_b`, `de`), se usa solo el `LIKE`.
  Listado, conteo y contadores comparten el mismo filtro. El AGAINST va como parámetro
  preparado.
- Parámetros verificados en el servidor de desarrollo (MariaDB 11.8): token mínimo 3,
  máximo 84, lista de stopwords por defecto de InnoDB (36 palabras, entre ellas `de`, `en`,
  `la`, `com`, `www`). Son los mismos defaults de MySQL 8. Si un servidor los cambia (otro
  token mínimo o una tabla de stopwords propia en `innodb_ft_server_stopword_table`), hay que
  ajustar las constantes de `Solicitud`; si no, el prefiltro podría excluir filas.

## Alternativas consideradas

- **Solo LIKE:** correcto, pero sin índice: el costo crece linealmente con la tabla.
- **Solo FULLTEXT:** cambia el contrato: `%` y `_` dejan de buscarse, una palabra corta o
  stopword no encuentra nada y `100%_ok` no coincide como texto exacto.
- **Parser `ngram`:** permitiría subcadenas dentro de palabras, pero existe en MySQL y no en
  MariaDB, y multiplica el tamaño del índice.
- **Tabla de trigramas propia o motor externo (Meilisearch, Elasticsearch):**
  desproporcionado para el volumen del TP y fuera del stack sin dependencias (ADR-0001).

## Consecuencias

- Con palabras indexables, `EXPLAIN` pasa de `type=ALL` a `type=fulltext key=ft_busqueda`,
  también en los contadores.
- Una subcadena **en medio de una palabra** (`ampus` en `campus`) ya no encuentra
  resultados cuando el texto tiene palabras indexables: la búsqueda es por palabra completa o
  por su comienzo. La UI lo explica ("Buscá por palabras"). Las búsquedas con `_` o
  apóstrofe siempre usan el LIKE completo.
- Verificado con una prueba diferencial de ~3000 consultas contra el LIKE puro: cero filas
  que el LIKE encuentre desde el inicio de una palabra y el híbrido no, y ninguna fila
  extra. Las 84 pruebas del smoke (casos B incluidos) dan lo mismo.
- **Limitación aceptada (D-62):** InnoDB no indexa tokens de más de 84 caracteres
  (`innodb_ft_max_token_size`). Una búsqueda cuyas palabras están dentro de un token así (por
  ejemplo, un hash o una cadena larga sin separadores) no lo encuentra. No se corrige
  reintentando con el LIKE cuando el FULLTEXT no devuelve nada: la misma búsqueda daría
  resultados de semántica distinta según haya o no otras coincidencias (por palabra en un
  caso, por subcadena en el otro), y eso es más confuso que la limitación. En un servidor
  propio se puede subir `innodb_ft_max_token_size` (y reconstruir el índice) y ajustar
  `FT_MAX` en `Solicitud`.
- Texto sin espacios en escrituras CJK (chino, japonés): el parser por defecto toma cada
  corrida sin separadores como una sola palabra, así que solo se encuentra por su comienzo.
- Las formas Unicode NFC y NFD de un mismo texto ("é" precompuesta o "e" + acento combinado)
  no se normalizan al guardar ni al buscar. La collation las iguala con `=`, pero el `LIKE` no
  es simétrico (verificado en MariaDB 11.8): una búsqueda escrita en NFD no encuentra un texto
  guardado en NFC, aunque al revés sí. La limitación ya existía con el LIKE, antes de R-1.
  Mejora posible: normalizar a NFC (`Normalizer`) al guardar y al buscar.
- InnoDB aplica los cambios del índice FULLTEXT al confirmar la transacción: una fila
  recién insertada y todavía no confirmada no aparece en la búsqueda (con autocommit no se
  nota).
