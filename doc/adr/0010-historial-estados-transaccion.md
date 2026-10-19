# ADR-0010 — Historial de estados en transacción

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-10), revisado e integrado por el orquestador.

## Contexto

El panel solo guardaba el estado actual de cada solicitud: no se sabía quién la cambió,
cuándo ni desde qué estado (mejora M-2, D-45). Dos administradores pueden cambiar la misma
solicitud casi a la vez, y el historial tiene que reflejar la secuencia real.

## Decisión

- Tabla `solicitud_eventos` con una fila por cambio real: solicitud, admin, estado anterior
  y nuevo (mismo ENUM que `solicitudes.estado`) y fecha, con índice
  `(solicitud_id, created_at)` para el detalle.
- `Solicitud::updateEstado($id, $estado, $adminId)` corre en una transacción del modelo:
  `SELECT estado … FOR UPDATE` → si el estado cambia, `UPDATE` + `INSERT` del evento →
  `commit`; ante cualquier excepción, `rollBack` y se relanza. Si el estado es el mismo no
  se escribe nada (pero el usuario igual recibe el flash, D-19). El alta no genera evento:
  el estado inicial es `created_at` + `pendiente`.
- `FOR UPDATE` serializa los cambios concurrentes sobre la misma fila: el segundo admin lee
  el estado que dejó el primero, así cada evento registra el estado anterior verdadero y la
  cadena de eventos coincide con el estado final.
- Claves foráneas: `solicitud_id … ON DELETE CASCADE` (el historial no tiene sentido sin su
  solicitud) y `admin_id … ON DELETE RESTRICT` (un admin con cambios registrados no se
  borra, se desactiva con `activo`; así ningún evento queda sin autor).

## Alternativas consideradas

- **Columnas de auditoría en `solicitudes`** (`updated_by`, `estado_anterior`): solo
  guardan el último cambio, no la secuencia.
- **Triggers de la DB:** registrarían también cambios hechos por fuera de la app, pero no
  conocen al admin de la sesión (habría que pasarlo por una variable de sesión SQL).
  Además esconden lógica de negocio en la base, dificultan la portabilidad MySQL/MariaDB y
  el código PHP deja de mostrar lo que pasa.
- **Leer el estado sin `FOR UPDATE`:** dos cambios simultáneos podrían registrar el mismo
  estado anterior y dejar un historial que no encadena.
- **`ON DELETE SET NULL` para el admin:** conservaría el evento sin autor; se prefirió
  impedir el borrado. La vista igual muestra "—" si el autor faltara.

## Consecuencias

- Cada cambio de estado son tres sentencias en una transacción, con lock de una sola fila
  durante milisegundos. Despreciable para el volumen del panel.
- El historial es inmutable desde la app (no hay edición ni borrado de eventos).
- Probado: rollback real cuando falla el INSERT del evento, CASCADE y RESTRICT, y cambios
  simultáneos encadenados (integración de AGENTE 2, `HistorialTransaccionTest`).
