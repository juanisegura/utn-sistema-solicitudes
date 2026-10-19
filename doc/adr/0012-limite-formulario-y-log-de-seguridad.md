# ADR-0012 — Límite del formulario público y log de seguridad

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-16), revisado e integrado por el orquestador.

## Contexto

La auditoría final dejó dos huecos (AU-8, AU-9): el formulario público no tenía límite de
frecuencia, así que un bot podía llenar la base y el panel de solicitudes basura, y los
eventos de seguridad (logins fallidos, CSRF inválidos, bloqueos) no quedaban registrados en
ningún lado para investigar un abuso. Juan decidió cerrarlos (D-63, PLAN §7, F-1 y F-2).

## Decisión

- **Límite por IP** en una tabla `envios_publicos` (ip, acción, fecha), con el mismo patrón
  "reservar y contar" del login (ADR-0009, D-46): el envío se registra antes de procesarlo y
  se cuenta la ventana incluyendo la fila propia, lo que lo hace exacto bajo concurrencia. Si
  supera el límite, se borra la fila propia y se responde 429 + `Retry-After` con el
  formulario y sus valores (escapados). Cuenta todo POST que pasó el CSRF, válido o con
  errores, porque ambos consumen recursos.
- Límites configurables y validados al arrancar (`app.form_max_por_ip` = 5,
  `app.form_ventana_seg` = 600). La IP sale de `Request::ip()`, que respeta
  `trusted_proxies`. La clase `FormThrottle` es genérica por acción; la consulta de
  seguimiento (F-3) la reutiliza contando solo los intentos fallidos.
- **Honeypot:** un campo `sitio_web` fuera de la pantalla (posición absoluta, sin
  `display:none`), con `aria-hidden` en su contenedor y `tabindex="-1"`. Si llega con
  contenido, no se guarda nada y se responde como un éxito: el POST devuelve exactamente la
  misma respuesta HTTP (302 a `/gracias`), pero la confirmación es genérica, sin número ni
  código. Un bot que lea esa página puede notar la diferencia: es una trampa barata asumida
  (D-65); mostrar un número o un código falsos le mentiría a una persona real que complete el
  campo por error.
- **Log de seguridad** en `storage/logs/security.log`, un canal de `App\Core\Log` con la
  misma rotación. Una línea por evento: `fecha | evento | ip | método path | admin_id |
  detalle`. Eventos: login ok, fallido y bloqueado; CSRF inválido; acceso al panel sin
  sesión; envío bloqueado; honeypot; cambio de contraseña, fallo de la actual y sesión
  invalidada; y los de seguimiento (F-3).
- Lo que **no** se registra: contraseñas, códigos, cuerpos y query strings. El email
  aparece solo como los primeros 12 caracteres de su SHA-256 normalizado, que alcanza para
  correlacionar intentos sin exponerlo. La IP sí se registra, porque es el dato que permite
  actuar ante un abuso. Cada campo se neutraliza (saltos de línea, controles y `|`), así una
  entrada maliciosa no puede inyectar líneas falsas.

## Alternativas consideradas

- **CAPTCHA:** frena mejor a los bots, pero requiere un servicio externo (dependencia,
  privacidad, CSP más abierta) y empeora la accesibilidad del formulario.
- **Límite en sesión o cookie:** el bot la descarta. **APCu o archivo:** no comparten
  estado entre procesos en todos los hostings y se pierden al reiniciar; la DB ya está y
  permite el conteo exacto.
- **Honeypot con `display:none`:** más simple, pero algunos bots lo detectan y lo ignoran.
- **Responder 422 o 400 al honeypot:** le confirma al bot que fue detectado.
- **Registrar los eventos en la DB:** el log en archivo es más simple de rotar y de leer
  con herramientas estándar, y no compite con la carga de la base durante un abuso.

## Consecuencias

- Un abuso desde una IP queda limitado a 5 solicitudes cada 10 minutos. Detrás de un NAT
  compartido (campus, empresa) los usuarios comparten ese límite; es configurable.
- **Las pruebas automáticas corren con un límite mayor:** una corrida completa del smoke
  hace unos 35 envíos desde la misma IP. `tests/run-all.sh` levanta su servidor con una
  config de prueba (`APP_CONFIG_LOCAL`, D-64) que solo sube `form_max_por_ip` a 50; la app
  conserva 5.
- Revisión A2-16: el honeypot es distinguible para un bot que lea `/gracias` y, con
  campos inválidos, responde 302 donde un envío real daría 422 (D-65). Se acepta.
- El `security.log` se puede desplazar por volumen: `acceso_sin_sesion` y `csrf_invalido`
  no tienen límite propio y la rotación conserva 5 MB; un flujo masivo de requests anónimas
  empuja fuera los eventos viejos. Mejora pendiente: limitar o agrupar esos eventos por IP, o
  enviar el canal a un colector externo con más retención.
- El honeypot solo frena bots simples. Uno que ejecute JavaScript y respete el CSS lo puede
  evitar; para eso está el límite por IP.
- El `security.log` contiene IPs, que son datos personales en algunas jurisdicciones:
  corresponde informarlo en la política de privacidad y respetar la retención (la rotación
  conserva 5 archivos de 1 MB).
