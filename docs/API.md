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
  "is_active": true
}
```

Reglas:

- `sku` es unico dentro de la empresa actual;
- `tracking_type` puede ser `quantity` o `serialized`;
- si no se envia `tracking_type`, el producto queda como `quantity`;
- los productos con `serialized` pueden tener unidades en `product_units` con IMEI o serial;
- si un producto ya tiene unidades serializadas, no se puede cambiar su `tracking_type`.

### Ver producto

```txt
GET /api/products/{product}
```

Permiso requerido:

```txt
products.view
```

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
- Las APIs de inventario modifican stock solo mediante servicios del modulo `Inventory`.
- Las APIs de reportes son solo lectura.
- Las APIs futuras de POS deben vivir en su propio modulo `POS`.
