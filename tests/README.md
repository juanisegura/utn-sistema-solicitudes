# Tests smoke (`tests/smoke.sh`)

## Qué es

Suite smoke por HTTP, escrita en bash y curl, sin dependencias adicionales. Ejecuta contra la app en marcha los casos mínimos de la consigna (P1 a P7) y las verificaciones de códigos HTTP y seguridad definidas en `doc/PLAN.md`.

Las verificaciones de persistencia se hacen por HTTP, a través del panel admin (listado y detalle). El script no accede nunca a la base de datos directamente.

## Requisitos y ejecución

- `curl` instalado.
- La app levantada desde la raíz del repo, con la base instalada y el seed cargado:

  ```bash
  php -S localhost:8080 -t public_html public_html/index.php
  ```

- Ejecución:

  ```bash
  tests/smoke.sh                              # contra http://localhost:8080
  tests/smoke.sh --base-url http://host:8080  # URL explícita
  BASE_URL=http://host:8080 tests/smoke.sh    # idem, por variable
  tests/smoke.sh --evidencia                  # guarda la salida en doc/pruebas/evidencias/smoke-AAAAMMDD-HHMMSS.txt
  tests/smoke.sh -h
  ```

- Variables de entorno sobreescribibles: `BASE_URL`, `CSRF_FIELD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`. Por defecto, el admin demo del seed (`admin@example.com` y su contraseña demo).
- Cookies y respuestas temporales se guardan en `tests/.tmp/`. Está ignorado por git y se recrea en cada corrida.
- Cada corrida crea filas nuevas marcadas con `smoke-<timestamp>-<pid>` en asuntos y textos. Ejecutar contra una base de desarrollo, nunca contra producción.

## Salida y códigos de salida

Cada caso imprime una línea con el formato:

```text
PASS  <ID>  <descripción>  [obtenido: <valor>]
FAIL  <ID>  <descripción>  [obtenido: <valor> | esperado: <valor>]
SKIP  <ID>  (<motivo>)
```

- `SKIP`: el caso no pudo ejecutarse porque falló un prerequisito (por ejemplo, el login de admin). No cuenta como PASS.
- `0`: todos los casos PASS.
- `1`: al menos un FAIL o SKIP.
- `2`: el servidor no responde a `GET /` (timeout de 3 s), falta `curl` o se pasó una opción inválida (incluido `--base-url` sin valor).

## Mapeo de casos

Columna "Prueba mínima": P1 a P7 corresponden a la consigna (P1 solicitud válida, P2 campos inválidos o incompletos, P3 acceso al panel sin sesión, P4 credenciales incorrectas, P5 cambio de estado autorizado, P6 cambio de estado no autorizado, P7 HTML/SQL malicioso). "extra" indica casos adicionales: H (códigos HTTP del PLAN), S (seguridad de sesión y headers), F5 (orden del listado) y F6 (filtro por estado).

