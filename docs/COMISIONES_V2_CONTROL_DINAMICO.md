# Comisiones V2: control de ventas dinamico

Fecha: 2026-08-15

## Objetivo

La pantalla de comisiones debe mostrar por defecto el control completo de
ventas inspirado en el formato fisico de Oscar Cell, pero permitir que cada
usuario arme su propia vista sin perder columnas ni datos.

La vista completa incluye cantidad, producto, moneda original, equivalente,
metodos de pago, financiamiento, vendedor, sede, total y comision.

## Columnas configurables

Todas las columnas visibles por defecto. El usuario puede:

- ocultar o mostrar columnas;
- elegir presets como `Vista completa`, `Solo dinero`, `Solo metodos` o
  `Comisiones en Bs`;
- activar metodos de pago individuales como `P.M.`, `P.V.`, efectivo o Zelle;
- conservar la ultima configuracion local de la tabla.

Los codigos de metodos no se hardcodean. Cada metodo de pago tiene una etiqueta
corta configurable por empresa y esa etiqueta se convierte en una columna
dinamica.

## Regla monetaria

- Una venta en USD llena el monto USD.
- Una venta en VES llena el monto Bs.
- La columna `Equiv. USD` solo se muestra para ventas en VES.
- El equivalente usa la tasa congelada en la venta/pago.
- Cambios posteriores de la tasa no modifican filas historicas.

## Filas y pagos

Cada fila representa un producto de una orden pagada. Cuando una orden tiene
varios pagos, el reporte distribuye cada pago proporcionalmente al valor base
de sus lineas, conserva la suma exacta y expone el resultado en la columna del
metodo correspondiente.

La fuente de verdad sigue siendo append-only: `sales`, `sale_items`,
`pos_orders` y `pos_payments`. Las comisiones se relacionan por
`pos_order_id`/`sale_item_id` y no sustituyen el control de ventas.

## Filtros y permisos

- Fecha desde/hasta.
- Vendedor o beneficiario.
- Cajero.
- Sede.
- Metodo de pago.
- Vista global con `commissions.view_all`.
- Vista propia limitada con `commissions.view_own`.

## Totales

El encabezado y la fila final muestran cantidades, USD, Bs, equivalente USD,
totales por metodo, monto financiado y comisiones en USD/Bs.

## No recalcular historicos

El reporte nunca consulta la tasa actual para reconstruir ventas antiguas. Usa
los snapshots monetarios guardados en `sale_items` y `pos_payments`. Una
correccion posterior debe registrarse como un movimiento append-only, nunca
editar la venta original.
