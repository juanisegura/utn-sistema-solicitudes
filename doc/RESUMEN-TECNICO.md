> Borrador redactado con asistencia de IA a partir del código y los docs del repo; revisado por el autor.

# Resumen técnico — Sistema de gestión de solicitudes

Implementé un sistema web de gestión de solicitudes en PHP 8.2+ vanilla y MySQL/MariaDB, sin frameworks ni Composer. Tiene un formulario público (nombre, email, asunto, descripción) con confirmación, y un panel de administración con login, listado, filtro por estado, detalle y cambio de estado. El desarrollo lo planifiqué en [PLAN.md](PLAN.md) y lo hice con asistencia de IA bajo un esquema de orquestador, implementador y revisor ([ADR-0002](adr/0002-metodo-ia-orquestador-agentes.md)). Estado: la corrida oficial de `tests/smoke.sh` dio **67 casos, 67 PASS, 0 FAIL, 0 SKIP** ([evidencia](pruebas/evidencias/smoke-20261019-135950.txt)). El timeout de sesión se probó solo de forma manual ([tests/README.md](../tests/README.md)).

## Arquitectura

```text
request
  -> public_html/index.php            front controller (bajo php -S sirve solo archivos
  |                                   estáticos reales dentro de public_html/: sin .php, sin dotfiles)
  -> app/bootstrap.php                handlers de errores, headers de seguridad, config, sesión
  -> Router (app/Core/Router.php)
       ruta coincide?  no -> 404 / método distinto -> 405 + Allow
       auth (Auth::requireAdmin)      solo rutas 'auth' => true
       CSRF                           todo POST por defecto
       -> Controller                  valida entrada, decide la respuesta
            -> Model                  único lugar con SQL (PDO, consultas preparadas)
            -> View                   e() para escapar, layouts
```

| Capa | Responsabilidad | Archivos |
|---|---|---|
| Entrada | Front controller y reglas de Apache | [public_html/index.php](../public_html/index.php), [public_html/.htaccess](../public_html/.htaccess) |
| Arranque | Errores, headers, config, sesión | [app/bootstrap.php](../app/bootstrap.php), [app/config/config.php](../app/config/config.php) |
| Rutas | Tabla método + patrón + opciones | [app/routes.php](../app/routes.php) |
| Núcleo | Router, Database, Session, Auth, Csrf, Request, Response, Validator, View, Config (configuración cargada una sola vez por `bootstrap`; las clases no leen `config.php` por su cuenta), Log | [app/Core/](../app/Core/) |
| Controllers | Finos, sin SQL | [app/Controllers/](../app/Controllers/) |
| Models | Acceso a datos y enum `Estado` | [app/Models/](../app/Models/) |
| Views | Presentación, sin lógica ni SQL | [app/Views/](../app/Views/) |

Solo `public_html/` es DocumentRoot; el resto del código y la config quedan fuera de alcance por URL.

Presentación: CSS propio sin frameworks ni JS, dividido por responsabilidad en `public_html/assets/css/` (base, layout, components, pages) y cargado por cada layout con `<link>` en orden de cascada, sin `@import` ni build step. Mobile first, paleta clara con tokens OKLCH y fallback hex, contraste AA verificado (texto principal 15,2:1, acento 5,8:1, badges ≥ 6,2:1) y nada inline, compatible con la CSP `default-src 'self'` (D-40, D-43).

## Base de datos

Definida en [database/schema.sql](../database/schema.sql), con cuatro tablas:

- `admins`: `id`, `nombre`, `email` (**UNIQUE**, `uq_admins_email`), `password_hash`, `activo`, `last_login_at`, `created_at`.
- `solicitudes`: `id`, `nombre`, `email`, `asunto`, `descripcion` (TEXT), `estado` (`ENUM('pendiente','en_proceso','resuelta')`, default `pendiente`), `created_at`, `updated_at`.
- `login_intentos`: `ip`, `email_hash` (SHA-256 del email normalizado: el email no se guarda en claro), `created_at`; índices `(ip, email_hash, created_at)` y `(ip, created_at)` para contar fallos por cuenta y por IP dentro de la ventana ([ADR-0009](adr/0009-limite-intentos-login.md)).
- `solicitud_eventos`: historial de cambios de estado (`solicitud_id` → solicitudes `ON DELETE CASCADE`, `admin_id` → admins `ON DELETE RESTRICT`, `estado_anterior`/`estado_nuevo` con el mismo ENUM, `created_at`; índice `(solicitud_id, created_at)`). El cambio de estado y su evento se escriben en una transacción con `SELECT … FOR UPDATE` ([ADR-0010](adr/0010-historial-estados-transaccion.md)).
- Índice `idx_estado_fecha (estado, created_at)`: cubre la consulta del panel, que filtra por estado y ordena por fecha.
- Motor InnoDB y `utf8mb4_unicode_ci`: InnoDB es el motor transaccional por defecto de MySQL 8 y MariaDB; `utf8mb4` guarda Unicode completo (tildes, eñes y emojis, que `utf8` de 3 bytes no admite) y la collation `_ci` hace que el email único no distinga mayúsculas.
- `Database::connect` ejecuta `SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'` ([app/Core/Database.php](../app/Core/Database.php)): sin modo estricto, MySQL guarda `''` ante un ENUM inválido o trunca en silencio.
- Seed ([database/seed.sql](../database/seed.sql)): un admin demo y ocho solicitudes ficticias en los tres estados, con dominios `example.com`. Se carga con `php scripts/instalar-db.php`.

## Decisiones principales

- [ADR-0001](adr/0001-mvc-ligero-front-controller.md): MVC ligero con front controller y tabla de rutas, `app/` separado de `public_html/`, sin framework ni Composer.
- [ADR-0002](adr/0002-metodo-ia-orquestador-agentes.md): desarrollo asistido por IA con orquestador, implementador y revisor, y registro de prompts.
- [ADR-0003](adr/0003-pdo-consultas-preparadas.md): PDO con preparación real (`EMULATE_PREPARES=false`) y SQL solo en Models.
- [ADR-0004](adr/0004-autenticacion-y-sesiones.md): `password_hash`, mensaje genérico anti-enumeración, regeneración de sesión y cookie endurecida.
- [ADR-0005](adr/0005-csrf-token-sincronizador.md): token CSRF por sesión, verificado centralmente en el Router.
- [ADR-0006](adr/0006-estados-enum.md): estados como ENUM en la DB y enum PHP como lista blanca.
- [ADR-0007](adr/0007-configuracion-y-secretos.md): defaults seguros versionados y credenciales en `config.local.php`, fuera de Git.
- [ADR-0008](adr/0008-errores-y-codigos-http.md): errores internos al log con vista 500 genérica, y códigos HTTP explícitos para los errores esperados.
- [ADR-0009](adr/0009-limite-intentos-login.md): límite de intentos de login por IP + email y por IP sola, con 429 y `Retry-After`.
- [ADR-0010](adr/0010-historial-estados-transaccion.md): historial de estados en tabla de eventos, escrito en la misma transacción que el cambio.

## Seguridad

