# PLAN — Sistema de gestión de solicitudes

Documento de planificación previo a la implementación: alcance, diseño y validación.
Fuente: consigna "Evaluación Final Integradora — Desafío avanzado" (UTN). Entrega: 2026-10-19.

## 1. Alcance

### Obligatorio (consigna §2–§4)

| ID | Requisito | Ruta / componente |
|----|-----------|-------------------|
| F1 | Formulario público: nombre, email, asunto, descripción | `GET /` |
| F2 | Validación server-side de obligatorios y formato de email | `POST /solicitudes` → `Validator` |
| F3 | Confirmación visible tras registrar (patrón PRG) | `GET /gracias` |
| F4 | Login / logout de administrador | `GET,POST /admin/login`, `POST /admin/logout` |
| F5 | Listado de solicitudes | `GET /admin` |
| F6 | Filtro por estado (pendiente, en proceso, resuelta) | `GET /admin?estado=…` |
| F7 | Detalle de solicitud | `GET /admin/solicitudes/{id}` |
| F8 | Cambio de estado | `POST /admin/solicitudes/{id}/estado` |
| F9 | Bloqueo de páginas y operaciones admin sin sesión | `Auth::requireAdmin()` en todo `/admin/*` salvo login |
| D1 | Tablas `admins` y `solicitudes` | `database/schema.sql` |
| D2 | Script SQL reproducible + datos de prueba no sensibles | `database/schema.sql`, `database/seed.sql` |

### Extra 1 implementado — límite de intentos de login (contrato, D-38)

- Tabla `login_intentos` (`id`, `ip VARCHAR(45)`, `email_hash CHAR(64)` = SHA-256 del email
  normalizado en minúsculas y recortado — no se guarda el email en claro, `created_at`;
  índices `(ip, email_hash, created_at)` y `(ip, created_at)`).
- Solo se registran **intentos fallidos**. Ventana: 15 min. Límites configurables en
  `app.login_max_por_cuenta` (5, por IP + email) y `app.login_max_por_ip` (20, por IP sola,
  frena el "password spraying" sobre muchos emails).
- Si se alcanzó un límite, el intento **no se verifica** (ni siquiera con la contraseña
  correcta): **429** + header `Retry-After` (segundos hasta que vence el intento más viejo de
  la ventana) + re-render del login con "Demasiados intentos. Probá de nuevo en N minutos.".
  El bloqueo aplica igual exista o no el email (no permite enumerar).
- Login exitoso borra los fallos de esa IP + email. Los registros de más de 24 h se purgan
  al registrar un fallo.
- IP = `REMOTE_ADDR` (no se confía en `X-Forwarded-For`).
- Precisiones (A2-05, D-41): los intentos bloqueados (429) **no** se registran; con ambos
  límites alcanzados, `Retry-After` = el mayor; "N minutos" = `ceil(seg/60)`, mínimo 1;
  normalización del email = `mb_strtolower(trim())`, y la búsqueda del admin en el login
  compara en forma **binaria** contra ese valor normalizado (las variantes con acento o con
  otros caracteres equivalentes por collation no autentican ni evitan el límite).
- El orden de controles no cambia: ruta → CSRF → límite → credenciales (el login no tiene
  `auth`); un POST sin CSRF válido da 419 y no cuenta como intento.

### Fuera de alcance (consigna §8) → "mejoras pendientes" del resumen técnico

Notificaciones por correo, múltiples roles, integraciones externas, deploy a producción,
diseño visual complejo.

### Extras (solo después de tener F1–F9 probados, en este orden)

1. ~~Límite de intentos de login~~ → implementado, ver contrato arriba (D-38).
2. Historial de cambios de estado (tabla `solicitud_eventos`: quién, cuándo, de→a).
3. Paginación del listado y búsqueda por texto.

## 2. Diseño

### Arquitectura: MVC ligero sin framework, front controller + tabla de rutas

Mismo patrón que los sitios de producción del estudio (tuportafolio.com.ar, intea.com.ar):
`public_html/` es el único DocumentRoot y contiene solo el front controller y los assets;
toda la lógica vive en `app/`, fuera del alcance HTTP. Ver ADR-0001.

