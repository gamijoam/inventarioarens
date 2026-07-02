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
- Las APIs de inventario modifican stock solo mediante servicios del modulo `Inventory`.
- Las APIs de reportes son solo lectura.
- Las APIs futuras de POS deben vivir en su propio modulo `POS`.
