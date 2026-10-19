# ADR-0001 — MVC ligero sin framework, front controller y separación app/ vs public_html/

- **Estado:** Aceptado
- **Fecha:** 2026-10-19

## Contexto

La consigna pide organizar claramente configuración, acceso a datos, lógica, manejo de
solicitudes y presentación, sin exigir MVC ni frameworks, con soluciones proporcionadas a
un proyecto de dos días. Se evalúan organización, seguridad y mantenibilidad.

## Decisión

- `public_html/` es el único DocumentRoot: contiene el front controller (`index.php`),
  `.htaccess` y assets. Todo el código PHP restante vive en `app/`, inaccesible por URL.
- Un front controller resuelve cada request contra una tabla de rutas (`app/routes.php`)
  y despacha a Controllers finos; el SQL vive solo en Models; las Views solo presentan.
- Infraestructura transversal (DB, sesión, CSRF, auth, validación, vistas) en `app/Core/`.
- Clases con namespaces `App\…` y un autoloader propio de pocas líneas; **sin Composer**.

Es el mismo patrón ("Patrón A: front controller + tabla de rutas") que usan sitios en
producción del autor (tuportafolio.com.ar, intea.com.ar), documentado en su base de
conocimiento.

## Alternativas consideradas

- **Páginas PHP sueltas en `public_html/`** (una por pantalla): más simple, pero repite
  bootstrap/guardas en cada archivo, expone nombres de scripts y hace fácil olvidar un
  `requireAdmin()` en una página nueva. La tabla de rutas centraliza método HTTP
  permitido y middleware.
- **Laravel / Slim**: resuelven routing, CSRF y sesiones, pero ocultan justamente lo que
  la consigna quiere evaluar y agregan dependencias desproporcionadas para 9 rutas.
- **Composer solo para autoload PSR-4**: un paso de instalación más para el evaluador sin
  ninguna dependencia que lo justifique; el autoloader propio cumple la misma función.

## Consecuencias

- Una regla de seguridad (CSRF, auth, 405) se aplica en un único punto.
- El evaluador corre el proyecto con `php -S` sin instalar nada más.
- Router propio con soporte mínimo de parámetros (`{id}` numérico): si el proyecto
  creciera a decenas de rutas, evaluar FastRoute.
