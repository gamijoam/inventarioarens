# Datos demo

Este documento explica como cargar datos persistentes para revisar la base local desde HeidiSQL.

## Base objetivo

Los datos demo se cargan en la base local:

```txt
inventory_arens
```

No dependen de la base de pruebas:

```txt
inventory_arens_testing
```

## Comando

Con Docker levantado, ejecutar:

```txt
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan db:seed --class=DemoDataSeeder
```

## Que crea

- Empresas demo:
  - `demo-caracas`
  - `demo-valencia`
- Usuarios demo:
  - `cajero.caracas@demo.test`
  - `gerente.caracas@demo.test`
  - `cajero.valencia@demo.test`
  - `gerente.valencia@demo.test`
- Sucursales y almacenes por empresa.
- Tasas `BCV` y `PARALELO`.
- Politicas de garantia por empresa:
  - `Android 30 dias`.
  - `Accesorios 7 dias`.
- Clientes:
  - `Consumidor final`, cliente generico por empresa.
  - `Cliente Demo POS Pagado`, asociado a la venta POS pagada.
  - `Cliente Demo Financiamiento`, asociado a la venta POS pendiente.
- Proveedores:
  - `Proveedor Demo CCS`
  - `Proveedor Demo VAL`
- Compras recibidas:
  - `COMPRA-DEMO-CCS`
  - `COMPRA-DEMO-VAL`
- Devoluciones a proveedor demo sobre compras recibidas.
- Cuentas por pagar demo generadas desde compras recibidas.
- Abonos demo a proveedores que dejan cuentas por pagar en estado parcial.
- Productos:
  - `Samsung A06 128GB`, serializado por IMEI, con garantia `Android 30 dias`.
  - `Audifonos Bluetooth`, controlado por cantidad, con garantia `Accesorios 7 dias`.
- Stock inicial.
- IMEIs demo en `product_units`.
- Entradas demo de 30 IMEIs por empresa usando el modulo `ProductEntries`.
- Salidas demo de un IMEI por empresa usando el modulo `ProductExits`.
- Transferencias demo de audifonos entre almacenes internos por empresa usando el modulo `InventoryTransfers`.
- Solicitud interempresa demo completada desde Caracas hacia Valencia usando `InventoryTransferRequests`.
- Compras recibidas con fecha de emision y vencimiento que generan movimientos `purchase` reales.
- Devoluciones a proveedor que generan movimientos `purchase_return` reales.
- Cuentas por pagar y pagos a proveedor generados por servicios reales.
- Cajas abiertas.
- Venta POS pagada con IMEI especifico asociado al `sale_item`.
- Venta POS con financiamiento externo pendiente.
- Venta demo a credito para revisar cuentas por cobrar.
- Items de venta con snapshot de garantia tomado desde la politica del producto.
- Casos demo de garantia recibidos, uno por empresa, para revisar `warranty_claims` y probar manualmente revision, reemplazo, rechazo o reembolso.
- Cuentas por cobrar demo generadas desde ventas confirmadas.
- Abonos demo de clientes que dejan cuentas por cobrar en estado parcial.
- Comprobantes demo emitidos automaticamente para cobros de clientes y pagos a proveedores.
- Ajustes financieros demo sobre cuentas por cobrar y cuentas por pagar.
- Datos financieros suficientes para consultar `FinanceReports`.
- Ventas y ordenes POS asociadas a clientes reales del modulo `Customers`.
- Devoluciones de venta demo sobre ventas POS pagadas usando el mismo IMEI vendido.
- Movimientos de inventario, caja y auditoria generados por servicios reales.
- Datos suficientes para consultar Kardex por producto desde `stock_movements`.

## Reglas

- El seeder es idempotente: se puede ejecutar mas de una vez sin duplicar los datos demo principales.
- Los datos demo son para ambiente local o demostracion.
- Las pruebas automatizadas no dejan datos persistentes porque usan `RefreshDatabase`.
