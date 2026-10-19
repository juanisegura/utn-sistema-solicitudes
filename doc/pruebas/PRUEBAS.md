# Pruebas

Verificación del sistema de solicitudes contra las pruebas mínimas de la consigna (§4) y los códigos HTTP definidos en el PLAN (§3). La prueba automatizada es `tests/smoke.sh` (bash + curl, por HTTP contra la app en ejecución); el mapeo caso → prueba mínima está en `tests/README.md`.
Para generar evidencia: `tests/smoke.sh --evidencia`, que escribe `doc/pruebas/evidencias/smoke-AAAAMMDD-HHMMSS.txt`.
Correr contra una base de desarrollo reinstalable con `php scripts/instalar-db.php` (ver `tests/README.md`, "Base de datos reinstalable").
Las columnas "Resultado obtenido" y "Evidencia" se completan al ejecutar contra la implementación. Hoy quedan vacías; se completan en la corrida final (ver "Corridas").

## Pruebas mínimas de la consigna

| Escenario de la consigna | Caso(s) del script | Resultado esperado | Resultado obtenido | Evidencia |
|---|---|---|---|---|
| P1. Solicitud válida | P1.1 a P1.6 | GET / 200 con formulario y token CSRF; POST válido 302 a /gracias; /gracias 200 con confirmación y, al repetirlo, 302 a /; la solicitud aparece en el panel con sus datos | | |
| P2. Campos inválidos o incompletos | P2.1 a P2.4 | 422 con mensaje por campo obligatorio o email inválido (también con valores solo espacios); ninguna fila nueva para los inválidos | | |
| P3. Acceso al panel sin sesión | P3.1 a P3.4 | 302 a /admin/login para /admin, /admin/solicitudes/1 y /admin?estado=pendiente; POST /admin/logout sin sesión 401 | | |
| P4. Credenciales incorrectas | P4.1 a P4.3 | 401 con mensaje genérico idéntico para password incorrecta y email inexistente (anti-enumeración); GET /admin posterior 302 a /admin/login | | |
| P5. Cambio de estado autorizado | P5.1 a P5.6 (P5.1 y P5.2 son el login correcto) | Login 302 a /admin; /admin 200; estado inicial pendiente; POST estado=en_proceso con sesión y CSRF 302 al detalle de la solicitud; el detalle muestra en_proceso y el flash "Estado actualizado", que no se repite al recargar | | |
| P6. Cambio de estado no autorizado | P6.1 a P6.8 | Sin sesión, 401 exacto, con o sin token CSRF; con sesión, sin CSRF o con token de otra sesión, 419; estado fuera de lista blanca, con inyección o ausente, 422; estado como array, 422. En todos los casos el estado persiste en en_proceso | | |
| P7. Ingreso de HTML/SQL malicioso | P7.1 a P7.7 | Payloads aceptados como texto (302 a /gracias); en listado y detalle, `<script>` e `<img onerror>` se muestran escapados y nunca crudos; payload SQL guardado literal; 422 re-renderiza escapado; filtro con inyección 400 sin error SQL; id inyectado en la ruta 404 | | |

## Verificaciones adicionales

