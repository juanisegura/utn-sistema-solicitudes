# AGENTS.md — utn-sistema-solicitudes

Lectura obligatoria para AGENTE 1 UTN y AGENTE 2 UTN antes de tocar este repo.
Las sesiones corren con cwd en `~/Documents/cerebro-personal` (rigen también su `CLAUDE.md`
y `AGENTS.md`); este archivo agrega las reglas específicas del TP y prevalece en lo que
sea propio del proyecto.

## Qué es

TP integrador UTN (individual, entrega 2026-10-19): sistema de gestión de solicitudes de
soporte en **PHP + MySQL**. Consigna resumida y diseño completo en [`doc/PLAN.md`](doc/PLAN.md)
— es la fuente de verdad de alcance, rutas, modelo de datos, seguridad y pruebas.
Se evalúa calidad, estructura, seguridad y **justificación de decisiones** por encima de
cantidad de features.

## Roles y método

- **Orquestador** (otra sesión de Claude Code): planifica, redacta los prompts, verifica e
  integra. Decide qué hallazgos se aceptan.
- **AGENTE 1 UTN — implementador**: escribe código, SQL, README y ADRs que se le pidan.
- **AGENTE 2 UTN — revisor/QA**: revisa adversarialmente y escribe/ejecuta pruebas. **No
  corrige código de la app**: reporta hallazgos (archivo:línea, escenario concreto,
  severidad, fix sugerido) y escribe solo en `tests/` y `doc/pruebas/`.
- Cada prompt trae un ID (`[A1-03]`, `[A2-01]`). Mencioná ese ID en tu respuesta final.
- Los prompts se registran automáticamente en `doc/ia/registro/` (hook). **No editar ni
  borrar nada en `doc/ia/`** — lo mantiene el orquestador.
- Hacé exactamente el alcance del prompt. Si algo del plan te parece incorrecto, decilo
  en la respuesta en vez de desviarte en silencio: las sugerencias descartadas también se
  documentan.

## Stack y restricciones

- PHP ≥ 8.2 (local: 8.5), MariaDB 11.8 local / compatible MySQL 8. Sin frameworks, **sin
  Composer**, sin build steps, sin CDNs ni JS de terceros. CSS propio y sobrio.
- MVC ligero: front controller `public_html/index.php` → `app/routes.php` → Controllers
  (finos, sin SQL) → Models (único lugar con SQL) → Views (sin lógica, todo escapado).
- `declare(strict_types=1);`, namespaces `App\Core|Controllers|Models`, autoloader propio.
- Funciones/métodos chicos y reutilizables; comentarios solo donde aportan el "por qué".

## Reglas de seguridad (no negociables)

1. SQL solo con PDO preparado (`EMULATE_PREPARES=false`). Nunca interpolar entrada.
2. Toda salida HTML pasa por `e()`. Sin excepciones, incluidos valores "propios".
3. Todo POST valida CSRF. Todo `/admin/*` (salvo login) exige `Auth::requireAdmin()`.
4. Contraseñas: `password_hash`/`password_verify`; nunca texto plano, ni en el seed.
5. Errores: nunca mostrar trazas, SQL ni rutas al usuario fuera de `debug`; loguear en
   `storage/logs/`.
6. **Nunca leer, imprimir ni copiar `app/config/config.local.php`** (credenciales reales).
   Para la conexión usá la config cargada por la app; para probar la DB, `php` con la app.
7. Nada de datos personales reales: el seed usa `example.com` y nombres ficticios.

## Entorno local

```bash
# servidor (desde la raíz del repo)
php -S localhost:8080 -t public_html public_html/index.php
# DB: utn_solicitudes (ya creada). Cargar estructura y datos:
#   el cliente necesita la password de config.local.php → usar scripts que la lean vía PHP,
#   o pedirle al orquestador que lo ejecute.
```

## Verificación mínima antes de responder

- `find app public_html scripts -name '*.php' -print0 | xargs -0 -n1 php -l`
- Probar con `curl` / navegador lo que tocaste e incluir en la respuesta qué verificaste y
  qué no pudiste verificar.

## Git

No commitear ni pushear: lo hace el orquestador tras verificar. No tocar archivos fuera
del alcance del prompt.
