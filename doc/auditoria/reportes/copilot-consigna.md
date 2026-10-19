Voy a relevar primero el árbol y los documentos normativos completos; después contrastaré cada requisito con el código y las pruebas, sin ejecutar la aplicación ni realizar cambios.

La documentación declara una suite extensa y una corrida oficial, pero ya aparece una posible inconsistencia temporal/conceptual en los conteos de `SELECCION-IA.md` frente a `PRUEBAS.md`; ahora la contrasto con README, configuración, rutas, controladores, modelos, vistas y SQL.

El contraste del código no muestra por ahora una vulnerabilidad demostrable: las rutas pasan por middleware central, los modelos parametrizan SQL y las vistas escapan datos. Sí encontré inconsistencias documentales verificables; completaré la matriz requisito-por-requisito y citaré líneas exactas, incluyendo la discrepancia entre el plan histórico y el árbol final.

## Resumen

La auditoría fue **solo de lectura**: no se modificaron archivos, no se ejecutó la aplicación, no se accedió a la base de datos ni se hizo uso de red.  
El código revisado cumple los requisitos funcionales, técnicos y de seguridad principales: rutas protegidas, CSRF centralizado, PDO con consultas preparadas, validación server-side, escape HTML, hash de contraseñas y configuración local fuera del repositorio.  
Las siete pruebas mínimas tienen evidencia documentada y casos concretos en `tests/` y `doc/pruebas/PRUEBAS.md`.  
Se detectaron inconsistencias documentales de severidad informativa, principalmente referencias históricas no actualizadas respecto de las mejoras finales.

## Hallazgos

| ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis |
|---|---|---|---|---|---|---|
| I-01 | Info | `doc/ia/SELECCION-IA.md:5`, `doc/ia/SELECCION-IA.md:89-91` | La selección de IA declara decisiones hasta D-53 y una corrida de 101/11/84, pero el registro final llega hasta D-62 y la evidencia vigente declara 137/27/84. | Un evaluador que compare la selección con `DECISIONES.md` y `PRUEBAS.md` verá que el documento no describe la versión final. | Actualizar D-53 → D-62 y 101/11/84 → 137/27/84; aclarar que las cifras antiguas pertenecen a una corrida histórica. | **Verificado** |
| I-02 | Info | `README.md:215` | El README enlaza “ADRs 0001–0010”, aunque existe y se utiliza `doc/adr/0011-busqueda-fulltext-hibrida.md`. | La arquitectura, el resumen técnico y las pruebas mencionan ADR-0011, pero el índice principal aparenta omitirlo. | Cambiar el rango a 0001–0011. | **Verificado** |
| I-03 | Info | `doc/PLAN.md:195`, `doc/ia/DECISIONES.md:28` | El plan y la decisión D-20 todavía describen el listado “sin paginación”, mientras que el código final implementa paginación y la documentación vigente la presenta como funcionalidad. | Un evaluador puede interpretar que la implementación contradice el contrato inicial o que la mejora no fue incorporada formalmente al requisito. | Marcar D-20 como superscripta por M-3/R-1 o actualizar el texto del plan para reflejar el estado final. | **Verificado** |
| I-04 | Info | `doc/RESUMEN-TECNICO.md:14-16`, `doc/pruebas/PRUEBAS.md:5`, `doc/ia/SELECCION-IA.md:89-91` | Hay tres cifras históricas distintas para la suite: 101/11/84, 137/27/84 y referencias a corridas previas. La evidencia vigente está correctamente identificada, pero la selección de IA no lo está. | Un lector que consulte primero `SELECCION-IA.md` puede tomar como resultado final una corrida anterior. | Mantener los históricos, pero rotularlos explícitamente como “corrida previa” y dejar una única cifra final consistente en todos los documentos principales. | **Verificado** |

No se identificó un hallazgo de seguridad explotable demostrable con el código leído.

## Detalle de cada hallazgo

### I-01 — Selección de IA desactualizada

`doc/ia/SELECCION-IA.md` afirma:

```text
[doc/ia/SELECCION-IA.md:3-7]
La bitácora por tarea está en PROMPTS.md, las decisiones numeradas (D-01…D-53)
en DECISIONES.md...
```

También informa:

```text
[doc/ia/SELECCION-IA.md:87-91]
Nunca acepté un "PASS" informado sin repetirlo...
La corrida oficial final con run-all.sh dio
unit 101, integración 11 y smoke 84, todos OK.
```

Sin embargo, `doc/ia/DECISIONES.md` contiene decisiones posteriores:

```text
[doc/ia/DECISIONES.md:61-69]
D-55 ... FULLTEXT, RouteMatcher ...
D-56 ... FULLTEXT híbrido ...
D-57 ... RouteMatcher ...
D-58 ... Database ...
D-59 ...
D-62 ... limitación documentada ...
```

Y la evidencia vigente declara:

```text
[doc/pruebas/PRUEBAS.md:5-6]
Corrida oficial vigente ... 137 tests unitarios, 27 de integración y 84 casos smoke...
```

El problema es documental, no funcional ni de seguridad: la selección contiene información válida de una fase anterior, pero no está rotulada como histórica.

### I-02 — Índice de ADR incompleto en README

El README dice:

```text
[README.md:213-216]
- Plan...
- Resumen técnico...
- Decisiones de arquitectura (ADR 0001–0010)
```

Existe además:

```text
doc/adr/0011-busqueda-fulltext-hibrida.md
```

El ADR-0011 es citado por el propio resumen técnico:

```text
[doc/RESUMEN-TECNICO.md:75-77]
... búsqueda FULLTEXT híbrida ... (ADR-0011)
```

La implementación también contiene el índice FULLTEXT:

```text
[database/schema.sql:39-42]
KEY idx_estado_fecha ...
FULLTEXT KEY ft_busqueda (asunto, nombre, email, descripcion)
```

No falta el ADR ni la implementación; solo está incompleto el índice documental del README.

### I-03 — Contrato histórico de paginación no actualizado

El plan inicial conserva:

```text
[doc/PLAN.md:195]
Listado ordenado por `created_at DESC, id DESC`, sin paginación (D-20).
```

La decisión correspondiente también dice:

```text
[doc/ia/DECISIONES.md:28]
D-20 ... Listado por fecha descendente, sin paginación ... paginación queda como mejora
```

Pero el controlador final implementa paginación:

```text
[app/Controllers/Admin/SolicitudAdminController.php:29-37]
$pagina = $this->filtroPagina();
$porPagina = self::porPagina();
...
$solicitudes = array_map(
    self::presentar(...),
    Solicitud::buscar($estado, $q, $porPagina, ($pagina - 1) * $porPagina)
);
```

Y la evidencia la verifica:

```text
[doc/pruebas/PRUEBAS.md:42]
B — búsqueda, paginación, historial...
```

La mejora sí está documentada en otras partes, pero falta marcar explícitamente que D-20 fue reemplazada por M-3/R-1.

### I-04 — Cifras históricas ambiguas

El resumen técnico declara:

```text
[doc/RESUMEN-TECNICO.md:14-16]
137 tests unitarios, 27 de integración y 84 casos de smoke HTTP
```

La evidencia vigente coincide:

```text
[doc/pruebas/PRUEBAS.md:26-31]
Unitarios ... 137 PASS
Integración ... 27 PASS
Smoke HTTP ... 84 PASS
```

Pero la selección de IA conserva:

```text
[doc/ia/SELECCION-IA.md:89-91]
La corrida oficial final ... unit 101, integración 11 y smoke 84
```

Además, `PRUEBAS.md` identifica correctamente corridas anteriores, por ejemplo:

```text
[doc/pruebas/PRUEBAS.md:61]
... todas en verde en la corrida oficial vigente (137 / 27 / 84)
```

La recomendación es conservar el historial, pero diferenciar visualmente “corrida histórica” de “corrida oficial vigente”.

## Matriz de cumplimiento de la consigna y entregables