```
app/
├── helpers.php              # e(): escape HTML único
├── bootstrap.php            # config, manejo de errores, headers de seguridad, sesión, autoload
├── routes.php               # tabla [método, patrón] → [Controller, acción, middleware]
├── config/
│   ├── config.php           # mezcla defaults seguros + config.local.php
│   └── config.local.example.php
├── Core/                    # infraestructura reutilizable (namespace App\Core)
│   ├── Database.php         # PDO único, ERRMODE_EXCEPTION, EMULATE_PREPARES=false
│   ├── Router.php           # resuelve ruta, 404/405 con header Allow
│   ├── Request.php          # acceso tipado a GET/POST/método/IP
│   ├── Response.php         # redirect, status, abort(código) con vista de error
│   ├── Session.php          # cookie endurecida, regenerate, timeout inactividad, flash
│   ├── Csrf.php             # token sincronizador por sesión + hash_equals
│   ├── Auth.php             # login/logout/requireAdmin; dummy hash anti-enumeración
│   ├── Validator.php        # encoding UTF-8, recorte Unicode, controles; reglas required/email/max/multiline
│   └── View.php             # render(vista, datos, layout) + helper e()
├── Controllers/             # namespace App\Controllers — finos, sin SQL
│   ├── SolicitudController.php          # form, store, gracias
│   └── Admin/
│       ├── AuthController.php           # showLogin, login, logout
│       └── SolicitudAdminController.php # index (filtro), show, updateEstado
├── Models/                  # namespace App\Models — único lugar con SQL (preparado)
│   ├── Solicitud.php        # create, all(estado?), find(id), updateEstado
│   ├── Admin.php            # findByEmail, find, create, updatePasswordHash, touchLastLogin
│   └── Estado.php           # enum PHP 8.1 backed: valores + etiquetas (lista blanca)
└── Views/
    ├── layouts/public.php · admin.php
    ├── solicitudes/create.php · gracias.php
    ├── admin/login.php · solicitudes/index.php · solicitudes/show.php
    └── errors/400.php · 401.php · 403.php · 404.php · 405.php · 419.php · 500.php
public_html/
├── index.php                # front controller (y router de php -S para estáticos)
├── .htaccess                # rewrite al front controller + headers de seguridad
└── assets/css/               # base/ · layout/ · components/ · pages/ (D-43, un <link> por hoja según layout)
database/  schema.sql · seed.sql
scripts/   instalar-db.php (schema + seed; guarda en producción) · crear-admin.php (CLI, password oculta)
tests/     smoke.sh (67 casos por HTTP: 7 pruebas mínimas + códigos, sesión, CSRF) · README.md
tools/     registrar-prompt.py (hook de registro de prompts)
storage/logs/                # log de errores de la app (no versionado)
doc/       PLAN.md · RESUMEN-TECNICO.md · adr/ · ia/ · pruebas/
```

Convenciones: PHP ≥ 8.2, `declare(strict_types=1);`, namespaces `App\…` con autoloader
propio de ~10 líneas en `app/autoload.php` (lo requieren `bootstrap.php` y los scripts CLI) (sin Composer: no hay dependencias que lo
justifiquen, ver ADR-0001). Nombres de dominio en español (Solicitud, Estado); infraestructura
en inglés (Router, Request). Sin lógica ni SQL en vistas.

### Rutas

| Método | Ruta | Acción | Protección |
|--------|------|--------|------------|
| GET | `/` | SolicitudController@create | — |
| POST | `/solicitudes` | SolicitudController@store | CSRF |
| GET | `/gracias` | SolicitudController@gracias | flash obligatorio (si no, redirect a `/`) |
| GET | `/admin/login` | AuthController@showLogin | guest (si ya hay sesión → `/admin`) |
| POST | `/admin/login` | AuthController@login | CSRF |
| POST | `/admin/logout` | AuthController@logout | auth + CSRF |
| GET | `/admin` | SolicitudAdminController@index | auth |
| GET | `/admin/solicitudes/{id}` | SolicitudAdminController@show | auth |
| POST | `/admin/solicitudes/{id}/estado` | SolicitudAdminController@updateEstado | auth + CSRF |

### Contrato de formularios (fijado para implementación y pruebas)

