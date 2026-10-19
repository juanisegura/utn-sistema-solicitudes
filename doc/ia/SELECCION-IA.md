# Uso de IA: selección de prompts, verificación y decisiones

Entregable de la consigna §5. El registro completo y literal de **todos** los prompts y
respuestas está en [`registro/`](registro/). La bitácora por tarea está en
[`PROMPTS.md`](PROMPTS.md), las decisiones numeradas (D-01…D-53) en
[`DECISIONES.md`](DECISIONES.md) y el triage de las revisiones automáticas en
[`revisiones/`](revisiones/).

## Herramientas

| Herramienta | Rol |
|-------------|-----|
| Claude Code, sesión orquestadora | Planificó (`doc/PLAN.md`), redactó los contratos y los prompts, asignó turnos de DB, verificó cada entrega con corridas propias, integró los ADR, hizo los commits y los push |
| Claude Code, AGENTE 1 UTN | Implementador. Delegó lo mecánico en subagentes: Haiku para los datos del seed y Sonnet para CSS y borradores de documentación. Lo de seguridad lo escribió él |
| Claude Code, AGENTE 2 UTN | Revisor/QA. Escribió las pruebas antes que el código, en cada fase, y revisó adversarialmente. Usó un subagente Sonnet como segunda opinión |
| Cerebro MCP (base de conocimiento propia) | Patrones ya validados en otros proyectos: capa PDO, sesiones, CSRF, front controller, diseño frontend |
| Ollama local (`qwen2.5-coder:7b`) vía `local-review.sh` | Revisión automática antes de los push; sus hallazgos se triagean a mano |
| `php -l`, `curl`, `tests/run-all.sh`, Chrome y Firefox headless, Apache local | Verificación |

**Método** ([ADR-0002](../adr/0002-metodo-ia-orquestador-agentes.md), modo de orquestación
"C"):

- Quien escribe el código no es quien lo revisa.
- Cada archivo tiene un solo escritor.
- Las pruebas se escriben contra un contrato fijado antes de implementar.
- Nada se acepta sin una verificación independiente del orquestador.

## Prompts principales

Son extractos. El texto completo de cada uno está en `registro/2026-10-19.md`, con su ID.

### A2-01 y A2-05: pruebas escritas antes que el código

> "La app todavía NO existe. Esta tarea es escribir las pruebas ANTES, contra el contrato
> del PLAN [...] En tu respuesta: lista de supuestos que tuviste que hacer sobre el contrato
> y cualquier ambigüedad o hueco del PLAN que veas como revisor."

**Por qué así:** si las pruebas se escriben mirando el código, se adaptan a lo que el código
hace. Escritas contra el plan, son el contrato.

**Resultado:**
- A2-01 detectó 9 huecos del plan (D-16 a D-22).
- A2-05, escrito contra el contrato del límite de intentos, encontró un bypass real: un email
  con acento autenticaba al admin aunque estuviera bloqueado (D-41).

### A1-01 / A1-03: implementación con controles fijados en el prompt

> "PDO lazy único [...] EMULATE_PREPARES=false [...] PROHIBIDO leer o imprimir
> config.local.php [...] Si algún caso falla por un supuesto del test que contradiga el
> PLAN/decisiones, reportalo en vez de acomodar el código."

**Por qué así:**
- Los controles de seguridad quedan explícitos.
- Las credenciales quedan fuera del alcance de la IA.
- El implementador no puede "hacer pasar" las pruebas cambiando el comportamiento esperado.

### A2-02, A2-03, A2-04 y A2-08: revisión adversarial

> "Para cada hallazgo: archivo:línea, escenario concreto de falla, severidad, fix sugerido.
> No reportes estilo ni preferencias; si algo está bien, decilo en una línea."

**Por qué así:** con un formato verificable, cada hallazgo se puede reproducir o descartar.

**Resultado:** varios problemas reales que el implementador no había visto:
- el Apache local exponía `.git`;
- UTF-8 inválido producía un error 500;
- el costo de bcrypt dependía de la versión de PHP;
- un byte NUL en la contraseña;
- ReDoS en la validación.

### A2-04: contexto reiniciado, prompt autocontenido

Juan reinició la sesión de AGENTE 2. El prompt se reescribió para que no dependiera de la
memoria: quién es cada sesión, el protocolo y qué leer primero (PLAN, DECISIONES y
bitácora), "para no repetir hallazgos ya corregidos (verificá que de verdad estén
corregidos)".

### A1-09: demostrar, no afirmar

> "Demostrá la exactitud bajo concurrencia: script [...] que dispare N logins fallidos
> simultáneos [...] y muestre cuántos pasaron a verificación — compará con el comportamiento
> anterior."

**Resultado:** el código anterior dejaba verificar entre 2 y 6 intentos de cada 10
simultáneos; el nuevo deja exactamente 1, también con 30.

## Cómo verifiqué las respuestas

