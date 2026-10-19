# Uso de IA: selección de prompts, verificación y decisiones

Entregable de la consigna §5. Registro completo y literal de todos los prompts y respuestas
en [`registro/`](registro/); bitácora por tarea en [`PROMPTS.md`](PROMPTS.md); decisiones
numeradas en [`DECISIONES.md`](DECISIONES.md); triage de revisiones automáticas en
[`revisiones/`](revisiones/).

## Herramientas

| Herramienta | Rol |
|-------------|-----|
| Claude Code — sesión orquestadora | Planificó (`doc/PLAN.md`), redactó los prompts, verificó cada entrega, integró ADRs, commiteó y pusheó |
| Claude Code — AGENTE 1 UTN | Implementador. Delegó lo mecánico en subagentes (Haiku: datos del seed; Sonnet: CSS) |
| Claude Code — AGENTE 2 UTN | Revisor/QA: escribió las pruebas antes que el código y revisó cada fase. Usó un subagente Sonnet como segunda opinión |
| Cerebro MCP (base de conocimiento propia) | Patrones ya validados en otros proyectos: capa PDO, sesiones, CSRF, front controller |
| Ollama local (`qwen2.5-coder:7b`) vía `local-review.sh` | Revisión automática antes de cada push |
| `php -l`, `curl`, `tests/smoke.sh`, navegador, Apache local | Verificación |

El método está en [ADR-0002](../adr/0002-metodo-ia-orquestador-agentes.md): quien escribe
el código no es quien lo revisa, y nada de lo que propone una IA entra sin una verificación
independiente.

## Prompts principales

Extractos; el texto completo de cada uno está en `registro/2026-10-19.md` con su ID.

### A2-01 — Pruebas escritas antes que el código
> "La app todavía NO existe. Esta tarea es escribir las pruebas ANTES, contra el contrato
> del PLAN, para que después validen la implementación (no se espera que pasen hoy) [...]
> En tu respuesta: lista de supuestos que tuviste que hacer sobre el contrato [...] y
> cualquier ambigüedad o hueco del PLAN que veas como revisor."

**Por qué así:** si las pruebas se escriben mirando el código, se adaptan a lo que el código
hace. Escribirlas contra el plan las convierte en el contrato.
**Resultado:** 55 casos (hoy 67) y 9 huecos del plan detectados (orden CSRF/auth, qué
devolver ante un filtro inválido, adónde redirigir tras un cambio de estado, etc.), que
resolví en las decisiones D-16 a D-22.

### A1-01 — Capa de datos
> "PDO lazy único: ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false [...] Helpers
> mínimos que fuercen consultas preparadas [...] PROHIBIDO leer o imprimir
> config.local.php [...] Verificación obligatoria (pegá las salidas en tu respuesta) [...]"

**Por qué así:** fijar los controles de seguridad en la instrucción, prohibir explícitamente
el acceso a credenciales y exigir la salida real de la verificación, no un "funciona".

### A2-02 / A2-03 / A2-04 — Revisión adversarial
> "Para cada hallazgo: archivo:línea, escenario concreto de falla, severidad, fix sugerido.
> No reportes estilo ni preferencias; si algo está bien, decilo en una línea."

**Por qué así:** con un formato verificable, cada hallazgo se puede reproducir o descartar.

### A1-03 — Autenticación y panel, más correcciones
> "Auth: attempt(email, password) con mensaje genérico y tiempo constante (password_verify
> contra un hash ficticio si el email no existe [...]) [...] Si algún caso falla por un
> supuesto del test que contradiga el PLAN/decisiones, reportalo en vez de acomodar el
> código."

**Por qué así:** evita que el implementador "haga pasar" las pruebas cambiando el
comportamiento esperado.

## Cómo verifiqué las respuestas

- **Nunca acepté un "PASS" reportado sin repetirlo.** Corrí las pruebas por mi cuenta
  sobre una DB reinstalada (67/67) y repetí con `curl` los casos críticos: 419 con el token
  de otra sesión, escape en el 422, traversal.
- **Reproduje los hallazgos antes de aceptarlos.** Ejemplo: M1 (el Apache local servía
  `.git/config`, código 200) lo reproduje yo; después del fix verifiqué que diera 403.
- **Leí el código de seguridad completo:** Database, Router, Session, Csrf, Auth.
- **Usé `php -l` en todos los archivos y revisión visual en el navegador (escritorio y
  celular).**
- **Contrasté cada hallazgo de la revisión automática local con el código** (ver
  `revisiones/`).

## Sugerencias descartadas o corregidas

| Origen | Sugerencia | Qué hice | Por qué |
|--------|-----------|----------|---------|
| AGENTE 2 (A2-01) | Validar CSRF antes que la autenticación | **Descartada** (D-16) | Una operación de admin anónima debe rechazarse por falta de sesión, con un único motivo |
| AGENTE 2 (A2-03) | No crear sesión en requests anónimas | **Descartada**, documentada como limitación (D-32) | El formulario público necesita sesión para el CSRF; la complejidad no se justifica |
| Revisión local (Ollama) | "Sanitizar" el `<script>` del seed antes de guardarlo | **Descartada** (D-30) | Se guarda lo que envió el usuario y se escapa al mostrar; sanitizar al guardar corrompe datos |
| Revisión local (Ollama) | "Inyección SQL" en `AuthController.php:28` | **Descartada**: confabulación | Esa línea no ejecuta ninguna consulta |
| Revisión local (Ollama) | Quitar `Require all denied` del `.htaccess` raíz | **Descartada** | Es el control que impide exponer `.git/` si se sirve la raíz |
| AGENTE 1 (A1-02), tras consultar Cerebro | Tokens CSRF de un solo uso | **Descartada** por el propio agente, con mi acuerdo (D-25) | Rompe el doble envío, el botón "atrás" y las pestañas múltiples |
| AGENTE 1 (A1-01) | `CHECK (activo IN (0,1))` | **No aplicada** | Fuera de alcance; la app acota el valor |
| AGENTE 1 (A1-03) | Tocar `public_html/.htaccess` (fuera de su reserva) | **Aceptada** (D-33) | Sin `Require all granted`, el deny de la raíz rompía el sitio bien configurado |

## Decisiones que tomé personalmente (Juan)

- Trabajar con tres sesiones (orquestador, implementador y revisor) y registrar **todos**
  los prompts, aunque la consigna solo pida una selección (D-01, D-02).
- MVC liviano sin framework ni Composer, al mismo nivel que mis sitios en producción
  (D-04, ADR-0001).
- Repo público, con un commit y un push por tarea y mi identidad de autor (D-03, D-26).
- Modo de orquestación C: prompts enviados por mensaje entre sesiones, subagentes por
  agente y un solo escritor por archivo (D-08).
- Reiniciar el contexto de AGENTE 2 antes de la revisión de autenticación y panel
  (A2-04); el prompt se reescribió autocontenido, apuntando a la bitácora y las decisiones
  del repo como memoria.

<!-- Se completa al cierre con A2-04 y lo que siga. -->