| Requisito | Estado (Cumple/Parcial/Falta) | Evidencia (archivo:línea) | Observación |
|---|---|---|---|
| Formulario público con nombre, correo, asunto y descripción | Cumple | `app/Controllers/SolicitudController.php:17-22`; `app/Views/solicitudes/create.php:38-56` | Los cuatro campos están definidos y renderizados. |
| Validación server-side de campos obligatorios | Cumple | `app/Controllers/SolicitudController.php:17-22`, `38-45`; `app/Core/Validator.php:23-57` | Se valida antes de insertar y se responde con 422. |
| Validación server-side de email | Cumple | `app/Controllers/SolicitudController.php:19`; `app/Core/Validator.php:119-123` | Usa `FILTER_VALIDATE_EMAIL`. |
| Confirmación visible después del alta | Cumple | `app/Controllers/SolicitudController.php:47-61`; `app/Views/solicitudes/gracias.php:1-10` | Usa PRG y flash de sesión. |
| Inicio de sesión de administrador | Cumple | `app/routes.php:17-18`; `app/Controllers/Admin/AuthController.php:14-52` | Login con CSRF y límite de intentos. |
| Cierre de sesión | Cumple | `app/routes.php:19`; `app/Controllers/Admin/AuthController.php:59-64`; `app/Core/Auth.php:96-100` | Requiere autenticación y CSRF por middleware. |
| Listado de solicitudes | Cumple | `app/routes.php:20`; `app/Controllers/Admin/SolicitudAdminController.php:25-52` | Acceso protegido. |
| Filtro por pendiente, en proceso y resuelta | Cumple | `app/Models/Estado.php`; `app/Controllers/Admin/SolicitudAdminController.php:81-93`; `app/Views/admin/solicitudes/index.php:41-47` | La lista blanca proviene del enum PHP. |
| Vista de detalle | Cumple | `app/routes.php:21`; `app/Controllers/Admin/SolicitudAdminController.php:54-59`; `app/Views/admin/solicitudes/show.php:20-65` | Incluye descripción, datos, estado e historial. |
| Cambio de estado desde el panel | Cumple | `app/routes.php:22`; `app/Controllers/Admin/SolicitudAdminController.php:61-79`; `app/Models/Solicitud.php:188-218` | Usa transacción y registra historial. |
| Restricción de operaciones administrativas sin sesión | Cumple | `app/routes.php:19-22`; `app/Core/Router.php:42-48`; `app/Core/Auth.php:84-94` | GET redirige; POST responde 401; el controller no se ejecuta. |
| Tablas para administradores y solicitudes | Cumple | `database/schema.sql:13-42` | Tablas InnoDB, `utf8mb4`, índices y restricciones. |
| Script SQL reproducible | Cumple | `database/schema.sql:1-11`; `scripts/instalar-db.php:39-68` | El instalador recrea esquema y seed mediante la configuración de la app. |
| Datos de prueba no sensibles | Cumple | `database/seed.sql:1-20`; `README.md:84-91` | Usa `example.com`, nombres ficticios y solo hash en SQL. |
| PHP en servidor | Cumple | `public_html/index.php:1-16`; `README.md:28-33` | Front controller propio, PHP 8.2+. |
| MySQL/MariaDB para persistencia | Cumple | `app/Core/Database.php:89-114`; `database/schema.sql:1-3` | PDO MySQL, compatible con MySQL 8/MariaDB. |
| Responsabilidades separadas | Cumple | `README.md:170-195`; `doc/RESUMEN-TECNICO.md:20-39` | Core, controllers, models, views, scripts y configuración separados. |
| Consultas preparadas | Cumple | `app/Core/Database.php:67-74`, `99-106`; `app/Models/Solicitud.php:32-35`, `181-218` | `prepare`, bind tipado y `EMULATE_PREPARES=false`. |
| Validación server-side | Cumple | `app/Core/Validator.php:23-57`; `app/Controllers/Admin/SolicitudAdminController.php:81-111` | También cubre arrays, UTF-8, controles y límites. |
| Escape HTML | Cumple | `app/helpers.php:5-8`; ejemplos en `app/Views/admin/solicitudes/show.php:23-64` y `app/Views/admin/solicitudes/index.php:22-103` | Las salidas dinámicas pasan por `e()`. El HTML estructural del layout no contiene datos crudos. |
| Hash seguro de contraseñas | Cumple | `app/Models/Admin.php:13-16`, `106-114`; `app/Core/Auth.php:36-40` | Bcrypt fijo, costo 12, `password_verify`, sin contraseña en seed. |
| CSRF en operaciones mutantes | Cumple | `app/Core/Router.php:46-48`; `app/Core/Csrf.php:10-43`; `app/routes.php:14`, `18-22` | Todos los POST quedan protegidos por defecto. |
| Administración adecuada de sesiones | Cumple | `app/Core/Session.php:35-60`, `65-99`; `app/Core/Auth.php:50-54` | Strict mode, cookies HttpOnly/SameSite, regeneración y timeout. |
| Códigos HTTP apropiados | Cumple | `app/Core/Router.php:24-30`, `42-48`; `app/Controllers/SolicitudController.php:40-50`; `doc/pruebas/PRUEBAS.md:14-20` | Se documentan 302, 400, 401, 404, 419, 422 y 429. |
| No exposición de detalles internos fuera de debug | Cumple | `app/bootstrap.php:18-24`, `72-109`; `app/Core/Database.php:112-115` | Mensajes públicos genéricos; detalles solo en log/debug. |
| Secretos fuera del repositorio | Cumple | `.gitignore:1-6`; `app/config/config.php:15-30`; `README.md:57-65` | `config.local.php` está excluido y hay ejemplo documentado. |
| Código legible y reutilizable | Cumple | `app/Core/*`; `doc/RESUMEN-TECNICO.md:28-39` | Separación clara y helpers reutilizables. |
| Prueba 1: solicitud válida | Cumple | `doc/pruebas/PRUEBAS.md:14`; evidencia `smoke## Resumen

La auditoría estática de cumplimiento no detecta vulnerabilidades explotables demostrables: las rutas aplican autenticación/CSRF centralmente, las consultas usan PDO preparado y las vistas escapan los datos.  
La funcionalidad obligatoria está implementada y existe evidencia documentada para las 7 pruebas mínimas.  
Sí hay inconsistencias documentales que un evaluador podría objetar: conteos y decisiones desactualizados en `SELECCION-IA.md`, el README omite ADR-0011 y el PLAN conserva afirmaciones de una versión anterior sin paginación.  
No ejecuté la aplicación, no accedí a la base de datos ni hice solicitudes de red, conforme a la regla de solo lectura.

## Hallazgos

| ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis |
|---|---|---|---|---|---|---|
| AUD-01 | ⚪ Info | `doc/ia/SELECCION-IA.md:5`, `doc/ia/SELECCION-IA.md:89-91`, `doc/pruebas/PRUEBAS.md:5`, `doc/ia/DECISIONES.md:69` | La selección de IA afirma que las decisiones llegan hasta D-53 y documenta una corrida final de 101/11/84, pero el registro contiene D-55 a D-62 y la evidencia vigente declara 137/27/84. | Un evaluador puede considerar que el entregable de IA no describe el estado final o que la corrida final no es identificable. | Actualizar `SELECCION-IA.md` para mencionar D-01…D-62 y reemplazar los conteos históricos por 137/27/84, aclarando que 101/11/84 fue una corrida anterior. | **Verificado** por lectura cruzada. |
| AUD-02 | ⚪ Info | `README.md:215` | El README enlaza “ADRs 0001–0010”, pero existe `doc/adr/0011-busqueda-fulltext-hibrida.md` y el resumen técnico lo cita. | El evaluador puede interpretar que falta documentar una decisión arquitectónica realmente utilizada. | Cambiar la referencia a “ADR 0001–0011”. | **Verificado** por listado del árbol y referencias cruzadas. |
| AUD-03 | ⚪ Info | `doc/PLAN.md:195`, `doc/ia/DECISIONES.md:28`, `README.md:20-22`, `doc/pruebas/PRUEBAS.md:41-46` | El PLAN y D-20 dicen que el listado no tiene paginación, mientras el código, README y pruebas finales implementan y verifican paginación. | El PLAN es presentado como fuente de verdad, pero describe un contrato anterior al estado final. | Marcar D-20/PLAN §6 como superseded por la fase de mejoras, o actualizar el contrato final para incluir búsqueda y paginación. | **Verificado** por comparación documental y código. |
| AUD-04 | ⚪ Info | `README.md:90-97`, `database/seed.sql:7-10` | El README publica credenciales demo. No es una violación de la consigna porque están explícitamente destinadas a datos de prueba, pero conviene distinguirlas visualmente de credenciales reales. | Un usuario podría reutilizar la contraseña demo fuera del entorno local. | Mantenerla solo como credencial local y agregar una advertencia visible de que debe cambiarse o no cargarse en producción. | **Verificado; mejora preventiva**, no vulnerabilidad del alcance. |
| AUD-05 | ⚪ Info | `README.md:118-130`, `public_html/.htaccess:5-23`, `public_html/index.php:8-24` | La protección depende de configurar correctamente `public_html/` como DocumentRoot o de que Apache procese `.htaccess`; el propio README lo advierte. | Una instalación Apache con `AllowOverride` deshabilitado podría no aplicar rewrite, headers o bloqueos. | Mantener la advertencia y agregar una comprobación de instalación/documentación para confirmar `AllowOverride` y DocumentRoot. | **Verificado como condición de despliegue**, no como defecto explotable dentro de la configuración documentada. |

## Detalle de cada hallazgo

### AUD-01 — Selección de IA desactualizada

`SELECCION-IA.md` afirma:

> `doc/ia/SELECCION-IA.md:5`  
> `las decisiones numeradas (D-01…D-53)`

y también informa:

> `doc/ia/SELECCION-IA.md:89-91`  
> `La corrida oficial final con run-all.sh dio ... unit 101, integración 11 y smoke 84`

Sin embargo, el registro contiene decisiones posteriores:

> `doc/ia/DECISIONES.md:63` — D-55  
> `doc/ia/DECISIONES.md:64` — D-56  
> `doc/ia/DECISIONES.md:69` — D-62

La evidencia vigente declara otros valores:

> `doc/pruebas/PRUEBAS.md:5`  
> `137 tests unitarios, 27 de integración y 84 casos smoke`

No es un fallo de seguridad ni demuestra que las pruebas no se hayan ejecutado; es una inconsistencia del entregable de metodología y evidencia.

### AUD-02 — Referencia incompleta de ADR

El README dice:

> `README.md:215`  
> `[Decisiones de arquitectura (ADR 0001–0010)](doc/adr/)`

Pero el árbol contiene `doc/adr/0011-busqueda-fulltext-hibrida.md`, y el resumen técnico utiliza esa decisión:

> `doc/RESUMEN-TECNICO.md:75-77`  
> `Búsqueda FULLTEXT híbrida ... (ADR-0011)`

La documentación no debería presentar como completo un rango que omite el ADR usado por el sistema final.

### AUD-03 — PLAN histórico contradictorio con la implementación final

El PLAN conserva:

> `doc/PLAN.md:195`  
> `Listado ordenado por created_at DESC, id DESC, sin paginación (D-20).`

Pero el controlador implementa paginación:

> `app/Controllers/Admin/SolicitudAdminController.php:29-37`  
> `filtroPagina()`, cálculo de páginas y `Solicitud::buscar(..., $porPagina, ...)`

El README también la anuncia:

> `README.md:20-22`  
> `filtro por estado, contadores y paginación (20 por página)`

Y las pruebas finales la cubren:

> `doc/pruebas/PRUEBAS.md:42`  
> `B — búsqueda, paginación, historial...`

La implementación parece coherente; lo que falta es actualizar o versionar explícitamente el contrato histórico.

### AUD-04 — Credencial demo publicada

El README publica:

> `README.md:84-91`  
> `admin@example.com` / `Demo-UTN-2026!`, indicando que son datos de prueba.

El seed contiene únicamente el hash:

> `database/seed.sql:7-10`  
> `La contraseña demo ... solo figura su hash`

La decisión es compatible con la consigna de datos de prueba no sensibles y el README advierte no cargar el seed en producción:

> `README.md:93-97`

Por eso no lo clasifico como vulnerabilidad del TP. Es solamente una advertencia de higiene operativa.

## Áreas verificadas OK

### Cumplimiento de la consigna y entregables

| Requisito | Estado (Cumple/Parcial/Falta) | Evidencia (archivo:línea) | Observación |
|---|---|---|---|
| Formulario público con nombre, email, asunto y descripción | Cumple | `app/Controllers/SolicitudController.php:17-22`; `app/Views/solicitudes/create.php:10-56`; `app/routes.php:13-15` | Los cuatro campos están definidos y publicados. |
| Validación server-side de obligatorios | Cumple | `app/Controllers/SolicitudController.php:17-22,40-45`; `app/Core/Validator.php:23-57,100-128` | Se validan reglas `required`, límites y controles. |
| Validación server-side de email | Cumple | `app/Controllers/SolicitudController.php:19`; `app/Core/Validator.php:119-123` | Usa `FILTER_VALIDATE_EMAIL`. |
| Confirmación visible posterior al alta | Cumple | `app/Controllers/SolicitudController.php:47-61`; `app/routes.php:15`; `doc/pruebas/PRUEBAS.md:14` | Implementa PRG y flash con número de solicitud. |
| Inicio de sesión administrativo | Cumple | `app/routes.php:17-18`; `app/Controllers/Admin/AuthController.php:10-57` | Incluye CSRF y límite de intentos. |
| Cierre de sesión | Cumple | `app/routes.php:19`; `app/Controllers/Admin/AuthController.php:61-66`; `app/Core/Auth.php:96-100` | Logout por POST protegido. |
| Listado de solicitudes | Cumple | `app/routes.php:20`; `app/Controllers/Admin/SolicitudAdminController.php:25-52`; `app/Models/Solicitud.php:42-68` | Incluye búsqueda, contadores y paginación adicional. |
| Filtro pendiente/en proceso/resuelta | Cumple | `app/Models/Estado.php`; `app/Controllers/Admin/SolicitudAdminController.php:81-93`; `database/schema.sql:33-41` | Lista blanca en enum PHP y ENUM SQL. |
| Detalle de solicitud | Cumple | `app/routes.php:21`; `app/Controllers/Admin/SolicitudAdminController.php:54-59`; `app/Views/admin/solicitudes/show.php:20-66` | Incluye historial y datos de la solicitud. |
| Cambio de estado | Cumple | `app/routes.php:22`; `app/Controllers/Admin/SolicitudAdminController.php:61-79`; `app/Models/Solicitud.php:188-218` | Se ejecuta en transacción con historial. |
| Restricción de `/admin` sin autenticación | Cumple | `app/routes.php:19-22`; `app/Core/Router.php:40-49`; `app/Core/Auth.php:84-94` | GET redirige a login; POST responde 401. |
| Tablas para administradores y solicitudes | Cumple | `database/schema.sql:13-42` | Ambas son InnoDB y usan `utf8mb4`. |
| Script SQL reproducible | Cumple | `database/schema.sql:1-11`; `scripts/instalar-db.php:35-85`; `README.md:67-74` | El instalador recrea esquema y opcionalmente carga seed. |
| Datos de prueba no sensibles | Cumple | `database/seed.sql:1-10`; `README.md:90-91` | Usa hash y dominios `example.com`. |
| PHP/MySQL | Cumple | `README.md:26-33`; `app/Core/Database.php:89-114` | PDO MySQL y requisitos documentados. |
| Separación de responsabilidades | Cumple | `README.md:170-195`; `doc/RESUMEN-TECNICO.md:20-39` | Front controller, Core, Controllers, Models y Views separados. |
| Consultas preparadas | Cumple | `app/Core/Database.php:67-74,99-106`; `app/Models/Solicitud.php:32-34,181-218,237-250`; `app/Models/Admin.php:59-85` | Los valores externos se bindean; SQL dinámico se limita a constantes internas. |
| Validación y escape HTML | Cumple | `app/Core/Validator.php:23-57`; `app/helpers.php:3-9`; `app/Views/admin/solicitudes/show.php:22-65`; `app/Views/admin/solicitudes/index.php:22-103` | Las salidas de datos pasan por `e()`. El `<wbr>` del email es markup constante y cada tramo se escapa. |
| Hash seguro de contraseñas | Cumple | `app/Models/Admin.php:14-36,81-114`; `app/Core/Auth.php:36-40`; `database/seed.sql:7-10` | Bcrypt, `password_hash`, `password_verify`, dummy hash y sin password en claro en SQL. |
| CSRF | Cumple | `app/Core/Router.php:46-48`; `app/Core/Csrf.php:10-43`; vistas de formularios con `Csrf::field()` | Todo POST queda protegido por defecto; no hay rutas POST con `csrf => false`. |
| Sesiones | Cumple | `app/Core/Session.php:35-61,65-100`; `app/Core/Auth.php:50-54,96-100` | HttpOnly, SameSite, Secure en producción, strict mode, regeneración y timeout. |
| HTTP y errores | Cumple | `app/Core/Router.php:24-30`; `app/Core/Kernel.php:10-18`; `app/bootstrap.php:42-119` | 404/405/401/419/500, header `Allow`, logging y vista 500 genérica. |
| Secretos fuera del repositorio | Cumple | `.gitignore:1-3`; `app/config/config.php:5-31`; `README.md:57-65` | `config.local.php` está ignorado y existe ejemplo documentado. |
| Código legible y reutilizable | Cumple | `app/Core/*`, `app/Models/*`, `doc/RESUMEN-TECNICO.md:20-39` | La estructura es clara y sin framework/Composer, conforme al alcance. |
| Prueba 1: solicitud válida | Cumple | `doc/pruebas/PRUEBAS.md:14`; `doc/pruebas/evidencias/smoke-20261019-162141.txt` | P1.1–P1.6, 6/6 declarados PASS. |
| Prueba 2: campos inválidos | Cumple | `doc/pruebas/PRUEBAS.md:15`; `tests/smoke.sh` mediante el mapeo de `tests/README.md` | P2.1–P2.5, 5/5 declarados PASS. |
| Prueba 3: panel sin sesión | Cumple | `doc/pruebas/PRUEBAS.md:16`; `app/Core/Auth.php:84-94` | GET redirige y logout anónimo responde 401. |
| Prueba 4: credenciales incorrectas | Cumple | `doc/pruebas/PRUEBAS.md:17`; `app/Controllers/Admin/AuthController.php:53-57` | Mensaje genérico y 401. |
| Prueba 5: cambio autorizado | Cumple | `doc/pruebas/PRUEBAS.md:18`; `app/Controllers/Admin/SolicitudAdminController.php:73-78` | Persistencia, redirect PRG y flash. |
| Prueba 6: cambio no autorizado | Cumple | `doc/pruebas/PRUEBAS.md:19`; `app/Core/Router.php:42-48`; `app/Controllers/Admin/SolicitudAdminController.php:65-70` | Cubre auth, CSRF, estado inválido, ausencia y arrays. |
| Prueba 7: HTML/SQL malicioso | Cumple | `doc/pruebas/PRUEBAS.md:20`; `app/helpers.php:3-9`; `app/Core/Database.php:67-74` | Escape HTML y consultas preparadas. |
| Selección de prompts y herramientas de IA | Parcial | `doc/ia/SELECCION-IA.md:1-119` | Contiene herramientas, prompts, verificación, descartes y decisiones; requiere corregir el rango D-01…D-53 y los conteos obsoletos de AUD-01. |
| Código fuente | Cumple | Árbol `app/`, `public_html/`, `scripts/`, `database/`, `tests/` | El export contiene el código fuente completo. |
| Archivo SQL | Cumple | `database/schema.sql`, `database/seed.sql` | Esquema y seed reproducibles mediante instalador. |
| README con requisitos/configuración/pasos | Cumple | `README.md:26-82,100-156` | Documenta requisitos, DB, configuración, ejecución, Apache y pruebas. |
| Resumen técnico de 1–3 páginas | Cumple | `doc/RESUMEN-TECNICO.md:1-145` | Incluye arquitectura, decisiones, dificultades, mejoras y límites. |
| Prompts y evidencia de IA | Cumple con corrección documental | `doc/ia/SELECCION-IA.md:28-119`; `doc/ia/PROMPTS.md`; `doc/ia/registro/` | Hay selección, registro completo, verificación y descartes; corregir referencias obsoletas. |
| Evidencia de pruebas | Cumple | `doc/pruebas/PRUEBAS.md:5-31,71-106`; `doc/pruebas/evidencias/` | Hay corrida vigente, smoke, integración, unitarios y capturas. La evidencia se acepta documentalmente; no fue reejecutada en esta auditoría. |
| Criterios de funcionamiento y organización | Cumple documentalmente | `doc/RESUMEN-TECNICO.md:20-39`; `README.md:168-195` | La separación de capas y rutas está respaldada por el código. |
| Criterios de DB y validaciones | Cumple | `database/schema.sql:13-71`; `app/Core/Validator.php:23-128`; `app/Models/Solicitud.php:227-252` | Esquema, límites y filtros están implementados. |
| Criterios de autenticación/sesiones | Cumple | `app/Core/Auth.php:24-100`; `app/Core/Session.php:35-100`; `app/Core/Csrf.php:23-43` | Guardas relacionadas revisadas junto con router y controladores. |
| Criterios de errores/mantenibilidad/justificación | Cumple | `app/bootstrap.php:42-119`; `doc/RESUMEN-TECNICO.md:81-140`; `doc/ia/DECISIONES.md:7-69` | Hay manejo central de errores, logs y justificaciones técnicas. |
| Repositorio Git o ZIP | Parcial / no verificable en este export | `README.md:37-42`; árbol auditado sin `.git/` | El README documenta un repositorio Git remoto, pero el material recibido es un export sin metadatos Git. No puedo verificar commits, historial o que el remoto corresponda al código auditado. |

### Áreas verificadas OK

- **OK: interfaz pública.** Rutas, campos, validación, CSRF, persistencia y confirmación PRG revisados en `app/routes.php:13-15`, `SolicitudController.php:17-61` y las vistas públicas.
- **OK: panel administrativo.** Login, logout, listado, filtro, detalle, cambio de estado y autorización revisados junto con sus llamadores y middleware.
- **OK: autenticación y sesiones.** `Auth::requireAdmin()`, revalidación del administrador activo, regeneración de sesión, cookies endurecidas y timeout revisados.
- **OK: CSRF.** El router valida todos los POST por defecto y los formularios generan el token mediante `Csrf::field()`.
- **OK: SQL injection.** No se encontró interpolación de entrada en consultas; los identificadores dinámicos observados provienen de constantes internas.
- **OK: XSS almacenado/reflejado.** Los datos de solicitudes, errores, búsqueda, emails, nombres y URLs se escapan con `e()`.
- **OK: contraseñas.** No se encontró password en claro en el seed; el alta y rehash pasan por `password_hash`.
- **OK: control de errores.** La aplicación desactiva detalles fuera de debug, registra excepciones y responde con vistas de error.
- **OK: protección de archivos públicos.** `public_html/index.php`, `.htaccess` y el DocumentRoot documentado restringen el acceso a `app`, `database`, `doc` y dotfiles bajo las condiciones Apache documentadas.
- **OK: persistencia y consistencia.** Las tablas requeridas existen; el cambio de estado usa transacción y el historial tiene claves foráneas.
- **OK: pruebas mínimas documentadas.** Las siete aparecen individualmente en `doc/pruebas/PRUEBAS.md:14-20`, con casos, resultado y evidencia.
- **OK: uso de IA.** La selección explica herramientas, prompts principales, verificación, sugerencias descartadas y decisiones del estudiante; requiere solamente actualizar las referencias históricas señaladas en AUD-01.
- **OK: entregables documentales.** Están presentes README, SQL, resumen técnico, selección de IA, registro, pruebas y evidencias.

