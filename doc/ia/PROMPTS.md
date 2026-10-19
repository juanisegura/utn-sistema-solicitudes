# Bitácora de prompts e IA

Registro curado de cada prompt enviado a los agentes, con su resultado y la verificación
hecha. El texto literal de cada prompt está en [`registro/`](registro/) (capturado
automáticamente por un hook de Claude Code, ver `tools/registrar-prompt.py`).

## Herramientas utilizadas

| Herramienta | Uso |
|-------------|-----|
| Claude Code (sesión orquestadora) | Planificación, redacción de prompts, verificación e integración. Sus prompts no se registran (decisión de método). |
| Claude Code — AGENTE 1 UTN | Implementación |
| Claude Code — AGENTE 2 UTN | Revisión adversarial y pruebas |
| Cerebro MCP (base de conocimiento propia) | Patrones documentados de proyectos PHP previos (front controller, sesiones, CSRF, PDO) |
| `php -l`, `curl`, navegador, `scripts/local-review.sh` (LLM local vía Ollama) | Verificación |

## Método

1. El orquestador redacta el prompt con un ID (`A1-nn` / `A2-nn`).
2. El orquestador lo guarda literal en `registro/` y lo envía al agente por mensaje entre
   sesiones (Modo C, D-08). Los prompts tipeados a mano en un agente los captura el hook.
   La respuesta del agente también se guarda en `registro/` (`tipo=respuesta`).
3. El orquestador verifica el resultado (lectura de diff, `php -l`, pruebas HTTP, DB).
4. Se registra abajo: resultado, verificación, correcciones/descartes y decisión de Juan.

## Registro

| ID | Agente | Objetivo | Resultado | Verificación | Corregido / descartado | Decisión |
|----|--------|----------|-----------|--------------|------------------------|----------|
| A1-01 | AGENTE 1 | Fase 1: schema, seed, config, Database, modelos, scripts CLI, texto ADR-0003/0006 | 13 archivos; seed delegado a Haiku (datos ficticios), resto escrito por el agente (seguridad) | Orq.: `php -l` de todo, `instalar-db.php` reproducible (1 admin / 8 solicitudes 3-3-2), lectura de Database/Models/schema: todo SQL preparado, hash bcrypt real en seed, sin credenciales en mensajes | Aceptadas 6 decisiones extra del agente (ATTR_FOUND_ROWS, hash dentro de Admin::create, error de conexión genérico, límite 72 bytes, etc.). No se agrega `CHECK (activo IN (0,1))` (TINYINT ya acotado por la app; fuera de alcance) | Autoloader queda en `app/autoload.php`; PLAN ajustado. 404 también en POST de estado |
| A2-01 | AGENTE 2 | Pruebas mínimas escritas antes del código, contra el contrato del PLAN | `tests/smoke.sh` (55 casos), `tests/README.md`, `doc/pruebas/PRUEBAS.md` | Agente: mock PHP del contrato 55/55 y 5 mutaciones detectadas. Orq.: `bash -n`, lectura del bloque de contrato, sin acceso a DB ni config | Supuestos 1–9 coinciden con el contrato del PLAN. Huecos a–i resueltos en D-16..D-22; propuesta "CSRF antes que auth" **descartada** (D-16) | Ajustar el smoke a las decisiones (A2-02) |
| A1-02 + A1-02b | AGENTE 1 | Fases 2+3: núcleo (router, sesión, CSRF, validación, vistas, errores), front controller y formulario público; addendum con D-16..D-21 | 25+ archivos; CSS delegado a Sonnet, el resto escrito por el agente | Agente: curl de todos los códigos, 500 simulado, revisión en Chrome, smoke 19/19 casos públicos. Orq.: curl independiente (302 válido, 419 sin token y con token de otra sesión, 422 con escape de `<b>`, 404 en `/../app/config`), lectura de Router/Session/Csrf | Aceptadas: CSRF por defecto en todo POST, errores→excepción, log sin query. Agente consultó Cerebro y **descartó** tokens CSRF one-time (Paragonie) con justificación (ADR-0005) | Timezone de la app: America/Argentina/Buenos_Aires (D-24) |
| A2-02 | AGENTE 2 | Ajustar smoke a D-16..D-21 + revisión adversarial de la fase 1 | Smoke 64 casos (nuevos: orden auth→CSRF, filtro 400, arrays, flash, DESC). Revisión: 0 inyección/fuga; M1–M3 medios, B1–B5 bajos, I1–I2 info, con subagente Sonnet como 2.ª opinión | Orq.: `bash -n`; **M1 reproducido** (`http://localhost/utn-sistema-solicitudes/.git/config` → 200 en Apache local). Smoke contra :8080: 19 PASS públicos, admin pendiente de A1-03 | Todos los hallazgos aceptados para corregir en A1-03 salvo B4 (se documenta: el SQL no viene del usuario) y B5 (cosmético, se documenta) | Ver D-26..D-28 |
| A2-03 | AGENTE 2 | Revisión adversarial de fases 2+3 sobre `c27205f` | 0 altos; 1 medio (UTF-8 inválido → 500 y valor del usuario en el log), 5 bajos, 5 info; 3 casos nuevos al smoke (67) | Agente: snapshot por `git archive`, subagente Sonnet en :8099 sin DB, reproducción en :8080. Orq.: lectura de los hallazgos contra el código citado; `bash -n` | Aceptados 1–5 y 7–10 → A1-03d. **Descartado** 6 (sesión en cada request anónima: el form la necesita para CSRF; queda como limitación). 11 ya cubierto (D-24) | D-31, D-32 |
| A1-03c / A1-03d | AGENTE 1 | Addendums: extender reserva a Validator/index.php; aplicar hallazgos de A2-03 | (en curso) | — | — | — |
