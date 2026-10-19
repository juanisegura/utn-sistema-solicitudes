## Resumen

Auditoría estática, completa y de solo lectura del proyecto UTN, sin ejecutar la aplicación, modificar archivos, acceder a bases de datos ni realizar conexiones de red.  
Se revisaron la consigna, el plan, las decisiones ADR-0001 a ADR-0011 y el código relevante de aplicación, rutas, modelos, vistas, configuración, scripts y SQL.  
No se identificaron vulnerabilidades explotables con confianza suficiente para reportar.  
Las limitaciones documentadas del proyecto no se consideran hallazgos cuando no implican un bypass de seguridad demostrable.

## Hallazgos

| ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis |
|---|---|---|---|---|---|---|
| — | — | — | No se identificaron vulnerabilidades de seguridad explotables en los cambios revisados. | — | — | Verificado mediante revisión estática |

## Detalle de cada hallazgo

No hay hallazgos que detallar.

## Áreas verificadas OK

- **Autenticación y contraseñas — OK.** `app/Core/Auth.php`, `app/Models/Admin.php`, `app/Controllers/Admin/AuthController.php`: bcrypt con costo fijo, `password_verify`, hash ficticio para emails inexistentes, mensaje uniforme, rechazo de NUL y passwords mayores a 72 bytes, rehash y verificación de cuenta activa.

- **Sesiones y fijación de sesión — OK.** `app/Core/Session.php:38-57,65-82,130-139`: `use_strict_mode`, solo cookies, sin trans-SID, `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS/producción, regeneración al autenticar, destrucción al cerrar sesión y timeout de inactividad.

- **Limitación de intentos de login — OK.** `app/Core/LoginThrottle.php:35-93` y `app/Models/LoginIntento.php:20-88`: límites por IP+email e IP, hash SHA-256 del email, reserva antes del conteo y rechazo previo a consultar/verificar credenciales.

- **Autorización de rutas — OK.** `app/routes.php:13-22`, `app/Core/Router.php:24-49` y `app/Core/Auth.php:84-96`: todas las rutas administrativas tienen `auth => true`; la autenticación se aplica antes del controller.

- **Métodos HTTP y matching de rutas — OK.** `app/Core/RouteMatcher.php:24-48`: 404/405 centralizados, encabezado `Allow`, parámetros `{id}` restringidos a enteros positivos y sin despacho arbitrario de clases o acciones desde la entrada.

- **CSRF — OK.** `app/Core/Csrf.php:15-43` y `app/Core/Router.php:42-49`: token aleatorio por sesión, `hash_equals`, rechazo de valores ausentes/no string, validación central para todos los POST y rotación al autenticar.

- **SQL injection en modelos y Database — OK.** `app/Core/Database.php:47-64`, `app/Models/Solicitud.php:226-251`, `app/Models/Admin.php:57-66`: preparación real de PDO, valores siempre bindeados, identificadores SQL provenientes de constantes, escape de `%`, `_` y `\`, y `AGAINST` parametrizado.

- **FULLTEXT y LIKE — OK.** `app/Models/Solicitud.php:91-170,226-251`: los operadores booleanos no llegan al `AGAINST`; las búsquedas exactas se confirman con `LIKE` y los comodines se escapan.

- **XSS en vistas y helper `e()` — OK.** `app/helpers.php:5-8` y las vistas bajo `app/Views/`: salidas de usuario escapadas con `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`; la única salida HTML no escapada es contenido generado internamente por las vistas.

- **Validación de entrada — OK.** `app/Core/Validator.php:22-119`, `app/Core/Request.php:20-63`: rechazo de arrays en parámetros, UTF-8 inválido, controles, límites de tamaño, formato de email y estados fuera de la lista blanca.

- **Open redirects y cabeceras — OK.** `app/Core/HttpException.php:18-29,56-73` y `app/Core/Response.php:32-57`: redirects restringidos a paths locales y rechazo de `//`, backslashes y caracteres de control; los headers dinámicos utilizados provienen de valores controlados o numéricos.

- **Manejo de errores y fugas — OK.** `app/bootstrap.php:25-126`: `display_errors` desactivado por defecto, errores internos convertidos en respuestas genéricas, logs sin query string ni cuerpo, y detalle visible únicamente con debug configurado explícitamente.

- **Headers de seguridad — OK.** `app/bootstrap.php:110-115` y `public_html/.htaccess:19-24`: CSP restrictiva, `frame-ancestors 'none'`, `form-action 'self'`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y eliminación de `X-Powered-By`.

- **Exposición de archivos y configuración — OK.** `.htaccess:1-4`, `public_html/index.php:7-22`, `.gitignore:1-7`: `public_html` como único árbol público, bloqueo del root mediante `.htaccess`, exclusión de `config.local.php` y logs del repositorio, y bloqueo de scripts PHP bajo el servidor embebido.

