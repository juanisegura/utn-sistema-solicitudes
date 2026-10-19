## Resumen

La arquitectura MVC liviana está bien separada: el router aplica autenticación/CSRF, los controllers coordinan, los modelos concentran SQL y las vistas escapan la salida.  
No encontré inyección SQL, bypass de autorización, errores de transacción ni inconsistencias graves en el esquema.  
Sí encontré una protección de caché documentada pero ausente en el código, una configuración que permite activar `debug` en producción y una cobertura incompleta de los contadores por estado.  
La suite declara resultados completos, pero algunas verificaciones importantes dependen de pruebas manuales o no están automatizadas.

## Hallazgos

| ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis |
|---|----------|---------------|----------|--------------------|--------------|----------------------|
| CAL-01 | 🟡 Media | `app/bootstrap.php:111-114` | No se envía `Cache-Control: no-store` en respuestas autenticadas | Un administrador consulta el panel, cierra sesión y otro usuario accede al mismo navegador/dispositivo; el navegador puede mostrar una copia cacheada del panel sin volver a consultar la sesión | Emitir `Cache-Control: no-store, private` en respuestas del panel y agregar una aserción HTTP que revise el header | Verificado |
| CAL-02 | ⚪ Baja | `app/config/config.php:51-53`, `app/bootstrap.php:118-119` | `env=production` no fuerza `debug=false`; además no se valida la combinación de configuración | Una configuración local o de despliegue contiene `env => production` y `debug => true`; un error interno activa `display_errors` y la vista 500 incluye clase, mensaje y archivo/línea | Rechazar configuraciones inválidas o forzar `debug=false` cuando `env === 'production'`; probar ambas combinaciones | Verificado |
| CAL-03 | ⚪ Baja | `tests/integration/FulltextBusquedaTest.php:192-204` | La prueba de M-4 valida solo el contador `Total`, no los tres contadores por estado | Una regresión en `countByEstado()` puede mostrar valores incorrectos para Pendiente/En proceso/Resuelta mientras `Total = resultados` sigue pasando | Crear datos con estados conocidos y afirmar individualmente los tres valores HTML | Verificado |
| CAL-04 | ℹ️ Info | `README.md:215` | La documentación enumera ADR-0001–0010 aunque existe ADR-0011 | Un evaluador puede interpretar que la decisión de búsqueda FULLTEXT no forma parte de la documentación arquitectónica entregada | Cambiar la referencia a ADR-0001–0011 o enlazar explícitamente ADR-0011 | Verificado |

## Detalle de cada hallazgo

### CAL-01 — Ausencia de `Cache-Control: no-store`

El bootstrap envía CSP, `nosniff`, X-Frame-Options y Referrer-Policy:

```php
// app/bootstrap.php:111-114
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
```

No hay ningún `Cache-Control`, `Pragma` ni política equivalente en `Response`, `Router`, los layouts o las respuestas del panel. Sin embargo, la documentación afirma:

```text
doc/pruebas/PRUEBAS.md:98
Sin caché en respuestas con sesión (Cache-Control: no-store)
```

La invalidación de la sesión evita que una nueva petición autorizada acceda al panel, pero no impide que el navegador muestre contenido previamente almacenado al usar “Atrás”. El escenario requiere un navegador que reutilice caché o un dispositivo compartido, por eso la severidad es Media y no Alta.

### CAL-02 — `debug` puede permanecer habilitado en producción

La configuración combina defaults y configuración local y solo normaliza el tipo booleano:

```php
// app/config/config.php:51-53
$config = array_replace_recursive($defaults, $local);
$config['app']['debug'] = filter_var(
    $config['app']['debug'],
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
) ?? false;
```

Luego el bootstrap usa ambos valores independientemente:

```php
// app/bootstrap.php:118-119
$debug = $config['app']['debug'];
ini_set('display_errors', $debug ? '1' : '0');
```

Y la vista 500 agrega detalles internos cuando `$debug` es verdadero:

```php
// app/bootstrap.php:67-70
$detalle = $debug
    ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine())
    : null;
```

