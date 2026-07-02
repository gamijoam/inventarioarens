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
  - `arens-demo-caracas`
  - `arens-demo-valencia`
- Usuarios demo:
  - `cajero.caracas@demo.test`
  - `gerente.caracas@demo.test`
  - `cajero.valencia@demo.test`
  - `gerente.valencia@demo.test`
- Sucursales y almacenes por empresa.
- Tasas `BCV` y `PARALELO`.
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
  - `Samsung A06 128GB`, serializado por IMEI.
  - `Audifonos Bluetooth`, controlado por cantidad.
- Stock inicial.
- IMEIs demo en `product_units`.
- Compras recibidas que generan movimientos `purchase` reales.
- Devoluciones a proveedor que generan movimientos `purchase_return` reales.
- Cuentas por pagar y pagos a proveedor generados por servicios reales.
- Cajas abiertas.
- Venta POS pagada.
- Venta POS con financiamiento externo pendiente.
- Venta demo a credito para revisar cuentas por cobrar.
- Cuentas por cobrar demo generadas desde ventas confirmadas.
- Abonos demo de clientes que dejan cuentas por cobrar en estado parcial.
- Datos financieros suficientes para consultar `FinanceReports`.
- Ventas y ordenes POS asociadas a clientes reales del modulo `Customers`.
- Devoluciones de venta demo sobre ventas POS pagadas.
- Movimientos de inventario, caja y auditoria generados por servicios reales.
- Datos suficientes para consultar Kardex por producto desde `stock_movements`.

## Reglas

- El seeder es idempotente: se puede ejecutar mas de una vez sin duplicar los datos demo principales.
- Los datos demo son para ambiente local o demostracion.
- Las pruebas automatizadas no dejan datos persistentes porque usan `RefreshDatabase`.
