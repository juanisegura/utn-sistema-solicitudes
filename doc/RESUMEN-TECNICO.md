# Resumen técnico — Sistema de gestión de solicitudes

Desarrollé un sistema de solicitudes de soporte en PHP 8.2+ y MySQL/MariaDB, sin frameworks
ni Composer:

- **Parte pública:** formulario con confirmación.
- **Panel de administración:** login con límite de intentos; listado con búsqueda, filtro por
  estado y paginación; detalle con cambio de estado e historial.

Lo planifiqué con contratos escritos antes de implementar ([PLAN](PLAN.md)). Lo desarrollé
con asistencia de IA: un orquestador, un implementador y un revisor
([ADR-0002](adr/0002-metodo-ia-orquestador-agentes.md)).

La verificación local completa (`bash tests/run-all.sh`) da todo OK (detalle en
[PRUEBAS](pruebas/PRUEBAS.md)): **151 tests unitarios, 31 de integración y 84 casos de smoke
HTTP**, entre ellos las 7 pruebas mínimas de la consigna.

## Arquitectura

```text
request → public_html/index.php   único DocumentRoot; front controller
        → app/bootstrap.php       errores, headers de seguridad, Config, Database
        → Kernel → Router         RouteMatcher (puro): 404 · 405 + Allow
                                  → sesión → auth (/admin) → CSRF (todo POST)
        → Controller → Model → View   SQL solo en Models; todo se escapa con e()
```

- **Capas:** MVC liviano con clases propias en `app/Core`
  ([ADR-0001](adr/0001-mvc-ligero-front-controller.md)).
- **Control de flujo:** los cortes (404, 419, redirects) se lanzan como `HttpException` y
  `Kernel` los emite en un solo punto: no hay `exit` en `app/`.
- **CSS:** propio, separado en base, layout, components y pages. Sin JS y compatible con la
  CSP `'self'`.
- **Base de datos:** cuatro tablas InnoDB `utf8mb4`.
  - `admins` y `solicitudes`: la segunda con un ENUM de estado, un índice `(estado,
    created_at)` y un índice FULLTEXT.
  - `solicitud_eventos`: el historial, con claves foráneas.
  - `login_intentos`: IP más el SHA-256 del email.
  - La conexión fija `sql_mode` estricto.

## Decisiones que tomé

- **Método de trabajo con IA:** una sesión orquestadora y dos agentes con roles separados,
  el que implementa y el que revisa. Registré **todos** los prompts, aunque la consigna pide
  una selección, y reinicié el contexto del revisor antes de la revisión de autenticación.
- **Arquitectura:** MVC liviano, sin framework ni Composer, con el mismo nivel de los
  sitios que tengo en producción.
- **Repositorio:** público, con un commit por tarea.
- **Alcance:** implementé lo obligatorio y después sumé:
  - el límite de intentos de login;
  - una primera tanda de mejoras: historial, búsqueda y paginación, contadores con
    `GROUP BY`, sesión solo en rutas válidas, rotación de logs, proxies de confianza, tests
    unitarios y timeout automatizado;
  - una segunda tanda: FULLTEXT, `RouteMatcher` y configuración centralizada.
- **Diseño visual:** paleta clara, blur solo en el header, sin cajas flotantes,
  aprovechando el ancho en desktop. CSS separado por responsabilidad. Aprobé el resultado
  revisando las capturas.
- **Pruebas:** locales con un único script, sin CI en GitHub. Como consecuencia, PHP 8.2 y
  MySQL 8.0 no se verifican automáticamente.

## Decisiones de diseño que adopté

Las propuso el orquestador durante el desarrollo y las validé como parte del trabajo. El
detalle está en [DECISIONES](ia/DECISIONES.md).

- **Contrato primero, pruebas antes que el código:** el revisor escribía los tests contra el
  plan mientras se implementaba, así las pruebas no se acomodaban al código.
- **Orden de controles ruta → auth → CSRF.** Sin sesión, un GET redirige al login y un POST
  responde 401: un POST redirigido ocultaría el rechazo.
- **Costo y algoritmo de bcrypt fijos** (costo 12): sin eso, la anti-enumeración por tiempo
  dependía de la versión de PHP.
- **Límite de intentos exacto con "reservar y contar"** (se registra el intento antes de
  contar), sin locks ni contadores aparte. `X-Forwarded-For` solo se lee si la conexión viene
  de un proxy de confianza, de derecha a izquierda.
- **Búsqueda FULLTEXT híbrida**: el índice prefiltra por palabras y un `LIKE` confirma la
  subcadena exacta; sin palabras indexables se usa solo el `LIKE`. Así no cambiaron los
  resultados que ya se esperaban ([ADR-0011](adr/0011-busqueda-fulltext-hibrida.md)).
- **Turnos de base de datos entre agentes:** las corridas simultáneas daban falsos
  negativos.

## Lo que encontraron los agentes y qué se decidió

El revisor escribió las pruebas y revisó cada fase con un formato verificable (archivo y
línea, escenario reproducible, severidad, fix). Cada hallazgo se reprodujo antes de
aceptarlo.

