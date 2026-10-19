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
2. Juan lo pega en el agente correspondiente; el hook lo guarda literal.
3. El orquestador verifica el resultado (lectura de diff, `php -l`, pruebas HTTP, DB).
4. Se registra abajo: resultado, verificación, correcciones/descartes y decisión de Juan.

## Registro

| ID | Agente | Objetivo | Resultado | Verificación | Corregido / descartado | Decisión |
|----|--------|----------|-----------|--------------|------------------------|----------|
