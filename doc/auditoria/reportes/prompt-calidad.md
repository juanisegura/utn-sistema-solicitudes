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
Auditoría de CALIDAD DE CÓDIGO, ARQUITECTURA y BASE DE DATOS de todo el proyecto.
Revisá: separación de responsabilidades (configuración, acceso a datos, lógica, manejo de solicitudes, presentación) frente a lo que pide la consigna; cohesión y acoplamiento de app/Core/*, Controllers, Models y Views; código muerto, duplicado o inconsistente; manejo de errores y excepciones; tipos (strict_types, firmas); diseño del esquema (database/schema.sql: tipos, índices, claves foráneas, collation, ENUM) y de las consultas (eficiencia, índices usados, N+1); mantenibilidad y legibilidad; coherencia entre el código y la documentación (README.md, doc/RESUMEN-TECNICO.md, doc/adr/*); calidad y cobertura de los tests (tests/unit, tests/integration, tests/smoke.sh): qué no está cubierto, tests frágiles o que no prueban lo que dicen.
