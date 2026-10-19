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
| Núcleo | Router, Database, Session, Auth, Csrf, Request, Response, Validator, View | [app/Core/](../app/Core/) |
| Controllers | Finos, sin SQL | [app/Controllers/](../app/Controllers/) |
| Models | Acceso a datos y enum `Estado` | [app/Models/](../app/Models/) |
| Views | Presentación, sin lógica ni SQL | [app/Views/](../app/Views/) |

Solo `public_html/` es DocumentRoot; el resto del código y la config quedan fuera de alcance por URL.

## Base de datos

Definida en [database/schema.sql](../database/schema.sql), con dos tablas:

- `admins`: `id`, `nombre`, `email` (**UNIQUE**, `uq_admins_email`), `password_hash`, `activo`, `last_login_at`, `created_at`.
- `solicitudes`: `id`, `nombre`, `email`, `asunto`, `descripcion` (TEXT), `estado` (`ENUM('pendiente','en_proceso','resuelta')`, default `pendiente`), `created_at`, `updated_at`.
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

## Seguridad

| Requisito | Cómo se cumple | Dónde | Cómo se verificó |
|---|---|---|---|
| SQL injection | Consultas preparadas reales; el estado entra solo como valor del enum `Estado` | [Database.php](../app/Core/Database.php), [Solicitud.php](../app/Models/Solicitud.php), [Estado.php](../app/Models/Estado.php) | P6.5, P7.1, P7.4, P7.6, P7.7 |
| XSS | `e()` (`htmlspecialchars` con `ENT_QUOTES`) en la salida; CSP `default-src 'self'`. Se guarda lo enviado y se escapa al mostrar (D-30) | [helpers.php](../app/helpers.php), [Views/](../app/Views/), [bootstrap.php](../app/bootstrap.php) | P7.2, P7.3, P7.4, P7.5, S.3 |
| Contraseñas | `password_hash` / `password_verify`, `password_needs_rehash`, hash ficticio si el email no existe, rechazo de más de 72 bytes | [Auth.php](../app/Core/Auth.php) | P4.1, P4.2 (mismo mensaje y 401) |
| Sesión | Cookie `utn_sesion` HttpOnly y SameSite=Lax (Secure con HTTPS o `env=production`), `use_strict_mode`, regeneración de ID y del token CSRF en login, timeout por inactividad (`app.session_timeout`), logout destruye la sesión y la cookie | [Session.php](../app/Core/Session.php), [Auth.php](../app/Core/Auth.php) | S.4, S.5, S.8; timeout solo manual |
| CSRF | Token de 32 bytes, `hash_equals`, validado en el Router en todo POST; fallo devuelve 419 | [Csrf.php](../app/Core/Csrf.php), [Router.php](../app/Core/Router.php) | H.1, H.3, H.4, H.5, P6.2, P6.3, S.7 |
| Autorización / acceso al panel | `'auth' => true` en las rutas `/admin/*` salvo login; `Auth::user()` re-verifica en DB que el admin siga activo. Sin sesión: GET 302, POST 401 (D-17) | [routes.php](../app/routes.php), [Auth.php](../app/Core/Auth.php) | P3.1–P3.4, P6.1, P6.7, H.2 |
| Validación server-side | `Validator`: obligatorios, email, largos máximos, UTF-8 válido, recorte de espacios Unicode y rechazo de caracteres de control (saltos de línea solo en la descripción); estado contra el enum `Estado`, `{id}` solo enteros positivos en el Router; parámetros como array rechazados por `Request::string()` | [Validator.php](../app/Core/Validator.php), [Request.php](../app/Core/Request.php) | P2.1–P2.5, P6.4, P6.6, P6.8, H.12–H.14 |
| Manejo de errores | Warnings a excepciones; log en `storage/logs/app.log`; vista 500 genérica; `display_errors=0` fuera de debug | [bootstrap.php](../app/bootstrap.php) | H.7, H.11, H.12, H.13, H.14 (sin fugas ni 500) |
| Secretos / configuración | Defaults seguros versionados; `config.local.php` fuera de Git; `.htaccess` raíz con `Require all denied` | [config.php](../app/config/config.php), [.htaccess](../.htaccess) | H.15 (dotfiles con `php -S`); el bloqueo de la raíz se verificó con Apache (`.git/config` 200 → 403, D-33), sin caso de smoke |
| Clickjacking / MIME | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `frame-ancestors 'none'`, `Referrer-Policy` | [bootstrap.php](../app/bootstrap.php), [public_html/.htaccess](../public_html/.htaccess) | S.1, S.2, S.3 |

## Dificultades encontradas

- **Cambio de estado al mismo valor.** Con el comportamiento por defecto de MySQL, `rowCount()` devuelve 0 si el UPDATE no modifica nada, y eso parecía "id inexistente". Activé `ATTR_FOUND_ROWS` para que cuente filas encontradas (D-10, [ADR-0003](adr/0003-pdo-consultas-preparadas.md)). PHP 8.5 depreca las constantes `PDO::MYSQL_*`, así que `foundRowsAttr()` usa `Pdo\Mysql` si existe.
- **`.htaccess` heredado.** Apache aplica los `.htaccess` de los directorios padres: el `Require all denied` de la raíz bloqueaba también el DocumentRoot correcto. Lo resolví con `Require all granted` en `public_html/.htaccess` (D-33).
- **UTF-8 inválido y modo estricto.** Un nombre con bytes inválidos llegaba a la DB, que lo rechazaba y terminaba en 500. `Validator` ahora usa `mb_check_encoding` y responde 422 (D-31).
- **Campos "obligatorios" con solo NBSP.** `trim()` solo quita espacios ASCII, así que pasaban como no vacíos. Implementé un recorte Unicode en `Validator` (D-31).
- **Deprecaciones de PHP 8.5.** En local uso PHP 8.5, que ya depreca las constantes `PDO::MYSQL_*` (lo vi al escribir la capa de datos). La revisión del agente revisor (A2-03) marcó que el manejador de errores convertía cualquier deprecación en excepción, de modo que una actualización de PHP podía tumbar requests que funcionaban. Ahora `bootstrap.php` las registra en el log y la request continúa.
- **Parámetros como array.** `estado[]=x` provocaba un `TypeError` (500) al llegar a código tipado con `string`. `Request::string()` devuelve null si el valor no es string (hallazgo M3, D-27).

## Mejoras pendientes

- Límite de intentos de login (D-22): no se implementó; no existe tabla `login_intentos` ni código asociado.
- Historial de cambios de estado (quién, cuándo, de y a).
- Paginación y búsqueda de texto en el listado (D-20).
- `countByEstado()` con `GROUP BY` (D-34): hoy los contadores del listado se calculan en PHP sobre `Solicitud::all()`.
- La sesión se crea en toda request que pasa por el front controller, también las anónimas y los 404 (los estáticos no pasan por PHP). Hace falta para el token CSRF del formulario público; queda como limitación documentada (D-32).
- Rotación de logs de `storage/logs/app.log`.
- Tests unitarios y CI; hoy solo hay smoke por HTTP.
- El timeout de sesión se probó solo manualmente (procedimiento en [tests/README.md](../tests/README.md)).
