# ADR-0009 — Límite de intentos de login

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-06), revisado e integrado por el orquestador.

## Contexto

La revisión A2-04 dejó al costo de bcrypt (≈150 ms por verificación) como única fricción
ante fuerza bruta sobre `/admin/login`. El PLAN lo tenía como extra 1 (D-22) y Juan decidió
implementarlo (D-38). Hay dos ataques a cubrir: probar muchas contraseñas contra una cuenta,
y probar pocas contraseñas comunes contra muchos emails ("password spraying"). El control no
debe permitir enumerar cuentas ni convertirse en una forma fácil de dejar al admin afuera.

## Decisión

- Se registran solo los **intentos fallidos** en `login_intentos` (ip, SHA-256 del email
  normalizado, created_at). El email no se guarda en claro.
- Dos límites en una ventana de 15 min, configurables (`app.login_max_por_cuenta` = 5 por
  IP + email; `app.login_max_por_ip` = 20 por IP sola).
- Si se alcanzó alguno, el intento **no se verifica** (ni `password_verify` ni consulta a
  `admins`, aunque la contraseña sea correcta). Respuesta 429 + `Retry-After` (segundos
  hasta que vence el intento más viejo de la ventana; el mayor si se alcanzaron ambos
  límites) + "Demasiados intentos. Probá de nuevo en N minutos.". Los intentos bloqueados no
  se registran, así el bloqueo vence cuando dice `Retry-After`.
- El chequeo depende solo de la IP y del hash del email, nunca de que la cuenta exista: el
  comportamiento es idéntico para emails existentes e inexistentes. Cuenta como fallo todo
  intento rechazado por `Auth::attempt`, incluidas las contraseñas de más de 72 bytes, con
  NUL o los emails no UTF-8. Un POST sin CSRF válido (419) no llega al controller y no
  cuenta.
- Un login exitoso borra los fallos de esa IP + email; los registros de más de 24 h se
  purgan al registrar un fallo.
- La ventana y el `Retry-After` se calculan en la DB (`NOW() - INTERVAL ? SECOND`,
  `TIMESTAMPDIFF`), con el mismo reloj que escribe `created_at`, independiente de la zona
  horaria de PHP (D-24).
- IP = `REMOTE_ADDR`; no se confía en `X-Forwarded-For`, que cualquier cliente puede
  falsificar.
- La política vive en `App\Core\LoginThrottle`, separada de `Auth`: Auth decide si las
  credenciales son válidas; el throttle, si se permite intentarlo.

## Alternativas consideradas

- **Solo por IP**: frena el spraying, pero con un umbral alto (necesario por NAT compartido)
  deja demasiados intentos contra una misma cuenta; con uno bajo bloquea a usuarios
  legítimos detrás de la misma IP.
- **Solo por cuenta, con bloqueo de la cuenta**: cualquiera que conozca el email del admin
  podría dejarlo afuera desde cualquier IP (denegación de servicio dirigida). Por eso el
  límite por cuenta se combina con la IP: el atacante solo se bloquea a sí mismo.
- **CAPTCHA**: requiere un servicio externo (dependencia, privacidad, CSP más abierta) o uno
  propio poco efectivo; desproporcionado para un panel de un rol.
- **Backoff exponencial (demora creciente por respuesta)**: retiene workers de PHP durante
  la espera, lo que facilita agotar el servidor; una respuesta 429 inmediata es más barata.
- **Contador en sesión**: inútil, el atacante descarta la cookie. **APCu / archivo**: APCu no
  comparte estado entre procesos en todos los hostings y se pierde al reiniciar; un archivo
  requiere locking propio. La DB ya está, es transaccional y permite consultas por ventana
  con índices.

## Consecuencias

- **Carrera:** contar y registrar no son atómicos; N requests simultáneas pueden pasar el
  chequeo antes de que se registre ninguna, así que el límite real puede excederse por el
  grado de concurrencia del atacante. Se acepta como límite aproximado: el objetivo es
  volver la fuerza bruta impráctica, no garantizar un número exacto. Hacerlo exacto
  requeriría `SELECT … FOR UPDATE` o un contador con `INSERT … ON DUPLICATE KEY UPDATE`.
- **NAT compartido:** usuarios detrás de la misma IP pública (campus, empresa) comparten el
  límite de 20 por IP; un atacante en esa red puede bloquear el login de los demás por hasta
  15 min. Aceptable para un panel de pocos administradores; los umbrales son configurables.
- **Proxy o CDN delante:** `REMOTE_ADDR` sería la IP del proxy y todos compartirían el
  límite. Habría que tomar la IP de `X-Forwarded-For` solo cuando la conexión viene de un
  proxy de confianza.
- **Variantes del email (corregido, D-41):** el email se normaliza en un solo lugar
  (`Admin::normalizeEmail` = `mb_strtolower(trim())`), que usan el alta, la búsqueda del
  login y el hash del contador. La búsqueda del admin compara en forma **binaria**
  (`email = ? AND email COLLATE utf8mb4_bin = ?`): la primera condición aprovecha el índice
  único y la segunda exige igualdad exacta. Variantes de mayúsculas y espacios
  (`ADMIN@EXAMPLE.COM`, ` admin@example.com `) son la misma cuenta y comparten contador.
  Variantes que la collation `_ci` consideraría equivalentes (`ádmin@…`, `ÁDMIN@…`) no
  autentican nunca: antes era un bypass real del límite por cuenta (hallazgo de A2-05),
  porque encontraban al admin con un contador distinto. La columna conserva
  `utf8mb4_unicode_ci` para que el `UNIQUE` siga rechazando cuentas que difieran solo en
  mayúsculas o acentos.
- **Pruebas:** cada corrida del smoke registra fallos desde la misma IP, así que hay que
  reinstalar la base (o vaciar `login_intentos`) entre corridas.
- Agrega una tabla y dos o tres consultas por intento de login; no afecta el resto de la
  app.
