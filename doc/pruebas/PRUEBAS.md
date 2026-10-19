# Pruebas

Evidencia de las pruebas realizadas sobre el sistema de solicitudes (consigna §4) y de las verificaciones adicionales definidas en el PLAN (§3 y §5).

**Corrida oficial vigente**: 2026-10-19 15:51, árbol del commit de cierre (código de `a34a0d8` + caso de TAB, D-50), DB recién reinstalada, `bash tests/run-all.sh --evidencia` → **todo OK**: sintaxis, 102 tests unitarios, 11 de integración y 84 casos smoke, sin fallas ni omitidos. Corrida hecha por el orquestador.
Evidencia: [`evidencias/run-all-20261019-155152.txt`](evidencias/run-all-20261019-155152.txt) (salida completa de las cinco etapas) y [`evidencias/smoke-20261019-155159.txt`](evidencias/smoke-20261019-155159.txt) (smoke con `--evidencia`).

Cómo reproducirla: `bash tests/run-all.sh` desde la raíz del repo (requiere la DB de desarrollo, que se borra y recrea; detalle en [`../../tests/README.md`](../../tests/README.md)).

## Pruebas mínimas de la consigna (§4)

| Prueba mínima | Resultado esperado | Resultado obtenido | Casos | Evidencia |
|---|---|---|---|---|
| 1. Registro de solicitud válida | 302 a `/gracias` con confirmación visible (PRG); la solicitud aparece en el panel con asunto, email y descripción | ✅ 6/6 PASS | P1.1–P1.6 | `smoke-20261019-155159.txt` |
| 2. Campos inválidos o incompletos | 422 con un mensaje por campo (obligatorio, email inválido, solo espacios, también Unicode); ninguna fila nueva | ✅ 5/5 PASS | P2.1–P2.5 | ídem |
| 3. Acceso al panel sin sesión | GET de `/admin`, detalle y filtro → 302 a `/admin/login`; POST de logout sin sesión → 401 | ✅ 4/4 PASS | P3.1–P3.4 | ídem |
| 4. Credenciales incorrectas | 401 con el mismo mensaje genérico para password errónea y email inexistente; sin acceso posterior | ✅ 3/3 PASS | P4.1–P4.3 | ídem |
| 5. Cambio de estado autorizado | Con sesión y CSRF: 302 al detalle, estado persistido, flash "Estado actualizado" que no se repite | ✅ 6/6 PASS | P5.1–P5.6 | ídem |
| 6. Cambio de estado no autorizado | Sin sesión → 401; sin CSRF o con token ajeno → 419; estado fuera de lista, inyectado, ausente o array → 422; el estado no cambia | ✅ 8/8 PASS | P6.1–P6.8 | ídem |
| 7. Ingreso de HTML/SQL malicioso | Se guarda literal; en listado y detalle se muestra escapado, nunca crudo; filtro inyectado → 400 sin error SQL; id inyectado → 404 | ✅ 7/7 PASS | P7.1–P7.7 | ídem |

El detalle de cada caso (ID → descripción) está en `tests/README.md`, sección "Mapeo de casos".

## Resumen de la suite automatizada

| Capa | Comando | Casos | Resultado oficial |
|---|---|---|---|
| Sintaxis | `php -l` de `app/`, `public_html/`, `scripts/`, `tests/` | todo el árbol | OK |
| Unitarios (sin DB ni red) | `php tests/unit/run.php` | 102 | 102 PASS en la corrida oficial |
| Integración (DB + app en `php -S` con 8 workers) | `php tests/integration/run.php` | 11 | 11 PASS |
| Smoke HTTP | `bash tests/smoke.sh` | 84 | 84 PASS |

**Smoke por familia** (84):

| Familia | Casos | Qué cubre |
|---|---|---|
| P — pruebas mínimas | 39 | las 7 de la consigna (tabla de arriba) |
| H — códigos HTTP y entrada malformada | 16 | CSRF 419 (H.1–H.5), 405 + `Allow` (H.6, H.10), 404 sin fugas (H.7–H.9, H.11), parámetros como array (H.12, H.13), UTF-8 inválido (H.14), dotfiles bajo `php -S` (H.15), password con NUL (H.16) |
| S — sesión y headers | 8 | XFO, nosniff, CSP (S.1–S.3), regeneración del ID al loguear (S.4), cookie HttpOnly + SameSite (S.5), redirect con sesión (S.6), logout con/sin CSRF (S.7, S.8) |
| F — listado | 5 | orden por fecha descendente (F5.1), filtro por estado incl. vacío e inválido (F6.1–F6.4) |
| L — límite de intentos (D-38, D-41) | 6 | 5 fallos → 401 y el 6.º → 429 + `Retry-After` (L.1); por cuenta, no global (L.2); bloqueado aun con password correcta (L.3); sin CSRF no cuenta (L.4); variantes con acento no son otra cuenta (L.6); mayúsculas sí se normalizan (L.7) |
| B — búsqueda, paginación, historial, sesión perezosa (M-2, M-3, M-5) | 10 | búsqueda (B.1), `%` y `_` literales (B.2), `q` reflejado escapado (B.3), `q`/`pagina` inválidos → 400 (B.4, B.4b, B.5), fuera de rango → 404 (B.6), links conservan `q` y `estado` (B.7), historial y no-op (B.8), 404/405/estáticos sin `Set-Cookie` (B.9) |

