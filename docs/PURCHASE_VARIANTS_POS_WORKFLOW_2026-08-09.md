# Variantes, compras y POS

## Regla de flujo

Una variante/color se selecciona al registrar la compra y se conserva en la recepción. El
IMEI o serial representa la unidad física y se selecciona en el POS al momento de confirmar
la venta.

## Compras

1. Crear el borrador de compra.
2. Seleccionar el producto y, cuando tenga colores o variantes activas, elegir la variante.
3. Indicar cantidad, costo y almacén.
4. Si el producto es serializado, registrar un IMEI/serial por cada unidad recibida.
5. Al recibir, el stock, el movimiento de inventario y cada unidad física conservan el
   `product_variant_id`.

Esto evita mezclar unidades de colores distintos y permite rastrear la variante desde la
recepción hasta la venta.

## `/pos/armar`

`/pos/armar` es una pantalla de preparación para vendedores. Su función es armar una orden,
asignar cliente y enviarla a la caja. No confirma la venta, no descuenta stock y no debe pedir
IMEI: el vendedor todavía no está asignando una unidad física concreta.

## `/pos`

La cajera abre la orden preparada y completa el checkout:

- el POS verifica stock real en el almacén;
- si el producto tiene variantes, se selecciona la variante disponible;
- si es serializado, se muestran los IMEI/seriales disponibles de esa variante;
- se selecciona exactamente una unidad por cada cantidad vendida;
- el backend vuelve a validar stock, variante, IMEI y permisos antes de cobrar.

Por tanto, sí: el IMEI se solicita una sola vez, en `/pos`, justo antes de confirmar la venta.
No se duplica la captura en `/pos/armar`.

## Permisos

Se reutilizan los permisos existentes: `purchases.create`, `purchases.view` y el permiso de
recepción vigente para compras; `pos.view`, `pos.checkout` y `pos.orders.hold` para el flujo POS.
La variante no crea permisos nuevos.