El ejemplo de configuración documenta que producción debe usar `debug=false`, pero esa condición no se impone en código. No es un bypass remoto por sí mismo: requiere que quien despliega configure mal el archivo local. El impacto sería exposición de rutas, clases y mensajes internos, por lo que corresponde Baja.

### CAL-03 — Cobertura incompleta de los contadores por estado

El controller calcula los contadores de estado mediante `countByEstado()`:

```php
// app/Controllers/Admin/SolicitudAdminController.php:38
$conteo = Solicitud::countByEstado($q);
```

La vista muestra cuatro valores independientes:

```php
// app/Views/admin/solicitudes/index.php:30-37
<dt>Total</dt>
<dd><?= e($total) ?></dd>

<?php foreach (Estado::cases() as $estado): ?>
    <dt><?= e($estado->label()) ?></dt>
    <dd><?= e($conteo[$estado->value] ?? 0) ?></dd>
<?php endforeach; ?>
```

Pero la prueba de integración solo extrae y compara `Total`:

```php
// tests/integration/FulltextBusquedaTest.php:201-204
if (preg_match('#<dt>Total</dt>\s*<dd>(\d+)</dd>#u', $html, $t) !== 1) {
    fail('no encontré el contador Total');
}
assertSame(3, (int) $t[1], 'Total = resultados');
```

Por lo tanto, una regresión que intercambie, omita o calcule incorrectamente los conteos individuales por estado puede pasar toda la suite. El comportamiento actual del modelo parece correcto y la documentación reconoce que M-4 no tiene un caso dedicado; el hallazgo es de cobertura, no de funcionamiento demostrado.

### CAL-04 — Referencia documental incompleta

El README enlaza la carpeta de ADR, pero describe el rango como:

```text
README.md:215
Decisiones de arquitectura (ADR 0001–0010)
```

La carpeta contiene también `doc/adr/0011-busqueda-fulltext-hibrida.md`, y el resumen técnico la cita expresamente como ADR-0011. Es una inconsistencia documental menor que puede corregirse sin modificar el diseño.

## Áreas verificadas OK

- **Separación de responsabilidades:** `public_html/index.php` funciona como front controller; `Router`/`Kernel` despachan; controllers no contienen SQL; los modelos concentran consultas; `View` encapsula el renderizado.
- **Autenticación y autorización:** las rutas administrativas declaran `auth => true`; `Auth::requireAdmin()` revalida el administrador en la base; los POST administrativos requieren CSRF.
- **CSRF y sesiones:** token por sesión, comparación con `hash_equals`, regeneración de ID al autenticar, cookie `HttpOnly`/`SameSite=Lax`, destrucción de sesión al salir y timeout de inactividad.
- **Validación:** `Request` rechaza arrays en parámetros escalares; `Validator` comprueba UTF-8, controles, límites, campos obligatorios y correo; el estado usa una lista blanca mediante `Estado`.
- **Escape de salida:** las vistas usan `e()` para valores de usuario, atributos, títulos, estados, mensajes, emails y descripciones. El HTML propio del layout y `<wbr>` está separado de los datos escapados.
- **SQL e inyección:**## Resumen

La revisión fue **solo lectura**, sin ejecutar la aplicación, acceder a la base de datos ni realizar llamadas de red.  
La arquitectura MVC liviana está bien separada y las consultas de aplicación usan PDO preparado, transacciones y claves foráneas correctamente.  
Encontré dos problemas funcionales/de defensa en profundidad y dos inconsistencias de documentación/cobertura de pruebas.  
No encontré inyección SQL, XSS demostrable, bypass de autenticación ni N+1 evidente en los recorridos revisados.

## Hallazgos

