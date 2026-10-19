# ADR-0002 — Desarrollo asistido por IA: orquestador + implementador + revisor, con registro de prompts

- **Estado:** Aceptado
- **Fecha:** 2026-10-19

## Contexto

La consigna permite IA, agentes y MCP, y pide entregar los prompts principales explicando
cómo se verificaron las respuestas, qué se descartó o corrigió y qué decidió el estudiante.
Como condición adicional propia, se decidió documentar **todos** los prompts enviados a
los agentes de desarrollo.

## Decisión

- Tres sesiones de Claude Code con roles separados:
  - **Orquestador**: planifica (`doc/PLAN.md`), redacta prompts con ID, verifica e integra.
  - **AGENTE 1 UTN**: implementa.
  - **AGENTE 2 UTN**: revisa adversarialmente y prueba; no corrige código de la app.
- Un hook `UserPromptSubmit` (`tools/registrar-prompt.py`) guarda textualmente cada prompt
  de los agentes en `doc/ia/registro/` (JSONL + Markdown). Solo actúa en sesiones lanzadas
  con la variable `AGENTE`, por eso los prompts del orquestador quedan fuera.
- `doc/ia/PROMPTS.md` es la bitácora curada (resultado, verificación, descartes, decisión);
  `doc/ia/SELECCION-IA.md` es el entregable con la selección representativa.
- Base de conocimiento propia (Cerebro MCP) como fuente de patrones ya validados en
  proyectos anteriores.

## Alternativas consideradas

- **Un solo agente que implementa y se autoevalúa**: menos fricción, pero la revisión de
  quien escribió el código es débil y no deja evidencia de verificación independiente.
- **Copiar prompts a mano**: propenso a omisiones y a "limpiar" el texto a posteriori; el
  hook garantiza registro completo y literal.

## Consecuencias

- Evidencia trazable de cada intervención de IA y de su verificación.
- Ningún hallazgo de IA se acepta sin verificación contra el código o una prueba.
- Riesgo: un secreto pegado por error en un prompt quedaría en un repo público → el hook
  redacta patrones típicos (`password=…`, `token=…`) y las reglas prohíben leer la config
  local con credenciales.