| Formulario | Campos (`name`) |
|------------|-----------------|
| Todos los POST | `csrf_token` (hidden) |
| Nueva solicitud | `nombre`, `email`, `asunto`, `descripcion` |
| Login | `email`, `password` |
| Cambio de estado | `estado` (select con los 3 valores de `Estado`) |
| Logout | solo `csrf_token` |

Límites de validación = columnas: nombre ≤100, email ≤254 y formato válido, asunto ≤150,
descripción ≤5000; todos obligatorios tras `trim`. Mensajes de error por campo junto al
input y resumen arriba del formulario (`role="alert"`).

### Modelo de datos (MySQL 8 / MariaDB ≥ 10.6, InnoDB, utf8mb4_unicode_ci)

```sql
admins (
  id INT UNSIGNED PK AI,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
solicitudes (
  id INT UNSIGNED PK AI,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  asunto VARCHAR(150) NOT NULL,
  descripcion TEXT NOT NULL,            -- validado ≤ 5000 caracteres en PHP
  estado ENUM('pendiente','en_proceso','resuelta') NOT NULL DEFAULT 'pendiente',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_estado_fecha (estado, created_at)
)
```

Seed: 1 admin demo (`admin@example.com`, password demo documentada en README, solo hash
en el SQL) + ~8 solicitudes ficticias repartidas en los 3 estados, con dominios
`example.com` (RFC 2606). Ver ADR-0006 (estados) y ADR-0007 (config/secretos).

### Seguridad (requisitos técnicos §3)

| Riesgo | Control |
|--------|---------|
| SQL injection | Solo PDO preparado, `EMULATE_PREPARES=false`; filtro de estado validado contra `Estado` enum antes de llegar al SQL |
| XSS | `e()` = `htmlspecialchars(ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` en TODA salida; CSP restrictiva (`default-src 'self'`) |
| Contraseñas | `password_hash(PASSWORD_DEFAULT)` + `password_verify`; `password_needs_rehash` al loguear; dummy hash si el email no existe (tiempo constante, mensaje genérico) |
| Sesión | `use_strict_mode`, `use_only_cookies`, cookie `HttpOnly` + `SameSite=Lax` (+`Secure` si HTTPS), nombre propio, `session_regenerate_id(true)` en login, timeout por inactividad (30 min), logout destruye sesión y cookie |
| CSRF | Token por sesión (`random_bytes(32)`), `hash_equals`, en todos los POST; falla → 419 |
| Autorización | `requireAdmin()` re-verifica en DB que el admin siga `activo` en cada request admin |
| Errores | `display_errors=0` fuera de debug; excepciones → log en `storage/logs/` + vista 500 genérica; códigos 400/403/404/405/419/422/500 |
| Clickjacking/MIME | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` |
| Secretos | `config.local.php` fuera de Git; `.example` documentado |

### Códigos HTTP

- **Orden de controles por ruta** (D-16): método/ruta (404/405) → **auth** (rutas `auth`) →
  **CSRF** (rutas POST `csrf`) → controller. Una operación admin anónima se rechaza por
  falta de sesión sin depender del token.
- Admin sin sesión: **GET → 302** a `/admin/login`; **POST → 401** con vista de error (no se
  redirige un POST: se perdería la operación y el código no reflejaría el rechazo) (D-17).
- Filtro `?estado=` fuera de la lista blanca: **400** con mensaje; vacío o ausente = todas (D-18).
- Cambio de estado exitoso: **302 al detalle** con flash "Estado actualizado" (D-19).
- Listado ordenado por `created_at DESC, id DESC`, sin paginación (D-20).
- Timeout de inactividad configurable: `app.session_timeout` (segundos, default 1800) (D-21).
- Si se implementa límite de login (extra 1): por IP+email, ≥5 intentos fallidos en 15 min (D-22).
- Validación fallida del formulario público: **422** re-renderizando el form con errores y
  valores previos escapados.
- Credenciales incorrectas: **401** con mensaje genérico ("Email o contraseña incorrectos").
- CSRF inválido: **419**. Estado fuera de lista blanca: **422**. ID inexistente: **404** (también en el POST de estado: `updateEstado()` devuelve false solo si el id no existe, ver ADR-0003).
  Método no permitido: **405** + `Allow`.

## ADRs previstos (`doc/adr/`)

| ADR | Decisión |
|-----|----------|
| 0001 | MVC ligero, front controller, `app/` vs `public_html/` (aceptado) |
| 0002 | Método de IA: orquestador + agentes + registro de prompts (aceptado) |
| 0003 | PDO con consultas preparadas reales |
| 0004 | Autenticación y sesiones (hash, regeneración, timeout, anti-enumeración) |
| 0005 | CSRF: token sincronizador por sesión |
| 0006 | Estados: ENUM en DB + enum PHP como lista blanca |
| 0007 | Configuración local y secretos fuera del repo |
| 0008 | Manejo de errores y códigos HTTP |

## 3. Validación (cómo se verifica cada cosa)

| Prueba mínima (consigna §4) | Verificación |
|-----------------------------|--------------|
| Solicitud válida | `smoke.sh`: POST con token → 302 a `/gracias` + la fila aparece en el panel (verificado por HTTP) |
| Campos inválidos/incompletos | POST vacío y email inválido → 422 + mensajes; sin fila nueva |
| Acceso al panel sin sesión | GET `/admin` y `/admin/solicitudes/1` → 302 a login |
| Credenciales incorrectas | POST login erróneo → 401, sin cookie de sesión autenticada |
| Cambio de estado autorizado | Login + POST estado → 302 + valor persistido en DB |
| Cambio de estado no autorizado | POST sin sesión / sin CSRF / estado inválido → rechazado, DB sin cambios |
| HTML/SQL malicioso | `<script>` y `' OR 1=1 --` en campos → se guardan literal, se muestran escapados, la query no cambia |

