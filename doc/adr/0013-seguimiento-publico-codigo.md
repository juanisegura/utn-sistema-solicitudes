# ADR-0013 — Seguimiento público con código de 128 bits

- **Estado:** Aceptado
- **Fecha:** 2026-10-19
- **Redacción:** AGENTE 1 UTN (A1-17), revisado e integrado por el orquestador.

## Contexto

Quien envía una solicitud no tiene cuenta. Para consultar el estado necesita algo que
pruebe que la solicitud es suya, y el número es secuencial y enumerable, así que no alcanza
solo (PLAN §7, F-3, D-63).

## Decisión

- Al crear la solicitud se genera un código con `random_bytes(16)` (128 bits), codificado
  en base32 de Crockford (sin I, L, O ni U): 26 caracteres en grupos de 4 con guion.
- Se muestra una sola vez en `/gracias`: viaja como flash de sesión entre el 302 y el GET y
  se borra al leerlo.
- En la DB se guarda solo `seguimiento_hash` = SHA-256 hex del código normalizado
  (mayúsculas, sin espacios ni guiones, O→0, I/L→1). El seed y las solicitudes previas
  quedan en NULL y no se pueden consultar.
- `POST /seguimiento` (número + código, con CSRF) siempre calcula el hash y compara con
  `hash_equals`, contra un valor fijo si no hay hash guardado. Id inexistente, sin código o
  código incorrecto dan el mismo 422 "Número o código incorrectos.". El resultado va en la
  respuesta del POST: el código nunca está en una URL, en la sesión ni en los logs.
- El resultado muestra asunto, estado, fechas e historial (fecha y de → a); no muestra
  nombre, email, descripción ni el admin que hizo cada cambio.
- Fuerza bruta: el patrón "reservar y contar" de F-1 con `accion='seguimiento'`
  (`seguimiento_max_por_ip` 10 en `seguimiento_ventana_seg` 900). Se reserva antes de
  verificar, así que estando bloqueado ni el código correcto se verifica; los fallidos
  cuentan y el acierto libera su reserva.
- Eventos `seguimiento_ok`, `seguimiento_fallido` y `seguimiento_bloqueado` en
  `security.log`, con el número pero nunca el código.

## Alternativas consideradas

- **Número + email:** el email es adivinable o conocido por terceros, y el resultado
  devolvería datos asociados a una persona a quien solo conoce su email.
- **Código corto (6–8 caracteres):** suficiente contra una sola IP con el límite, pero no
  contra muchas. Con 128 bits la fuerza bruta es inviable aunque se evada el límite rotando
  IPs.
- **Guardar el código en claro o cifrado:** una filtración de la DB permitiría consultar
  todas las solicitudes; con el hash no. No hace falta un hash lento: con 128 bits de
  entropía no hay diccionario posible.
- **Enviar el código por email:** el sistema no manda correos (fuera de alcance,
  consigna §8).
- **Link con el código en la URL:** quedaría en el historial del navegador, en los logs del
  servidor y en el Referer.

## Consecuencias

- Si la persona pierde el código, no hay recuperación: tiene que pedir el estado a soporte.
  La página lo dice.
- El código pasa brevemente **en claro** por la sesión (flash, en el archivo de sesión del
  servidor) y se borra al mostrarlo; si `/gracias` nunca se visita, queda hasta que el
  recolector de sesiones lo elimina. No tiene exposición por HTTP, pero quien lea los
  archivos de sesión del servidor podría verlo en esa ventana (señalado en la revisión A2-16,
  aceptado: el patrón PRG lo requiere).
- El número sigue siendo enumerable, pero sin el código no revela nada: todas las respuestas
  fallidas son iguales.
- Un número con formato inválido no consulta la DB; eso se distingue por tiempo, pero solo
  revela que el formato era inválido.
- Mejora pendiente: un límite global además del límite por IP (con 128 bits sería
  redundante).
