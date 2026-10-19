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
Auditoría de SEGURIDAD (OWASP Top 10 / WSTG) de todo el proyecto.
Revisá: autenticación y sesiones (app/Core/Auth.php, Session.php, LoginThrottle.php, Controllers/Admin/AuthController.php), autorización de todas las rutas (app/routes.php, Router.php, RouteMatcher.php, Kernel.php), CSRF (Csrf.php), inyección SQL en todas las consultas (app/Models/*, app/Core/Database.php, incluida la búsqueda FULLTEXT/LIKE), XSS en todas las vistas (app/Views/**) y en el helper e(), validación de entrada (Validator.php, Request.php), redirecciones abiertas y cabeceras (Response.php, HttpException.php, bootstrap.php, public_html/.htaccess, public_html/index.php), manejo de errores y fugas de información, secretos y configuración (app/config/*, .htaccess raíz, scripts/*), y el seed (database/*.sql).