| ID | Severidad | Archivo:línea | Problema | Escenario concreto | Fix sugerido | Verificado/Hipótesis |
|---|---|---|---|---|---|---|
| Q-01 | 🟡 Media | `app/bootstrap.php:111-114` | Las respuestas autenticadas no envían `Cache-Control: no-store`, aunque la documentación afirma que sí. | Un administrador consulta el panel desde un equipo compartido, cierra sesión y el navegador puede mostrar una copia cacheada al usar “Atrás”. | Emitir `Cache-Control: no-store, private` en las respuestas del panel y probar explícitamente los headers. | Verificado por lectura |
| Q-02 | 🟡 Media | `app/config/config.php:51-53`, `app/bootstrap.php:118` | `debug=true` sigue habilitado aunque `env=production`; no se valida la combinación antes de configurar `display_errors`. | Una configuración local desplegada con `env => 'production'` y `debug => true` permite que un error remoto muestre clase, mensaje y archivo:línea. | Rechazar `debug=true` en producción o forzarlo a `false` con validación explícita de configuración. | Verificado por lectura |
| Q-03 | ⚪ Baja | `tests/integration/FulltextBusquedaTest.php:192-204` | La prueba de contadores solo verifica `Total`, no los tres contadores por estado. | Una regresión en `countByEstado()` podría mostrar valores incorrectos para Pendiente/En proceso/Resuelta y la suite seguiría pasando. | Crear filas con estados conocidos y verificar cada `<dd>` individual, incluidos estados con cero resultados. | Verificado por lectura |
| Q-04 | ⚪ Info | `README.md:215` | La documentación enumera ADR 0001–0010, pero el repositorio contiene y utiliza `doc/adr/0011-busqueda-fulltext-hibrida.md`. | Un evaluador puede no localizar la decisión que justifica FULLTEXT, sus limitaciones y la semántica de búsqueda. | Actualizar el rango a ADR 0001–0011 y revisar referencias similares. | Verificado por lectura |

## Detalle de cada hallazgo

### Q-01 — Falta de política de no caché en el panel

El bootstrap establece CSP, `nosniff`, `X-Frame-Options` y `Referrer-Policy`, pero no una política de caché:

```php
// app/bootstrap.php:111-114
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
```

Las rutas administrativas abren sesión y exigen autenticación desde el router:

```php
// app/Core/Router.php
Session::start();
$this->applyMiddleware($match->method, $match->options);
```

y `Auth::requireAdmin()` verifica la sesión antes del controller. Eso protege el acceso al servidor, pero no impide que el navegador reutilice una respuesta HTML previamente almacenada después del logout.

La propia documentación afirma una protección que no aparece en el código:

```text
doc/pruebas/PRUEBAS.md:98
Sin caché en respuestas con sesión (Cache-Control: no-store)
```

El escenario es plausible en una computadora compartida y afecta confidencialidad de nombres, emails, descripciones e historial. La prueba citada en la documentación verifica el comportamiento del navegador, pero no hay una aserción visible de `Cache-Control` en `tests/smoke.sh` ni en `tests/integration/ControlFlujoTest.php`.

### Q-02 — `debug` no queda subordinado a `env=production`

La configuración combina defaults y configuración local, y solo normaliza el tipo de `debug`:

```php
// app/config/config.php:51-53
$config = array_replace_recursive($defaults, $local);
$config['app']['debug'] = filter_var(
    $config['app']['debug'],
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
) ?? false;
```

Luego el bootstrap usa directamente ese valor:

```php
// app/bootstrap.php:118
$debug = $config['app']['debug'];
ini_set('display_errors', $debug ? '1' : '0');
```

La única relación con producción es configurar `Secure` para la cookie:

```php
// app/bootstrap.php:126
Session::configure((int) $config['app']['session_timeout'], $config['app']['env'] === 'production');
```

No hay una guarda que impida `env=production` junto con `debug=true`. Si una configuración de despliegue queda con esa combinación y un atacante provoca una excepción, `app_render_500()` mostrará el detalle:

```php
// app/bootstrap.php:68-71
$detalle = $debug
    ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine())
    : null;
```

La documentación indica que fuera de desarrollo debe usarse `debug=false`, pero la seguridad depende completamente de que el operador no cometa ese error. La corrección debería estar en la carga/validación central de configuración, no solo en README.

### Q-03 — Cobertura incompleta de los contadores por estado