**Unitarios por unidad** (102): Validator 19 (incluye TAB, D-50) · Estado 6 · Router 14 (incluye M-5) · Csrf 8 · Response::redirect 3 · Admin 10 · Request::ip con proxies (M-1) 24 · Log con rotación (M-6) 7 · timeout de sesión (M-8) 7 · escape de LIKE (M-3) 4. Incluyen las regresiones de los hallazgos de A2-08 (recorte lineal sin `pcre.jit`, rotación que falla sin lanzar).

**Integración** (11): atomicidad del límite de intentos bajo concurrencia (M-1, 3 casos: 4 fallos + 8 intentos simultáneos → como mucho un 401) e historial de estados en transacción (M-2, 8 casos: eventos encadenados también con cambios simultáneos, no-op sin evento, FKs CASCADE/RESTRICT).

**Mejoras de la fase §5 y dónde se prueban**

| Mejora | Pruebas |
|---|---|
| M-1 límite exacto y proxies de confianza | `integration/LoginAtomicidadTest.php`, `unit/RequestIpTest.php` |
| M-2 historial de estados | `integration/HistorialTransaccionTest.php`, smoke B.8 |
| M-3 búsqueda y paginación | `unit/LikeEscapeTest.php`, smoke B.1–B.7 |
| M-4 contadores con `GROUP BY` | sin caso dedicado: revisión de código en A2-08 (una consulta, respeta `q`, ceros para estados sin filas); el listado de B.1 los muestra pero el smoke no compara sus valores |
| M-5 sesión solo con ruta resuelta | `unit/RouterTest.php` (casos M-5), smoke B.9 |
| M-6 rotación de logs | `unit/LogTest.php` |
| M-7 tests y corrida local | este documento; `tests/run-all.sh` (no hay CI en GitHub, D-51) |
| M-8 timeout de sesión | `unit/SessionTimeoutTest.php` |

**Segunda fase de mejoras (R-1..R-3, PLAN §6)**: pruebas escritas contra el contrato (A2-10), pendientes de la corrida oficial de cierre. Con A1-13 en el árbol la suite unitaria pasa a **131** (los 12 casos de Router/redirect por Reflection o proceso hijo se reemplazan por 41 en proceso) y la de integración a **24**:

| Mejora | Pruebas |
|---|---|
| R-1 búsqueda FULLTEXT híbrida (D-56) | `unit/FulltextQueryTest.php` (extracción de palabras, stopwords, operadores), `integration/FulltextBusquedaTest.php` (índice, `EXPLAIN`, prefijo, subcadena interna, fallback LIKE, contadores) y los casos B del smoke, que deben dar lo mismo que con LIKE |
| R-2 `RouteMatcher` y despacho sin `exit` (D-57) | `unit/RouteMatcherTest.php`, `unit/DespachoTest.php` (incluye "ningún `exit` en `app/`"), `integration/ControlFlujoTest.php` (sin log ni vista 500 para 404/405/419/401/redirect) |
| R-3 `Database` solo por `Config` (D-58) | `unit/DatabaseTest.php`, `integration/ControlFlujoTest.php` (`bootstrap-cli.php`) |

**Fuera de la verificación automática** (D-51): la versión mínima PHP 8.2 y MySQL 8.0 (el desarrollo usa PHP 8.5 y MariaDB 11.8). Procedimiento manual con contenedores en `tests/README.md`, sección "Corrida completa: run-all.sh".

## Pruebas manuales

**Revisión visual** (escritorio y 390 px de ancho), aprobada por Juan (D-44). Capturas en [`evidencias/capturas/`](evidencias/capturas/):

