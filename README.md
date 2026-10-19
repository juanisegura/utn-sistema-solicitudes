# Sistema de gestión de solicitudes

Trabajo práctico integrador (UTN): sistema de solicitudes de soporte en **PHP + MySQL**,
sin frameworks ni dependencias externas. Patrón MVC ligero con front controller propio,
consultas preparadas, sesiones endurecidas y protección CSRF.

## Funcionalidades

**Público**
- Formulario de solicitud (nombre, email, asunto, descripción) con validación server-side
  y mensajes por campo.
- Confirmación con el número de solicitud (patrón POST → redirect → GET).

**Panel administrativo** (`/admin`)
- Login y logout de administrador.
- Listado de solicitudes, de la más reciente a la más antigua, con filtro por estado
  (pendiente, en proceso, resuelta) y contadores.
- Detalle de cada solicitud y cambio de estado.
- Todas las páginas y operaciones del panel exigen sesión de administrador.

## Requisitos

- PHP **8.2 o superior** con las extensiones `pdo_mysql` y `mbstring` (`php -m` las lista).
- **MySQL 8** o **MariaDB 10.6 o superior**.
- No usa Composer, `npm` ni build steps.

## Instalación local

1. Clonar el repositorio:

   ```bash
   git clone https://github.com/juanisegura/utn-sistema-solicitudes.git
   cd utn-sistema-solicitudes
   ```

2. Crear la base y un usuario propio, como root de MySQL/MariaDB. Reemplazar
   `CAMBIAR_ESTA_PASSWORD`:

   ```sql
   CREATE DATABASE utn_solicitudes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'utn_solicitudes'@'localhost' IDENTIFIED BY 'CAMBIAR_ESTA_PASSWORD';
   GRANT ALL PRIVILEGES ON utn_solicitudes.* TO 'utn_solicitudes'@'localhost';
   ```

   El usuario necesita permisos de creación y borrado de tablas porque el instalador
   recrea el esquema. Si MySQL 8 rechaza la conexión por `127.0.0.1`, crear el mismo
   usuario también para el host `'127.0.0.1'`.

3. Crear la configuración local. Este archivo queda fuera de Git (ver
   [ADR-0007](doc/adr/0007-configuracion-y-secretos.md)):

   ```bash
   cp app/config/config.local.example.php app/config/config.local.php
   ```

   Completar `db.name`, `db.user` y `db.pass`. Para desarrollo se puede dejar
   `env => 'local'` y `debug => true`. En cualquier otro entorno van `production` y `false`.

4. Crear las tablas y cargar los datos de prueba:

   ```bash
   php scripts/instalar-db.php
   ```

   El script **borra y recrea** las tablas `admins` y `solicitudes`. Con `--sin-datos`
   crea solo la estructura.

5. Levantar el servidor de desarrollo desde la raíz del repositorio:

   ```bash
   php -S localhost:8080 -t public_html public_html/index.php
   ```

6. Abrir <http://localhost:8080/> para el formulario y <http://localhost:8080/admin> para el panel.

### Credenciales demo

| Usuario | Contraseña |
|---------|------------|
| `admin@example.com` | `Demo-UTN-2026!` |

Son **solo datos de prueba**: el seed (`database/seed.sql`) contiene únicamente el hash de
esta contraseña y solicitudes ficticias con emails `example.com`.

**En producción no se carga el seed.** Ahí se usa:

```bash
php scripts/instalar-db.php --sin-datos --force   # con app.env = production exige --force
php scripts/crear-admin.php                       # pide nombre, email y contraseña (mín. 12 caracteres)
```

## Apache

- El **DocumentRoot debe ser `public_html/`**, nunca la raíz del repositorio. Todo el
  código PHP, la configuración, los scripts y `.git/` quedan fuera del alcance web.