El controller usa la consulta agrupada para generar los cuatro valores visibles:

```php
// app/Controllers/Admin/SolicitudAdminController.php:38
$conteo = Solicitud::countByEstado($q);
```

La vista muestra un contador por cada estado:

```php
// app/Views/admin/solicitudes/index.php:31-36
<dt>Total</dt>
<dd><?= e($total) ?></dd>
...
<dt><?= e($estado->label()) ?></dt>
<dd><?= e($conteo[$estado->value] ?? 0) ?></dd>
```

Sin embargo, la prueba de integración solo extrae y compara `Total`:

```php
// tests/integration/FulltextBusquedaTest.php:192-204
test('contadores con q coherentes con el listado (M-4 con el mismo filtro)', function () {
    ...
    if (preg_match('#<dt>Total</dt>\s*<dd>(\d+)</dd>#u', $html, $t) !== 1) {
        fail('no encontré el contador Total');
    }
    assertSame(3, (int) $t[1], 'Total = resultados');
});
```

Por lo tanto, un cambio que altere las claves devueltas por `countByEstado()`, el mapeo de estados o los ceros para estados ausentes podría no ser detectado. Es una deficiencia de cobertura, no un fallo de seguridad actual.

### Q-04 — Rango de ADR desactualizado

El README enlaza la documentación con este texto:

```text
README.md:215
Decisiones de arquitectura (ADR 0001–0010)
```

Pero existe `doc/adr/0011-busqueda-fulltext-hibrida.md`, y además `doc/RESUMEN-TECNICO.md` lo cita explícitamente para justificar la búsqueda FULLTEXT. La inconsistencia puede dificultar la evaluación y rompe la trazabilidad documental, pero no afecta el comportamiento de la aplicación.

## Áreas verificadas OK

- **Separación de responsabilidades:** `public_html/index.php` actúa como front controller; `Router` despacha; los controllers coordinan; los modelos concentran SQL; las vistas presentan datos.
- **Autenticación y autorización:** las rutas administrativas declaran `auth => true`; `Auth::requireAdmin()` se ejecuta antes del CSRF y del controller; se revalida el administrador contra la base.
- **CSRF y sesiones:** los POST se protegen por defecto; login regenera la sesión y rota el token; logout requiere POST y CSRF; las cookies usan `HttpOnly`, `SameSite=Lax` y `Secure` en producción.
- **Validación de entrada:** `Request::string()` rechaza arrays; `Validator` comprueba UTF-8, controles, límites, campos obligatorios y email; los estados pasan por el enum `Estado`.
- **Escape HTML:** las vistas usan `e()` para datos de usuario y atributos; el único HTML no escapado relevante (`$content` y `<wbr>`) es markup generado internamente, no entrada externa.
- **SQL de la aplicación:** las consultas de modelos usan `Database::prepare()` y parámetros; los identificadores dinámicos revisados proceden de constantes internas.
- **Transacciones:** el cambio de estado usa `SELECT ... FOR UPDATE`, actualiza el estado y registra el evento dentro de una misma transacción.
- **Base de datos:** InnoDB, `utf8mb4`, claves primarias, índices para estado/login/FULLTEXT y FKs con `CASCADE`/`RESTRICT` coherentes con el historial.
- **Consultas y N+1:** el listado obtiene sus filas en una consulta, los contadores en otra agrupada y el historial en una consulta; no se observó un N+1 en las rutas revisadas.
- **Manejo de errores:** se separan errores de control HTTP de excepciones internas; se registra el error y se evita mostrar detalles fuera de debug.
- **Tipos y estilo:** los archivos PHP revisados declaran `strict_types=1`, usan firmas tipadas y namespaces coherentes.
- **Pruebas positivas:** la suite cubre rutas, CSRF, sesiones, validación, autenticación, concurrencia del límite de login, transacciones del historial, FULLTEXT y códigos HTTP.
- **Limitaciones documentadas:** la semántica FULLTEXT para subcadenas internas y tokens de más de 84 caracteres figura explícitamente en ADR-0011; no la reporto como hallazgo nuevo.

