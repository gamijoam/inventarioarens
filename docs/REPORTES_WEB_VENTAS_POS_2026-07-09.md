# Reportes Web De Ventas POS - 2026-07-09

## Objetivo

Mejorar el modulo administrativo web de ventas POS para que el administrador pueda revisar ventas por empresa, sucursal, caja, cajero, metodo de pago y productos vendidos desde una vista compacta.

## Cambios Realizados

- Se agrego el indicador **Ticket prom.** al resumen de ventas POS.
- Se agrego el ranking **Por caja**, usando caja fisica y sucursal.
- El detalle de pagos ahora muestra equivalente en bolivares y tasa aplicada cuando esta disponible.
- El panel **Detalle de venta** ahora muestra contexto operativo: cliente, caja, cajero, sucursal, productos, seriales/IMEI, descuentos, pagos, referencias, tasas y equivalencias.
- La interfaz del modulo mantiene el estilo administrativo de alta densidad definido para el portal web.

## API Afectada

- `GET /api/admin-portal/pos-sales`
  - Ahora incluye `summary.average_ticket_base_amount`.
  - Ahora incluye `analytics.by_cash_register`.
- `GET /api/admin-portal/pos-sales/{orden}`
  - Se usa para mostrar detalle compacto de productos y pagos.
  - Los pagos deben exponer monto base, monto local, referencia y tasa usada cuando aplique.

## Validacion Esperada

- Si una venta se hace desde el POS local y se sincroniza a la nube, debe aparecer en Ventas POS.
- El resumen debe mostrar ordenes, pagadas, pendientes, total, cobrado y ticket promedio.
- El ranking por caja debe permitir ver que caja fisica vendio y cuanto cobro.
- Al seleccionar una orden, el panel lateral debe mostrar el detalle sin mezclar productos y pagos en una sola linea.
- Los filtros por sucursal, caja, cajero, estado y busqueda deben seguir funcionando.

## Pruebas

- Prueba especifica: `tests/Feature/AdminPortal/AdminPosSalesApiTest.php`.
- La prueba valida resumen, ticket promedio, aislamiento por empresa, detalle, exportacion CSV y ranking por caja.
