# Tests (`tests/`)

Tres capas: **unitarios** (`tests/unit/`, puros, sin DB ni red), **integración** (`tests/integration/`, contra la DB y la app en marcha) y **smoke HTTP** (`tests/smoke.sh`, las pruebas mínimas de la consigna). Con las correcciones de la auditoría final (A2-14): **151 unitarios, 31 de integración y 84 casos smoke** (la corrida oficial vigente, en `doc/pruebas/PRUEBAS.md`).md`). La forma oficial de correr todo es **`bash tests/run-all.sh`** (sección "Corrida completa: run-all.sh" al final); no hay CI en GitHub (D-51).

# Smoke HTTP (`tests/smoke.sh`)

## Qué es

Suite smoke por HTTP, escrita en bash y curl, sin dependencias adicionales. Ejecuta contra la app en marcha los casos mínimos de la consigna (P1 a P7) y las verificaciones de códigos HTTP y seguridad definidas en `doc/PLAN.md`.

Las verificaciones de persistencia se hacen por HTTP, a través del panel admin (listado y detalle). El script no accede nunca a la base de datos directamente.

## Requisitos y ejecución

- `curl` instalado.
- La app levantada desde la raíz del repo, con la base instalada y el seed cargado:

  ```bash
  php -S localhost:8080 -t public_html public_html/index.php
  ```

- Ejecución:

  ```bash
  tests/smoke.sh                              # contra http://localhost:8080
  tests/smoke.sh --base-url http://host:8080  # URL explícita
  BASE_URL=http://host:8080 tests/smoke.sh    # idem, por variable
  tests/smoke.sh --evidencia                  # guarda la salida en doc/pruebas/evidencias/smoke-AAAAMMDD-HHMMSS.txt
  tests/smoke.sh -h
  ```

- Variables de entorno sobreescribibles: `BASE_URL`, `CSRF_FIELD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`. Por defecto, el admin demo del seed (`admin@example.com` y su contraseña demo).
- Cookies y respuestas temporales se guardan en `tests/.tmp/`. Está ignorado por git y se recrea en cada corrida.
- Cada corrida crea filas nuevas marcadas con `smoke-<timestamp>-<pid>` en asuntos y textos. Ejecutar contra una base de desarrollo, nunca contra producción.

## Salida y códigos de salida

Cada caso imprime una línea con el formato:

```text
PASS  <ID>  <descripción>  [obtenido: <valor>]
FAIL  <ID>  <descripción>  [obtenido: <valor> | esperado: <valor>]
SKIP  <ID>  (<motivo>)
```

- `SKIP`: el caso no pudo ejecutarse porque falló un prerequisito (por ejemplo, el login de admin). No cuenta como PASS.
- `0`: todos los casos PASS.
- `1`: al menos un FAIL o SKIP.
- `2`: el servidor no responde a `GET /` (timeout de 3 s), falta `curl` o se pasó una opción inválida (incluido `--base-url` sin valor).

## Mapeo de casos

Columna "Prueba mínima": P1 a P7 corresponden a la consigna (P1 solicitud válida, P2 campos inválidos o incompletos, P3 acceso al panel sin sesión, P4 credenciales incorrectas, P5 cambio de estado autorizado, P6 cambio de estado no autorizado, P7 HTML/SQL malicioso). "extra" indica casos adicionales: H (códigos HTTP del PLAN), S (seguridad de sesión y headers), F5 (orden del listado) y F6 (filtro por estado).

| ID de caso | Qué verifica | Prueba mínima |
|---|---|---|
| S.1 | X-Frame-Options | extra · S |
| S.2 | X-Content-Type-Options | extra · S |
| S.3 | Content-Security-Policy con default-src 'self' | extra · S |
| P1.1 | GET / → 200 con form y token CSRF | P1 |
| P3.1 | GET /admin sin sesión → 302 a /admin/login | P3 |
| P3.2 | GET /admin/solicitudes/1 sin sesión → 302 a /admin/login | P3 |
| P3.3 | GET /admin?estado=pendiente sin sesión → 302 a /admin/login | P3 |
| P3.4 | POST /admin/logout sin sesión → 401 (D-17) | P3 |
| P4.1 | login con password incorrecta → 401 + mensaje genérico | P4 |
| P4.2 | login con email inexistente → 401 + mismo mensaje (anti-enumeración) | P4 |
| P4.3 | GET /admin tras login fallido → 302 a /admin/login | P4 |
| H.1 | login con credenciales correctas pero sin CSRF → 419 | extra · H |
| H.2 | GET /admin tras login sin CSRF → sigue sin acceso | extra · H |
| H.16 | login con password correcta + NUL (%00) + sufijo → 401 y sin acceso (bcrypt corta en el NUL; A2-04) | extra · H |
| P5.1 | login correcto → 302 a /admin (prerrequisito) | P5 |
| P5.2 | GET /admin con sesión → 200 (prerrequisito) | P5 |
| S.4 | el ID de sesión cambia al loguear (session_regenerate_id) | extra · S |
| S.5 | Set-Cookie de sesión con HttpOnly y SameSite=Lax | extra · S |
| P1.2 | POST /solicitudes válida → 302 a /gracias | P1 |
| P1.3 | GET /gracias → 200 con confirmación visible | P1 |
| P1.4 | GET /gracias otra vez (flash consumido) → 302 a / | P1 |
| P2.1 | POST con campos vacíos → 422 + mensajes de obligatorio | P2 |
| P2.2 | POST con email inválido → 422 + mensaje de email | P2 |
| P2.3 | POST con campos solo espacios → 422 (trim antes de validar) | P2 |
| P2.5 | POST con nombre de solo espacio Unicode (NBSP) → 422 | P2 |
| H.14 | POST con UTF-8 inválido en nombre → 422 sin fugas, no 500 | extra · H |
| H.3 | POST /solicitudes sin token CSRF → 419 | extra · H |
| H.4 | POST /solicitudes con token CSRF falso → 419 | extra · H |
| H.5 | POST /solicitudes con token de otra sesión (sin cookie) → 419 | extra · H |
| P1.5 | la solicitud válida aparece en el listado del panel | P1 |
| P2.4 | ninguna alta por inválidos/CSRF (marcas ausentes, única fila nueva = la válida) | P2 |
| P1.6 | detalle persistido (asunto, email, descripción) | P1 |
| P5.3 | estado inicial = pendiente | P5 |
| P5.4 | POST estado=en_proceso con sesión + CSRF → 302 al detalle (D-19) | P5 |
| P5.5 | el detalle muestra el nuevo estado y el flash | P5 |
| P5.6 | el flash no se repite al recargar | P5 |
| F6.1 | filtro ?estado=en_proceso incluye la solicitud | extra · F6 |
| F6.2 | filtro ?estado=pendiente no la incluye | extra · F6 |
| F6.3 | filtro ?estado= vacío → 200 con todas (D-18) | extra · F6 |
| F6.4 | filtro ?estado=cerrada (fuera de lista) → 400 (D-18) | extra · F6 |
| S.6 | GET /admin/login con sesión → 302 a /admin | extra · S |
| P6.1 | sin sesión (con CSRF válido de invitado) → 401 (D-17); sin cambio | P6 |
| P6.2 | con sesión, sin CSRF → 419; sin cambio | P6 |
| P6.3 | con sesión, token CSRF de otra sesión → 419; sin cambio | P6 |
| P6.4 | estado fuera de lista blanca (cerrada) → 422; sin cambio | P6 |
| P6.5 | estado con inyección SQL → 422; sin cambio | P6 |
| P6.6 | sin campo estado → 422; sin cambio | P6 |
| P6.7 | sin sesión y sin token → 401: auth antes que CSRF (D-16) | P6 |
| P6.8 | estado como array (estado[]=resuelta) → 422, no 500 | P6 |
| P7.1 | POST con `<script>` y `' OR '1'='1' -- ` en campos → 302 a /gracias (se acepta como texto) | P7 |
| P7.2 | listado: `<script>` escapado (`&lt;script&gt;`) y nunca crudo | P7 |
| F5.1 | listado por fecha descendente: la solicitud más nueva aparece primero (D-20) | extra · F5 |
| P7.3 | detalle: `<script>` e `<img onerror>` escapados, nunca crudos | P7 |
| P7.4 | detalle: payload SQL guardado literal y escapado | P7 |
| P7.5 | 422 re-renderiza valores previos escapados (sin XSS reflejado) | P7 |
| P7.6 | filtro ?estado=' OR '1'='1 → 400 sin error SQL (D-18) | P7 |
| P7.7 | id con inyección en la ruta → 404 | P7 |
| H.6 | GET /admin/solicitudes/{id}/estado → 405 + Allow con POST | extra · H |
| H.7 | GET detalle de id inexistente → 404 sin fugas | extra · H |
| H.8 | GET /admin/solicitudes/abc → 404 | extra · H |
| H.9 | POST estado sobre id inexistente → 404 | extra · H |
| H.10 | GET /solicitudes → 405 + Allow con POST | extra · H |
| H.11 | ruta inexistente → 404 sin fugas | extra · H |
| H.15 | GET /.htaccess no se sirve (ni con php -S) → 403/404 | extra · H |
| H.12 | POST /solicitudes con nombre[] (array) → 422 sin fugas, no 500 | extra · H |
| H.13 | filtro ?estado[]=pendiente (array) → 400 sin fugas, no 500 | extra · H |
| S.7 | POST /admin/logout sin CSRF → 419 y la sesión sigue | extra · S |
| S.8 | POST /admin/logout con CSRF → 302; después /admin → login | extra · S |
| L.1 | 5 logins fallidos con un email inexistente de la corrida → 401 c/u; el 6.º → 429 + `Retry-After` entero en [1, 900] + "Demasiados intentos" | extra · L (D-38) |
| L.4 | 3 POST de login sin CSRF (sin campo, token falso, sin campo) intercalados en la secuencia de L.1 → 419 y no cuentan: el 5.º fallo válido sigue en 401 | extra · L (D-38) |
| L.2 | con la cuenta de L.1 bloqueada, el admin demo desde la misma IP entra, ve /admin y sale (límite por cuenta, no global) | extra · L (D-38) |
| L.7 | email del admin en mayúsculas (`ADMIN@EXAMPLE.COM`) + password correcta → 302, /admin 200, logout 302 (la normalización a minúsculas sigue vigente con D-41) | extra · L (D-41) |
| L.3 | admin demo: 5 fallos → 401; el 6.º con la password **correcta** → 429 + Retry-After + mensaje; /admin sigue en 302 a login | extra · L (D-38) |
| L.6 | con el admin bloqueado (después de L.3): `ádmin@example.com` y `ÁDMIN@example.com` + password correcta → 401 o 429, nunca 302; /admin sigue en 302 a login (bypass por acentos, D-41) | extra · L (D-41) |
| B.1 | búsqueda `q=pag-<marca>` → 200, "21 resultados para «…»", 20 filas en la página 1 (filas creadas por la corrida) | extra · B (M-3) |
| B.2 | `q` con `%` y `_` literales: encuentra `escA-<marca>-a%b_c` y no `escB-<marca>-aZZbYc` (comodines escapados) | extra · B (M-3) |
| B.3 | `q=<script>alert('b3')</script>` reflejado escapado en el estado de búsqueda y en el `value` del input; nunca crudo | extra · B (M-3) |
| B.4 | `q` inválido → 400 sin fugas: 101 caracteres, UTF-8 inválido, control, salto de línea, array | extra · B (M-3) |
| B.4b | `q` de exactamente 100 caracteres → 200 | extra · B (M-3) |
| B.5 | `pagina` 0, abc, 01, -1, 1.5, espacio, 1e2 y array → 400 | extra · B (M-3) |
| B.6 | `pagina=2` → 1 fila y "Página 2 de 2"; `pagina=3` y `999999999` → 404; sin resultados con `pagina=3` → 200 "No hay solicitudes que coincidan." | extra · B (M-3) |
| B.7 | con `estado` y `q`: "Siguiente" → `?estado=…&amp;q=…&amp;pagina=2`; los filtros conservan `q` | extra · B (M-3) |
| B.8 | detalle de la solicitud de P5: "Historial de estados" con 1 evento pendiente → en proceso; POST al mismo estado → 302 + flash y sigue 1 evento (no-op) | extra · B (M-2) |
| B.9 | 404, 405, estático e id inválido sin `Set-Cookie`; `GET /` sí crea sesión | extra · B (M-5) |

