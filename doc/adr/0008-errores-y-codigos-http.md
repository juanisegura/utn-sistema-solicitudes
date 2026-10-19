# ADR-0008 — Manejo de errores y códigos HTTP

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-02), revisado e integrado por el orquestador.

## Contexto

La consigna pide manejo de errores que no exponga información interna, y el PLAN fija
códigos HTTP concretos para cada situación (§Códigos HTTP). Hay que distinguir el error del
usuario (corregible y explicado) de la falla interna (genérica para el usuario, detallada
para el desarrollador).

## Decisión

- **Errores internos:** `app/bootstrap.php` registra tres capas.
  - `set_error_handler` convierte warnings/notices en `ErrorException`.
  - `set_exception_handler` atrapa las excepciones no capturadas.
  - `register_shutdown_function` cubre los fatales.
  - Las tres hacen lo mismo: descartan la salida a medio renderizar, escriben una línea en
    `storage/logs/app.log` (fecha, método, path sin query ni cuerpo, clase, mensaje,
    archivo:línea, causa) y responden **500** con vista genérica. `display_errors=0` salvo
    `app.debug=true`; solo en debug la vista 500 agrega clase, mensaje y archivo:línea,
    nunca la configuración.
  - Hasta cargar la config se asume producción.
- **Errores esperados:** códigos explícitos vía `Response::abort()`, con vista propia en
  `app/Views/errors/`, sin datos de la request:
  - 400: parámetro con formato inválido, p. ej. `?estado=` fuera de lista (D-18).
  - 401: sin sesión en POST admin (D-17) y credenciales incorrectas, re-renderizando el
    login con mensaje genérico.
  - 404: ruta o id inexistente; `{id}` solo acepta dígitos.
  - 405: método no permitido, con header `Allow` calculado de la tabla de rutas.
  - 419: CSRF inválido.
  - 422: validación del formulario, re-renderizando con errores por campo y valores
    escapados.
- Sin sesión en GET admin → 302 a `/admin/login`. Alta correcta → 302 (PRG).
- La DB reenvuelve los fallos de conexión con un mensaje genérico (ADR-0003); el detalle
  queda solo en el log.

## Alternativas consideradas

- **Mostrar errores de PHP en desarrollo y "confiar" en apagarlos en producción**: el
  default seguro debe ser apagado; el debug se activa explícitamente en
  `config.local.php`.
- **Una única página de error genérica para todo**: no le dice al usuario si debe corregir
  algo (422), recargar (419) o si la dirección no existe (404), y complica las pruebas
  automáticas, que verifican códigos.
- **Responder 200 con mensaje de error en el cuerpo**: rompe la semántica HTTP, cachés y las
  pruebas (`smoke.sh` verifica códigos).
- **Librería de logging (Monolog)**: requiere Composer (ADR-0001); una línea por error con
  `file_put_contents(LOCK_EX)` alcanza.

## Consecuencias

- Ningún error deja ver SQL, trazas ni rutas absolutas al usuario: lo verifica el chequeo
  de fugas del smoke.
- Cada código tiene una vista y un test asociado.
- El log `storage/logs/app.log` rota por tamaño (`app.log_max_bytes` = 1 MB,
  `app.log_max_archivos` = 5; `App\Core\Log`, con `flock` sobre un archivo de lock para que
  rotación y escritura no se pisen entre requests). En un servidor propio puede
  reemplazarse por `logrotate` (fase de mejoras, M-6).
- `exit` en `redirect/abort` simplifica los controllers, pero hace a esos métodos difíciles
  de testear unitariamente: se aceptó porque la verificación es por HTTP (`smoke.sh`).
