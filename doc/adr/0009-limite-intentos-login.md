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

- **Concurrencia (exacto, D-46):** el intento se **reserva antes de contar**: INSERT en
  `login_intentos` y recién después el conteo de la ventana, que incluye la fila propia. Si
  supera el límite se borra esa fila y se responde 429 (los bloqueados no quedan
  registrados, D-39). Si las credenciales son válidas se borra la fila y se limpian los
  fallos de la cuenta; si no, la fila queda como fallo. Con autocommit cada INSERT es
  visible para el COUNT de las requests concurrentes: para que dos pasen haría falta que
  ambos cuenten ≤ límite incluyendo la fila del otro, imposible cuando solo queda un lugar.
  Bajo carrera puede bloquear de más, nunca de menos. Medido con 8 workers, 4 fallos
  previos y 10 o 30 intentos simultáneos: pasa a verificar exactamente 1, contra 2 a 6 de
  10 con la versión anterior. Sin `SELECT … FOR UPDATE`, locks con nombre ni filas contador.
- **NAT compartido:** usuarios detrás de la misma IP pública (campus, empresa) comparten el
  límite de 20 por IP; un atacante en esa red puede bloquear el login de los demás por hasta
  15 min. Aceptable para un panel de pocos administradores; los umbrales son configurables.
- **Proxy o CDN delante (D-47, D-49):** por defecto la IP es `REMOTE_ADDR` y
  `X-Forwarded-For` se ignora, porque lo puede escribir cualquier cliente. Si hay proxies
  propios se declaran en `app.trusted_proxies` (IPs o CIDR, IPv4 e IPv6; una entrada
  inválida aborta la carga de la config). Solo cuando `REMOTE_ADDR` es uno de ellos se lee
  `X-Forwarded-For` **de derecha a izquierda**, porque el tramo final lo agregaron nuestros
  proxies. La IP del cliente es la primera entrada que no es un proxy de confianza; si
  aparece una entrada inválida antes, se usa `REMOTE_ADDR`; si todas son de confianza, la
  de más a la izquierda. Las direcciones se normalizan (puertos, corchetes de IPv6,
  IPv4-mapped) antes de comparar y de guardar. Sin esta configuración, todos los usuarios
  detrás de un proxy comparten el límite por IP.
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
