# ADR-0003 — PDO con consultas preparadas reales

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-01), revisado e integrado por el orquestador.

## Contexto

La consigna exige prevenir inyección SQL. La entrada pública (formulario) y la del panel
(filtro, id, estado) llegan a consultas. Además, el acceso a datos debe estar centralizado
(ADR-0001: SQL solo en Models).

## Decisión

- Una conexión PDO única y lazy en `App\Core\Database`, con `ERRMODE_EXCEPTION`,
  `FETCH_ASSOC`, `EMULATE_PREPARES=false` y charset utf8mb4 en el DSN.
- Los modelos usan solo `fetchOne/fetchAll/execute(string $sql, array $params)`, que
  siempre hacen `prepare()` + `bindValue` con tipo según el valor (int → `PARAM_INT`).
  No hay API para ejecutar SQL con valores interpolados.
- `ATTR_FOUND_ROWS=true`: `rowCount()` cuenta filas encontradas, así un UPDATE al mismo
  valor no se confunde con "id inexistente".
- Las credenciales se leen de la config cargada por la app (ADR-0007). Un fallo de
  conexión se reenvuelve con un mensaje genérico; el detalle queda en la excepción previa,
  para el log.

## Alternativas consideradas

- **Preparación emulada (default de PDO MySQL)**: el driver interpola valores escapados
  del lado del cliente. Es segura en la práctica con charset correcto en el DSN, pero
  depende de que el escape y el charset coincidan, y convierte los int en strings. La
  preparación real separa sentencia y datos en el protocolo.
- **mysqli**: válido, pero más verboso para bindear y sin un modo de excepciones tan
  uniforme; PDO permite cambiar de motor.
- **ORM / query builder**: dependencias desproporcionadas (sin Composer, ADR-0001).

## Consecuencias

- La inyección SQL queda bloqueada por diseño en los valores. Los identificadores (nombres
  de tabla/columna) no se pueden parametrizar: solo aparecen como constantes de código,
  nunca desde la entrada.
- Un round-trip extra por consulta (prepare + execute), despreciable a esta escala.
- La clase estática simplifica el uso pero acopla los modelos. Si se necesitaran tests
  unitarios con mocks de persistencia, inyectar la conexión.
