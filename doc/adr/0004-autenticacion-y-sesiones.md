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

## Revisión posterior (A2-04)

La revisión adversarial de auth y panel encontró tres ajustes, incorporados sin cambiar la
decisión:

- **Costo de bcrypt fijo (D-35).** Las contraseñas se hashean con `PASSWORD_BCRYPT` y
  `['cost' => 12]` (`Admin::HASH_ALGO` / `HASH_OPTIONS`), en el alta, en el rehash al
  loguear y en el hash ficticio de `Auth`. Antes se usaba `PASSWORD_DEFAULT`, cuyo costo
  pasó de 10 a 12 en PHP 8.4. Con PHP 8.2/8.3, los hashes reales (costo 10) y el ficticio
  (costo 12) tardarían distinto, y el tiempo de respuesta revelaría qué emails existen,
  anulando la anti-enumeración. Se fijó también el algoritmo, para que un cambio futuro de
  `PASSWORD_DEFAULT` no reabra la diferencia.
- **Contraseñas con byte NUL (D-36).** bcrypt corta en el primer `\0`, así que
  `"correcta\0cualquiercosa"` verificaría como válida. Se rechazan igual que las de más de
  72 bytes: fallo genérico en el login, `InvalidArgumentException` al crear o rehashear y
  mensaje explícito en `crear-admin.php` (smoke H.16).
- **Sesión (D-36).** `session.gc_maxlifetime` se fija en `max(app.session_timeout, 1440)`,
  para que el recolector de PHP no borre sesiones antes del timeout documentado. Un
  `POST /admin/login` con sesión activa redirige a `/admin` sin procesar credenciales.

**Nota sobre el email del login:** la columna `email` usa `utf8mb4_unicode_ci`, así que la
búsqueda no distingue mayúsculas ni acentos (`Admin@Example.com` encuentra a
`admin@example.com`). No debilita la autenticación: igual hace falta la contraseña correcta
de esa cuenta, y el `UNIQUE` con la misma collation impide dos cuentas que difieran solo en
eso.

## Revisión posterior (fase de mejoras, M-5)

La sesión no se abre en el arranque sino en el Router, después de resolver ruta y método y
antes de autenticación y CSRF: las respuestas 404 y 405 y los archivos estáticos no crean
sesión ni envían `Set-Cookie`. Cierra la limitación D-32.

## Revisión posterior: cambio de contraseña (F-5)

El panel permite al admin cambiar su contraseña en `/admin/cuenta/password` (auth + CSRF):

- Pide la actual, verificada con `password_verify`. Sus fallos cuentan en el límite de
  login de esa cuenta (IP + email, ADR-0009), así el formulario no sirve para probar
  contraseñas sin límite.
- La nueva debe tener 12 caracteres o más, cumplir `Admin::passwordAcceptable` (≤ 72 bytes,
  sin NUL), ser distinta de la actual y coincidir con la confirmación. Errores → 422 por
  campo, sin re-mostrar contraseñas.
- Al cambiarla se guarda `admins.password_changed_at` (DATETIME(6), `NOW(6)`). Cada sesión
  guarda `auth_at`, el instante de su login (también `NOW(6)`, reloj de la DB).
  `Auth::user()` rechaza toda sesión con `auth_at` anterior al último cambio: la cierra y
  registra `sesion_invalidada`. La sesión que hizo el cambio regenera su ID, rota el token
  CSRF y actualiza su `auth_at`, así que sigue activa.
- Los microsegundos y el reloj único (la DB) evitan que un login y un cambio en el mismo
  segundo, o la diferencia de reloj entre PHP y la DB, dejen viva una sesión que debía
  cerrarse.
- El rehash automático al loguear (`password_needs_rehash`) **no** actualiza
  `password_changed_at`: un cambio de algoritmo o costo no debe cerrar sesiones.
