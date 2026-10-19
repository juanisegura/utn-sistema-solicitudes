# Auditoría final del proyecto

- **Fecha:** 2026-10-19
- **Objeto:** el repositorio completo en el commit `97963c1` (export limpio con `git archive`:
  sin `config.local.php` ni datos locales).
- **Modalidad:** solo lectura. Ningún auditor ejecutó la app, tocó la DB ni modificó
  archivos.

## Auditores

| Auditor | Herramienta | Foco | Reporte crudo |
|---------|-------------|------|---------------|
| C-1 | GitHub Copilot CLI 1.0.91 (modelo `auto`) | Seguridad (OWASP Top 10 / WSTG) | [copilot-seguridad.md](reportes/copilot-seguridad.md) |
| C-2 | GitHub Copilot CLI 1.0.91 | Calidad, arquitectura, base de datos, tests | [copilot-calidad.md](reportes/copilot-calidad.md) |
| C-3 | GitHub Copilot CLI 1.0.91 | Cumplimiento de la consigna y entregables | [copilot-consigna.md](reportes/copilot-consigna.md) |
| S-A | Subagente Claude (Sonnet, perfil security-auditor) | Seguridad, segunda opinión independiente | [subagente-claude-seguridad.md](reportes/subagente-claude-seguridad.md) |
| S-B | Subagente Claude (Sonnet, perfil code-reviewer) | Calidad y arquitectura, segunda opinión independiente | [subagente-claude-calidad.md](reportes/subagente-claude-calidad.md) |

**Configuración común de los cinco auditores:**

- **Prompt:** el mismo para todos ([prompt-seguridad](reportes/prompt-seguridad.md),
  [prompt-calidad](reportes/prompt-calidad.md), [prompt-consigna](reportes/prompt-consigna.md)).
- **Exigencias del prompt:**
  - citar archivo:línea y el código;
  - leer las guardas y los llamadores antes de afirmar un problema;
  - aplicar un criterio de severidad explícito;
  - no repetir limitaciones ya aceptadas en las decisiones o los ADR.
- **Permisos de Copilot:** escritura denegada; solo comandos de lectura (`rg`, `cat`,
  `sed`, `ls`, `find`, `wc`, `grep`).
- **Registro:** los cinco prompts están en `doc/ia/registro/`.

**Cómo se verificaron los hallazgos:**

- **Triage:** el orquestador contrastó cada hallazgo con el código. Los concretos se
  reprodujeron: headers por HTTP, `grep` de usos, lectura de las líneas citadas y la zona
  horaria de la DB contra la de PHP.
- **Lo que no se sostuvo:** se marca como descartado.
- **Salida de Copilot:** imprimió su respuesta dos veces en cada reporte; el contenido de
  ambas copias es equivalente.

## Resultado

- **Seguridad:** ningún hallazgo de severidad Alta ni Media en ninguna de las cinco
  revisiones. C-1 no encontró vulnerabilidades; S-A, 5 Bajas y 1 Info de defensa en
  profundidad.
- **Calidad:** ningún defecto Alto ni Medio confirmado. Los hallazgos son de cohesión,
  código muerto, cobertura de tests y documentación.
- **Consigna:** todos los requisitos funcionales, técnicos, las 7 pruebas mínimas y los
  entregables figuran como cumplidos (C-3). Las únicas observaciones son inconsistencias
  documentales.
- **Falsos positivos:** uno (AU-0, abajo), descartado tras verificarlo por HTTP.

## Hallazgos consolidados

Severidad final: la asignada por el orquestador tras verificar, no la del auditor.