| Grupo temático | Caso(s) del script | Resultado esperado | Resultado obtenido | Evidencia |
|---|---|---|---|---|
| CSRF (419) | H.1 a H.5 | 419 en login aun con credenciales correctas, y en /solicitudes sin token, con token falso o con token de otra sesión; sin acceso al panel tras el intento (H.2, 302 a /admin/login) | | |
| Método incorrecto (405) | H.6, H.10 | 405 con cabecera Allow que incluye POST, en GET /admin/solicitudes/{id}/estado y GET /solicitudes | | |
| 404 | H.7, H.8, H.9, H.11 | 404 para id inexistente (GET y POST), id no numérico y ruta inexistente | | |
| Sin fugas de errores | H.7, H.11 | El cuerpo de los 404 no contiene SQLSTATE, PDOException, Stack trace, Fatal error, "on line N" ni errores SQL | | |
| Headers de seguridad | S.1 a S.3 | X-Frame-Options: DENY; X-Content-Type-Options: nosniff; Content-Security-Policy con default-src 'self' | | |
| Rotación de ID de sesión | S.4 | El ID de sesión cambia al loguear (session_regenerate_id) | | |
| Flags de cookie | S.5 | Set-Cookie de sesión con HttpOnly y SameSite=Lax | | |
| Guest redirect | S.6 | GET /admin/login con sesión activa 302 a /admin | | |
| Logout | S.7, S.8 | POST sin CSRF 419 y la sesión sigue activa; con CSRF 302, y luego /admin redirige a /admin/login | | |
| Filtro por estado (F6) | F6.1 a F6.4 | ?estado=en_proceso incluye la solicitud; ?estado=pendiente no la incluye; ?estado= vacío da 200 con todas; ?estado=cerrada da 400 | | |
| Orden del listado (F5) | F5.1 | Listado ordenado por created_at DESC: la solicitud más nueva aparece primero (D-20) | | |
| Entrada malformada: UTF-8 inválido (H.14) y espacios Unicode (P2.5) | H.14, P2.5 | 422, nunca 500 ni alta | | |
| Archivos ocultos bajo php -S (H.15) | H.15 | GET /.htaccess → 403/404 | | |
| Límite de intentos (D-38, D-41) | L.1 a L.4, L.6, L.7 | 5 fallos → 401; 6.º → 429 + Retry-After + "Demasiados intentos" (cuenta inexistente y admin, este con la password correcta); POST sin CSRF → 419 sin contar; una cuenta bloqueada no frena a otra desde la misma IP; con el admin bloqueado, variantes con acento + password correcta → 401/429 (L.6); email en mayúsculas sigue entrando (L.7) | | |
| Búsqueda y paginación (M-3) | B.1 a B.7 | búsqueda propia de la corrida (21 filas, 2 páginas); `%` y `_` literales; `q` reflejado escapado; `q` y `pagina` inválidos → 400; página fuera de rango → 404; links conservan `q` y `estado` | | |
| Historial (M-2) | B.8 | el detalle muestra el cambio de P5; un POST al mismo estado no agrega evento | | |
| Sesión perezosa (M-5) | B.9 | 404, 405, estáticos e id inválido sin `Set-Cookie` | | |
| Password con NUL (H.16) | H.16 | password correcta + %00 + sufijo en el login → 401 y /admin sigue en 302 (bcrypt ignora lo que sigue al NUL) | | |
| Parámetros como array (H.12, H.13, P6.8) | H.12, H.13, P6.8 | nombre[] en el formulario público 422 (H.12); ?estado[] 400 (H.13); estado[] en el cambio de estado 422 (P6.8); sin fugas de error ni 500 | | |

## Tests automatizados (unitarios e integración)

Además del smoke HTTP hay tests unitarios (`php tests/unit/run.php`, sin DB ni red) e integración (`php tests/integration/run.php`, contra la DB y la app), con un runner propio sin dependencias (D-48). El detalle por archivo está en `tests/README.md`, sección "Tests unitarios, integración y CI". Todo (sintaxis, unitarios, integración y smoke) se corre localmente con `bash tests/run-all.sh` (D-51: no hay CI en GitHub). PHP 8.2 y MySQL 8.0 no se verifican automáticamente; el procedimiento manual está en `tests/README.md`.

