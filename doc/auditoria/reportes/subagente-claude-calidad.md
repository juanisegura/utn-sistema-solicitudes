# Auditoría de calidad y arquitectura — subagente Claude (Sonnet, perfil code-reviewer)

## Resumen

Revisé de punta a punta `app/` (Core, Controllers, Models, Views, config, bootstrap), `database/schema.sql`, `seed.sql`, `scripts/`, los tests (runner, adaptador `contrato.php`, unit e integration), `run-all.sh`, el README, el RESUMEN-TECNICO, los ADR 0001/0006/0009/0011, `DECISIONES.md` y la evidencia de la corrida. No ejecuté nada.

La arquitectura cumple lo que pide la consigna: configuración, acceso a datos, lógica, manejo de solicitudes y presentación están separados; el SQL está solo en Models y todo va parametrizado. **No encontré defectos Altos ni Medios.** Hay 11 hallazgos Bajos y 1 grupo Info. Los más relevantes: la cohesión de `Solicitud.php` y `Request.php`, código muerto, validación de config inconsistente con lo que dice el README, y algunos tests que no prueban lo que dicen o que quedan "verdes" si falta una pieza.

## Hallazgos

| ID | Sev. | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Estado |
|---|---|---|---|---|---|---|
| Q-01 | Baja | `app/Models/Solicitud.php:15-176` | El Model mezcla acceso a datos con un motor de búsqueda (stopwords, plegado, tokenización, escape de LIKE): ~120 de 255 líneas sin SQL | Cambiar la política de búsqueda obliga a tocar el modelo de persistencia; las constantes copian parámetros de InnoDB sin chequeo en runtime | Extraer `App\Models\Busqueda` o `App\Core\FulltextQuery`; opcional: validar `innodb_ft_min_token_size` al arrancar | Verificado |
| Q-02 | Baja | `app/Core/Request.php:84-215` | `Request` incluye ~130 líneas de lógica de IP/CIDR/proxies y estado estático de config | Un cambio en `trusted_proxies` toca la clase que lee POST y query | Extraer `ClientIp` o `TrustedProxies` | Verificado |
| Q-03 | Baja | `Solicitud.php:43-46`; `Session.php:14,20,60-61,120-129`; `Request.php:60-63`; `HttpException.php:35-44` | Código muerto: `Solicitud::all()`, `Session::flashInput/old/OLD/$old`, `Request::query()`, `HttpException::getStatus/getHeaders` sin usos en `app/` | `Session::start` lee y borra `_old` en cada request sin que nadie lo use; ADR-0006 sigue citando `Solicitud::all(?Estado)` | Borrar lo muerto y actualizar el ADR | Verificado |
| Q-04 | Baja | `bootstrap.php:123-126`; `SolicitudAdminController.php:161`; `Log.php:21-22`; README:102-103 | El README dice que "una configuración inválida se rechaza al arrancar", pero solo es cierto para `login_*` y `trusted_proxies` | `session_timeout` se castea a int sin validar: con 0 o negativo, la sesión se vacía en cada request; `por_pagina` y `log_max_*` se corrigen en silencio | Validar todo en un solo lugar o ajustar el README | Verificado (el disparo requiere config errónea) |
| Q-05 | Baja | `Database.php:109`; `bootstrap.php:31`; `SolicitudAdminController.php:215-218` | D-24 (hora local en log y panel): el log usa la zona de PHP, el panel muestra `created_at` del reloj de la DB y nadie fija `time_zone` en la sesión SQL | Con un MySQL en UTC (frecuente en hosting), el panel muestra una hora distinta a la del log | `SET time_zone = '-03:00'` al conectar, o convertir al mostrar | Hipótesis (depende del servidor) |
| Q-06 | Baja | `SolicitudAdminController.php:32,38`; `schema.sql:39,46-54`; `LoginIntento.php:84-87` | Eficiencia: (a) `contar()` y `countByEstado()` repiten trabajo; (b) el listado "Todas" ordena por `created_at DESC, id DESC` sin índice utilizable (filesort); (c) `purgar()` borra por `created_at` sin índice propio | Volumen del TP chico, sin impacto hoy; crece linealmente | Índice `(created_at)` en `solicitudes` y `login_intentos`; omitir `contar()` sin filtro de estado | Verificado |
| Q-07 | Info | `Solicitud.php:193-215` | El modelo toma el PDO crudo para `beginTransaction/commit/rollBack`; correcto, pero la abstracción de `Database` se filtra | — | `Database::transaction(callable)` | Verificado |
| Q-08 | Baja | `tests/lib/runner.php:266`; `contrato.php:37,53,81`; `HistorialTransaccionTest.php:16-18` | `PENDIENTE` no hace fallar la corrida (solo FAIL, o SKIP con `SIN_SKIP=1`) | Si `solicitud_eventos` desaparece o se renombra una clase, esos tests pasan a PEND y `run-all` sale con 0 | Con `SIN_SKIP=1`, contar PEND como FAIL, o quitar los `pending()` | Verificado |
| Q-09 | Baja | `HistorialTransaccionTest.php:169-186` | El test "muestra Sin cambios… y después los cambios" no prueba lo que dice: busca "Resuelta" y "En proceso" después de "Historial", pero los `<option>` del select siempre contienen esas palabras; `$posResuelta`/`$posProceso` no se comparan. El orden "más reciente primero" no lo cubre nadie | Rompés el `ORDER BY` o no renderizás los eventos y el test sigue en verde | Contar `class="timeline-item` tras dos cambios y comparar posiciones dentro de `<ol class="timeline">` | Verificado |
| Q-10 | Baja | `RouterTest.php:22-64,99-140`; `ResponseTest.php:21-37`; `contrato.php`; `tests/README.md:230` | Andamiaje obsoleto: ~60 líneas bajo `if (!hayRouteMatcher())`/`if (!hayHttpException())` que nunca se ejecutan; nombres alternativos y `RedirectException` inexistente en el adaptador; README describe tests por Reflection/`exit` | Cuesta leer la suite y quita confianza | Borrar los bloques muertos, simplificar el adaptador y actualizar el README | Verificado |
| Q-11 | Baja | `tests/` | Huecos de cobertura: sin tests directos de `LoginThrottle::configure` (rangos de D-53), `reservar/exito/fallo`, rehash en `Auth::attempt`, `Config`, `View`, `instalar-db::sentencias()`; `EstadoTest` verifica el ENUM de `solicitudes.estado` pero no los dos de `solicitud_eventos` | Un cambio en esos ENUM desincroniza sin alarma; un límite mal configurado solo se detecta en producción | Tests unitarios chicos para `LoginThrottle::configure` y para los tres ENUM | Verificado |
| Q-12 | Info | varios | Documentación y estilo menores | — | — | Verificado |