Además: `php -l` sobre todo el árbol, revisión visual en navegador (form, panel, errores),
`scripts/local-review.sh` antes del push y revisión adversarial de AGENTE 2.
Evidencia en `doc/pruebas/`.

## 4. Fases

| Fase | Responsable | Entregable |
|------|-------------|------------|
| 0 | Orquestador | Repo, este plan, CLAUDE.md, registro de prompts, DB local |
| 1 | AGENTE 1 | `database/schema.sql`, `seed.sql`, `scripts/instalar-db.php`, `scripts/crear-admin.php`, ADR-0006 |
| 2 | AGENTE 1 | `app/Core/*`, `bootstrap.php`, `config.php`, front controller, `.htaccess`, vistas de error |
| 3 | AGENTE 1 | Formulario público (F1–F3) |
| 4 | AGENTE 1 | Auth admin (F4, F9) |
| 5 | AGENTE 1 | Panel (F5–F8) |
| 6 | AGENTE 2 | Revisión adversarial contra consigna + OWASP → hallazgos |
| 7 | AGENTE 1 | Correcciones de hallazgos aceptados por el orquestador |
| 8 | AGENTE 2 | `tests/smoke.sh`, evidencias, `doc/pruebas/PRUEBAS.md` |
| 9 | AGENTE 1 + orquestador | README, ADRs restantes, RESUMEN-TECNICO, SELECCION-IA |
| 10 | Orquestador | local-review, verificación final, push |

## 5. Fase de mejoras (pedido de Juan, 2026-10-19, D-45)

Cierra las "mejoras pendientes" del resumen técnico. Mismo criterio que las fases
anteriores: contrato acá primero, pruebas escritas contra el contrato en paralelo a la
implementación, revisión adversarial, corrida oficial del orquestador.

### M-1 Límite de intentos exacto y proxies de confianza (ADR-0009 revisado)

- **Atomicidad por "reservar y contar"**: antes de verificar credenciales se INSERTA el
  intento en `login_intentos` y recién después se cuentan los de la ventana (incluido el
  propio). Si el conteo supera el límite → se borra la fila propia (los bloqueados no se
  registran, D-39) y 429. Con autocommit cada INSERT es visible para los conteos
  concurrentes, así que N requests simultáneas no pueden pasar todas: como mucho pasa el
  límite (puede bloquear de más bajo concurrencia, nunca de menos). Éxito → borrar la fila
  propia y limpiar la cuenta. Fallo → la fila queda.