## Contrato asumido

Al inicio de `tests/smoke.sh` (bloque "Contrato asumido") se fijan los nombres de campos y las expresiones regulares de los mensajes. Si la implementación difiere, se ajustan ahí y no en los casos.

| Variable | Valor por defecto | Qué ajusta |
|---|---|---|
| `CSRF_FIELD` | `csrf_token` | nombre del input hidden del token |
| `F_NOMBRE`, `F_EMAIL`, `F_ASUNTO`, `F_DESC` | `nombre`, `email`, `asunto`, `descripcion` | campos del formulario público |
| `F_ESTADO` | `estado` | campo del form de cambio de estado |
| `F_LOGIN_EMAIL`, `F_LOGIN_PASS` | `email`, `password` | campos del login |
| `RE_CONFIRMACION` | `recibi\|registrad\|gracias` | texto de la página /gracias |
| `RE_ERR_OBLIGATORIO` | `obligatori\|requerid` | mensaje de campo obligatorio |
| `RE_ERR_EMAIL` | email o correo seguido de "válido" (en ambos órdenes) | mensaje de email inválido |
| `RE_LOGIN_ERROR` | `Email o contraseña incorrectos` | mensaje genérico de login (`.{1,2}` tolera la ñ en cualquier codificación) |
| `RE_FLASH_ESTADO` | `Estado actualizado` | flash tras un cambio de estado (P5.5, P5.6) |
| `RE_FUGA` | `SQLSTATE`, `PDOException`, `Stack trace`, `Fatal error`, `on line N`, error de SQL de MySQL | detección de fugas de error (H.7, H.11, H.12, H.13, H.14, P7.6) |
| `ID_INEXISTENTE` | `999999999` | id usado en H.7 y H.9 |