| ID de caso | Qué verifica | Prueba mínima |
|---|---|---|
| S.1 | X-Frame-Options | extra · S |
| S.2 | X-Content-Type-Options | extra · S |
| S.3 | Content-Security-Policy con default-src 'self' | extra · S |
| P1.1 | GET / → 200 con form y token CSRF | P1 |
| P3.1 | GET /admin sin sesión → 302 a /admin/login | P3 |
| P3.2 | GET /admin/solicitudes/1 sin sesión → 302 a /admin/login | P3 |
| P3.3 | GET /admin?estado=pendiente sin sesión → 302 a /admin/login | P3 |
| P3.4 | POST /admin/logout sin sesión → 401 (D-17) | P3 |
| P4.1 | login con password incorrecta → 401 + mensaje genérico | P4 |
| P4.2 | login con email inexistente → 401 + mismo mensaje (anti-enumeración) | P4 |
| P4.3 | GET /admin tras login fallido → 302 a /admin/login | P4 |
| H.1 | login con credenciales correctas pero sin CSRF → 419 | extra · H |
| H.2 | GET /admin tras login sin CSRF → sigue sin acceso | extra · H |
| H.16 | login con password correcta + NUL (%00) + sufijo → 401 y sin acceso (bcrypt corta en el NUL; A2-04) | extra · H |
| P5.1 | login correcto → 302 a /admin (prerrequisito) | P5 |
| P5.2 | GET /admin con sesión → 200 (prerrequisito) | P5 |
| S.4 | el ID de sesión cambia al loguear (session_regenerate_id) | extra · S |
| S.5 | Set-Cookie de sesión con HttpOnly y SameSite=Lax | extra · S |
| P1.2 | POST /solicitudes válida → 302 a /gracias | P1 |
| P1.3 | GET /gracias → 200 con confirmación visible | P1 |
| P1.4 | GET /gracias otra vez (flash consumido) → 302 a / | P1 |
| P2.1 | POST con campos vacíos → 422 + mensajes de obligatorio | P2 |
| P2.2 | POST con email inválido → 422 + mensaje de email | P2 |
| P2.3 | POST con campos solo espacios → 422 (trim antes de validar) | P2 |
| P2.5 | POST con nombre de solo espacio Unicode (NBSP) → 422 | P2 |
| H.14 | POST con UTF-8 inválido en nombre → 422 sin fugas, no 500 | extra · H |
| H.3 | POST /solicitudes sin token CSRF → 419 | extra · H |
| H.4 | POST /solicitudes con token CSRF falso → 419 | extra · H |
| H.5 | POST /solicitudes con token de otra sesión (sin cookie) → 419 | extra · H |
| P1.5 | la solicitud válida aparece en el listado del panel | P1 |
| P2.4 | ninguna alta por inválidos/CSRF (marcas ausentes, única fila nueva = la válida) | P2 |
| P1.6 | detalle persistido (asunto, email, descripción) | P1 |
| P5.3 | estado inicial = pendiente | P5 |
| P5.4 | POST estado=en_proceso con sesión + CSRF → 302 al detalle (D-19) | P5 |
| P5.5 | el detalle muestra el nuevo estado y el flash | P5 |
| P5.6 | el flash no se repite al recargar | P5 |
| F6.1 | filtro ?estado=en_proceso incluye la solicitud | extra · F6 |
| F6.2 | filtro ?estado=pendiente no la incluye | extra · F6 |
| F6.3 | filtro ?estado= vacío → 200 con todas (D-18) | extra · F6 |
| F6.4 | filtro ?estado=cerrada (fuera de lista) → 400 (D-18) | extra · F6 |
| S.6 | GET /admin/login con sesión → 302 a /admin | extra · S |
| P6.1 | sin sesión (con CSRF válido de invitado) → 401 (D-17); sin cambio | P6 |
| P6.2 | con sesión, sin CSRF → 419; sin cambio | P6 |
| P6.3 | con sesión, token CSRF de otra sesión → 419; sin cambio | P6 |
| P6.4 | estado fuera de lista blanca (cerrada) → 422; sin cambio | P6 |
| P6.5 | estado con inyección SQL → 422; sin cambio | P6 |
| P6.6 | sin campo estado → 422; sin cambio | P6 |
| P6.7 | sin sesión y sin token → 401: auth antes que CSRF (D-16) | P6 |
| P6.8 | estado como array (estado[]=resuelta) → 422, no 500 | P6 |
| P7.1 | POST con `<script>` y `' OR '1'='1' -- ` en campos → 302 a /gracias (se acepta como texto) | P7 |
| P7.2 | listado: `<script>` escapado (`&lt;script&gt;`) y nunca crudo | P7 |
| F5.1 | listado por fecha descendente: la solicitud más nueva aparece primero (D-20) | extra · F5 |
| P7.3 | detalle: `<script>` e `<img onerror>` escapados, nunca crudos | P7 |
| P7.4 | detalle: payload SQL guardado literal y escapado | P7 |
| P7.5 | 422 re-renderiza valores previos escapados (sin XSS reflejado) | P7 |
| P7.6 | filtro ?estado=' OR '1'='1 → 400 sin error SQL (D-18) | P7 |
| P7.7 | id con inyección en la ruta → 404 | P7 |
| H.6 | GET /admin/solicitudes/{id}/estado → 405 + Allow con POST | extra · H |
| H.7 | GET detalle de id inexistente → 404 sin fugas | extra · H |
| H.8 | GET /admin/solicitudes/abc → 404 | extra · H |
| H.9 | POST estado sobre id inexistente → 404 | extra · H |
| H.10 | GET /solicitudes → 405 + Allow con POST | extra · H |
| H.11 | ruta inexistente → 404 sin fugas | extra · H |
| H.15 | GET /.htaccess no se sirve (ni con php -S) → 403/404 | extra · H |
| H.12 | POST /solicitudes con nombre[] (array) → 422 sin fugas, no 500 | extra · H |
| H.13 | filtro ?estado[]=pendiente (array) → 400 sin fugas, no 500 | extra · H |
| S.7 | POST /admin/logout sin CSRF → 419 y la sesión sigue | extra · S |
| S.8 | POST /admin/logout con CSRF → 302; después /admin → login | extra · S |

## Contrato asumido

Al inicio de `tests/smoke.sh` (bloque "Contrato asumido") se fijan los nombres de campos y las expresiones regulares de los mensajes. Si la implementación difiere, se ajustan ahí y no en los casos.