- **Proxies de confianza**: `app.trusted_proxies` (lista de IPs o CIDR, vacía por
  defecto). Si `REMOTE_ADDR` está en la lista, la IP del cliente es la primera dirección de
  `X-Forwarded-For` **leyendo de derecha a izquierda** que no sea un proxy de confianza; si
  el header falta o es inválido, se usa `REMOTE_ADDR`. Sin proxies configurados el header se
  ignora (comportamiento actual). Validación con `filter_var(FILTER_VALIDATE_IP)`; IPv4 e
  IPv6.
- API y bordes (D-49): `Request::configure(array $app)` + `Request::ip()`; todas las
  entradas confiables → la de más a la izquierda; entrada inválida en el recorrido →
  `REMOTE_ADDR`; se normalizan puerto, corchetes e IPv4-mapped; `trusted_proxies` inválido →
  `InvalidArgumentException`. M-5: `Session::configure()` en bootstrap + `Session::start()`
  desde el Router. M-6: `App\Core\Log::configure()/write()`.

### M-2 Historial de cambios de estado

- Tabla `solicitud_eventos` (`id`, `solicitud_id` FK → solicitudes ON DELETE CASCADE,
  `admin_id` FK → admins ON DELETE RESTRICT, `estado_anterior` y `estado_nuevo` con el mismo
  ENUM, `created_at`; índice `(solicitud_id, created_at)`).
- El cambio de estado corre en **transacción**: `SELECT estado … FOR UPDATE`, si es igual al
  nuevo no se registra evento (no-op, sigue el 302 + flash), si cambia → `UPDATE` + `INSERT`
  del evento; commit. Id inexistente → 404 como hoy.
- El detalle muestra el historial (más reciente primero): fecha, admin, de → a. Sin
  historial: "Sin cambios de estado todavía.". El alta no genera evento (el estado inicial
  es `created_at` + `pendiente`).

### M-3 Paginación y búsqueda en el listado

- Query string: `estado` (como hoy), `q` (texto, trim, ≤ 100 caracteres, UTF-8 válido, sin
  controles; vacío = sin búsqueda) y `pagina` (entero ≥ 1, default 1). 20 por página
  (`app.por_pagina`).
