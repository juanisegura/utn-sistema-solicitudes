Contexto: trabajo práctico universitario (UTN) "Sistema de gestión de solicitudes" en PHP 8.2+ vanilla y MySQL/MariaDB, sin frameworks ni Composer por decisión explícita (no lo propongas como solución). La consigna está en CONSIGNA.txt. Decisiones de arquitectura en doc/adr/ (ADR-0001..0011), decisiones numeradas en doc/ia/DECISIONES.md, plan y contratos en doc/PLAN.md, pruebas en tests/ (unit, integration, smoke.sh) y doc/pruebas/PRUEBAS.md.

Reglas de la auditoría (obligatorias):
- Solo lectura. No modifiques ni crees archivos. No ejecutes la app ni toques bases de datos ni red.
- Cada hallazgo DEBE citar archivo:línea y el fragmento de código relevante. Antes de afirmar un problema, leé todas las guardas y todos los llamadores relacionados (validación previa, router, middleware, controller, modelo) y explicá por qué no lo cubren. Si no podés demostrarlo con el código, marcalo como "Hipótesis" y no le asignes severidad mayor que Baja.
- Severidad: Alta = explotable por un atacante remoto sin condiciones irreales y con impacto real (acceso no autorizado, inyección, robo de sesión, pérdida de datos). Media = requiere condiciones plausibles o tiene impacto acotado. Baja = robustez/defensa en profundidad. Info = estilo, documentación o mejora. No infles la severidad.
- No reportes como hallazgo nuevo una limitación que ya figure como aceptada en doc/ia/DECISIONES.md o en un ADR, salvo que argumentes con el código por qué la decisión es incorrecta.
- Para cada área revisada sin problemas, una línea "OK: …" con lo que verificaste.

Formato de salida (Markdown):
## Resumen (3-5 líneas)
## Hallazgos (tabla: ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis)
## Detalle de cada hallazgo (con el fragmento de código citado)
## Áreas verificadas OK
Auditoría de CUMPLIMIENTO DE LA CONSIGNA y de los ENTREGABLES.
Leé CONSIGNA.txt completa y verificá punto por punto, citando el archivo/línea que lo cumple (o que falta): cada requisito funcional obligatorio (interfaz pública, panel administrativo, persistencia), cada requisito técnico, cada una de las 7 pruebas mínimas (¿hay evidencia concreta en doc/pruebas/PRUEBAS.md y en tests/ que las cubra?), la sección de herramientas de IA (doc/ia/SELECCION-IA.md: ¿hay selección de prompts, cómo se verificaron, qué se descartó/corrigió y qué decidió el estudiante?), cada entregable (código, SQL, README con requisitos/configuración/pasos, resumen técnico de 1 a 3 páginas en doc/RESUMEN-TECNICO.md, prompts, evidencia de pruebas) y los criterios de revisión técnica. Señalá inconsistencias entre documentos (números, rutas, links, afirmaciones que el código no respalda) y cualquier cosa que un evaluador podría objetar. Usá una tabla "Requisito | Estado (Cumple/Parcial/Falta) | Evidencia (archivo:línea) | Observación".
