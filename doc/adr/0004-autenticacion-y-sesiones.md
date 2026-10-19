# ADR-0004 — Autenticación y sesiones del administrador

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-03), revisado e integrado por el orquestador.

## Contexto

El panel (F4–F9) debe quedar accesible solo para administradores autenticados. La consigna
exige contraseñas con hash, bloqueo de páginas y operaciones sin sesión y manejo seguro de
la sesión. Riesgos concretos: enumeración de usuarios por mensaje o tiempo de respuesta,
fijación y robo de sesión, sesiones que sobreviven a la baja de un admin y truncamiento
silencioso de bcrypt.

## Decisión

- **Contraseñas:** `password_hash(PASSWORD_DEFAULT)` (bcrypt, costo 12) y
  `password_verify`. `password_needs_rehash` al loguear actualiza el hash si cambia el
  algoritmo o el costo. Más de 72 bytes se rechaza (bcrypt truncaría): en el modelo
  (excepción), en el CLI y en el login (fallo genérico antes de verificar).
- **Anti-enumeración:** un único mensaje ("Email o contraseña incorrectos.", 401) para
  email inexistente, contraseña errónea o admin inactivo. Si el email no existe se verifica
  igual contra un hash ficticio del mismo costo, así el tiempo de respuesta no revela qué
  emails existen.
- **Sesión:** cookie propia `utn_sesion` con `HttpOnly`, `SameSite=Lax` y `Secure` si hay
  HTTPS o `app.env = production`. `use_strict_mode`, `use_only_cookies`, sin trans-sid. En
  login: `session_regenerate_id(true)` y rotación del token CSRF (anti-fixation). En sesión
  solo se guarda el `id` del admin.
- **Timeout de inactividad** configurable (`app.session_timeout`, 1800 s por defecto,
  D-21): vencido, la sesión se vacía y cambia de ID.
- **Re-verificación en DB** en cada request admin (`Auth::user()` → `Admin::find`): un
  admin borrado o desactivado pierde el acceso en la siguiente request aunque su cookie
  siga viva.
- **Guardia central:** las rutas con `'auth' => true` pasan por `Auth::requireAdmin()`
  antes del CSRF y del controller (D-16). Sin sesión: GET → 302 a `/admin/login`; otros
  métodos → 401 sin ejecutar nada (D-17).
- **Logout** solo por POST con CSRF: destruye la sesión y expira la cookie.

## Alternativas consideradas

- **Guardar el registro completo del admin en la sesión**: evita una consulta por request,
  pero deja de enterarse de una baja o un cambio de estado hasta que la sesión expire. Una
  consulta por id primario es despreciable.
- **Mensajes distintos ("el email no existe")**: mejor UX, pero entrega a un atacante la
  lista de cuentas válidas.
- **Fingerprint de IP/User-Agent en la sesión** (patrón de otros proyectos del autor): corta
  sesiones legítimas en redes móviles o con proxies y no frena a un atacante que copia
  también el User-Agent. Se priorizó regenerate + HttpOnly + SameSite + timeout.
- **Argon2id**: más resistente, pero depende de cómo se compiló PHP en el hosting;
  `PASSWORD_DEFAULT` con `needs_rehash` permite migrar sin código nuevo.
- **Logout por GET (link "salir")**: un `<img src="/admin/logout">` de terceros cerraría la
  sesión; por eso POST + CSRF.

## Consecuencias

- Verificado por `tests/smoke.sh` (familias P3, P4, P5, P6, S y H).
- Una consulta extra por request admin.
- Sin límite de intentos de login todavía: es el extra 1 del PLAN (D-22: IP+email, ≥5
  intentos en 15 min). Hasta entonces el costo de bcrypt es la única fricción ante fuerza
  bruta.
- Un solo rol: si aparecieran roles, `requireAdmin` pasaría a recibir el permiso requerido.