| Hallazgo | Fase | Decisión |
|---|---|---|
| 9 huecos del plan (orden de controles, filtro inválido, destino tras cambiar el estado, etc.) | Pruebas antes del código | Resueltos en el plan. Se descartó la propuesta de "CSRF antes que auth" |
| El Apache local servía `.git/` y los SQL del repo (reproducido: `.git/config` respondía 200) | Revisión de la base de datos | `.htaccess` en la raíz que niega todo y DocumentRoot documentado |
| `estado[]=x` producía un `TypeError` y una página 500 | Ídem | `Request::string()`: un valor que no es string se trata como inválido |
| UTF-8 inválido y caracteres de control llegaban a la DB y producían 500 | Revisión del núcleo | Validación de encoding y de controles: 422 |
| Un nombre hecho solo de espacios Unicode (NBSP) pasaba como "obligatorio" | Ídem | Recorte de espacios Unicode |
| El costo de bcrypt dependía de la versión de PHP (riesgo de enumeración en 8.2 y 8.3) | Revisión de autenticación | Costo y algoritmo fijos |
| `contraseña\0basura` autenticaba | Ídem | Se rechaza el byte NUL |
| `ádmin@…` entraba aunque `admin@…` estuviera bloqueado | Pruebas del límite | Normalización única y comparación binaria. El implementador lo había anotado como "limitación aceptable" y se corrigió |
| ReDoS en el recorte de espacios sin JIT de PCRE | Revisión de mejoras | Recorte lineal y tope de bytes |
| `ESCAPE '\\'` dependía del `sql_mode` del servidor | Ídem | Carácter de escape bindeado |

**Sugerencias descartadas:**
- Tokens CSRF de un solo uso: rompen el doble envío y las pestañas múltiples.
- "Sanitizar" el HTML antes de guardarlo: se escapa al mostrarlo.
- Varios hallazgos de la revisión automática con un LLM local que resultaron confabulados
  ([revisiones](ia/revisiones/)).

## Errores encontrados y soluciones

- **Cambiar al mismo estado parecía "id inexistente".** El UPDATE que no cambia nada
  devuelve 0 filas afectadas. Lo resolví con `ATTR_FOUND_ROWS`.
- **`.htaccess` heredado.** El `Require all denied` de la raíz también bloqueaba el
  DocumentRoot correcto. Lo resolví con `Require all granted` en `public_html/`.
- **Carrera en el límite de intentos.** Medido con 10 logins fallidos simultáneos contra
  una cuenta que ya tenía 4: antes llegaban a verificar la contraseña entre 2 y 6; con
  "reservar y contar" llega exactamente 1, también con 30.
- **ReDoS.** Sin JIT, 20 000 espacios internos tardaban 2,45 s y 200 000 no terminaban.
  Ahora 200 000 tardan 0,009 s.
- **FULLTEXT ≠ LIKE.** Un índice puro cambiaba el resultado de búsquedas como `100%_ok`, de
  palabras cortas y de stopwords. Medí la tokenización real de MariaDB y validé el diseño
  híbrido con unas 3000 consultas comparadas contra `LIKE`: cero falsos negativos.
- **Proceso.** Hubo corridas de tests simultáneas de distintas sesiones sobre la misma base,
  y una la provocó la propia orquestación. Se descartaron y se fijaron turnos explícitos. El
  push del workflow de CI requería un permiso extra de GitHub; después decidí no usar CI.

## Auditoría final

Antes de entregar auditamos todo el proyecto con GitHub Copilot CLI (seguridad, calidad y
cumplimiento de la consigna) y dos subagentes como segunda opinión, en solo lectura. Ninguna
revisión encontró vulnerabilidades de severidad alta o media. Corregí los hallazgos bajos más
baratos (debug forzado en producción, seed bloqueado en producción, validación de toda la
configuración, zona horaria de la DB, código muerto, índices y tests más estrictos) y
documenté el resto ([AUDITORIA-FINAL](auditoria/AUDITORIA-FINAL.md)).

## Límites conocidos y mejoras pendientes

- El límite por cuenta es por IP + email: rotando direcciones IPv6 se obtienen contadores
  nuevos. Mejora: agrupar por /64.
- Detrás de un CDN sin `trusted_proxies`, todos los clientes comparten el límite por IP.
- Con palabras indexables, la búsqueda es por palabra o prefijo: `ampus` no encuentra
  `campus`. Mejora: un índice de trigramas propio, porque MariaDB no tiene `ngram`.
- PHP 8.2 y MySQL 8.0 no tienen verificación automática: todo se probó con PHP 8.5 y
  MariaDB 11.8.
- En un servidor propio convendría usar `logrotate` en lugar de la rotación del código.
- El formulario público no tiene límite de frecuencia (solo CSRF): mejora, reutilizar el
  patrón del límite de login por IP.
- No hay registro de eventos de seguridad (logins, 429, 419); mejora, una línea de log sin
  datos personales por evento.
- En producción conviene un usuario de DB solo con permisos de datos para la app y otro con
  DDL para el instalador; HSTS desde el servidor HTTPS y vencimiento absoluto de sesión.
- Refactor de cohesión: extraer la búsqueda de `Solicitud` y la lógica de IP de `Request`.

---

Redactado con asistencia de IA a partir del código, la documentación y el registro del
proyecto; revisado por el autor.