Supuestos estructurales sobre el HTML:

- **Listado `/admin`**: cada fila enlaza a `/admin/solicitudes/{id}` y muestra el asunto dentro del mismo `<tr>`, `<li>` o `<article>`. La función `id_por_marca` parte el HTML por esas etiquetas. Si el enlace y el asunto quedan en elementos distintos, fallan P1.5, P1.6 y P7.2 a P7.4.
- **Orden del listado**: lo más nuevo primero (verificado por F5.1), o sin paginar, para que la fila recién creada aparezca en la primera página.
- **Detalle**: form de cambio de estado con `<select name="estado">`, donde la `<option>` del estado actual lleva el atributo `selected`. El script toma el primer `<option ... selected>` cuyo value sea minúsculas con guión bajo en toda la página, así que ningún otro select anterior debe tener `selected`.
- **Token CSRF**: `<input type="hidden" name="csrf_token" value="...">`. El script toma el `value` del primer input con ese nombre.
- **P7.4**: el payload SQL escapado debe aparecer como `&#039;`, `&#39;` o `&apos;` (regex `RE_SQL_ESC`, definida en la sección P7 del script).

## Base de datos reinstalable

Cada corrida crea filas nuevas en la base. Ejecutar el smoke solo contra una base de desarrollo que se pueda reinstalar, nunca contra producción:

```bash
php scripts/instalar-db.php   # borra y recrea las tablas, y carga el seed
```

- La sección B crea 23 solicitudes más por el formulario público (21 `pag-<marca>` para tener dos páginas y 2 para el escape de comodines); no hace logins fallidos, así que no cambia el presupuesto por IP.
- Por corrida se crean dos solicitudes válidas: la normal (asunto `Valida <marca>`, que P5 pasa a `en_proceso`) y la de payloads HTML/SQL (P7.1).
- Los casos inválidos no crean filas: P2.1 a P2.3, H.3 a H.5, P7.5 y H.12. Tampoco los intentos de login ni de cambio de estado rechazados (P4.x, P6.x).
- El seed trae sus propias solicitudes, entre ellas una con `<script>`. Por eso P7.2 busca la fila de esta corrida por su marca y no la primera que contenga el payload.
- La contraseña demo del admin del seed es la que usa `ADMIN_PASSWORD` por defecto (ver "Requisitos y ejecución").
- **El smoke exige una DB recién reinstalada** (D-38): los intentos fallidos persisten 15 min en `login_intentos` y L.3 deja al admin demo bloqueado desde la IP de la corrida. Una segunda corrida sin reinstalar falla desde P5.1 (login → 429). Si el primer intento de L.1 ya da 429, el script avisa.
- Presupuesto del límite por IP (20 fallos / 15 min): una corrida registra hasta 15 fallos válidos desde la misma IP (P4.1, P4.2, H.16, 5 de L.1, 5 de L.3 y hasta 2 de L.6 si responden 401); P5.1, L.2 y L.7 además borran los del admin, y los 429 no se registran (PLAN §Extra 1, "Precisiones"). Margen: 5. Agregar casos con logins fallidos exige rehacer esta cuenta.