- **Scripts administrativos — OK.** `scripts/crear-admin.php:8-14` y `scripts/instalar-db.php:8-14,64-71`: ejecución restringida a CLI y protección explícita del instalador destructivo en `production`.

- **Seed y esquema — OK.** `database/schema.sql` y `database/seed.sql`: el seed usa datos ficticios y un hash de demostración documentado como no productivo; el esquema aplica tipos, enums, claves foráneas e índices coherentes con las validaciones de la aplicación.## Resumen

Auditoría estática de seguridad realizada sobre todo el proyecto, sin modificar archivos, ejecutar la aplicación, acceder a bases de datos ni utilizar red.  
Se revisaron autenticación, sesiones, autorización, CSRF, SQL, XSS, validación, cabeceras, errores, configuración, scripts y seed, junto con la consigna, el plan, las decisiones y los ADR.  
No se identificaron vulnerabilidades explotables con evidencia suficiente para reportar.

## Hallazgos

| ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis |
|---|---|---|---|---|---|---|
| — | — | — | No se identificaron vulnerabilidades de seguridad demostrables. | — | — | Verificado mediante revisión estática |

## Detalle de cada hallazgo

No hay hallazgos que detallar.

## Áreas verificadas OK

- **Autenticación y contraseñas:** `app/Core/Auth.php`, `app/Models/Admin.php` y `app/Controllers/Admin/AuthController.php`. Se verificaron `password_hash`, `password_verify`, hash ficticio para usuarios inexistentes, mensajes uniformes, límites de contraseña, rehash y control de cuentas activas.
- **Sesiones:** `app/Core/Session.php`. Se verificaron modo estricto, cookies `HttpOnly`, `SameSite=Lax`, `Secure` bajo HTTPS/producción, regeneración tras autenticación, destrucción al cerrar sesión y timeout de inactividad.
- **Login throttling:** `app/Core/LoginThrottle.php` y `app/Models/LoginIntento.php`. Se verificaron límites por IP/email e IP, hash del email y reserva previa al conteo.
- **Autorización:** `app/routes.php`, `app/Core/Router.php` y `app/Core/Auth.php`. Todas las rutas administrativas requieren autenticación antes de ejecutar el controlador.
- **Métodos HTTP y rutas:** `app/Core/RouteMatcher.php`. Se verificaron respuestas centralizadas 404/405, encabezado `Allow`, parámetros numéricos restringidos y ausencia de despacho arbitrario desde la entrada.
- **CSRF:** `app/Core/Csrf.php` y `app/Core/Router.php`. Los POST requieren token aleatorio de sesión, comparación segura, validación de tipo y rotación posterior a la autenticación.
- **SQL injection:** `app/Core/Database.php` y `app/Models/*`. Se verificaron PDO con preparaciones reales, valores enlazados, identificadores provenientes de constantes y ausencia de interpolación de entrada.
- **FULLTEXT y LIKE:** `app/Models/Solicitud.php`, líneas 91-170 y 226-251. Se verificó parametrización de `AGAINST` y escape de `%`, `_` y `\` en búsquedas `LIKE`.
- **XSS:** `app/helpers.php` y `app/Views/**`. Las salidas pasan por `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`; no se encontró salida de usuario sin escapar.
- **Validación:** `app/Core/Validator.php` y `app/Core/Request.php`. Se verifican arrays, UTF-8 inválido, caracteres de control, tamaños, emails y listas blancas de estados.
- **Open redirects y cabeceras:** `app/Core/HttpException.php` y `app/Core/Response.php`. Las redirecciones se limitan a paths locales y rechazan `//`, backslashes y controles.
- **Errores y fugas:** `app/bootstrap.php`. Los errores no se muestran por defecto, se devuelven respuestas genéricas y los detalles solo aparecen con debug explícito; el logging evita query strings y cuerpos.
- **Cabeceras de seguridad:** `app/bootstrap.php` y `public_html/.htaccess`. Se verificaron CSP, `frame-ancestors`, `form-action`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y eliminación de `X-Powered-By`.
- **Exposición de archivos:** `.htaccess`, `public_html/index.php` y `.gitignore`. `public_html` es el único árbol público; se bloquean configuración, logs y scripts expuestos.
- **Scripts administrativos:** `scripts/crear-admin.php` y `scripts/instalar-db.php`. Se verificó requisito de ejecución por CLI y protección del instalador destructivo en producción.
- **Seed y esquema:** `database/schema.sql` y `database/seed.sql`. Se verificaron datos ficticios, hash de demostración documentado y restricciones de tipos, claves foráneas e índices.