- **Nunca acepté un "PASS" informado sin repetirlo.** Corrí la suite completa por mi cuenta
  sobre una DB reinstalada en cada entrega. La corrida oficial final con `run-all.sh` dio
  unit 101, integración 11 y smoke 84, todos OK.
- **Reproduje los hallazgos antes de aceptarlos.** Por ejemplo:
  - M1: `.git/config` respondía 200 en Apache; después del fix, 403.
  - El login con `password%00xyz` entraba.
  - El ReDoS: 20 000 espacios tardaban 2,47 s; después del fix, 200 000 tardan 0,0096 s.
- **Leí todo el código de seguridad** (Database, Router, Session, Csrf, Auth, LoginThrottle)
  y revisé cada cambio que salió de la reserva de archivos acordada.
- **Simulé la CI** en un worktree limpio, sin configuración local, antes del primer push.
- **Revisé las capturas** de cada cambio visual antes de mostrárselas a Juan.
- **Contrasté con el código cada hallazgo de la revisión local con Ollama.** Casi todos eran
  confabulaciones (ver `revisiones/`).
- **Coordiné los turnos de DB.** Dos corridas simultáneas de distintos agentes dieron
  falsos FAIL: se descartaron y desde entonces los turnos los asigna el orquestador (D-42).

## Sugerencias descartadas o corregidas

| Origen | Sugerencia | Qué se hizo | Por qué |
|--------|-----------|-------------|---------|
| AGENTE 2 (A2-01) | Validar CSRF antes que la autenticación | **Descartada** (D-16) | Una operación de admin anónima se rechaza por falta de sesión, con un único motivo |
| AGENTE 2 (A2-03) | No crear sesión en requests anónimas | Primero **descartada** (D-32). Después se implementó en la fase de mejoras, a pedido de Juan (M-5) | El costo era bajo una vez que el Router abre la sesión |
| AGENTE 1 (A1-06) | Las variantes con acento del email eran una "limitación aceptable" | **Corregida** (D-41) | A2-05 demostró que era un bypass real del límite |
| AGENTE 1 (A1-02), tras consultar Cerebro | Tokens CSRF de un solo uso | **Descartada**, con acuerdo del orquestador (D-25) | Rompe el doble envío, el botón "atrás" y las pestañas múltiples |
| AGENTE 2 (A2-03) | Fijar en el contrato todos los bordes de `X-Forwarded-For` | **Aceptada** como API y reglas (D-49) | Sin un contrato fijo no se puede testear antes de implementar |
| AGENTE 2 (A2-07) | Refactor del Router a `RouteMatcher` para testear sin Reflection | **No aplicada**, queda como mejora | Fuera del alcance; los tests ya cubren el comportamiento |
| Revisión local (Ollama) | "Sanitizar" el `<script>` del seed al guardarlo | **Descartada** (D-30) | Se guarda lo enviado y se escapa al mostrar |
| Revisión local (Ollama) | "Inyección SQL" en `AuthController.php:28` | **Descartada**: confabulación | Esa línea no ejecuta ninguna consulta |
| Revisión local (Ollama) | Quitar `Require all denied` del `.htaccess` raíz | **Descartada** | Es el control que impide exponer `.git/` |
| AGENTE 1 (A1-03) | Tocar `public_html/.htaccess`, fuera de su reserva | **Aceptada** (D-33) | Sin `Require all granted`, el deny de la raíz rompía el sitio |
| AGENTE 1 (A1-05) | Fijar también el algoritmo de hash, no solo el costo | **Aceptada** (D-37) | Un cambio de `PASSWORD_DEFAULT` reabriría la fuga por tiempo |

## Decisiones que tomé personalmente (Juan)

- **Organización del trabajo con IA:**
  - Tres sesiones: orquestador, implementador y revisor.
  - Registrar **todos** los prompts, aunque la consigna pida una selección (D-01, D-02).
  - Modo de orquestación C: prompts enviados por mensaje entre sesiones, subagentes por
    agente y un solo escritor por archivo (D-08).
  - Reiniciar el contexto de AGENTE 2 antes de la revisión de autenticación (A2-04).
- **Arquitectura:** MVC liviano sin framework ni Composer, al nivel de mis sitios en
  producción (D-04, ADR-0001).
- **Repositorio:** público, con un commit y un push por tarea y mi identidad de autor
  (D-03, D-26).
- **Alcance de la funcionalidad:**
  - Implementar el límite de intentos de login (D-38).
  - Implementar las 8 mejoras que habían quedado pendientes (D-45).
- **Diseño visual:**
  - Paleta clara, blur, sin cajas flotantes y aprovechando el ancho en desktop (D-40).
  - CSS separado por responsabilidad (D-43).
  - Aprobé el resultado visual y de accesibilidad sin una revisión extra de AGENTE 2 (D-44).
- **Pruebas:** sin CI en GitHub; las pruebas se corren localmente (D-51).