| Requisito | Cómo se cumple | Dónde | Cómo se verificó |
|---|---|---|---|
| SQL injection | Consultas preparadas reales; el estado entra solo como valor del enum `Estado`; la búsqueda usa `LIKE ? ESCAPE '\\'` con `%`, `_` y `\` escapados (`Solicitud::escapeLike`) y `LIMIT`/`OFFSET` como enteros bindeados | [Database.php](../app/Core/Database.php), [Solicitud.php](../app/Models/Solicitud.php), [Estado.php](../app/Models/Estado.php) | P6.5, P7.1, P7.4, P7.6, P7.7 |
| XSS | `e()` (`htmlspecialchars` con `ENT_QUOTES`) en la salida; CSP `default-src 'self'`. Se guarda lo enviado y se escapa al mostrar (D-30) | [helpers.php](../app/helpers.php), [Views/](../app/Views/), [bootstrap.php](../app/bootstrap.php) | P7.2, P7.3, P7.4, P7.5, S.3 |
| Contraseñas | bcrypt con costo fijo 12 (`Admin::HASH_ALGO`/`HASH_OPTIONS`, D-35) en el alta, el rehash y el hash ficticio que se verifica si el email no existe; `password_verify`; rechazo de más de 72 bytes o con byte NUL (D-36) | [Auth.php](../app/Core/Auth.php), [Admin.php](../app/Models/Admin.php) | P4.1, P4.2 (mismo mensaje y 401), H.16 |
| Fuerza bruta en el login | Límite de intentos fallidos en 15 min: 5 por IP + email y 20 por IP sola (`app.login_*`). Al alcanzarlo, 429 + `Retry-After` sin verificar la contraseña ni consultar `admins` (igual para emails existentes o no). El éxito limpia los fallos de esa cuenta; el email se guarda como SHA-256 (D-38). Contador y búsqueda del admin usan la misma forma canónica (`mb_strtolower(trim())`), y el login compara el email en binario: las variantes con acento no autentican ni esquivan el contador (D-41). Exacto bajo concurrencia: el intento se registra antes de contar ("reservar y contar", D-46). Detrás de un proxy propio la IP sale de `X-Forwarded-For` solo si `REMOTE_ADDR` está en `app.trusted_proxies` (D-47) | [LoginThrottle.php](../app/Core/LoginThrottle.php), [LoginIntento.php](../app/Models/LoginIntento.php), [Admin.php](../app/Models/Admin.php), [AuthController.php](../app/Controllers/Admin/AuthController.php), [Request.php](../app/Core/Request.php) | L.1–L.4, L.6, L.7; concurrencia medida (abajo) |
| Sesión | Cookie `utn_sesion` HttpOnly y SameSite=Lax (Secure con HTTPS o `env=production`), `use_strict_mode`, regeneración de ID y del token CSRF en login, timeout por inactividad (`app.session_timeout`), logout destruye la sesión y la cookie. La sesión se abre en el Router solo cuando hay ruta y método válidos: 404, 405 y estáticos no crean sesión ni mandan `Set-Cookie` (M-5) | [Session.php](../app/Core/Session.php), [Auth.php](../app/Core/Auth.php), [Router.php](../app/Core/Router.php) | S.4, S.5, S.8; timeout solo manual |
| CSRF | Token de 32 bytes, `hash_equals`, validado en el Router en todo POST; fallo devuelve 419 | [Csrf.php](../app/Core/Csrf.php), [Router.php](../app/Core/Router.php) | H.1, H.3, H.4, H.5, P6.2, P6.3, S.7 |
| Autorización / acceso al panel | `'auth' => true` en las rutas `/admin/*` salvo login; `Auth::user()` re-verifica en DB que el admin siga activo. Sin sesión: GET 302, POST 401 (D-17) | [routes.php](../app/routes.php), [Auth.php](../app/Core/Auth.php) | P3.1–P3.4, P6.1, P6.7, H.2 |
| Validación server-side | `Validator`: obligatorios, email, largos máximos, UTF-8 válido, recorte de espacios Unicode y rechazo de caracteres de control (TAB y saltos de línea solo en la descripción, D-50); estado contra el enum `Estado`, `{id}` solo enteros positivos en el Router; parámetros como array rechazados por `Request::string()`; en el listado, `q` (≤ 100, mismas reglas del `Validator`) y `pagina` (solo dígitos, ≥ 1) inválidos → 400, página fuera de rango → 404 | [Validator.php](../app/Core/Validator.php), [Request.php](../app/Core/Request.php) | P2.1–P2.5, P6.4, P6.6, P6.8, H.12–H.14 |
| Manejo de errores | Warnings a excepciones; log en `storage/logs/app.log` con rotación por tamaño (1 MB, 5 archivos, `flock`; M-6); vista 500 genérica; `display_errors=0` fuera de debug | [bootstrap.php](../app/bootstrap.php), [Log.php](../app/Core/Log.php) | H.7, H.11, H.12, H.13, H.14 (sin fugas ni 500) |
| Secretos / configuración | Defaults seguros versionados; `config.local.php` fuera de Git; `.htaccess` raíz con `Require all denied` | [config.php](../app/config/config.php), [.htaccess](../.htaccess) | H.15 (dotfiles con `php -S`); el bloqueo de la raíz se verificó con Apache (`.git/config` 200 → 403, D-33), sin caso de smoke |
| Clickjacking / MIME | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `frame-ancestors 'none'`, `Referrer-Policy` | [bootstrap.php](../app/bootstrap.php), [public_html/.htaccess](../public_html/.htaccess) | S.1, S.2, S.3 |

Límites conocidos de estos controles:

- El límite por cuenta es por IP + email: quien rota direcciones (por ejemplo dentro de un /64 de IPv6) obtiene contadores nuevos. Lo frena el límite por IP solo dentro de la misma dirección; agrupar IPv6 por /64 queda como mejora.
- Detrás de un CDN o proxy sin `app.trusted_proxies`, todos los clientes comparten la IP del proxy y, con ella, el límite por IP (ADR-0009).
- El log y su `.lock` se crean con los permisos del proceso que los crea: si los scripts CLI y el servidor web corren con usuarios distintos y el CLI crea el archivo primero, el web no puede escribirlo y cae a `error_log` del servidor (no se pierde, pero queda en otro lugar). En producción, crear `storage/logs/` con dueño y grupo del usuario web.

## Dificultades encontradas

- **Cambio de estado al mismo valor.** Con el comportamiento por defecto de MySQL, `rowCount()` devuelve 0 si el UPDATE no modifica nada, y eso parecía "id inexistente". Activé `ATTR_FOUND_ROWS` para que cuente filas encontradas (D-10, [ADR-0003](adr/0003-pdo-consultas-preparadas.md)). PHP 8.5 depreca las constantes `PDO::MYSQL_*`, así que `foundRowsAttr()` usa `Pdo\Mysql` si existe.
- **`.htaccess` heredado.** Apache aplica los `.htaccess` de los directorios padres: el `Require all denied` de la raíz bloqueaba también el DocumentRoot correcto. Lo resolví con `Require all granted` en `public_html/.htaccess` (D-33).
- **UTF-8 inválido y modo estricto.** Un nombre con bytes inválidos llegaba a la DB, que lo rechazaba y terminaba en 500. `Validator` ahora usa `mb_check_encoding` y responde 422 (D-31).
- **Campos "obligatorios" con solo NBSP.** `trim()` solo quita espacios ASCII, así que pasaban como no vacíos. Implementé un recorte Unicode en `Validator` (D-31).
- **Deprecaciones de PHP 8.5.** En local uso PHP 8.5, que ya depreca las constantes `PDO::MYSQL_*` (lo vi al escribir la capa de datos). La revisión del agente revisor (A2-03) marcó que el manejador de errores convertía cualquier deprecación en excepción, de modo que una actualización de PHP podía tumbar requests que funcionaban. Ahora `bootstrap.php` las registra en el log y la request continúa.
- **Costo de bcrypt y versión de PHP.** Al principio hasheaba con `PASSWORD_DEFAULT`, cuyo costo pasó de 10 a 12 en PHP 8.4. El hash ficticio del login (para que un email inexistente tarde lo mismo) tenía costo 12: con PHP 8.2/8.3 los hashes reales serían de costo 10 y el tiempo de respuesta revelaría qué emails existen. Ahora el algoritmo y el costo son fijos y compartidos (D-35, hallazgo de la revisión A2-04).
- **Ventana del límite de intentos y zona horaria.** Al diseñarlo vi un riesgo: la bitácora de fallos usa `created_at DEFAULT CURRENT_TIMESTAMP` (reloj de la DB) y PHP corre en `America/Argentina/Buenos_Aires` (D-24). Calcular el corte de la ventana en PHP podía correrla horas si ambas zonas horarias no coincidían. Por eso tanto el corte (`NOW() - INTERVAL ? SECOND`) como los segundos del `Retry-After` (`TIMESTAMPDIFF`) se calculan en la DB, con un solo reloj.
- **Variantes de acento del email (bypass del límite).** La revisión A2-05 encontró que, con `admin@example.com` bloqueado, `ádmin@example.com` + la contraseña correcta entraba al panel: la búsqueda en `admins` usaba la collation `utf8mb4_unicode_ci` (ignora acentos), pero el contador de intentos usaba otro hash, así que la variante tenía su propio contador. Lo había anotado como "limitación aceptada" y era un bypass real. Ahora hay una única normalización (`Admin::normalizeEmail`) y la búsqueda exige igualdad binaria; la columna conserva `_ci` para que el `UNIQUE` siga impidiendo cuentas que difieran solo en mayúsculas o acentos (D-41).
- **Recorte de espacios con costo cuadrático (ReDoS).** La revisión A2-08 (con un subagente como segunda opinión) encontró que el recorte Unicode del `Validator` usaba `^espacios+|espacios+$`: sin JIT de PCRE (deshabilitado en este equipo), una corrida larga de espacios en el medio del texto se reintentaba desde cada posición. Medido con `php -d pcre.jit=0`: 20 000 espacios internos tardaban 2,45 s y 200 000 no terminaban en 60 s; una sola request pública de ~400 KB ocupaba un worker por minutos. Lo resolví recortando en dos pasos sin alternancia (el del final con un lookbehind, que solo puede empezar donde empieza la corrida de espacios) y rechazando antes de recortar todo valor de más de 4×N bytes cuando el campo admite N caracteres. Ahora 200 000 espacios internos tardan 0,009 s sin JIT (D-52).
- **Carrera en el límite de intentos.** El primer diseño contaba los fallos y recién después registraba el nuevo: requests simultáneas veían el mismo conteo. Lo medí con 10 logins fallidos en paralelo contra una cuenta con 4 fallos previos (servidor con 8 workers): con el código anterior pasaban a verificar la contraseña entre 2 y 6 en lugar de 1. Con "reservar y contar" (INSERT antes del conteo, borrar la fila si queda bloqueado o si el login es exitoso) pasa exactamente 1 de 10, y 1 de 30 (D-46).
- **Parámetros como array.** `estado[]=x` provocaba un `TypeError` (500) al llegar a código tipado con `string`. `Request::string()` devuelve null si el valor no es string (hallazgo M3, D-27).

## Mejoras pendientes

- Router: extraer la resolución de rutas a un `RouteMatcher` público y que `dispatch` devuelva un resultado en vez de terminar con `exit`, para testearlo sin procesos hijo (sugerencia de AGENTE 2 en A2-07).
- Búsqueda: hoy es `LIKE '%texto%'` sobre cuatro columnas, que recorre la tabla completa (EXPLAIN: `type=ALL`). Alcanza para el volumen del TP; con miles de solicitudes convendría un índice `FULLTEXT` (`MATCH … AGAINST`).
- Logs: la rotación propia alcanza para un hosting compartido; en un servidor propio conviene `logrotate` (rotación por tiempo, compresión y retención centralizadas) y desactivar la del código con un `app.log_max_bytes` alto.
- Tests unitarios y CI; hoy solo hay smoke por HTTP.
- El timeout de sesión se probó solo manualmente (procedimiento en [tests/README.md](../tests/README.md)).
