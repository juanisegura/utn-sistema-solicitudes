# Auditoría de seguridad — subagente Claude (Sonnet, perfil security-auditor)

## Resumen

Auditoría de solo lectura de todo el código. Hallazgos explotables: ninguno de severidad Alta ni Media. Revisé la ruta de punta a punta (Router, auth, CSRF, modelos, vistas, headers, scripts, seed) y todas las consultas SQL son parametrizadas. Toda salida HTML pasa por `e()`. Quedan 5 hallazgos de severidad Baja (defensa en profundidad y configuración) y 1 Info. El único comando que corrí fue un `php` de cálculo puro, para comprobar que `Auth::DUMMY_HASH` es un bcrypt costo 12 válido (~153 ms). El script temporal que usé lo borré.

## Hallazgos

| ID | Sev. | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Estado |
|---|---|---|---|---|---|---|
| S-01 | Baja | app/routes.php:14, app/Controllers/SolicitudController.php:38-51 | `POST /solicitudes` público sin límite de frecuencia ni anti-bot | Un script hace `GET /` (cookie + token CSRF) y luego POST en bucle con 5000 caracteres de descripción; llena `solicitudes` y el panel | Reusar el patrón de `login_intentos` (contador por IP en ventana) o un honeypot, más un tope diario | Verificado |
| S-02 | Baja | scripts/instalar-db.php:57,65-70; README.md:84-91; database/seed.sql:10-11 | El seed crea `admin@example.com` con contraseña pública (repo público). La guardia solo bloquea si `env === 'production'`, y con `--force` sin `--sin-datos` carga el seed igual | Quien instala con `env` distinto de `production`, o usa `--force` sin `--sin-datos`, queda con un admin de contraseña conocida | Con `--force`, ignorar el seed (o exigir `--con-datos` explícito); seed solo con `env=local` | Verificado |
| S-03 | Baja | app/config/config.local.example.php:7-8 | La plantilla trae `env=local` y `debug=true`; copiada tal cual a producción, `display_errors=1`, el 500 muestra clase/mensaje/archivo:línea y la cookie no fuerza `Secure` | Despliegue por copia literal de la plantilla | Plantilla con `env=production` y `debug=false`; config de desarrollo pedida explícitamente | Verificado |
| S-04 | Baja | README.md:50 | El mismo usuario de DB tiene `GRANT ALL PRIVILEGES` (incluido DROP/ALTER) y es el que usa la web | Si apareciera una SQLi futura, el impacto sería DDL y no solo DML (no encontré ninguna hoy) | Dos usuarios: uno DDL para `instalar-db.php` y otro DML para la web | Verificado |
| S-05 | Baja | app/Controllers/Admin/AuthController.php:49-64; app/Models/LoginIntento.php:82-88; app/bootstrap.php:34-51 | No hay registro de eventos de seguridad: `app_log` solo registra excepciones; fallos de login hasheados y purgados a las 24 h; logins exitosos no se registran | Tras un acceso indebido no hay forma de saber cuándo ni desde qué IP entró el admin (solo `last_login_at`) | Agregar a `Log` una línea sin PII para login ok/fail, 429 y 419 | Verificado |
| S-06 | Info | app/bootstrap.php:110-114; public_html/.htaccess:20-26; app/Core/Session.php:131-140 | Sin HSTS ni Permissions-Policy; sesión con timeout solo por inactividad, sin duración absoluta | Una sesión robada y usada continuamente nunca vence | HSTS desde el vhost HTTPS; opcional: expiración absoluta de 8–12 h | Verificado |

## Detalle

