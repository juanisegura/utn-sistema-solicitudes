# Registro de decisiones técnicas

Decisiones tomadas durante el desarrollo, quién las tomó y por qué. Las de mayor peso
tienen ADR propio en [`../adr/`](../adr/). "Juan" = el estudiante; "Orq." = sesión
orquestadora; A1/A2 = agentes.

| # | Fecha y hora | Decisión | Quién | Motivo / alternativa descartada |
|---|--------------|----------|-------|----------------------------------|
| D-01 | 2026-10-19 13:10 | Tres sesiones: orquestador + AGENTE 1 (implementa) + AGENTE 2 (revisa/prueba) | Juan | Verificación independiente de lo que produce la IA (ADR-0002) |
| D-02 | 2026-10-19 13:10 | Registrar **todos** los prompts a los agentes (la consigna solo pide una selección) | Juan | Trazabilidad completa; la selección sale de este registro |
| D-03 | 2026-10-19 13:20 | Repo público en GitHub, código en `/var/www/html/` | Juan | Mismo lugar que el resto de los proyectos PHP locales |
| D-04 | 2026-10-19 13:20 | MVC ligero + front controller, sin framework ni Composer, mismo nivel que los sitios en producción del estudio | Juan + Orq. | ADR-0001. Descartado: páginas sueltas (guardas repetidas), Laravel (desproporcionado) |
| D-05 | 2026-10-19 13:22 | Servidor de desarrollo `php -S` en vez de VirtualHost de Apache | Orq. | El evaluador lo reproduce sin configurar Apache |
| D-06 | 2026-10-19 13:23 | Usuario MariaDB dedicado con password aleatoria escrita solo en `config.local.php`; los agentes tienen prohibido leer ese archivo | Orq. | Requisito de secretos fuera del repo; evitar que la password quede en transcripciones públicas |
| D-07 | 2026-10-19 13:25 | Hook `UserPromptSubmit` para capturar prompts literal, con redacción de patrones de secretos | Orq. | Registro completo sin depender de copiar a mano |
| D-08 | 2026-10-19 13:40 | Modo C: el orquestador envía los prompts por mensaje entre sesiones; cada agente delega lo mecánico en subagentes Sonnet/Haiku (Haiku nunca en seguridad); un escritor por archivo; ADRs los integra el orquestador | Juan | Paralelismo (A2 escribe las pruebas antes que exista la app) sin conflictos de archivos |
| D-09 | 2026-10-19 13:40 | Las pruebas (A2-01) se escriben contra el contrato del PLAN antes de la implementación | Orq. | Las pruebas no se acomodan a lo que el código hace; fijan el contrato |
| D-10 | 2026-10-19 13:55 | `ATTR_FOUND_ROWS=true`: `updateEstado()` false ⇔ id inexistente → 404 (también en POST) | A1 propone, Orq. acepta | Sin esto, re-guardar el mismo estado se confundía con "no existe" |
| D-11 | 2026-10-19 13:55 | `Admin::create` recibe la password en claro y hashea adentro | A1 propone, Orq. acepta | Imposible persistir texto plano por error desde un llamador |
| D-12 | 2026-10-19 13:55 | Seed con fechas relativas a `NOW()` y una solicitud con `<script>` / `' OR 1=1 --` | A1 propone, Orq. acepta | Datos siempre "recientes" y caso visible para demostrar escape en el panel |
| D-13 | 2026-10-19 13:55 | El hook no captura mensajes entre sesiones → el registro de prompts/respuestas lo escribe el orquestador (`via=orquestador`) | Orq. | Verificado: el registro no tuvo entradas `via=hook` tras A1-01/A2-01 |
| D-14 | 2026-10-19 13:58 | Contrato de formularios fijado en el PLAN (`csrf_token`, nombres de campos, límites) | Orq. | Que implementación (A1) y pruebas (A2) no dependan de supuestos distintos |
| D-15 | 2026-10-19 13:58 | Fases 2+3 juntas en A1-02 (núcleo + formulario público) y 4+5 en A1-03 (auth + panel) | Orq. | Entrega en el día; se mantiene un punto de revisión entre parte pública y panel |
| D-16 | 2026-10-19 14:10 | Orden de controles: ruta → auth → CSRF → controller | Orq. (descarta propuesta de A2: CSRF primero) | Una operación admin anónima debe rechazarse por falta de sesión, con un único motivo y sin depender del estado del token; el resultado de seguridad es el mismo |
| D-17 | 2026-10-19 14:10 | Sin sesión: GET → 302 a login; POST → 401 | Orq. (resuelve ambigüedad marcada por A2) | Un POST redirigido se convierte en GET y oculta el rechazo |
| D-18 | 2026-10-19 14:10 | Filtro con estado inválido → 400 | Orq. (A2 marcó el hueco) | Ignorarlo en silencio muestra un listado que el usuario no pidió |
| D-19 | 2026-10-19 14:10 | Cambio de estado OK → 302 al detalle + flash | Orq. (acepta sugerencia de A2) | Patrón PRG; el admin ve el resultado sobre la misma solicitud |
| D-20 | 2026-10-19 14:10 | Listado por fecha descendente, sin paginación | Orq. | Volumen del TP; paginación queda como mejora |
| D-21 | 2026-10-19 14:10 | Timeout de sesión configurable (`app.session_timeout`) | Orq. (A2 marcó que no era probable) | Permite probar la expiración bajando el valor en config local |
| D-22 | 2026-10-19 14:10 | Si hay límite de login: IP+email, umbral ≥5 / 15 min | Orq. (acepta restricción de A2) | El smoke hace 3 intentos fallidos por corrida |
| D-23 | 2026-10-19 14:30 | CSRF activo por defecto en todo POST; desactivarlo exige `'csrf'=>false` explícito | A1 propone, Orq. acepta | Seguro por defecto: una ruta nueva no puede olvidarlo (ADR-0005) |
| D-24 | 2026-10-19 14:30 | Zona horaria `America/Argentina/Buenos_Aires` en bootstrap (logs y fechas del panel) | Orq. (A1 marcó que el PLAN no la fijaba) | Las fechas mostradas al admin y las del log deben coincidir con la hora local |
| D-25 | 2026-10-19 14:30 | Token CSRF por sesión y no de un solo uso | A1 (tras consultar Cerebro), Orq. acepta | One-time rompe doble envío, "atrás" y pestañas múltiples sin beneficio proporcional en un panel de un rol |
