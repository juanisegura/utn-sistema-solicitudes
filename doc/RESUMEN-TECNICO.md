# Resumen técnico — Sistema de gestión de solicitudes

Implementé un sistema de solicitudes de soporte en PHP 8.2+ y MySQL/MariaDB, sin frameworks ni Composer. La parte pública es un formulario con confirmación. El panel de administración tiene login con límite de intentos, listado con búsqueda, filtro por estado y paginación, y un detalle con cambio de estado e historial. Lo planifiqué en el [PLAN](PLAN.md) y lo desarrollé con asistencia de IA: un orquestador, un implementador y un revisor ([ADR-0002](adr/0002-metodo-ia-orquestador-agentes.md)).

La verificación local completa (`bash tests/run-all.sh`) da todo OK: 102 tests unitarios, 11 de integración y 84 casos de smoke HTTP ([evidencia](pruebas/evidencias/run-all-20261019-155152.txt), detalle en [PRUEBAS](pruebas/PRUEBAS.md)). Los unitarios corren sin DB. La integración prueba la transacción del historial y la atomicidad del límite de intentos contra un servidor con 8 workers. El smoke recorre por HTTP las pruebas mínimas de la consigna y los códigos de error.

## Arquitectura

```text
request → public_html/index.php      front controller (con php -S sirve solo estáticos reales de public_html/)
        → app/bootstrap.php          manejo de errores, headers de seguridad, Config
        → Router                     ruta: 404 · método: 405 + Allow
                                     → abre la sesión → auth (rutas /admin) → CSRF (todo POST)
        → Controller                 valida la entrada y decide la respuesta
        → Model                      único lugar con SQL (PDO con consultas preparadas)
        → View                       todo se escapa con e(); layouts público y de panel
```

| Capa | Archivos |
|---|---|
| Entrada y arranque | [public_html/index.php](../public_html/index.php), [app/bootstrap.php](../app/bootstrap.php), [app/routes.php](../app/routes.php) |
| Núcleo (Router, Request, Response, Session, Csrf, Auth, LoginThrottle, Validator, View, Config, Log, Database) | [app/Core/](../app/Core/) |
| Controllers finos, Models con SQL, Views sin lógica | [app/Controllers/](../app/Controllers/), [app/Models/](../app/Models/), [app/Views/](../app/Views/) |
| CSS propio por responsabilidad (base, layout, components, pages), sin JS | [public_html/assets/css/](../public_html/assets/css/) |

`public_html/` es el único DocumentRoot ([ADR-0001](adr/0001-mvc-ligero-front-controller.md)).

La presentación es mobile first, con paleta clara definida en tokens OKLCH y contraste AA verificado (texto 15,2:1, acento 5,8:1, badges ≥ 6,2:1). Todo es compatible con la CSP `'self'`: no hay estilos ni scripts inline. Las [capturas](pruebas/evidencias/capturas/) cubren desktop y mobile.

## Base de datos

[schema.sql](../database/schema.sql): InnoDB y `utf8mb4_unicode_ci`. La sesión de DB usa `sql_mode` estricto, así un valor inválido no se guarda en silencio.

| Tabla | Para qué | Detalles |
|---|---|---|
| `admins` | Administradores | `email` UNIQUE, `password_hash`, `activo` |
| `solicitudes` | Solicitudes | `estado` ENUM, índice `(estado, created_at)` para el listado |
| `solicitud_eventos` | Historial de estados | FK a solicitud (CASCADE) y a admin (RESTRICT) |
| `login_intentos` | Límite de intentos | IP + SHA-256 del email, nunca el email en claro |

El [seed](../database/seed.sql) trae un admin demo, ocho solicitudes ficticias (`example.com`) y su historial.

## Decisiones (ADR)

1. [MVC ligero con front controller](adr/0001-mvc-ligero-front-controller.md), sin framework ni Composer.
2. [Método de IA](adr/0002-metodo-ia-orquestador-agentes.md): orquestador, implementador y revisor, con registro de prompts.
3. [PDO con consultas preparadas reales](adr/0003-pdo-consultas-preparadas.md); SQL solo en Models.
4. [Autenticación y sesiones](adr/0004-autenticacion-y-sesiones.md): bcrypt con costo fijo, anti-enumeración, regeneración de ID y sesión solo en rutas válidas.
5. [CSRF](adr/0005-csrf-token-sincronizador.md): token por sesión, validado centralmente en el Router.
6. [Estados](adr/0006-estados-enum.md): ENUM en la DB y enum PHP como lista blanca.
7. [Configuración y secretos](adr/0007-configuracion-y-secretos.md): defaults seguros versionados; credenciales fuera de Git.
8. [Errores y códigos HTTP](adr/0008-errores-y-codigos-http.md): log con rotación y vista 500 genérica.
9. [Límite de intentos de login](adr/0009-limite-intentos-login.md): por IP + email y por IP, exacto bajo concurrencia, con proxies de confianza.
10. [Historial de estados en transacción](adr/0010-historial-estados-transaccion.md): con `SELECT … FOR UPDATE`.

## Seguridad

