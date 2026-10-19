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
└── assets/css/app.css
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