## Prueba manual del límite por IP (L.5, D-38)

No se automatiza: necesita 21 fallos desde una IP, lo que agota el presupuesto de la IP de la corrida (bloquearía el login del admin para el resto del smoke y para la corrida siguiente), y la app no confía en `X-Forwarded-For`, así que no hay forma de simular otra IP contra el `php -S` de `localhost` (escucha solo en `[::1]`). Procedimiento con un servidor propio en IPv4, usando otras direcciones de loopback como IP de origen:

```bash
php -S 127.0.0.1:8099 -t public_html public_html/index.php   # en otra terminal
# 20 fallos con 20 emails distintos desde 127.0.0.11 → 401 c/u; el 21.º (email nuevo) → 429,
# y también el admin con password correcta desde 127.0.0.11 → 429;
# el admin desde 127.0.0.12 → 302 (el bloqueo es solo para esa IP).
curl --interface 127.0.0.11 ...   # mismo flujo que el smoke: GET /admin/login, token, POST
```

Al terminar, reinstalar la DB.

## Prueba manual del timeout de sesión (D-21)

El timeout de inactividad no se automatiza: el default es 1800 s. Desde M-8 está automatizado en `tests/unit/SessionTimeoutTest.php` (ver abajo); este procedimiento queda para verificarlo de punta a punta por HTTP.

Procedimiento:

1. En la config local (`app/config/config.local.php`, clave `app.session_timeout`) bajar el valor a, por ejemplo, `60`.
2. Reiniciar el servidor si hace falta.
3. Loguearse en `/admin`.
4. Esperar más de 60 s sin actividad.
5. Recargar `/admin`: debe redirigir (302) a `/admin/login`.
6. Enviar el form de cambio de estado cargado antes del vencimiento: debe responder 401 y el estado no debe cambiar.
7. Restaurar `app.session_timeout` al valor original (1800) al terminar.

La config local la edita la persona que prueba; el script no la lee ni la modifica.

Alternativa sin tocar la config (usada en A2-04, solo en desarrollo con `php -S`): la sesión se
guarda en `/tmp/sess_<id>` (save_path por defecto). Tras loguearse, reescribir `_last_activity`
de ese archivo a `ahora - 1801` (y `ahora - 1700` como control): `GET /admin` → 302 a
`/admin/login` con ID nuevo y el archivo viejo borrado; un `POST` de estado con el token previo
→ 401 sin cambio. Con el control (1700 s) → 200.

## Tests unitarios e integración (M-7, M-8, D-48)

Runner propio sin dependencias (`tests/lib/runner.php`, sin Composer por ADR-0001). Descubre los `*Test.php` del directorio; cada caso es `test('nombre', fn () => …)` con aserciones mínimas (`assertSame`, `assertTrue`, `assertFalse`, `assertNull`, `assertNotSame`, `assertContains`, `assertThrows`). Salida por caso: `PASS`, `FAIL` (con el mensaje), `PEND` (la unidad del contrato todavía no existe: se implementa en una tarea posterior) o `SKIP` (falta DB o servidor). Exit code 0 sin FAIL, 1 con alguno, 2 si no hay tests. Con `SIN_SKIP=1` (así los corre `run-all.sh`) un SKIP o un PENDIENTE cuentan como FAIL (AU-6, Q-08).

