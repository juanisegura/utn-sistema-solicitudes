# Revisiones automáticas locales

Antes de cada push no trivial se corre `scripts/local-review.sh` del workspace del autor:
revisión con un LLM local (Ollama, `qwen2.5-coder:7b`) + chequeos deterministas (secretos,
`php -l`, `bash -n`). Sus hallazgos **no se aceptan sin contrastarlos con el código**: el
modelo local tiende a confabular.

| Revisión | Alcance | Hallazgos | Triage |
|----------|---------|-----------|--------|
| [01](local-review-01.txt) | `fa60cf3...3486bd3` (fases 1–3 + tests) | 6 info, 0 deterministas | **6 descartados**: `.htaccess:22` ya hace `unset X-Powered-By` (lectura invertida); DROP en `schema.sql` es intencional (script idempotente; la guarda de producción llega en A1-03/M2); la fila `<script>` del seed es un dato de prueba a propósito — se almacena literal y se escapa al mostrar (sanitizar al guardar alteraría los datos); `TIPO` está documentado en el docstring; `smoke.sh:7` no es un hallazgo |
| [02](local-review-02.txt) | worktree de A1-03 (auth, panel, correcciones) | 2 "high" del LLM, 0 deterministas | **2 descartados**: `AuthController.php:28` no ejecuta ninguna consulta — la contraseña nunca llega a SQL y el email va por consulta preparada en `Admin::findByEmail` (confabulación); `.htaccess:4` (`Require all denied`) es intencional: es la raíz del repo, no el DocumentRoot (control M1, ADR-0007) |