| Variable | Valor por defecto | Qué ajusta |
|---|---|---|
| `CSRF_FIELD` | `csrf_token` | nombre del input hidden del token |
| `F_NOMBRE`, `F_EMAIL`, `F_ASUNTO`, `F_DESC` | `nombre`, `email`, `asunto`, `descripcion` | campos del formulario público |
| `F_ESTADO` | `estado` | campo del form de cambio de estado |
| `F_LOGIN_EMAIL`, `F_LOGIN_PASS` | `email`, `password` | campos del login |
| `RE_CONFIRMACION` | `recibi\|registrad\|gracias` | texto de la página /gracias |
| `RE_ERR_OBLIGATORIO` | `obligatori\|requerid` | mensaje de campo obligatorio |
| `RE_ERR_EMAIL` | email o correo seguido de "válido" (en ambos órdenes) | mensaje de email inválido |
| `RE_LOGIN_ERROR` | `Email o contraseña incorrectos` | mensaje genérico de login (`.{1,2}` tolera la ñ en cualquier codificación) |
| `RE_FLASH_ESTADO` | `Estado actualizado` | flash tras un cambio de estado (P5.5, P5.6) |
| `RE_FUGA` | `SQLSTATE`, `PDOException`, `Stack trace`, `Fatal error`, `on line N`, error de SQL de MySQL | detección de fugas de error (H.7, H.11, H.12, H.13, H.14, P7.6) |
| `ID_INEXISTENTE` | `999999999` | id usado en H.7 y H.9 |

Supuestos estructurales sobre el HTML:

- **Listado `/admin`**: cada fila enlaza a `/admin/solicitudes/{id}` y muestra el asunto dentro del mismo `<tr>`, `<li>` o `<article>`. La función `id_por_marca` parte el HTML por esas etiquetas. Si el enlace y el asunto quedan en elementos distintos, fallan P1.5, P1.6 y P7.2 a P7.4.
- **Orden del listado**: lo más nuevo primero (verificado por F5.1), o sin paginar, para que la fila recién creada aparezca en la primera página.
- **Detalle**: form de cambio de estado con `<select name="estado">`, donde la `<option>` del estado actual lleva el atributo `selected`. El script toma el primer `<option ... selected>` cuyo value sea minúsculas con guión bajo en toda la página, así que ningún otro select anterior debe tener `selected`.
- **Token CSRF**: `<input type="hidden" name="csrf_token" value="...">`. El script toma el `value` del primer input con ese nombre.
- **P7.4**: el payload SQL escapado debe aparecer como `&#039;`, `&#39;` o `&apos;` (regex `RE_SQL_ESC`, definida en la sección P7 del script).

## Base de datos reinstalable

Cada corrida crea filas nuevas en la base. Ejecutar el smoke solo contra una base de desarrollo que se pueda reinstalar, nunca contra producción:

```bash
php scripts/instalar-db.php   # borra y recrea las tablas, y carga el seed
```

- Por corrida se crean dos solicitudes válidas: la normal (asunto `Valida <marca>`, que P5 pasa a `en_proceso`) y la de payloads HTML/SQL (P7.1).
- Los casos inválidos no crean filas: P2.1 a P2.3, H.3 a H.5, P7.5 y H.12. Tampoco los intentos de login ni de cambio de estado rechazados (P4.x, P6.x).
- El seed trae sus propias solicitudes, entre ellas una con `<script>`. Por eso P7.2 busca la fila de esta corrida por su marca y no la primera que contenga el payload.
- La contraseña demo del admin del seed es la que usa `ADMIN_PASSWORD` por defecto (ver "Requisitos y ejecución").

## Prueba manual del timeout de sesión (D-21)

El timeout de inactividad no se automatiza: el default es 1800 s. Procedimiento:

1. En la config local (`app/config/config.local.php`, clave `app.session_timeout`) bajar el valor a, por ejemplo, `60`.
2. Reiniciar el servidor si hace falta.
3. Loguearse en `/admin`.
4. Esperar más de 60 s sin actividad.
5. Recargar `/admin`: debe redirigir (302) a `/admin/login`.
6. Enviar el form de cambio de estado cargado antes del vencimiento: debe responder 401 y el estado no debe cambiar.
7. Restaurar `app.session_timeout` al valor original (1800) al terminar.

La config local la edita la persona que prueba; el script no la lee ni la modifica.

Alternativa sin tocar la config (usada en A2-04, solo en desarrollo con `php -S`): la sesión se
guarda en `/tmp/sess_<id>` (save_path por defecto). Tras loguearse, reescribir `_last_activity`
de ese archivo a `ahora - 1801` (y `ahora - 1700` como control): `GET /admin` → 302 a
`/admin/login` con ID nuevo y el archivo viejo borrado; un `POST` de estado con el token previo
→ 401 sin cambio. Con el control (1700 s) → 200.