```bash
php tests/unit/run.php                 # todos los unitarios
php tests/unit/run.php Router          # filtro por archivo o nombre de caso
BASE_URL=http://localhost:8080 php tests/integration/run.php
```

- **Unitarios**: no necesitan config ni DB (no cargan `config.local.php`; lo que una unidad necesita se le pasa armado). Lo que termina con `exit` (`Response::redirect`/`abort`, `Router::dispatch`) o abre una sesión corre en un **proceso PHP hijo** (`tests/lib/child.php`), que informa el código HTTP, el estado de la sesión y lo que el test devuelva. `Router::match`/`allowHeader` (privados y puros) se prueban por Reflection. El header `Allow` no es observable en CLI (`headers_list()` vacío): se verifica su composición en `allowHeader` y el 405 en el dispatch.
- **Proceso hijo**: lo usan solo los casos que lo necesitan: sesión activa dentro de la acción (`RouterTest`, `session_start()` exige un proceso sin salida previa), `Kernel::handle` (`send()` emite headers y salida), `Database` sin configurar (estado estático) y la config de prueba (`ConfigTest`, `InstalarDbTest`: copia de la app con un `config.local.php` de prueba vía `tests/lib/arbol.php`; el real se excluye por nombre y la DB apunta a `127.0.0.1:1`, donde no escucha nada). El matching puro y los 404/405/redirect corren en proceso. Si un test termina el proceso con `exit`, el runner lo detecta y sale con 1 (`ABORTADO: …`). `tests/lib/contrato.php` tiene los helpers de R-1/R-2 (`rmMatch`, `controlDe`, `terminosFt`).
- **Integración**: usan la DB de la app a través de `App\Core\Database` (configurada por `app/bootstrap-cli.php` desde R-3, que `tests/integration/run.php` carga antes que el runner; si falta, SKIP explicado; el test no lee ni imprime credenciales) y HTTP contra `BASE_URL` con ext-curl. Requieren **turno exclusivo** de DB y servidor (D-42). Limpian sus filas: no gastan el presupuesto por IP del smoke. Para que la ráfaga de M-1 pruebe la carrera de verdad, el servidor tiene que atender en paralelo: `PHP_CLI_SERVER_WORKERS=8 php -S …` (con un solo worker el caso pasa pero solo verifica el conteo).