| Requisito | Cómo se cumple | Verificado por |
|---|---|---|
| Inyección SQL | Preparación real (`EMULATE_PREPARES=false`). El estado solo entra como valor del enum. La búsqueda usa `LIKE ? ESCAPE ?` con `%`, `_` y `\` escapados, y `LIMIT`/`OFFSET` van como enteros bindeados | smoke P6.5, P7.4, P7.6, P7.7, B.2; unit LikeEscape |
| XSS | `e()` en toda salida; se guarda lo enviado y se escapa al mostrar; CSP `default-src 'self'` sin inline | P7.2–P7.5, B.3 |
| Contraseñas | bcrypt costo 12 fijo, también en el hash ficticio que iguala tiempos si el email no existe; se rechazan las de más de 72 bytes o con byte NUL | P4.1, P4.2, H.16; unit Admin |
| Fuerza bruta | 5 fallos por IP + email y 20 por IP en 15 min → 429 + `Retry-After`, sin verificar la contraseña. Intento reservado antes de contar, email canónico y comparación binaria; `X-Forwarded-For` solo desde `trusted_proxies` | L.1–L.4, L.6, L.7; integración LoginAtomicidad; unit RequestIp |
| Sesión | Cookie HttpOnly, SameSite=Lax y Secure en producción. `use_strict_mode`, regeneración de ID y token al loguear. Timeout por inactividad. La sesión solo se abre en rutas válidas | S.4–S.8, B.9; unit SessionTimeout |
| CSRF | Token de 32 bytes, `hash_equals`, en todo POST → 419 | H.1, H.3–H.5, P6.2, P6.3, S.7 |
| Acceso al panel | `/admin/*` exige un admin activo, re-verificado en la DB en cada request. Sin sesión: GET 302, POST 401 | P3.1–P3.4, P6.1, P6.7 |
| Validación | Obligatorios, email y largos. Exige UTF-8 válido, recorta espacios Unicode y rechaza caracteres de control. Rechaza parámetros enviados como array. En el listado, `q` o `pagina` inválidos → 400 | P2.1–P2.5, P6.4–P6.8, H.12–H.14, B.4–B.6; unit Validator |
| Errores | Warnings a excepciones, log con rotación por tamaño, vista 500 genérica sin detalles internos | H.7, H.11–H.14; unit Log |
| Secretos y despliegue | `config.local.php` fuera de Git; `.htaccess` en la raíz que niega todo si se sirve el repo entero | H.15; Apache: `.git/config` 200 → 403 |
| Clickjacking y MIME | `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy` | S.1–S.3 |

Los IDs corresponden a [tests/smoke.sh](../tests/smoke.sh) y a los archivos de [tests/unit](../tests/unit/) y [tests/integration](../tests/integration/).

## Dificultades encontradas

- **Cambiar al mismo estado parecía "id inexistente".** MySQL devuelve 0 filas afectadas si el UPDATE no cambia nada. Activé `ATTR_FOUND_ROWS` para contar filas encontradas ([ADR-0003](adr/0003-pdo-consultas-preparadas.md)).
- **`.htaccess` heredado.** Apache aplica también los `.htaccess` de los directorios padres. El `Require all denied` de la raíz bloqueaba incluso el DocumentRoot bien configurado; lo resolví con `Require all granted` en `public_html/` ([ADR-0007](adr/0007-configuracion-y-secretos.md)).
- **Bypass del límite con acentos.** La revisión encontró que `ádmin@example.com` con la contraseña correcta entraba aunque `admin@example.com` estuviera bloqueado. La collation ignora acentos, pero el contador usaba otro hash. Ahora hay una sola normalización y la búsqueda compara en binario ([ADR-0009](adr/0009-limite-intentos-login.md)).
- **Carrera en el límite de intentos.** Lo medí con 10 logins fallidos simultáneos contra una cuenta con 4 fallos previos: llegaban a verificar la contraseña entre 2 y 6. Con "reservar y contar" (registrar el intento antes de contar) llega exactamente 1, también con 30 simultáneos.
- **Recorte con costo cuadrático (ReDoS).** Sin JIT de PCRE, `^espacios+|espacios+$` tardaba 2,45 s con 20 000 espacios internos y no terminaba con 200 000. Con un recorte en dos pasos (lookbehind al final) y un tope de bytes antes de recortar, 200 000 espacios tardan 0,009 s.

## Límites conocidos

- El límite por cuenta se cuenta por IP + email: rotando direcciones (por ejemplo dentro de un /64 de IPv6) se obtienen contadores nuevos.
- Detrás de un CDN sin `trusted_proxies`, todos los clientes comparten el límite por IP.
- Si los scripts CLI y el servidor web corren con usuarios distintos, conviene crear `storage/logs/` con el usuario web; si no, el log cae a `error_log`.
- La compatibilidad con PHP 8.2 y MySQL 8.0 no se verifica automáticamente: no hay CI y todo se probó con PHP 8.5 y MariaDB 11.8.

## Mejoras pendientes

- Índice `FULLTEXT` para la búsqueda: hoy `LIKE '%…%'` recorre toda la tabla.
- Extraer un `RouteMatcher` y que `dispatch` devuelva un resultado en vez de terminar con `exit`.
- `Database` todavía lee la configuración por su cuenta si nadie la configuró; unificarla en `Config`.
- Agrupar IPv6 por /64 en el límite por IP.
- `logrotate` en un servidor propio, en lugar de la rotación del código.

---

Redactado con asistencia de IA a partir del código y la documentación del repo; revisado por el autor.