- Búsqueda: `LIKE` sobre `asunto`, `nombre`, `email` y `descripcion`, con `%`, `_` y `\`
  del texto escapados (`ESCAPE '\\'`) y parámetro preparado. Combina con `estado`.
- Errores: `q` inválido o `pagina` no entera/< 1 → **400**; `pagina` mayor que la última
  (con al menos un resultado) → **404**; sin resultados → 200 con "No hay solicitudes que
  coincidan.".
- Los links de filtros y de paginación conservan los otros parámetros
  (`http_build_query`, escapado con `e()`); el filtro activo sigue con `aria-current`;
  la paginación es `<nav aria-label="Paginación">` con anterior/siguiente y "Página N de M".
- Orden sin cambios: `created_at DESC, id DESC` (D-20).
- `pagina` acepta hasta 9 dígitos (`^[1-9][0-9]{0,8}$`): más largo, `01` o vacío → 400
  (evita desbordes en el OFFSET; D-53).

### M-4 Contadores con `GROUP BY`

- `Solicitud::countByEstado(?string $q)`: un `SELECT estado, COUNT(*) … GROUP BY estado`
  (con el mismo filtro de búsqueda que el listado, **sin** el filtro de estado), completado
  con ceros para estados sin filas. Total = suma. Reemplaza `all()` + `array_count_values`
  (D-34).

### M-5 Sesión solo donde hace falta

- La sesión ya no se inicia en `bootstrap` para toda request: se inicia **después de que el
  Router encuentra la ruta y el método** (todas las rutas actuales la usan: CSRF, flash o
  auth). 404, 405 y archivos estáticos no crean sesión ni envían `Set-Cookie`. Cierra D-32.

### M-6 Rotación de logs

- `storage/logs/app.log` rota por tamaño: al superar `app.log_max_bytes` (1 MB) se renombra
  a `app.log.1` (corriendo `.1`→`.2`… hasta `app.log_max_archivos` = 5, el más viejo se
  descarta), con `flock` para que dos requests no roten a la vez. Documentar `logrotate`
  como alternativa en un servidor propio.

### M-7 Tests unitarios y CI

- Runner propio sin dependencias (`tests/unit/run.php`, `php tests/unit/run.php`), con
  aserciones mínimas y salida PASS/FAIL + exit code; sin Composer (ADR-0001). Cubre
  unidades puras: `Validator`, `Estado`, `Router` (matching, 404/405, `$D`), `Csrf`,
  `Response::redirect` (destinos rechazados), `Request::ip()` con proxies (M-1),
  `Admin::normalizeEmail`/`passwordAcceptable`, escape de `LIKE` (M-3), rotación (M-6).
- Integración contra DB (`tests/integration/`, mismo runner, requiere DB de prueba):
  atomicidad del límite (M-1) y transacción del historial (M-2).
- **Ejecución local, sin CI en GitHub** (decisión de Juan, D-51): un script
  `tests/run-all.sh` corre en orden `php -l` de todo, unit, `instalar-db`, integración (con
  `php -S` de varios workers para probar la concurrencia de M-1) y smoke sobre DB recién
  reinstalada. La compatibilidad con PHP 8.2 y MySQL 8.0 queda como verificación manual
  documentada (no se automatiza).

### M-8 Timeout de sesión automatizado

- Test que arranca la sesión con un timeout de pocos segundos (o manipula
  `_last_activity`), y verifica que al vencer se vacía y cambia el ID, y que antes de vencer
  no. Va en la suite unitaria/integración (CLI), no en el smoke HTTP.

### Reparto y orden

| Tarea | Responsable | Contenido | En paralelo con |
|-------|-------------|-----------|-----------------|
| A1-09 | AGENTE 1 | M-1, M-5, M-6 (infraestructura y seguridad) | A2-07 |
| A2-07 | AGENTE 2 | M-7 (runner, unit de lo estable, CI) + M-8 | A1-09 |
| A1-10 | AGENTE 1 | M-2, M-3, M-4 (panel) | A2-08 |
| A2-08 | AGENTE 2 | Pruebas de M-1..M-5 contra contrato (unit/integración/smoke) | A1-10 |
| A2-09 | AGENTE 2 | Revisión adversarial de toda la fase de mejoras | — |

## 6. Segunda fase de mejoras (pedido de Juan, 2026-10-19, D-55)

### R-1 Búsqueda con índice FULLTEXT (D-56)

- Índice `FULLTEXT ft_busqueda (asunto, nombre, email, descripcion)` en `solicitudes`
  (InnoDB, utf8mb4; MySQL 8 y MariaDB ≥ 10.6).
- **Híbrido, para no cambiar el contrato de M-3** (subcadena literal, `%` y `_` sin
  comodín): de `q` se extraen las palabras indexables (secuencias de letras/dígitos Unicode
  de ≥ 3 caracteres que no estén en la lista de stopwords por defecto de InnoDB, que es la
  misma en MySQL y MariaDB e incluye `de`, `en`, `la`, `com`); si hay al menos una, el WHERE
  es `MATCH(asunto, nombre, email, descripcion) AGAINST(? IN BOOLEAN MODE)` con
  `+palabra*` por cada una (prefijo, todas obligatorias; operadores booleanos del usuario
  escapados/eliminados) **AND** el `LIKE … ESCAPE ?` actual como confirmación exacta. Si no
  hay ninguna palabra indexable (por ejemplo `%`, `a_b`, `de`), se usa solo el `LIKE` actual
  (scan completo, aceptado para ese caso).
- Consecuencia aceptada: una subcadena **en medio** de una palabra (`ampus` en `campus`) ya
  no encuentra resultados cuando `q` tiene palabras indexables (la búsqueda pasa a ser por
  palabra/prefijo). Se documenta en la UI ("Buscá por palabras") y en el ADR.
- Caracteres que InnoDB considera parte de una palabra pero el contrato no (`_` y `'`, D-59):
  si `q` contiene alguno, se usa el fallback `LIKE` completo (no se parte la palabra), para
  que el resultado siga siendo idéntico al de `LIKE`.
- `countByEstado(q)` y `contar(q)` usan exactamente el mismo filtro.
- Verificación: `EXPLAIN` muestra `type=fulltext` (o equivalente en MariaDB) con
  `key=ft_busqueda` para una búsqueda con palabras; resultados idénticos a la versión LIKE
  para todos los casos B del smoke.