- **S-01.** El Router (`app/Core/Router.php:42-49`) solo exige CSRF para este POST y ninguna guarda limita la frecuencia; el token se obtiene gratis con un `GET /`. `Validator` limita el tamaño por request, no la cantidad. Ninguna decisión ni ADR acepta explícitamente la falta de límite en el formulario público.
- **S-02.** `$conDatos = !in_array('--sin-datos', $args, true);` (instalar-db.php:57) y `if ($config['app']['env'] === 'production' && !in_array('--force', $args, true)) { ... exit(1); }` (:65). El mensaje aconseja `--sin-datos --force`, pero el código no lo impone.
- **S-03.** Los defaults de `config.php` son seguros, pero `config.local.php` los pisa con `array_replace_recursive`; `bootstrap.php:119` hace `ini_set('display_errors', $debug ? '1' : '0')`.
- **S-04.** `schema.sql` hace `DROP TABLE`, así que el instalador necesita DDL; la web no.
- **S-05.** La trazabilidad de cambios de estado existe (`solicitud_eventos`, ADR-0010); falta la de autenticación.
- **S-06.** La CSP y los otros headers están presentes en PHP y en `.htaccess`.

No reportados por estar aceptados: rotación IPv6 /64, CDN sin `trusted_proxies`, NAT compartido (ADR-0009), sesión en requests anónimas (D-32), CSRF no de un solo uso (D-25), escapar en la salida (D-30), B4/B5 de scripts (D-27).

## Áreas verificadas OK

- **Inyección SQL:** todas las consultas usan `?` con `bindValue` y `EMULATE_PREPARES=false`; `LIMIT ? OFFSET ?` bindeado como INT; identificadores de `instalar-db.php` desde una constante.
- **Búsqueda FULLTEXT/LIKE:** `fulltextQuery` solo emite `+palabra*` con `\p{L}\p{M}\p{N}` y devuelve null ante `_` o apóstrofe; `AGAINST(?)` y `ESCAPE ?` bindeados; `q` ≤ 100 caracteres.
- **XSS:** todas las vistas escapan con `e()` (`ENT_QUOTES|ENT_SUBSTITUTE`, UTF-8), atributos entrecomillados, `$content` ya escapado, `<wbr>` como markup propio, sin JS ni `style=` inline, CSP `default-src 'self'`.
- **Autorización:** las rutas admin y el logout tienen `'auth'=>true`; auth antes de CSRF y del controller; `Auth::user()` re-verifica en DB; GET sin sesión → login, POST → 401; `{id}` restringido con `/D`; HEAD como GET; CSRF por defecto en POST nuevos; probado el borde `//host/admin`.
- **Autenticación:** bcrypt costo 12 fijo; hash ficticio válido (~153 ms medido); NUL y > 72 bytes rechazados; email en binario; mensaje genérico; "reservar y contar"; rehash.
- **Sesiones:** strict mode, solo cookies, sin trans-sid, HttpOnly/SameSite/Secure en producción, regeneración y rotación de CSRF al loguear, logout destruye sesión y cookie, timeout con regeneración, `gc_maxlifetime` ≥ timeout, headers `nocache` por `session.cache_limiter`.
- **CSRF:** `hash_equals`, 32 bytes, un único punto de verificación, todos los formularios POST con `Csrf::field()`.
- **Redirecciones y headers:** `Location` constantes + `(int)$id`; `HttpException::assertPathLocal` rechaza `//`, `\` y controles; `Retry-After` y `Allow` desde el código; headers de seguridad en PHP y `.htaccess`.
- **Validación:** `Request::string` descarta arrays; UTF-8, controles, recorte lineal y tope de bytes; `Estado::fromInput` lista blanca; `Request::only` evita mass assignment; XFF solo desde proxies de confianza.
- **Errores y fugas:** `display_errors=0` por defecto; 500 genérica fuera de debug; log sin query/cuerpo y con `\r\n` neutralizados; error de conexión envuelto; 404 con texto fijo.
- **Secretos y config:** `config.local.php` en `.gitignore`; sin tokens ni claves en el repo; el hook de registro redacta secretos; `.htaccess` raíz `Require all denied`; `app/`, `storage/`, `database/`, `scripts/` fuera de `public_html/`; scripts solo CLI.
- **Autoload y vistas:** nombre de clase y de vista validados por regex (sin path traversal ni LFI).
- **Seed y esquema:** datos ficticios y solo el hash; FKs coherentes; email de `login_intentos` como SHA-256.

Resumen final: 0 Alta, 0 Media, 5 Baja, 1 Info. Postura sólida para el alcance de un TP. Prioridades: S-02, S-03, S-01.
