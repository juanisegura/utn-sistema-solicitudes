# ADR-0006 — Estados: ENUM en DB + enum PHP como lista blanca

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-01), revisado e integrado por el orquestador.

## Contexto

Una solicitud tiene tres estados fijos: pendiente, en proceso y resuelta (consigna §2).
El estado llega desde la entrada del usuario en dos lugares: el filtro
`GET /admin?estado=…` y el `POST /admin/solicitudes/{id}/estado`. Un valor no validado
podría terminar en el SQL o persistir un estado inexistente.

## Decisión

- En la DB: `estado ENUM('pendiente','en_proceso','resuelta') NOT NULL DEFAULT 'pendiente'`.
- En PHP: `enum App\Models\Estado: string` con los mismos valores, `label()` para la
  presentación y `fromInput(?string): ?self` (trim + `tryFrom`).
- Los modelos solo aceptan `Estado` (no strings) en `Solicitud::all(?Estado)` y
  `updateEstado(int, Estado)`. La entrada inválida se corta en el controller:
  `fromInput` devuelve null → 422. El valor llega al SQL solo como parámetro preparado
  (`$estado->value`).

## Alternativas consideradas

- **Tabla `estados` + FK**: permite agregar estados sin migración, pero agrega un JOIN y
  datos de referencia para un conjunto que la consigna fija en tres. Es la opción si los
  estados pasaran a ser configurables.
- **VARCHAR + validación solo en PHP**: deja la integridad a la app; un INSERT manual o un
  bug podrían guardar "resuleta".
- **Constantes de clase / array de strings**: no tipan los parámetros. Con un enum, un
  string sin validar no puede llegar a `updateEstado` sin error de tipo (`strict_types`).

## Consecuencias

- Doble barrera: el tipo `Estado` en PHP y el ENUM en la DB (en modo estricto,
  MySQL/MariaDB rechazan valores fuera del ENUM).
- Las etiquetas de UI viven en un solo lugar (`label()`).
- Deuda: los valores están duplicados (`schema.sql` y `Estado.php`), y agregar un estado
  exige un `ALTER TABLE` más el cambio del enum. Se acepta para 3 valores fijos; si
  cambian a menudo, migrar a tabla + FK.

## Revisión posterior (fase de mejoras, M-2)

El ENUM de estados se repite en `solicitud_eventos.estado_anterior/estado_nuevo`
(ADR-0010): agregar un estado exige actualizar las tres columnas además del enum PHP.
