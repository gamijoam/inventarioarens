# Catalogo de APIs

Todas las rutas actuales usan el prefijo global de Laravel:

```txt
/api
```

Todas las rutas actuales requieren:

- usuario autenticado;
- tenant resuelto;
- header recomendado: `X-Tenant: <slug-del-tenant>`;
- pertenencia activa del usuario al tenant.

## Productos

Archivo de rutas:

```txt
app/Modules/Products/routes.php
```

Controller:

```txt
App\Modules\Products\Controllers\ProductController
```

### Listar productos

```txt
GET /api/products
```

Permiso requerido:

```txt
products.view
```

### Crear producto

```txt
POST /api/products
```

Permiso requerido:

```txt
products.create
```

Body:

```json
{
  "name": "Samsung A06",
  "sku": "SAMSUNG-A06",
  "tracking_type": "serialized",
  "base_price": 100,
  "sale_currency": "VES",
  "sale_exchange_rate_type_id": 2,
  "is_active": true
}
```

Reglas:

- `sku` es unico dentro de la empresa actual;
- `tracking_type` puede ser `quantity` o `serialized`;
- si no se envia `tracking_type`, el producto queda como `quantity`;
- los productos con `serialized` pueden tener unidades en `product_units` con IMEI o serial;
- si un producto ya tiene unidades serializadas, no se puede cambiar su `tracking_type`.
- `base_price` es el precio base interno en `USD`;
- `sale_currency` puede ser `USD` o `VES`;
- `sale_exchange_rate_type_id` permite asignar una tasa sugerida, por ejemplo `BCV` o `PARALELO`.

### Ver producto

```txt
GET /api/products/{product}
```

Permiso requerido:

```txt
products.view
```

### Consultar precio calculado

```txt
GET /api/products/{product}/price
```

Permiso requerido:

```txt
products.view
```

Reglas:

- usa `base_price` como precio interno en `USD`;
- si el producto tiene `sale_exchange_rate_type_id`, usa ese tipo de tasa;
- si no tiene tipo de tasa asignado, usa el tipo de tasa predeterminado de la empresa;
- si `sale_currency = VES`, requiere una tasa activa;
- devuelve precio en `USD`, equivalente en `VES`, tipo de tasa y valor de tasa usado;
- esta cotizacion no mueve inventario ni crea venta.

### Actualizar producto

```txt
PATCH /api/products/{product}
PUT /api/products/{product}
```

Permiso requerido:

```txt
products.update
```

### Desactivar producto

```txt
DELETE /api/products/{product}
```

Permiso requerido:

```txt
products.delete
```

Regla:

- no borra fisicamente el producto; marca `is_active = false`.

## Sucursales

Archivo de rutas:

```txt
app/Modules/Branches/routes.php
```

Controller:

```txt
App\Modules\Branches\Controllers\BranchController
```

### Listar sucursales

```txt
GET /api/branches
```

Permiso requerido:

```txt
branches.view
```

### Crear sucursal

```txt
POST /api/branches
```

Permiso requerido:

```txt
branches.create
```

Body:

```json
{
  "name": "Principal",
  "code": "MAIN",
  "status": "active"
}
```

Reglas:

- `code` es unico dentro de la empresa actual;
- `status` puede ser `active` o `inactive`;
- si no se envia `status`, queda como `active`.

### Ver sucursal

```txt
GET /api/branches/{branch}
```

Permiso requerido:

```txt
branches.view
```

### Actualizar sucursal

```txt
PATCH /api/branches/{branch}
PUT /api/branches/{branch}
```

Permiso requerido:

```txt
branches.update
```

### Desactivar sucursal

```txt
DELETE /api/branches/{branch}
```

Permiso requerido:

```txt
branches.delete
```

Regla:

- no borra fisicamente la sucursal; marca `status = inactive`.

## Almacenes

Archivo de rutas:

```txt
app/Modules/Warehouses/routes.php
```

Controller:

```txt
App\Modules\Warehouses\Controllers\WarehouseController
```

### Listar almacenes

```txt
GET /api/warehouses
```

Permiso requerido:

```txt
warehouses.view
```

### Crear almacen

```txt
POST /api/warehouses
```

Permiso requerido:

```txt
warehouses.create
```

Body:

```json
{
  "branch_id": 1,
  "name": "Almacen tienda",
  "code": "WH-STORE",
  "status": "active"
}
```

Reglas:

- `branch_id` debe pertenecer a la empresa actual;
- `code` es unico dentro de la empresa actual;
- `status` puede ser `active` o `inactive`;
- si no se envia `status`, queda como `active`.

### Ver almacen

```txt
GET /api/warehouses/{warehouse}
```

Permiso requerido:

```txt
warehouses.view
```

### Actualizar almacen

```txt
PATCH /api/warehouses/{warehouse}
PUT /api/warehouses/{warehouse}
```

Permiso requerido:

```txt
warehouses.update
```

### Desactivar almacen

```txt
DELETE /api/warehouses/{warehouse}
```

Permiso requerido:

```txt
warehouses.delete
```

Regla:

- no borra fisicamente el almacen; marca `status = inactive`.

## Moneda y tasas

Archivo de rutas:

```txt
app/Modules/Currency/routes.php
```

Controllers:

```txt
App\Modules\Currency\Controllers\ExchangeRateTypeController
App\Modules\Currency\Controllers\ExchangeRateController
```

### Listar tipos de tasa

```txt
GET /api/currency/rate-types
```

Permiso requerido:

```txt
currency.view
```

### Crear tipo de tasa

```txt
POST /api/currency/rate-types
```

Permiso requerido:

```txt
currency.manage
```

Body:

```json
{
  "code": "BCV",
  "name": "Tasa BCV",
  "is_default": true,
  "is_active": true
}
```

Reglas:

- `code` es unico dentro de la empresa actual;
- puede existir mas de un tipo de tasa para `USD` a `VES`, por ejemplo `BCV` y `PARALELO`;
- solo un tipo de tasa queda como predeterminado por empresa.

### Ver tipo de tasa

```txt
GET /api/currency/rate-types/{type}
```

Permiso requerido:

```txt
currency.view
```

### Actualizar tipo de tasa

```txt
PATCH /api/currency/rate-types/{type}
PUT /api/currency/rate-types/{type}
```

Permiso requerido:

```txt
currency.manage
```

### Desactivar tipo de tasa

```txt
DELETE /api/currency/rate-types/{type}
```

Permiso requerido:

```txt
currency.manage
```

Regla:

- no borra fisicamente el tipo de tasa; marca `is_active = false`.

### Listar historial de tasas

```txt
GET /api/currency/rates
```

Permiso requerido:

```txt
currency.view
```

### Consultar tasas activas actuales

```txt
GET /api/currency/rates/current
GET /api/currency/rates/current?rate_type_code=BCV
```

Permiso requerido:

```txt
currency.view
```

### Crear tasa

```txt
POST /api/currency/rates
```

Permiso requerido:

```txt
currency.manage
```

Body:

```json
{
  "exchange_rate_type_id": 1,
  "base_currency": "USD",
  "quote_currency": "VES",
  "rate": 500,
  "effective_at": "2026-07-02T08:00:00-04:00",
  "is_active": true,
  "source": "Manual"
}
```

Reglas:

- la moneda base inicial es `USD`;
- la moneda cotizada inicial es `VES`;
- `rate` debe ser mayor que cero;
- `exchange_rate_type_id` debe pertenecer a la empresa actual;
- si se crea con `is_active = true`, se desactivan las tasas activas anteriores del mismo tipo y par de monedas;
- activar una tasa `BCV` no desactiva una tasa `PARALELO`.

Esta es la API para cargar una nueva tasa del dia o una tasa manual. Ejemplo: crear una nueva tasa `BCV = 500` o `PARALELO = 600`.

### Ver tasa

```txt
GET /api/currency/rates/{rate}
```

Permiso requerido:

```txt
currency.view
```

### Activar tasa

```txt
PATCH /api/currency/rates/{rate}/activate
```

Permiso requerido:

```txt
currency.manage
```

Regla:

- solo queda activa una tasa por empresa, tipo de tasa, moneda base y moneda cotizada.

### Desactivar tasa

```txt
PATCH /api/currency/rates/{rate}/deactivate
```

Permiso requerido:

```txt
currency.manage
```

Regla:

- no borra fisicamente la tasa historica; marca `is_active = false`.

## Clientes

Archivo de rutas:

```txt
app/Modules/Customers/routes.php
```

Controller:

```txt
App\Modules\Customers\Controllers\CustomerController
```

### Listar clientes

```txt
GET /api/customers
```

Permiso requerido:

```txt
customers.view
```

### Crear cliente

```txt
POST /api/customers
```

Permiso requerido:

```txt
customers.create
```

Body:

```json
{
  "name": "Cliente Mostrador",
  "document_type": "V",
  "document_number": "12345678",
  "phone": "04141234567",
  "email": "cliente@example.com",
  "fiscal_address": "Caracas",
  "is_generic": false,
  "is_active": true
}
```

Reglas:

- `document_type` puede ser `V`, `E`, `J`, `G` o `P`;
- `document_type + document_number` es unico dentro de la empresa actual;
- dos empresas distintas pueden registrar el mismo documento sin mezclarse;
- `is_generic` permite crear un cliente generico como `Consumidor final`;
- desactivar un cliente no borra ventas historicas.

### Ver cliente

```txt
GET /api/customers/{customer}
```

Permiso requerido:

```txt
customers.view
```

### Actualizar cliente

```txt
PATCH /api/customers/{customer}
PUT /api/customers/{customer}
```

Permiso requerido:

```txt
customers.update
```

### Desactivar cliente

```txt
DELETE /api/customers/{customer}
```

Permiso requerido:

```txt
customers.delete
```

Regla:

- no borra fisicamente el cliente; marca `is_active = false`.

## Proveedores

Archivo de rutas:

```txt
app/Modules/Suppliers/routes.php
```

Controller:

```txt
App\Modules\Suppliers\Controllers\SupplierController
```

### Listar proveedores

```txt
GET /api/suppliers
```

Permiso requerido:

```txt
suppliers.view
```

### Crear proveedor

```txt
POST /api/suppliers
```

Permiso requerido:

```txt
suppliers.create
```

Body:

```json
{
  "name": "Distribuidora Demo",
  "document_type": "J",
  "document_number": "123456789",
  "phone": "02121234567",
  "email": "compras@example.com",
  "fiscal_address": "Caracas",
  "notes": "Proveedor principal",
  "is_active": true
}
```

Reglas:

- `document_type` puede ser `V`, `E`, `J`, `G` o `P`;
- `document_type + document_number` es unico dentro de la empresa actual;
- dos empresas distintas pueden registrar el mismo documento sin mezclarse;
- desactivar un proveedor no borra compras historicas.

### Ver proveedor

```txt
GET /api/suppliers/{supplier}
```

Permiso requerido:

```txt
suppliers.view
```

### Actualizar proveedor

```txt
PATCH /api/suppliers/{supplier}
PUT /api/suppliers/{supplier}
```

Permiso requerido:

```txt
suppliers.update
```

### Desactivar proveedor

```txt
DELETE /api/suppliers/{supplier}
```

Permiso requerido:

```txt
suppliers.delete
```

Regla:

- no borra fisicamente el proveedor; marca `is_active = false`.

## Compras

Archivo de rutas:

```txt
app/Modules/Purchases/routes.php
```

Controller:

```txt
App\Modules\Purchases\Controllers\PurchaseOrderController
```

### Listar compras

```txt
GET /api/purchases
```

Permiso requerido:

```txt
purchases.view
```

### Crear compra en borrador

```txt
POST /api/purchases
```

Permiso requerido:

```txt
purchases.create
```

Body:

```json
{
  "supplier_id": 1,
  "document_number": "FAC-001",
  "purchase_currency": "USD",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 2,
      "unit_cost": 80,
      "serial_units": [
        {
          "serial_type": "imei",
          "serial_number": "860001000000001"
        }
      ]
    }
  ]
}
```

Reglas:

- crear una compra la deja en `draft`;
- crear una compra no aumenta inventario;
- `supplier_id` es opcional, pero si se envia debe pertenecer a la empresa actual;
- `warehouse_id` y `product_id` deben pertenecer a la empresa actual;
- `purchase_currency` puede ser `USD` o `VES`;
- si `purchase_currency = VES`, se debe enviar `exchange_rate_type_id` o existir un tipo de tasa predeterminado activo;
- las compras en `VES` guardan snapshot de tipo de tasa, codigo y valor;
- `unit_cost` se guarda en la moneda de la compra y tambien se calcula como costo base en `USD`;
- productos serializados requieren un serial o IMEI por cada unidad comprada;
- productos por cantidad no aceptan seriales.

### Ver compra

```txt
GET /api/purchases/{purchaseOrder}
```

Permiso requerido:

```txt
purchases.view
```

### Recibir compra

```txt
PATCH /api/purchases/{purchaseOrder}/receive
```

Permiso requerido:

```txt
purchases.approve
```

Reglas:

- solo se reciben compras en `draft`;
- recibir una compra genera movimientos `purchase` en inventario;
- cada item queda enlazado a su `stock_movement_id`;
- si el producto es serializado, se crean unidades en `product_units` como disponibles;
- la compra recibida queda en estado `received`.

### Cancelar compra en borrador

```txt
PATCH /api/purchases/{purchaseOrder}/cancel
```

Permiso requerido:

```txt
purchases.create
```

Regla:

- en esta fase solo se cancelan compras en `draft`; compras recibidas requeriran reverso/devolucion controlada mas adelante.

## Devoluciones a proveedor

Archivo de rutas:

```txt
app/Modules/PurchaseReturns/routes.php
```

Controller:

```txt
App\Modules\PurchaseReturns\Controllers\PurchaseReturnController
```

### Listar devoluciones a proveedor

```txt
GET /api/purchase-returns
```

Permiso requerido:

```txt
purchase_returns.view
```

### Crear devolucion a proveedor

```txt
POST /api/purchase-returns
```

Permiso requerido:

```txt
purchase_returns.create
```

Body:

```json
{
  "purchase_order_id": 1,
  "reason": "Mercancia defectuosa",
  "items": [
    {
      "purchase_item_id": 1,
      "quantity": 1,
      "product_unit_ids": [1],
      "reason": "Equipo devuelto al proveedor"
    }
  ]
}
```

Reglas:

- solo se pueden devolver compras recibidas;
- una devolucion a proveedor no borra ni cancela la compra original;
- no se puede devolver mas cantidad que la comprada menos devoluciones previas;
- cada item genera movimiento de inventario `purchase_return`;
- si el producto es serializado, se debe enviar una unidad por cada cantidad devuelta;
- las unidades serializadas devueltas quedan con estado `removed`;
- todos los ids deben pertenecer a la empresa actual.

### Ver devolucion a proveedor

```txt
GET /api/purchase-returns/{purchaseReturn}
```

Permiso requerido:

```txt
purchase_returns.view
```

## Cuentas por pagar

Archivo de rutas:

```txt
app/Modules/AccountsPayable/routes.php
```

Controller:

```txt
App\Modules\AccountsPayable\Controllers\AccountsPayableController
```

### Listar cuentas por pagar

```txt
GET /api/accounts-payable
```

Permiso requerido:

```txt
accounts_payable.view
```

Reglas:

- las cuentas por pagar se crean automaticamente al recibir una compra;
- no hay endpoint publico para crear deuda manual en esta fase;
- cada cuenta queda asociada a la compra y al proveedor;
- el saldo principal se guarda en `USD` base;
- si la compra o el pago usan `VES`, se guarda el snapshot de tasa usado;
- las devoluciones a proveedor reducen el saldo pendiente sin borrar la compra.

### Ver cuenta por pagar

```txt
GET /api/accounts-payable/{accountsPayable}
```

Permiso requerido:

```txt
accounts_payable.view
```

Incluye:

- proveedor;
- compra origen;
- montos originales;
- montos devueltos;
- montos pagados;
- saldo pendiente;
- pagos registrados.

### Registrar pago a proveedor

```txt
POST /api/accounts-payable/{accountsPayable}/payments
```

Permiso requerido:

```txt
accounts_payable.pay
```

Body:

```json
{
  "payment_currency": "VES",
  "amount": 60000,
  "exchange_rate_type_id": 1,
  "method": "pago movil",
  "reference": "PM-001",
  "notes": "Abono a factura proveedor"
}
```

Reglas:

- `payment_currency` puede ser `USD` o `VES`;
- los pagos en `VES` requieren una tasa activa o `exchange_rate` explicito;
- el pago guarda tipo de tasa, codigo y valor exacto usado;
- no se permite pagar mas que el saldo pendiente;
- una cuenta pagada no acepta nuevos pagos;
- todos los ids deben pertenecer a la empresa actual.

## Cuentas por cobrar

Archivo de rutas:

```txt
app/Modules/AccountsReceivable/routes.php
```

Controller:

```txt
App\Modules\AccountsReceivable\Controllers\AccountsReceivableController
```

### Listar cuentas por cobrar

```txt
GET /api/accounts-receivable
```

Permiso requerido:

```txt
accounts_receivable.view
```

Reglas:

- las cuentas por cobrar se crean automaticamente al confirmar una venta;
- no hay endpoint publico para crear deuda manual en esta fase;
- cada cuenta queda asociada a la venta y opcionalmente al cliente;
- el saldo principal se guarda en `USD` base;
- si el cobro usa `VES`, se guarda el snapshot de tasa usado;
- las devoluciones de venta reducen el saldo pendiente sin borrar la venta.