### R-2 `RouteMatcher` y despacho sin `exit` (D-57)

- `App\Core\RouteMatcher` (pura, sin `$_SERVER`, sin salida): `match(string $method,
  string $path): RouteMatch` → objeto inmutable con `status` (200 | 404 | 405), y según el
  caso `handler`, `params` (ints), `options`, o `allow` (lista para el header `Allow`, con
  HEAD si hay GET). Absorbe la regex de `{id}` (`$D`, `[1-9][0-9]{0,9}`).
- `Router::dispatch()` deja de terminar el proceso: usa `RouteMatcher`, aplica sesión →
  auth → CSRF y ejecuta el controller. Los cortes de flujo (`Response::abort()` y
  `Response::redirect()`) **lanzan** una excepción de control (`App\Core\HttpException` con
  status y headers; la misma `HttpException` con `Location` e `isRedirect()`, D-59) en vez de
  `exit`. Un único punto (`public_html/index.php` o un `App\Core\Kernel::handle()`) las
  captura y emite la respuesta (vista de error o redirect). Ningún `exit`/`die` en `app/`
  salvo el front controller y los scripts CLI.
- Las excepciones de control **no** se loguean como errores ni pasan por la vista 500.
- Los tests de Router dejan de necesitar Reflection y procesos hijo.

### R-3 `Database` sin lectura propia de la configuración (D-58)

- Se elimina `Database::loadConfig()`: `Database::pdo()` sin `configure()` previo →
  `LogicException` clara. `bootstrap.php` configura `Database` desde `Config`.
- Bootstrap mínimo para CLI (`app/bootstrap-cli.php`): autoload, helpers, timezone,
  `Config::configure(require config.php)` y `Database::configure(Config::get('db'))`, sin
  sesión ni headers ni handlers HTTP. Lo usan `scripts/instalar-db.php`,
  `scripts/crear-admin.php` y los tests que tocan la DB (`tests/lib`), en vez de cargar la
  config cada uno a su manera. Nadie imprime la config.

### Reparto

| Tarea | Responsable | Contenido | En paralelo con |
|-------|-------------|-----------|-----------------|
| A1-13 | AGENTE 1 | R-1, R-2, R-3 (turno de DB) | A2-10 |
| A2-10 | AGENTE 2 | Tests contra el contrato: `RouteMatcher` puro, dispatch sin hijo, `Database` sin configure, búsqueda FULLTEXT (unit de extracción de palabras + integración con EXPLAIN y casos de borde); `tests/lib` pasa a `bootstrap-cli.php` | A1-13 |
| A2-11 | AGENTE 2 | Revisión adversarial de A1-13 + corrida | — |

## 7. Funcionalidades finales (pedido de Juan, 2026-10-19, D-63)

Se hacen después de cerrar las correcciones de la auditoría (A1-15/A2-14). Mismo método:
contrato, pruebas contra el contrato en paralelo, revisión adversarial, corrida oficial.

### F-1 Límite de frecuencia en el formulario público (cierra AU-8)

- Tabla `envios_publicos` (`id`, `ip VARCHAR(45)`, `accion ENUM('solicitud','seguimiento')`,
  `created_at`; índice `(accion, ip, created_at)` y `(created_at)` para la purga). Mismo
  patrón "reservar y contar" que el login (D-46): se inserta antes de procesar y se cuenta
  la ventana incluyendo la fila propia; si supera, se borra la fila propia y **429** +
  `Retry-After` + re-render del formulario con los valores escapados y el mensaje
  "Demasiados envíos. Probá de nuevo en N minutos.". Cuenta todo POST que pasó el CSRF
  (válido o 422). Configurable: `app.form_max_por_ip` (5) y `app.form_ventana_seg` (600),
  validados como el resto (AU-3). IP con `Request::ip()` (respeta `trusted_proxies`).
- **Honeypot**: campo `sitio_web` en el formulario, oculto con una clase CSS (sin estilos
  inline, CSP), `tabindex="-1"`, `autocomplete="off"`, `aria-hidden="true"` en su
  contenedor y etiqueta "No completar este campo". Si llega con contenido: **no** se guarda
  nada, se registra el evento (F-2) y se responde igual que un éxito (302 a `/gracias`, con
  una confirmación genérica sin número ni código), para no darle señal al bot.

