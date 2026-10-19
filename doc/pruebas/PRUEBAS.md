# Pruebas

Verificación del sistema de solicitudes contra las pruebas mínimas de la consigna (§4) y los códigos HTTP definidos en el PLAN (§3). La prueba automatizada es `tests/smoke.sh` (bash + curl, por HTTP contra la app en ejecución); el mapeo caso → prueba mínima está en `tests/README.md`.
Para generar evidencia: `tests/smoke.sh --evidencia`, que escribe `doc/pruebas/evidencias/smoke-AAAAMMDD-HHMMSS.txt`.
Correr contra una base de desarrollo reinstalable con `php scripts/instalar-db.php` (ver `tests/README.md`, "Base de datos reinstalable").
Las columnas "Resultado obtenido" y "Evidencia" se completan al ejecutar contra la implementación. Hoy quedan vacías; se completan en la corrida final (ver "Corridas").

## Pruebas mínimas de la consigna

| Escenario de la consigna | Caso(s) del script | Resultado esperado | Resultado obtenido | Evidencia |
|---|---|---|---|---|
| P1. Solicitud válida | P1.1 a P1.6 | GET / 200 con formulario y token CSRF; POST válido 302 a /gracias; /gracias 200 con confirmación y, al repetirlo, 302 a /; la solicitud aparece en el panel con sus datos | | |
| P2. Campos inválidos o incompletos | P2.1 a P2.4 | 422 con mensaje por campo obligatorio o email inválido (también con valores solo espacios); ninguna fila nueva para los inválidos | | |
| P3. Acceso al panel sin sesión | P3.1 a P3.4 | 302 a /admin/login para /admin, /admin/solicitudes/1 y /admin?estado=pendiente; POST /admin/logout sin sesión 401 | | |
| P4. Credenciales incorrectas | P4.1 a P4.3 | 401 con mensaje genérico idéntico para password incorrecta y email inexistente (anti-enumeración); GET /admin posterior 302 a /admin/login | | |
| P5. Cambio de estado autorizado | P5.1 a P5.6 (P5.1 y P5.2 son el login correcto) | Login 302 a /admin; /admin 200; estado inicial pendiente; POST estado=en_proceso con sesión y CSRF 302 al detalle de la solicitud; el detalle muestra en_proceso y el flash "Estado actualizado", que no se repite al recargar | | |
| P6. Cambio de estado no autorizado | P6.1 a P6.8 | Sin sesión, 401 exacto, con o sin token CSRF; con sesión, sin CSRF o con token de otra sesión, 419; estado fuera de lista blanca, con inyección o ausente, 422; estado como array, 422. En todos los casos el estado persiste en en_proceso | | |
| P7. Ingreso de HTML/SQL malicioso | P7.1 a P7.7 | Payloads aceptados como texto (302 a /gracias); en listado y detalle, `<script>` e `<img onerror>` se muestran escapados y nunca crudos; payload SQL guardado literal; 422 re-renderiza escapado; filtro con inyección 400 sin error SQL; id inyectado en la ruta 404 | | |

## Verificaciones adicionales

| Grupo temático | Caso(s) del script | Resultado esperado | Resultado obtenido | Evidencia |
|---|---|---|---|---|
| CSRF (419) | H.1 a H.5 | 419 en login aun con credenciales correctas, y en /solicitudes sin token, con token falso o con token de otra sesión; sin acceso al panel tras el intento (H.2, 302 a /admin/login) | | |
| Método incorrecto (405) | H.6, H.10 | 405 con cabecera Allow que incluye POST, en GET /admin/solicitudes/{id}/estado y GET /solicitudes | | |
| 404 | H.7, H.8, H.9, H.11 | 404 para id inexistente (GET y POST), id no numérico y ruta inexistente | | |
| Sin fugas de errores | H.7, H.11 | El cuerpo de los 404 no contiene SQLSTATE, PDOException, Stack trace, Fatal error, "on line N" ni errores SQL | | |
| Headers de seguridad | S.1 a S.3 | X-Frame-Options: DENY; X-Content-Type-Options: nosniff; Content-Security-Policy con default-src 'self' | | |
| Rotación de ID de sesión | S.4 | El ID de sesión cambia al loguear (session_regenerate_id) | | |
| Flags de cookie | S.5 | Set-Cookie de sesión con HttpOnly y SameSite=Lax | | |
| Guest redirect | S.6 | GET /admin/login con sesión activa 302 a /admin | | |
| Logout | S.7, S.8 | POST sin CSRF 419 y la sesión sigue activa; con CSRF 302, y luego /admin redirige a /admin/login | | |
| Filtro por estado (F6) | F6.1 a F6.4 | ?estado=en_proceso incluye la solicitud; ?estado=pendiente no la incluye; ?estado= vacío da 200 con todas; ?estado=cerrada da 400 | | |
| Orden del listado (F5) | F5.1 | Listado ordenado por created_at DESC: la solicitud más nueva aparece primero (D-20) | | |
| Parámetros como array (H.12, H.13, P6.8) | H.12, H.13, P6.8 | nombre[] en el formulario público 422 (H.12); ?estado[] 400 (H.13); estado[] en el cambio de estado 422 (P6.8); sin fugas de error ni 500 | | |

## Pruebas manuales (navegador)

El script no cubre los siguientes puntos:

- [ ] Revisión visual del formulario público (campos, etiquetas, layout en ancho de teléfono).
- [ ] Revisión visual del panel admin (listado, filtros, detalle).
- [ ] Revisión visual de las páginas de error (404, 405, 419) y de /gracias.
- [ ] Mensajes de error junto a cada campo inválido.
- [ ] Timeout de sesión por inactividad (D-21): procedimiento en `tests/README.md`, sección "Prueba manual del timeout de sesión (D-21)", bajando `app.session_timeout` en la config local.

Comportamiento con JavaScript deshabilitado: no aplica, la aplicación no usa JS.

## Corridas

| Fecha | Estado de la implementación | Resultado | Evidencia |
|---|---|---|---|
| 2026-10-19 13:45 | Fase 1 + formulario público; panel admin sin implementar (A1-03 pendiente) | 64 casos: 19 PASS, 12 FAIL, 33 SKIP. Público (P1.1–P1.4, P2.1–P2.3, P7.1, P7.5, H.3–H.5, H.10–H.12, S.1–S.3, S.5) en PASS; todo /admin responde 404 (rutas aún no existen), el resto SKIP por falta de login | sin archivo (corrida exploratoria) |
