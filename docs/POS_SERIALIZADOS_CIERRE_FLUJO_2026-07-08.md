# POS - Cierre del flujo de productos serializados

## Objetivo

Cerrar el flujo operativo de productos serializados/IMEI dentro del POS para que una venta no pueda avanzar con seriales ambiguos, repetidos o no disponibles.

## Reglas implementadas

- Un producto serializado requiere seleccionar un IMEI/serial por cada unidad vendida.
- Una orden POS pendiente no puede reservar un producto serializado sin IMEI.
- No se permite repetir el mismo IMEI dentro de una misma orden POS.
- El IMEI debe pertenecer al mismo producto y al mismo almacen de la venta.
- Si una orden queda pendiente, el IMEI pasa a estado `reserved`.
- Mientras el IMEI esta reservado, otra caja no puede venderlo.
- Cuando el cobro pendiente se completa, la reserva se libera dentro de la misma transaccion y la venta confirma el IMEI como `sold`.
- Si la venta se paga completa desde el primer intento, el IMEI pasa directamente de `available` a `sold`.

## Validaciones cubiertas por pruebas

- Venta POS serializada pagada completa registra el IMEI vendido.
- Orden POS pendiente reserva el IMEI hasta completar el cobro.
- Checkout pendiente rechaza producto serializado sin IMEI.
- Checkout pendiente rechaza IMEI repetido.
- Checkout pendiente rechaza IMEI de otro almacen.
- Otra caja no puede vender una unidad ya reservada por una orden pendiente.

## Impacto operativo

El cajero puede escanear un IMEI desde el POS y el backend confirma que ese serial esta disponible antes de permitir reservar o vender. Esto evita dobles ventas cuando hay varias cajas trabajando al mismo tiempo.

## Pendiente futuro

- Agregar una pantalla de auditoria visual por IMEI en el POS.
- Permitir cancelar ordenes POS pendientes y liberar automaticamente los IMEI reservados.
- Mostrar en el buscador POS un mensaje mas explicito si el IMEI existe pero esta reservado, vendido, danado o en garantia.