- `public_html/.htaccess` envía todo lo que no sea un archivo real al front controller
  (`mod_rewrite`), desactiva el listado de directorios, bloquea dotfiles y repite los
  headers de seguridad (`mod_headers`). Necesita `AllowOverride All`, o al menos
  `FileInfo`, `Options`, `Indexes` y `AuthConfig`, sobre ese directorio.
- El `.htaccess` de la raíz del repositorio contiene `Require all denied`: si por error se
  sirve la raíz, `.git/`, `database/`, `doc/` y `app/` responden 403. Como Apache aplica
  también los `.htaccess` de los directorios padres, `public_html/.htaccess` declara
  `Require all granted` para que ese deny no bloquee el sitio
  ([ADR-0007](doc/adr/0007-configuracion-y-secretos.md)).

## Pruebas

Con la app levantada en `localhost:8080`:

```bash
php scripts/instalar-db.php     # base limpia (el smoke agrega filas en cada corrida)
bash tests/smoke.sh             # 67 casos: consigna §4 + códigos HTTP + seguridad
bash tests/smoke.sh --evidencia # además guarda la salida en doc/pruebas/evidencias/
```

- Qué cubre cada caso: [tests/README.md](tests/README.md) y [doc/pruebas/PRUEBAS.md](doc/pruebas/PRUEBAS.md).
- Evidencia oficial (67/67 PASS): [doc/pruebas/evidencias/smoke-20261019-135950.txt](doc/pruebas/evidencias/smoke-20261019-135950.txt).
- Ejecutar solo contra una base de desarrollo: cada corrida crea solicitudes de prueba.

## Estructura

```text
app/
├── bootstrap.php      arranque: manejo de errores, headers de seguridad, config, sesión
├── routes.php         tabla de rutas (método, patrón, controller, auth)
├── autoload.php       autoloader propio App\* → app/
├── config/            config.php (defaults seguros) + config.local.php (fuera de Git)
├── Core/              Router, Request, Response, Session, Csrf, Auth, Validator, View, Database
├── Controllers/       controllers finos, sin SQL (público y Admin/)
├── Models/            único lugar con SQL: Solicitud, Admin, enum Estado
└── Views/             layouts, formulario, panel y páginas de error (todo escapado con e())
public_html/           único directorio público: index.php (front controller), .htaccess, assets/css
database/              schema.sql (idempotente) y seed.sql (datos ficticios)
scripts/               instalar-db.php y crear-admin.php (solo CLI)
tests/                 smoke.sh (bash + curl) y su README
storage/logs/          log de errores de la app (no versionado)
doc/                   plan, ADRs, resumen técnico, pruebas y registro de uso de IA
tools/                 hook de registro de prompts
```

### Rutas

| Método | Ruta | Descripción | Protección |
|--------|------|-------------|------------|
| GET | `/` | Formulario público | — |
| POST | `/solicitudes` | Alta de solicitud | CSRF |
| GET | `/gracias` | Confirmación (solo tras un alta) | — |
| GET | `/admin/login` | Formulario de login | — |
| POST | `/admin/login` | Login | CSRF |
| POST | `/admin/logout` | Logout | sesión + CSRF |
| GET | `/admin` | Listado (`?estado=pendiente\|en_proceso\|resuelta`) | sesión |
| GET | `/admin/solicitudes/{id}` | Detalle | sesión |
| POST | `/admin/solicitudes/{id}/estado` | Cambio de estado | sesión + CSRF |

## Documentación

- [Plan: alcance, diseño y validación](doc/PLAN.md)
- [Resumen técnico](doc/RESUMEN-TECNICO.md)
- [Decisiones de arquitectura (ADRs 0001–0008)](doc/adr/)
- [Pruebas](doc/pruebas/PRUEBAS.md)
- Uso de IA:
  - [bitácora de prompts](doc/ia/PROMPTS.md)
  - [decisiones](doc/ia/DECISIONES.md)
  - [registro literal](doc/ia/registro/)
  - [revisiones locales](doc/ia/revisiones/)
  - [selección de IA](doc/ia/SELECCION-IA.md)