## Q-12 (Info), puntos sueltos

- README:215 dice "ADR 0001–0010" y existe el ADR-0011.
- ADR-0009:36-37 afirma "IP = REMOTE_ADDR; no se confía en X-Forwarded-For" y las líneas 73-82 describen `trusted_proxies`; la primera quedó superada y no está marcada.
- ADR-0009:17 dice "se registran solo los intentos fallidos"; el código registra cada intento y borra el bloqueado o el exitoso.
- `AuthController.php:73-74`: dos docblocks seguidos.
- `Response.php:6-7`: líneas en blanco duplicadas, la clase no tiene docblock.
- `DECISIONES.md` salta de D-60 a D-62.
- `RESUMEN-TECNICO.md` (~140 líneas) podría exceder "idealmente 1 a 3 páginas".
- La lista de tablas está en tres lugares (DROP de `schema.sql`, `TABLAS` en `instalar-db.php`, README).

## Áreas verificadas OK

- Separación de capas: controllers finos, SQL solo en Models, views sin lógica; cumple la consigna §3.
- Consultas parametrizadas: `prepare` + `bindValue` con `EMULATE_PREPARES=false`; columnas y tablas constantes; `ESCAPE` bindeado.
- `strict_types=1` en todo `app/` y `scripts/`; firmas consistentes; enum `Estado` como lista blanca con test contra el ENUM de `solicitudes`.
- Manejo de errores: handlers globales, 500 genérica fuera de debug, `HttpException` validada en el constructor, redirects solo a paths locales, log con rotación y `flock`.
- Transacción con `FOR UPDATE` en `updateEstado` y atomicidad del límite de login, con tests de concurrencia (8 workers).
- Esquema: FKs `CASCADE`/`RESTRICT` justificadas, collation uniforme, índices para el historial y el límite de login, UNIQUE de `admins.email` + comparación binaria razonados (D-41).
- Seed reproducible con datos ficticios; `instalar-db.php` se niega a correr en producción sin `--force`.
- Coherencia código-documentación en lo central: rutas, tabla de config y conteos 137/27/84 coinciden con el código y la evidencia.
- Auth y sesión: hash ficticio con el mismo algoritmo y costo, regeneración de ID y rotación de CSRF, `hash_equals`, orden ruta → auth → CSRF.
- Tests con buena cobertura en Validator, RouteMatcher, `Request::ip`, Log, Csrf, Session y timeout; el runner detecta `exit`; las 7 pruebas mínimas están mapeadas en el smoke.