| Pantalla | Escritorio | Móvil |
|---|---|---|
| Formulario público | [01](evidencias/capturas/desktop-01-formulario.png) | [01](evidencias/capturas/mobile-01-formulario.png) |
| Formulario con errores (422) | [02](evidencias/capturas/desktop-02-formulario-422.png) | [02](evidencias/capturas/mobile-02-formulario-422.png) |
| Confirmación `/gracias` | [03](evidencias/capturas/desktop-03-gracias.png) | [03](evidencias/capturas/mobile-03-gracias.png) |
| Login | [04](evidencias/capturas/desktop-04-login.png) | [04](evidencias/capturas/mobile-04-login.png) |
| Login con error (401) | [05](evidencias/capturas/desktop-05-login-error.png) | [05](evidencias/capturas/mobile-05-login-error.png) |
| Login bloqueado (429) | [06](evidencias/capturas/desktop-06-login-429.png) | [06](evidencias/capturas/mobile-06-login-429.png) |
| Listado | [07](evidencias/capturas/desktop-07-listado.png) | [07](evidencias/capturas/mobile-07-listado.png) |
| Listado filtrado | [08](evidencias/capturas/desktop-08-listado-filtrado.png) | [08](evidencias/capturas/mobile-08-listado-filtrado.png) |
| Detalle con flash | [09](evidencias/capturas/desktop-09-detalle-flash.png) | [09](evidencias/capturas/mobile-09-detalle-flash.png) |
| Página 404 | [10](evidencias/capturas/desktop-10-404.png) | [10](evidencias/capturas/mobile-10-404.png) |
| Búsqueda con paginación | [11](evidencias/capturas/desktop-11-listado-busqueda-paginacion.png) | [11](evidencias/capturas/mobile-11-listado-busqueda-paginacion.png) |
| Filtro + búsqueda, página 2 | [12](evidencias/capturas/desktop-12-listado-filtro-busqueda-pagina2.png) | [12](evidencias/capturas/mobile-12-listado-filtro-busqueda-pagina2.png) |
| Detalle con historial | [13](evidencias/capturas/desktop-13-detalle-historial.png) | [13](evidencias/capturas/mobile-13-detalle-historial.png) |
| Búsqueda sin resultados | [14](evidencias/capturas/desktop-14-listado-sin-resultados.png) | [14](evidencias/capturas/mobile-14-listado-sin-resultados.png) |
| Detalle con email largo | [15](evidencias/capturas/desktop-15-detalle-email-largo.png) | [15](evidencias/capturas/mobile-15-detalle-email-largo.png) |

**Verificado a mano** (curl y scripts PHP que cargan la app; no automatizable desde el smoke sin acceso a la DB o a las sesiones):

- [x] **Límite por IP (L.5, D-38)**: 20 fallos con 20 emails distintos desde una IP → 401 cada uno; el 21.º (otro email) → 429, y el admin con password correcta desde esa IP → 429; desde otra IP el admin entra. Los intentos bloqueados no se registran. Procedimiento con IPs de loopback en `tests/README.md` (A2-05).
- [x] **Admin desactivado** con sesión viva: GET → 302 a login, POST → 401; login correcto → 401 genérico; al reactivarlo la sesión vieja no recupera el acceso (A2-04).
- [x] **Fijación de sesión**: una cookie inventada se reemplaza (use_strict_mode); la de invitado deja de valer al loguear y la autenticada al salir (A2-04).
- [x] **Sin caché** en respuestas con sesión (`Cache-Control: no-store`): "Atrás" tras el logout no muestra datos (A2-04).
- [x] **Timeout de inactividad (D-21)**: automatizado en `unit/SessionTimeoutTest.php` (M-8). Queda el procedimiento opcional de punta a punta por HTTP en `tests/README.md`.

La aplicación no usa JavaScript: no aplica la prueba con JS deshabilitado.

## Historial de corridas

Corridas previas, durante el desarrollo. **No son la evidencia vigente** (ver "Corrida oficial vigente" arriba); quedan como registro del proceso.

| Momento | Estado del código | Resultado | Tipo | Evidencia |
|---|---|---|---|---|
| 13:45 | Fase 1 + formulario; panel sin implementar | smoke 64: 19 PASS, 12 FAIL, 33 SKIP (rutas admin inexistentes) | exploratoria | — |
| 13:59 | A1-03 (auth + panel) | smoke 67/67 | oficial de entonces (suite vieja) | [`evidencias/historial/smoke-20261019-135950.txt`](evidencias/historial/smoke-20261019-135950.txt) |
| A2-04 | 7bb711d | smoke 68: 67 PASS, 1 FAIL (H.16, hallazgo después corregido en c710de5) | revisión | — |
| A2-05 | c710de5 + límite de login sin commitear | smoke 72/72 | revisión | — |
| A2-06, 14:21 | ídem + A1-07 en curso | smoke 73/74 | **inválida**: colisión con otra sesión en la DB (D-42) | — |
| A2-07 | A1-09 sin commitear | unitarios 99/99; contra el commit previo sin A1-09: 56 PASS, 39 FAIL (los tests distinguen) | revisión | — |
| A2-08, 15:05 | f09af47 + A1-11 sin commitear | run-all: todo OK (99 / 11 / 84) | revisión, árbol en curso | [`evidencias/historial/run-all-20261019-150507-verde.txt`](evidencias/historial/run-all-20261019-150507-verde.txt) |
| A2-08, 15:06 | ídem + 2 tests de hallazgos | run-all corta en unitarios: 99 PASS, 2 FAIL (esperado hasta el fix de a34a0d8) | revisión, falla intencional | [`evidencias/historial/run-all-20261019-150635-con-hallazgos.txt`](evidencias/historial/run-all-20261019-150635-con-hallazgos.txt) |
| 15:12 | a34a0d8 (fixes de A2-08) | run-all: todo OK (101 / 11 / 84) | oficial de entonces | [`evidencias/historial/run-all-20261019-151247.txt`](evidencias/historial/run-all-20261019-151247.txt), [`evidencias/historial/smoke-20261019-151255.txt`](evidencias/historial/smoke-20261019-151255.txt) |