### F-2 Registro de eventos de seguridad (cierra AU-9)

- Archivo propio `storage/logs/security.log`, con la misma rotación de `App\Core\Log`
  (otro canal, mismos límites de config). Una línea por evento:
  `fecha | evento | ip | método path | admin_id o - | detalle`.
- Eventos: `login_ok`, `login_fallido`, `login_bloqueado` (429), `csrf_invalido` (419),
  `acceso_sin_sesion` (302/401 en `/admin`), `form_bloqueado` (429), `honeypot`,
  `seguimiento_ok`, `seguimiento_fallido`, `seguimiento_bloqueado`, `password_cambiada`,
  `password_fallida`, `sesion_invalidada`.
- **Sin datos personales en claro**: nunca contraseñas, códigos de seguimiento, cuerpos ni
  query strings; el email, si hace falta correlacionar, como los primeros 12 caracteres de su
  SHA-256 normalizado. La IP sí se registra (dato necesario para seguridad; documentado).
  Saltos de línea y separadores del detalle neutralizados.

### F-3 Seguimiento público de la solicitud

- Al crear la solicitud se genera un **código de seguimiento** de 128 bits
  (`random_bytes(16)`), mostrado una sola vez en `/gracias` en un formato legible (por
  ejemplo grupos de 4 caracteres en base32 sin ambiguos), junto con el número. En la DB se
  guarda solo `seguimiento_hash CHAR(64)` = SHA-256 del código normalizado; nunca el código.
  Las solicitudes del seed y las previas tienen `NULL` (no consultables).
- `GET /seguimiento`: formulario (número + código). `POST /seguimiento` (CSRF como todo
  POST): busca por id, compara con `hash_equals` contra el hash guardado; si el id no existe,
  no tiene código o el código no coincide → **el mismo** 422 con "Número o código
  incorrectos." (sin distinguir casos). Normaliza el código (mayúsculas, sin guiones ni
  espacios) antes de hashear.
- Resultado: asunto, estado actual (badge), fecha de alta y última actualización, e
  historial de estados (fecha, de → a) **sin** nombre del admin ni email del solicitante ni
  descripción. Se muestra en la respuesta del POST (no se pone el código en la URL ni en la
  sesión).
- Fuerza bruta: los intentos **fallidos** cuentan con F-1 (`accion='seguimiento'`):
  `app.seguimiento_max_por_ip` (10) en `app.seguimiento_ventana_seg` (900) → 429.
- Link "Consultar el estado de una solicitud" en el sitio público.

### F-5 Cambio de contraseña del admin desde el panel

- `GET/POST /admin/cuenta/password` (auth + CSRF): contraseña actual, nueva y
  confirmación. Reglas: actual correcta (`password_verify`), nueva ≥ 12 caracteres,
  `Admin::passwordAcceptable` (≤ 72 bytes, sin NUL), distinta de la actual, confirmación
  igual. Errores → 422 con mensaje por campo (nunca se re-muestran contraseñas).
- Intentos con la actual incorrecta cuentan en el límite de login de esa cuenta (IP + email
  del admin, D-38): al superarlo, 429.
- Éxito: `Admin::updatePasswordHash`, columna nueva `admins.password_changed_at`,
  `Session::regenerate()` + `Csrf::rotate()`, flash "Contraseña actualizada." y 302 al panel.
- **Invalidación de otras sesiones**: la sesión guarda `auth_at` al loguear; `Auth::user()`
  rechaza la sesión si `auth_at < password_changed_at` (se cierra y se registra
  `sesion_invalidada`). La sesión que cambió la contraseña actualiza su `auth_at`.
- Link "Cambiar contraseña" en el header del panel.

### Reparto previsto

| Tarea | Responsable | Contenido |
|-------|-------------|-----------|
| A1-16 | AGENTE 1 | F-1, F-2, F-5 (infraestructura de seguridad) |
| A2-15 | AGENTE 2 | Pruebas contra el contrato de F-1, F-2, F-3, F-5 (en paralelo) |
| A1-17 | AGENTE 1 | F-3 (seguimiento público) |
| A2-16 | AGENTE 2 | Revisión adversarial de F-1..F-5 + corrida |