| ID | Sev. | Hallazgo | Fuentes | Verificación del orquestador | Decisión propuesta |
|----|------|----------|---------|------------------------------|--------------------|
| AU-0 | — | "Falta `Cache-Control: no-store` en respuestas autenticadas" | C-2 (Media) | **Falso positivo**: `GET /` y `/admin/login` devuelven `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`, que PHP envía al iniciar la sesión (`session.cache_limiter=nocache`) | Descartado como hallazgo; opcional: hacerlo explícito para no depender del `php.ini` |
| AU-1 | Baja | `debug=true` posible con `env=production`; la plantilla de config trae `debug=true` | C-2, S-A (S-03) | Confirmado: `config.php` no impone la combinación; `bootstrap.php:119` y la vista 500 exponen el detalle | Corregir: forzar `debug=false` en producción |
| AU-2 | Baja | `instalar-db --force` sin `--sin-datos` carga el seed (admin con contraseña pública) en producción | S-A (S-02) | Confirmado: `instalar-db.php:57,65` | Corregir: en producción el seed no se carga nunca |
| AU-3 | Baja | `session_timeout` (y otros) sin validar; el README dice que una config inválida se rechaza | S-B (Q-04) | Confirmado: `bootstrap.php:126` castea a int; con 0 la sesión se vacía en cada request | Corregir: validación de toda la config en un lugar |
| AU-4 | Baja | Zona horaria de la sesión SQL sin fijar (D-24) | S-B (Q-05) | Hipótesis confirmada como riesgo: en local DB y PHP coinciden (`SYSTEM`, -03), en un hosting con la DB en UTC diferirían | Corregir: `SET time_zone` al conectar |
| AU-5 | Baja | Código muerto (`Solicitud::all`, `Session::flashInput/old`, `Request::query`, getters de `HttpException`) | S-B (Q-03) | Confirmado por `grep`: 0 usos en `app/` | Corregir: borrar y actualizar ADR-0006 |
| AU-6 | Baja | Tests débiles: el historial no verifica el orden ni el render real (Q-09); los contadores por estado solo verifican el total (C-2); `PENDIENTE` no hace fallar la corrida (Q-08); andamiaje obsoleto en RouterTest/ResponseTest/adaptador (Q-10) | C-2, S-B | Confirmado por lectura (`HistorialTransaccionTest.php:169-186`, `runner.php:266`) | Corregir |
| AU-7 | Baja | Huecos de cobertura: `LoginThrottle::configure` con rangos inválidos, ENUM de `solicitud_eventos`, rehash, `Config`, `View` | S-B (Q-11) | Confirmado | Corregir lo barato: `LoginThrottle::configure` y los tres ENUM |
| AU-8 | Baja | Formulario público sin límite de frecuencia (relleno de la tabla por script) | S-A (S-01) | Confirmado por lectura: solo CSRF, que se obtiene con un GET | A decidir: reutilizar el patrón del límite de login (por IP) o documentarlo como límite conocido |
| AU-9 | Baja | No se registran eventos de seguridad (login ok/fail, 429, 419) | S-A (S-05) | Confirmado | A decidir: una línea de log sin PII por evento, o documentarlo |
| AU-10 | Baja | La app web usa el mismo usuario de DB (con DDL) que el instalador | S-A (S-04) | Confirmado | Documentar como recomendación de despliegue (dos usuarios) |
| AU-11 | Baja | Eficiencia: listado "Todas" sin índice para el `ORDER BY`; `purgar()` sin índice; conteos duplicados | S-B (Q-06) | Confirmado por lectura del esquema | Corregir lo barato: índices `(created_at)` |
| AU-12 | Baja | Cohesión: búsqueda dentro de `Solicitud` (Q-01), lógica de IP dentro de `Request` (Q-02), transacción con PDO crudo (Q-07) | S-B | Confirmado; no afecta el funcionamiento | A decidir: refactor opcional |
| AU-13 | Info | Sin HSTS ni vencimiento absoluto de sesión | S-A (S-06) | Confirmado; HSTS corresponde al servidor HTTPS | Documentar |
| AU-14 | Info | Documentación desactualizada o contradictoria | C-3, C-2, S-B | Confirmado. Detalle abajo | Corregir |

**Detalle de AU-14:**

- El README cita ADR 0001–0010 y existe el 0011.
- `SELECCION-IA.md` dice D-53 y 101/11/84.
- D-20 y el PLAN dicen "sin paginación".
- El ADR-0009 conserva dos frases superadas.
- La numeración de DECISIONES tiene un salto.
- Hay docblocks duplicados.

## Lo que todos verificaron OK

Coinciden en esto las cinco revisiones:

- inyección SQL (consultas preparadas reales, identificadores constantes, `AGAINST` y
  `ESCAPE` bindeados);
- XSS (`e()` en toda salida, CSP sin inline);
- CSRF (token por sesión, un único punto de verificación);
- autorización (todas las rutas `/admin` con auth antes del controller, re-verificación en
  DB);
- autenticación (bcrypt costo fijo, hash ficticio, anti-enumeración, NUL y 72 bytes);
- sesiones (strict mode, cookie endurecida, regeneración, timeout);
- límite de intentos ("reservar y contar");
- redirecciones solo a paths locales;
- manejo de errores sin fugas;
- secretos fuera del repo;
- `.htaccess` de la raíz;
- seed con datos ficticios;
- separación de capas exigida por la consigna.