### Ver cuenta por cobrar

```txt
GET /api/accounts-receivable/{accountsReceivable}
```

Permiso requerido:

```txt
accounts_receivable.view
```

### Registrar cobro de cliente

```txt
POST /api/accounts-receivable/{accountsReceivable}/payments
```

Permiso requerido:

```txt
accounts_receivable.collect
```

Body:

```json
{
  "payment_currency": "VES",
  "amount": 60000,
  "exchange_rate_type_id": 1,
  "method": "pago movil",
  "reference": "PM-CLIENTE-001",
  "notes": "Abono de cliente"
}
```

Reglas:

- `payment_currency` puede ser `USD` o `VES`;
- los cobros en `VES` requieren una tasa activa o `exchange_rate` explicito;
- el cobro guarda tipo de tasa, codigo y valor exacto usado;
- no se permite cobrar mas que el saldo pendiente;
- una cuenta pagada no acepta nuevos cobros;
- todos los ids deben pertenecer a la empresa actual.

## Reportes financieros

Archivo de rutas:

```txt
app/Modules/FinanceReports/routes.php
```

Controller:

```txt
App\Modules\FinanceReports\Controllers\FinanceReportController
```

### Resumen financiero

```txt
GET /api/finance-reports/summary
```

Permiso requerido:

```txt
finance_reports.view
```

Filtros:

- `date_from`
- `date_to`
- `status`
- `customer_id`
- `supplier_id`

Incluye:

- total por cobrar en `USD`;
- total por pagar en `USD`;
- cantidad de cuentas pendientes, parciales, pagadas y vencidas;
- cobros recibidos en el periodo;
- pagos hechos a proveedores en el periodo;
- balance neto: por cobrar menos por pagar.

### Cuentas por cobrar financieras

```txt
GET /api/finance-reports/receivables
```

Permiso requerido:

```txt
finance_reports.view
```

### Cuentas por pagar financieras

```txt
GET /api/finance-reports/payables
```

Permiso requerido:

```txt
finance_reports.view
```

Reglas:

- los reportes financieros son solo lectura;
- no crean ni modifican cuentas;
- usan `USD` como moneda base de resumen;
- respetan tenant y permisos;
- no mezclan cuentas, pagos, clientes ni proveedores entre empresas.

## Ventas

Archivo de rutas:

```txt
app/Modules/Sales/routes.php
```

Controller:

```txt
App\Modules\Sales\Controllers\SaleController
```

### Listar ventas

```txt
GET /api/sales
```

Permiso requerido:

```txt
sales.view
```

### Crear venta en borrador

```txt
POST /api/sales
```

Permiso requerido:

```txt
sales.create
```

Body:

```json
{
  "customer_id": 1,
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 2
    }
  ]
}
```

Reglas:

- crear una venta la deja en `draft`;
- crear una venta no descuenta inventario;
- copia el precio actual del producto;
- copia moneda, tipo de tasa y valor exacto de tasa;
- `customer_id` es opcional;
- si se envia `customer_id`, debe pertenecer a la empresa actual;
- `warehouse_id` y `product_id` deben pertenecer a la empresa actual.

### Ver venta

```txt
GET /api/sales/{sale}
```

Permiso requerido:

```txt
sales.view
```

### Confirmar venta

```txt
PATCH /api/sales/{sale}/confirm
```

Permiso requerido:

```txt
sales.create
```

Reglas:

- solo confirma ventas en `draft`;
- valida stock disponible;
- descuenta inventario con movimiento `sale`;
- enlaza los movimientos de inventario con la venta.

### Cancelar venta

```txt
PATCH /api/sales/{sale}/cancel
```

Permiso requerido:

```txt
sales.cancel
```

Regla:

- en esta fase solo se cancelan ventas en `draft`; ventas confirmadas requeriran devolucion/reverso controlado mas adelante.

## Devoluciones de venta

Archivo de rutas:

```txt
app/Modules/SalesReturns/routes.php
```

Controller:

```txt
App\Modules\SalesReturns\Controllers\SalesReturnController
```

### Listar devoluciones de venta

```txt
GET /api/sales-returns
```

Permiso requerido:

```txt
sales_returns.view
```

### Crear devolucion de venta

```txt
POST /api/sales-returns
```

Permiso requerido:

```txt
sales_returns.create
```

Body:

```json
{
  "sale_id": 1,
  "reason": "Cliente devolvio el producto",
  "items": [
    {
      "sale_item_id": 1,
      "quantity": 1,
      "condition": "sellable",
      "product_unit_ids": [1],
      "reason": "Producto en buen estado"
    }
  ]
}
```

Condiciones iniciales:

- `sellable`: vuelve como disponible para venta;
- `damaged`: vuelve marcado como danado para revision.

Reglas:

- solo se pueden devolver ventas confirmadas;
- una devolucion no borra ni cancela la venta original;
- no se puede devolver mas cantidad que la vendida menos devoluciones previas;
- cada item genera movimiento de inventario `sale_return`;
- si el producto es serializado, se debe enviar una unidad por cada cantidad devuelta;
- si la unidad vuelve como `sellable`, queda disponible;
- si la unidad vuelve como `damaged`, queda marcada como danada;
- todos los ids deben pertenecer a la empresa actual.

### Ver devolucion de venta

```txt
GET /api/sales-returns/{salesReturn}
```

Permiso requerido:

```txt
sales_returns.view
```

## Inventario

Archivo de rutas:

```txt
app/Modules/Inventory/routes.php
```

Controller:

```txt
App\Modules\Inventory\Controllers\InventoryMovementController
```

Servicio usado:

```txt
App\Modules\Inventory\Services\AuthorizedInventoryMovementService
```

### Registrar entrada por compra

```txt
POST /api/inventory/purchases
```

Permiso requerido:

```txt
purchases.create
```

Body:

```json
{
  "warehouse_id": 1,
  "product_id": 1,
  "quantity": 10,
  "unit_cost": 80,
  "reason": "Compra inicial"
}
```

Movimiento creado:

```txt
purchase
```

### Registrar salida por venta

```txt
POST /api/inventory/sales
```

Permiso requerido:

```txt
sales.create
```

Movimiento creado:

```txt
sale
```

### Ajuste positivo

```txt
POST /api/inventory/adjustments/in
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
adjustment_in
```

### Ajuste negativo

```txt
POST /api/inventory/adjustments/out
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
adjustment_out
```

### Reservar stock

```txt
POST /api/inventory/reservations
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
reserved
```

### Liberar reserva

```txt
POST /api/inventory/releases
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
released
```

### Marcar stock danado

```txt
POST /api/inventory/damages
```

Permiso requerido:

```txt
inventory.adjust
```

Movimiento creado:

```txt
damaged
```

### Transferir entre almacenes

```txt
POST /api/inventory/transfers
```

Permiso requerido:

```txt
inventory.transfer
```

Body:

```json
{
  "from_warehouse_id": 1,
  "to_warehouse_id": 2,
  "product_id": 1,
  "quantity": 4,
  "reason": "Reposicion"
}
```

Movimientos creados:

```txt
transfer_out
transfer_in
```

## Reportes

Archivo de rutas:

```txt
app/Modules/Reports/routes.php
```

Controller:

```txt
App\Modules\Reports\Controllers\InventoryReportController
```

Permiso requerido para todos los endpoints:

```txt
reports.view
```

### Stock actual

```txt
GET /api/reports/stock
```

Filtros:

- `warehouse_id`
- `product_id`

### Bajo stock

```txt
GET /api/reports/stock/low
```

Filtros:

- `threshold`
- `warehouse_id`
- `product_id`

### Movimientos

```txt
GET /api/reports/movements
```

Filtros:

- `warehouse_id`
- `product_id`
- `type`
- `date_from`
- `date_to`

## Kardex

Archivo de rutas:

```txt
app/Modules/Kardex/routes.php
```

Controller:

```txt
App\Modules\Kardex\Controllers\KardexController
```

### Kardex por producto

```txt
GET /api/kardex/products/{product}
GET /api/kardex/products/{product}?warehouse_id=1
GET /api/kardex/products/{product}?date_from=2026-07-01&date_to=2026-07-31
```

Permiso requerido:

```txt
kardex.view
```

Respuesta:

- producto consultado;
- almacen filtrado, si aplica;
- saldo inicial;
- saldo final;
- movimientos ordenados cronologicamente;
- cantidad de entrada;
- cantidad de salida;
- saldo corrido por movimiento;
- referencia al documento origen.

Reglas:

- Kardex no duplica datos;
- Kardex lee `stock_movements`;
- `warehouse_id` debe pertenecer a la empresa actual;
- `product` debe pertenecer a la empresa actual;
- `date_from` y `date_to` permiten calcular saldo inicial y saldo final del periodo;
- movimientos de entrada incluyen `purchase`, `sale_return`, `adjustment_in`, `transfer_in`, `return_in` y `released`;
- movimientos de salida incluyen `sale`, `adjustment_out`, `transfer_out`, `return_out`, `damaged` y `reserved`.

## POS

Archivo de rutas:

```txt
app/Modules/POS/routes.php
```

Controller:

```txt
App\Modules\POS\Controllers\PosOrderController
```

### Listar ordenes POS

```txt
GET /api/pos/orders
```

Permiso requerido:

```txt
pos.view
```

Respuesta:

- ordenes POS de la empresa actual;
- venta asociada;
- pagos registrados.

### Crear checkout POS

```txt
POST /api/pos/checkouts
```

Permiso requerido:

```txt
pos.checkout
```

Body:

```json
{
  "cash_register_session_id": 1,
  "customer_id": 1,
  "customer_name": "Cliente mostrador",
  "items": [
    {
      "warehouse_id": 1,
      "product_id": 1,
      "quantity": 2
    }
  ],
  "payments": [
    {
      "method": "cash",
      "currency": "USD",
      "amount": 200,
      "status": "captured"
    }
  ]
}
```

Metodos de pago iniciales:

- `cash`
- `card`
- `mobile_payment`
- `transfer`
- `zelle`
- `external_financing`
- `other`

Estados de pago iniciales:

- `captured`: cuenta como pago valido para cerrar la orden;
- `pending`: queda registrado, pero no confirma la venta;
- `failed`: queda registrado, pero no confirma la venta.

Reglas:

- POS requiere `cash_register_session_id`;
- `customer_id` es opcional y debe pertenecer a la empresa actual cuando se envia;
- `customer_name` puede conservar el nombre mostrado en ticket aunque exista `customer_id`;
- la caja debe estar abierta;
- la caja debe pertenecer al cajero autenticado;
- POS crea una venta en `Sales`;
- POS registra los pagos en `pos_payments`;
- solo pagos `captured` suman al total pagado;
- cada pago `captured` crea un movimiento `pos_payment` en la caja asociada;
- si los pagos capturados cubren el total base, POS confirma la venta y descuenta inventario mediante `Sales`;
- si el pago queda pendiente, la orden POS queda `open` y la venta queda `draft`;
- si dos cajas intentan vender la ultima unidad, la segunda operacion debe fallar por stock insuficiente;
- pagos en `VES` requieren una tasa activa y guardan snapshot de tipo de tasa, codigo y valor;
- pagos con financiadoras externas pueden usar `external_provider`, `reference` y `metadata`.

Ejemplo de pago en bolivares:

```json
{
  "method": "mobile_payment",
  "currency": "VES",
  "amount": 60000,
  "exchange_rate_type_id": 2,
  "reference": "PM-001",
  "status": "captured"
}
```

Ejemplo de financiadora externa pendiente:

```json
{
  "method": "external_financing",
  "currency": "USD",
  "amount": 100,
  "status": "pending",
  "external_provider": "Financiadora Demo",
  "reference": "SOL-1001"
}
```

### Ver orden POS

```txt
GET /api/pos/orders/{posOrder}
```

Permiso requerido:

```txt
pos.view
```

## Caja

Archivo de rutas:

```txt
app/Modules/CashRegister/routes.php
```

Controller:

```txt
App\Modules\CashRegister\Controllers\CashRegisterSessionController
```

### Listar sesiones de caja

```txt
GET /api/cash-register/sessions
```

Permiso requerido:

```txt
cash_register.view
```

### Abrir caja

```txt
POST /api/cash-register/sessions
```

Permiso requerido:

```txt
cash_register.open
```

Body:

```json
{
  "branch_id": 1,
  "cashier_id": 1,
  "opening_currency": "USD",
  "opening_amount": 50,
  "notes": "Inicio de turno"
}
```

Reglas:

- `cashier_id` es opcional; si no se envia, se usa el usuario autenticado;
- la sucursal debe pertenecer a la empresa actual;
- un cajero no puede tener dos cajas abiertas al mismo tiempo;
- si el monto inicial esta en `VES`, debe existir una tasa activa.

### Ver sesion de caja

```txt
GET /api/cash-register/sessions/{cashRegisterSession}
```

Permiso requerido:

```txt
cash_register.view
```

