# ADR-0007 — Configuración local y secretos fuera del repositorio

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-03), revisado e integrado por el orquestador.

## Contexto

La app necesita credenciales de DB y flags de entorno (debug, env, timeout). La consigna
pide no exponer información sensible y que el proyecto sea reproducible por el evaluador.
Además, la revisión de AGENTE 2 (hallazgos M1 y M2) mostró dos riesgos de entorno: servir
la raíz del repo por error y reinstalar la DB de producción con datos demo.

## Decisión

- `app/config/config.php` (versionado) define **defaults seguros**: `env=production`,
  `debug=false`, `session_timeout=1800`, DB sin credenciales. Los mezcla con
  `app/config/config.local.php`, que está **fuera de Git** (`.gitignore`) y se crea
  copiando `config.local.example.php`. Si falta, la app corta con un error que nombra solo
  rutas relativas al proyecto.
- `debug` se interpreta con `FILTER_VALIDATE_BOOLEAN` (un `'false'` string no activa el
  modo debug).
- Las credenciales solo viajan de la config a `Database`. Un fallo de conexión se muestra
  genérico y el detalle va al log. Ningún script imprime la configuración.
- Todo vive fuera del DocumentRoot: el único directorio publicado es `public_html/`. Como
  defensa en profundidad, un `.htaccess` en la raíz del repo con `Require all denied`
  bloquea `.git/`, `database/`, `doc/`, `app/` y la config si alguien apunta el servidor a
  la raíz (M1). `public_html/.htaccess` declara `Require all granted` porque Apache hereda
  los `.htaccess` de los directorios padres y, sin eso, el deny bloquearía también el
  sitio bien configurado. Verificado con Apache.
- `scripts/instalar-db.php` (borra y recrea tablas) se niega a correr con
  `app.env = production` salvo `--force`. En producción el procedimiento es
  `--sin-datos --force` + `scripts/crear-admin.php`, nunca el seed, cuyo admin tiene la
  contraseña demo publicada en el README (M2).
- El seed solo contiene datos ficticios (`example.com`) y el hash de la contraseña demo,
  nunca texto plano.

## Alternativas consideradas

- **Variables de entorno / `.env` con parser**: estándar en frameworks, pero en hosting
  compartido sin acceso a la config del servidor exige un parser propio o Composer
  (ADR-0001). Un array PHP no se puede descargar como texto aunque quede expuesto.
- **Credenciales en `config.php` versionado "para la demo"**: terminan en GitHub y en el
  historial para siempre.
- **Solo documentar el DocumentRoot, sin `.htaccess` en la raíz**: el error de
  configuración que M1 reprodujo en el entorno local lo muestra insuficiente.
- **Confirmación interactiva en `instalar-db.php`**: no sirve en ejecuciones no
  interactivas y es fácil de aceptar sin leer; un flag explícito deja rastro en el comando.

## Consecuencias

- El evaluador copia un archivo, completa 3 valores y corre `instalar-db.php`.
- El `.htaccess` de la raíz solo protege en Apache con `AllowOverride` habilitado; en Nginx
  o con `AllowOverride None` la protección real es el DocumentRoot correcto, que el README
  debe explicitar.
- `public_html/.htaccess` y el de la raíz quedan acoplados: si se quita el
  `Require all granted`, el sitio responde 403.

## Revisión posterior (R-3, D-58)

La configuración se carga en un solo lugar por contexto: `app/bootstrap.php` (web) y
`app/bootstrap-cli.php` (scripts y tests: Config y Database, sin sesión ni headers).
`Database` ya no lee `config.php` por su cuenta: sin `configure()` lanza `LogicException`.