| Archivo | Unidad | Qué verifica |
|---|---|---|
| `unit/ValidatorTest.php` (19) | `Validator` | UTF-8 inválido, recorte Unicode (NBSP, ZWSP, BOM, U+3000), controles, CR/LF y TAB en una línea (D-50), multiline, `max` en caracteres, email, regla desconocida; recorte lineal con `pcre.jit=0` (regresión del hallazgo de A2-08) |
| `unit/EstadoTest.php` (7) | `Estado` | `fromInput` (válidos, null/vacío, trim, fuera de lista), coincidencia con el ENUM de `schema.sql`; los tres ENUM de `schema.sql` (`solicitudes.estado`, `solicitud_eventos.estado_anterior`/`estado_nuevo`) = `Estado::cases()` (AU-7) |
| `unit/RouterTest.php` (4) | `Router::dispatch` (M-5) | en hijo: `{id}` llega como int, HEAD con la ruta GET, la acción corre con sesión activa en GET y POST |
| `unit/CsrfTest.php` (8) | `Csrf` | `verify` con null, vacío, otro token, prefijo/sufijo, guardado no-string, array (TypeError); `rotate`; formato de `token()` |
| `unit/ResponseTest.php` (1) | `Response::redirect` | 15 destinos rechazados (esquema, `//`, `/\`, CR/LF, NUL, TAB, DEL); local → 302 y termina |
| `unit/AdminTest.php` (10) | `Admin` | `normalizeEmail` (multibyte, idempotente, sin quitar acentos — D-41), `passwordAcceptable` (72 bytes, NUL), bcrypt costo 12 fijo y hash ficticio igual (D-35, D-37) |
| `unit/RequestIpTest.php` (24) | `Request::ip` (M-1) | sin proxies, proxy confiable, XFF falsificado, derecha a izquierda, cadena, CIDR v4/v6, huecos (a)–(d) de D-49 |
| `unit/LogTest.php` (7) | `Log` (M-6) | "supera" estricto, rotación antes de escribir, corrimiento y descarte, tope de archivos, 2 procesos concurrentes sin líneas perdidas/mezcladas; una rotación que falla no hace lanzar a `write` (regresión del hallazgo de A2-08) |
| `unit/SessionTimeoutTest.php` (7) | `Session` (**M-8**) | antes/después de vencer (manipulando `_last_activity`), ID nuevo y sesión vieja borrada, ventana deslizante, tiempo real 1 s, `gc_maxlifetime ≥ timeout` |
| `unit/LikeEscapeTest.php` (4) | escape de LIKE (M-3) | `%`, `_`, `\` (la barra primero), multibyte |
| `unit/RouteMatcherTest.php` (17) | `RouteMatcher` (R-2) | 200 con handler/params int/options, HEAD → GET, 404 (`{id}` con 0, ceros, > 10 dígitos, `\n`, `%0A`, `.` literal), 405 con `allow` (+HEAD), puro (sin `$_SERVER` ni salida), `RouteMatch` readonly, tabla real de `app/routes.php` |
| `unit/DespachoTest.php` (10) | despacho sin `exit` (R-2) | `abort()`/`redirect()` lanzan `HttpException` con status, headers y `Location` sin emitir salida; redirect sigue rechazando destinos externos; `dispatch` 404/405 en proceso sin abrir sesión; ningún `exit`/`die` en `app/` (análisis de tokens); redirect solo 3xx y abort solo 4xx/5xx (regresión del hallazgo de A2-11, corregido en d648f11) |
| `unit/DatabaseTest.php` (4) | `Database` (R-3) | sin `loadConfig`; `pdo()` y helpers sin `configure()` → `LogicException`; servidor inexistente → error genérico sin credenciales (cada caso en proceso hijo: estado estático) |
| `unit/FulltextQueryTest.php` (11) | `Solicitud::fulltextQuery` (R-1) | `+palabra*` por palabra de ≥ 3 caracteres Unicode, dígitos, stopwords de InnoDB (`the`, `com`, `with`…), operadores booleanos neutralizados, separadores, `null` → fallback LIKE |
| `unit/KernelTest.php` (5) | `Kernel::handle` (R-2) | `abort`/ruta inexistente → 404 con la vista, redirect → 302 sin cuerpo, una excepción real no se traga, acción normal sin código de error (en hijo: `send()` emite headers y salida) |
| `unit/LoginThrottleTest.php` (3) | `LoginThrottle::configure` (AU-7) | acepta enteros > 0 (también como texto); rechaza 0, negativos, decimales, texto, vacío, null, array y claves faltantes con un mensaje que nombra la clave |
| `unit/ConfigTest.php` (5) | configuración (AU-1, AU-3) | sobre una copia con config de prueba: base válida; `env=production` fuerza `debug=false`; cada valor inválido (`session_timeout` < 60, `por_pagina` 0/101, `log_max_bytes` < 1024, `log_max_archivos` 0/51, `env` desconocido) → excepción que nombra la clave; bordes válidos |
| `unit/InstalarDbTest.php` (5) | `scripts/instalar-db.php` (AU-2) | en producción: sin `--force` y con `--force` sin `--sin-datos` aborta antes de conectar; con `--force --sin-datos` pasa la guardia; local pasa; opción desconocida → uso |
| `integration/EsquemaTest.php` (3) | esquema instalado y hora (AU-7, AU-4) | los tres ENUM de la DB = `Estado::cases()`; `NOW()` de la DB = `date()` de PHP ±2 s; `created_at` de una fila nueva en hora local ±2 s |
| `integration/LoginAtomicidadTest.php` (3) | límite (M-1, D-46) | 4 fallos + ráfaga de 8 simultáneos → ≤ 1 verificado (401) y filas = previas + verificados ≤ 5; CSRF inválido no reserva; éxito no deja fila |
| `integration/HistorialTransaccionTest.php` (9) | historial (M-2) | alta sin evento, evento con anterior/nuevo/admin, no-op sin evento, 422/404 sin evento, cambios simultáneos encadenados, FK CASCADE/RESTRICT; render real dentro de `<ol class="timeline">`: sin eventos no hay lista, y tras dos cambios + un no-op hay 2 ítems, el último primero, con anterior → nuevo (AU-6, Q-09) |
| `integration/FulltextBusquedaTest.php` (13) | búsqueda híbrida (R-1) | índice `ft_busqueda` FULLTEXT de 4 columnas, `EXPLAIN` con `key=ft_busqueda`, prefijo, varias palabras, subcadena interna sin resultados (D-56), fallback LIKE (`%_`, `a_b`), confirmación de frase, guion bajo dentro de una palabra = LIKE, email completo, contadores = listado (Total y los tres estados, con uno en cero, también con filtro de estado: AU-6, C-2); token de más de 84 caracteres: el híbrido no lo encuentra y el LIKE sí (limitación documentada, D-61); variante acentuada de stopword (`cóm`) = LIKE (regresión del hallazgo de A2-11, corregido en d648f11); token de exactamente 84 |
| `integration/ControlFlujoTest.php` (3) | control de flujo (R-2) y `bootstrap-cli` (R-3) | 404/405/419/401/redirects sin escribir `app.log` ni vista 500; `Allow` en el 405; `bootstrap-cli.php` deja la DB lista sin sesión, salida ni handlers |

## Corrida completa: `run-all.sh` (D-51)

```bash
bash tests/run-all.sh                 # servidor de prueba propio en 127.0.0.1:8090
bash tests/run-all.sh --puerto 8095   # otro puerto (nunca 8080: es el de desarrollo)
bash tests/run-all.sh --evidencia     # se lo pasa al smoke (doc/pruebas/evidencias/)
bash tests/run-all.sh --continuar     # no corta en el primer fallo: corre todas las etapas (exit ≠ 0 si alguna falló)
```

Etapas, en orden, cortando en el primer fallo: (1) `php -l` de `app/`, `public_html/`, `scripts/` y `tests/`; (2) unitarios; (3) `instalar-db`; (4) levanta un `php -S` propio con `PHP_CLI_SERVER_WORKERS=8` (concurrencia real para la ráfaga de M-1), espera a que responda y corre la integración con `SIN_SKIP=1`; (5) reinstala la DB y corre el smoke contra ese mismo servidor. Al salir (también si falla o con Ctrl+C) baja el servidor y su grupo de workers e imprime un resumen por etapa. Exit ≠ 0 si algo falló. No toca el servidor de desarrollo de `:8080` ni lee `config.local.php` (la DB la usa la app con su propia config). **Requiere turno exclusivo de DB** (D-42): borra y recrea las tablas; al terminar quedan el seed y las filas del smoke.

**Qué no se verifica automáticamente (D-51)**: no hay CI, así que la versión mínima **PHP 8.2** (D-35) y **MySQL 8.0** (el desarrollo usa PHP 8.5 y MariaDB 11.8) no se prueban en cada cambio. Si hiciera falta, a mano con contenedores efímeros (Podman o Docker), por ejemplo:

```bash
podman run -d --name utn-mysql8 -e MYSQL_ROOT_PASSWORD=<efímera> -e MYSQL_DATABASE=utn_solicitudes \
  -e MYSQL_USER=utn_prueba -e MYSQL_PASSWORD=<efímera> -p 3307:3306 mysql:8.0
# config.local.php de una copia del repo apuntando a 127.0.0.1:3307 con esas credenciales efímeras;
# PHP 8.2 con las extensiones pdo_mysql, mbstring y curl (por ejemplo la imagen php:8.2-cli):
podman run --rm --network host -v "$PWD":/app -w /app php:8.2-cli bash tests/run-all.sh --puerto 8096
podman rm -f utn-mysql8
```

(la imagen `php:8.2-cli` necesita `docker-php-ext-install pdo_mysql` y `curl` antes de correr; MySQL 8.0 usa `caching_sha2_password`, que mysqlnd soporta).