### Registrar movimiento de caja

```txt
POST /api/cash-register/sessions/{cashRegisterSession}/movements
```

Permiso requerido:

```txt
cash_register.move
```

Body:

```json
{
  "type": "inflow",
  "method": "cash",
  "currency": "VES",
  "amount": 50000,
  "exchange_rate_type_id": 1,
  "reference": "ING-1",
  "notes": "Entrada manual"
}
```

Tipos iniciales:

- `inflow`
- `outflow`
- `adjustment`

Metodos iniciales:

- `cash`
- `card`
- `mobile_payment`
- `transfer`
- `zelle`
- `external_financing`
- `other`

Reglas:

- una caja cerrada no acepta movimientos;
- `outflow` resta al monto esperado;
- `inflow` y `adjustment` suman al monto esperado en esta fase;
- movimientos en `VES` guardan snapshot de tipo de tasa, codigo y valor.

### Cerrar caja

```txt
PATCH /api/cash-register/sessions/{cashRegisterSession}/close
```

Permiso requerido:

```txt
cash_register.close
```

Body:

```json
{
  "counted_currency": "USD",
  "counted_amount": 110,
  "closing_notes": "Faltante reportado"
}
```

Reglas:

- calcula diferencia entre monto contado y monto esperado;
- cambia la sesion a `closed`;
- despues del cierre no se pueden registrar nuevos movimientos.

## Respuestas y errores comunes

### Sin autenticacion

```txt
401 Unauthorized
```

### Sin permiso

```txt
403 Forbidden
```

### Recurso fuera del tenant actual

```txt
422 Unprocessable Entity
```

### Tenant inexistente o no resuelto

```txt
404 Not Found
```

## Reglas importantes

- Ninguna API debe permitir acceder a datos de otro tenant.
- Ninguna API debe saltarse policies, permisos o servicios autorizados.
- Las APIs de productos deben respetar SKU unico por tenant.
- Las APIs de sucursales y almacenes deben respetar codigo unico por tenant.
- Un almacen nunca debe apuntar a una sucursal de otra empresa.
- Las APIs de moneda deben permitir multiples tipos de tasa por empresa, como `BCV` y `PARALELO`.
- Las APIs de clientes deben vivir en el modulo `Customers` y no mezclar documentos entre empresas.
- Las APIs de proveedores deben vivir en el modulo `Suppliers` y no mezclar documentos entre empresas.
- Las APIs de compras deben vivir en el modulo `Purchases` y usar `Inventory` para recibir stock.
- Las APIs de compras no deben mover inventario al crear borradores.
- Las APIs de devoluciones a proveedor deben vivir en el modulo `PurchaseReturns`.
- Las devoluciones a proveedor deben crear movimientos `purchase_return`, no borrar compras historicas.
- Las APIs de cuentas por pagar deben vivir en el modulo `AccountsPayable`.
- Las cuentas por pagar se crean al recibir compras y se reducen con pagos o devoluciones a proveedor.
- Las APIs de ventas deben copiar precio y tasa exacta usada, no recalcular historia.
- Las APIs de ventas pueden asociar `customer_id`, pero solo del tenant actual.
- Las APIs de cuentas por cobrar deben vivir en el modulo `AccountsReceivable`.
- Las cuentas por cobrar se crean al confirmar ventas y se reducen con cobros o devoluciones de venta.
- Las APIs de reportes financieros deben vivir en el modulo `FinanceReports`.
- Los reportes financieros son solo lectura y resumen cuentas por cobrar, cuentas por pagar, cobros y pagos.
- Las APIs de devoluciones de venta deben vivir en el modulo `SalesReturns`.
- Las devoluciones de venta deben crear movimientos `sale_return`, no borrar ventas historicas.
- Las APIs de POS deben vivir en el modulo `POS` y usar `Sales` como motor de venta.
- Las APIs de POS no deben descontar inventario directamente.
- Las APIs de POS deben asociar checkouts a una caja abierta cuando sean ventas de mostrador.
- Las APIs de POS pueden asociar `customer_id`, pero solo del tenant actual.
- Las APIs de caja deben vivir en el modulo `CashRegister`, separadas de POS.
- Las APIs de caja deben guardar diferencias de cierre sin alterar ventas historicas.
- Las APIs de inventario modifican stock solo mediante servicios del modulo `Inventory`.
- Las APIs de reportes son solo lectura.
- Las APIs de Kardex son solo lectura y deben calcular saldos desde `stock_movements`.