| Mejora | Cubierta por | Resultado esperado |
|---|---|---|
| M-1 IP con proxies de confianza | `unit/RequestIpTest.php` (24 casos) | XFF solo desde proxies configurados, de derecha a izquierda; huecos (a)–(d) de D-49 |
| M-1 límite exacto bajo concurrencia | `integration/LoginAtomicidadTest.php` | 4 fallos + 8 simultáneos → como mucho un 401; filas ≤ 5 |
| M-2 historial en transacción | `integration/HistorialTransaccionTest.php` | eventos encadenados también con cambios simultáneos; no-op sin evento; FKs |
| M-3 escape de LIKE | `unit/LikeEscapeTest.php` | `%`, `_` y `\` escapados |
| M-5 sesión solo con ruta resuelta | `unit/RouterTest.php` (casos M-5) | 404/405 sin sesión; la acción corre con sesión activa |
| M-6 rotación de logs | `unit/LogTest.php` | rotación por tamaño, tope de archivos, sin pérdidas con dos procesos |
| M-8 timeout de sesión | `unit/SessionTimeoutTest.php` | antes de vencer conserva datos e ID; después se vacía y cambia el ID |
| Validator, Estado, Router, Csrf, redirect, Admin | `unit/*Test.php` | regresiones de A2-02..A2-06 |

## Pruebas manuales (navegador)

El script no cubre los siguientes puntos:

- [ ] Revisión visual del formulario público (campos, etiquetas, layout en ancho de teléfono).
- [ ] Revisión visual del panel admin (listado, filtros, detalle).
- [ ] Revisión visual de las páginas de error (404, 405, 419) y de /gracias.
- [ ] Mensajes de error junto a cada campo inválido.
- [x] Timeout de sesión por inactividad (D-21): automatizado en `tests/unit/SessionTimeoutTest.php` (M-8). Opcional de punta a punta por HTTP: procedimiento en `tests/README.md`, sección "Prueba manual del timeout de sesión (D-21)", bajando `app.session_timeout` en la config local.

Verificado a mano en A2-04 (curl + snippets PHP que cargan la app; no automatizable sin acceso a la DB/sesiones desde el smoke):

- [x] Admin desactivado (`activo=0`) con sesión viva: GET /admin → 302 a login, POST de estado → 401; login con credenciales correctas → 401 con el mensaje genérico. Al reactivarlo la sesión vieja no recupera el acceso.
- [x] Timeout de inactividad simulado editando `_last_activity` del archivo de sesión (ver `tests/README.md`): 1700 s → 200; 1801 s → 302 a login, ID de sesión nuevo; POST con el token previo → 401 sin cambio.
- [x] Fijación: cookie `utn_sesion` inventada → el servidor emite un ID nuevo (use_strict_mode); la cookie de invitado deja de valer tras el login y la autenticada tras el logout (302 a login).
- [x] Respuestas con sesión llevan `Cache-Control: no-store, no-cache, must-revalidate` (cache_limiter de PHP): "Atrás" tras logout no muestra datos cacheados.

Verificado a mano en A2-05 contra la implementación de A1-06 (servidor propio en 127.0.0.1:8099, IP de origen con `curl --interface`):

- [x] L.5 límite por IP: 20 fallos con 20 emails distintos desde 127.0.0.11 → 401 c/u; el 21.º con otro email → 429 (Retry-After 897) y el admin con password correcta desde esa IP → 429; desde 127.0.0.12 el admin entra (302).
- [x] Los intentos bloqueados (429) no se registran: 3 más desde 127.0.0.11 dejaron el conteo en 20 filas.
- [x] El 429 re-renderiza el login con "Probá de nuevo en 15 minutos." y el email escapado.
- [x] Hueco de acentos (hallado en A2-05: con admin@example.com bloqueado, `ádmin@example.com` + password correcta entraba por la colación `utf8mb4_unicode_ci`): corregido por D-41 y cubierto por **L.6**; L.7 confirma que las mayúsculas siguen normalizándose.

Comportamiento con JavaScript deshabilitado: no aplica, la aplicación no usa JS.

## Corridas

| Fecha | Estado de la implementación | Resultado | Evidencia |
|---|---|---|---|
| 2026-10-19 13:45 | Fase 1 + formulario público; panel admin sin implementar (A1-03 pendiente) | 64 casos: 19 PASS, 12 FAIL, 33 SKIP. Público (P1.1–P1.4, P2.1–P2.3, P7.1, P7.5, H.3–H.5, H.10–H.12, S.1–S.3, S.5) en PASS; todo /admin responde 404 (rutas aún no existen), el resto SKIP por falta de login | sin archivo (corrida exploratoria) |
| 2026-10-19 14:20 | Commit 7bb711d (auth + panel), DB recién reinstalada (A2-04) | 68 casos: 67 PASS, 1 FAIL (H.16, hallazgo nuevo de A2-04: login acepta password + NUL + sufijo), 0 SKIP | sin archivo (corrida de revisión) |
| 2026-10-19 16:50 | c710de5 + A1-06 sin commitear (límite de login), DB recién reinstalada (A2-05) | 72 casos: 72 PASS, 0 FAIL, 0 SKIP | sin archivo (corrida de revisión) |
| 2026-10-19 18:40 | A1-09 en el árbol sin commitear (M-1, M-5, M-6) + `escapeLike` de A1-10 (A2-07) | Unitarios: 99 tests, 99 PASS. Los mismos tests contra `HEAD` sin A1-09: 56 PASS, 39 FAIL (todos los de M-1, M-5, M-6 y M-8), 4 PENDIENTE. Integración y smoke: no ejecutados (sin turno de DB, D-42) | sin archivo |
| 2026-10-19 15:05 | Árbol actual (f09af47 + 4b12373 + A1-11 sin commitear) con los casos B (A2-08), antes de los 2 tests de hallazgos | `run-all.sh`: sintaxis OK, unitarios 99/99, integración 11/11 (8 workers), smoke 84/84 | `evidencias/run-all-20261019-150507-verde.txt` |
| 2026-10-19 15:06 | Ídem + 2 tests unitarios de hallazgos de A2-08 (recorte cuadrático, rotación que lanza) | `run-all.sh` corta en la etapa 2: 101 tests, 99 PASS, 2 FAIL (los dos hallazgos, esperado hasta el fix) | `evidencias/run-all-20261019-150635-con-hallazgos.txt` |
