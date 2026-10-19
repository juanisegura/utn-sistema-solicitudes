# ADR-0005 — CSRF: token sincronizador por sesión, validado centralmente en el Router

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-02), revisado e integrado por el orquestador.

## Contexto

Todas las operaciones que cambian estado son POST: alta de solicitud, login, logout y
cambio de estado. Un sitio de terceros podría hacer que el navegador de un admin con
sesión envíe un POST (por ejemplo, marcar solicitudes como resueltas). La consigna exige
proteger estas operaciones y el PLAN fija 419 para token inválido.

## Decisión

- Token sincronizador: 32 bytes de `random_bytes`, en hex, guardado en la sesión y emitido
  en cada formulario como `<input type="hidden" name="csrf_token">` (`Csrf::field()`,
  valor escapado).
- La verificación (`Csrf::verify`, `hash_equals`, rechaza ausente/vacío/no-string) ocurre
  en **un solo lugar**: el Router, antes de ejecutar el controller. Va activa **por defecto
  en todo POST**; desactivarla exige escribir `'csrf' => false` en la ruta. Si falla → 419
  con vista propia, sin ejecutar la acción.
- Orden de controles (D-16): ruta → auth → CSRF → controller. Sin sesión de admin se
  responde por auth (302/401), no 419.
- El token rota (`Csrf::rotate()`) al cambiar de privilegio (login/logout), así uno
  obtenido antes deja de servir.
- Defensa en profundidad: cookie `SameSite=Lax` (el navegador no la manda en POST
  cross-site) y CSP `form-action 'self'`.

## Alternativas consideradas

- **Token por formulario o de un solo uso** (patrón de paragonie/anti-csrf): limita el
  replay, pero rompe el doble envío, el botón "atrás" y varias pestañas abiertas, y obliga
  a guardar N tokens. Para un panel de un solo rol es complejidad sin beneficio
  proporcional; la rotación en login cubre el caso principal.
- **Solo SameSite=Lax**: depende del navegador y no cubre clientes viejos ni subdominios
  del mismo sitio. Queda como segunda barrera, no única.
- **Double-submit cookie**: no requiere estado en servidor, pero ya hay sesión, y el
  sincronizador es más simple de razonar y de testear.
- **Verificar en cada controller**: fácil de olvidar en una ruta nueva; centralizarlo es el
  motivo de tener un Router (ADR-0001).

## Consecuencias

- Ninguna ruta POST puede quedar sin CSRF por omisión; lo verifican los casos de CSRF de
  `tests/smoke.sh`.
- Todo formulario debe incluir `Csrf::field()`; si falta, el POST falla con 419 (falla
  segura, visible en desarrollo).
- Si la sesión expira (30 min, D-21), un formulario abierto da 419 y hay que recargar: la
  vista lo explica.
