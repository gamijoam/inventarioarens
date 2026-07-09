# POS - Cancelacion de ordenes pendientes

## Objetivo

Permitir que el cajero cancele una orden POS pendiente cuando no hay dinero capturado, liberando automaticamente el stock reservado y los IMEI/seriales asociados.

## Reglas implementadas

- Solo se pueden cancelar ordenes POS en estado `open`.
- La venta asociada debe seguir en estado `draft`.
- La caja debe estar abierta, activa y pertenecer al cajero actual.
- Si la orden tiene pagos capturados, la cancelacion se rechaza.
- Si la orden solo tiene pagos pendientes o no capturados, se cancela la venta borrador.
- La reserva de inventario se libera mediante movimiento `released`.
- Los IMEI/seriales reservados vuelven a estado `available`.
- Se registra evento `pos.order.cancelled` en `sync_outbox`.

## Interfaz de escritorio

La ventana de ordenes POS pendientes ahora incluye el boton `Cancelar orden`. Antes de cancelar, muestra una confirmacion para evitar acciones accidentales. Al completarse, recarga la lista de pendientes.

## Pruebas realizadas

- Orden pendiente serializada se cancela y libera IMEI.
- Orden pendiente con pago capturado no puede cancelarse sin devolucion/anulacion.
- Suite especifica POS ejecutada contra PostgreSQL local.

## Pendiente futuro

- Crear flujo de devolucion/anulacion de pagos capturados.
- Mostrar historial de cancelaciones en reportes administrativos.
- Permitir reglas de aprobacion para cancelar ordenes segun rol.
