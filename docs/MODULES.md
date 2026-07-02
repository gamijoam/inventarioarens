# Mapa modular del proyecto

Este proyecto se organiza como monolito modular. La regla practica es: si hay un fallo o una mejora, primero se identifica el modulo dueño del comportamiento y se trabaja ahi.

## Convenciones

Cada modulo debe vivir en:

```txt
app/Modules/NombreModulo
```

Estructura recomendada:

```txt
NombreModulo/
|-- Actions/
|-- Controllers/
|-- DTOs/
|-- Exceptions/
|-- Models/
|-- Policies/
|-- Requests/
|-- Resources/
|-- Services/
|-- routes.php
`-- ModuleServiceProvider.php
```

No todas las carpetas son obligatorias. Se crean cuando el modulo las necesita.

## Modulos actuales

### Tenancy

Responsabilidad:

- resolver el tenant actual;
- mantener el tenant actual durante la peticion;
- definir el modelo `Tenant`;
- registrar servicios de tenancy.

Archivos principales:

- `app/Modules/Tenancy/Models/Tenant.php`
- `app/Modules/Tenancy/Middleware/ResolveTenant.php`
- `app/Modules/Tenancy/Providers/TenancyServiceProvider.php`

### Products

Responsabilidad:

- productos tenant-scoped;
- policy base de productos;
- definir si un producto se controla por cantidad o por unidades serializadas.

Archivos principales:

- `app/Modules/Products/Models/Product.php`
- `app/Modules/Products/Policies/ProductPolicy.php`

Regla importante:

- `tracking_type = quantity` se usa para productos normales por cantidad;
- `tracking_type = serialized` se usa para productos que requieren IMEI, serial u otro identificador unico por unidad.

### Branches

Responsabilidad:

- sucursales por tenant.

Archivo principal:

- `app/Modules/Branches/Models/Branch.php`

### Warehouses

Responsabilidad:

- almacenes por tenant y sucursal.

Archivo principal:

- `app/Modules/Warehouses/Models/Warehouse.php`

### Inventory

Responsabilidad:

- movimientos de inventario;
- balances de stock;
- unidades fisicas serializadas;
- operaciones de entrada, salida, reserva, liberacion, danado y transferencia;
- autorizacion operativa de inventario;
- API de movimientos de inventario.

Archivos principales:

- `app/Modules/Inventory/Models/StockMovement.php`
- `app/Modules/Inventory/Models/StockBalance.php`
- `app/Modules/Inventory/Models/ProductUnit.php`
- `app/Modules/Inventory/Services/InventoryMovementService.php`
- `app/Modules/Inventory/Services/AuthorizedInventoryMovementService.php`
- `app/Modules/Inventory/Policies/InventoryPolicy.php`
- `app/Modules/Inventory/Controllers/InventoryMovementController.php`
- `app/Modules/Inventory/routes.php`

Regla importante:

- no agregar columna `stock` a productos;
- el historico vive en `stock_movements`;
- la lectura rapida vive en `stock_balances`.
- los IMEIs y seriales viven en `product_units`, no en `products`.

### Reports

Responsabilidad:

- reportes de stock;
- reportes de bajo stock;
- reportes de movimientos.

Archivos principales:

- `app/Modules/Reports/Controllers/InventoryReportController.php`
- `app/Modules/Reports/Requests/StockReportRequest.php`
- `app/Modules/Reports/Requests/MovementReportRequest.php`
- `app/Modules/Reports/Resources/StockReportResource.php`
- `app/Modules/Reports/Resources/MovementReportResource.php`
- `app/Modules/Reports/routes.php`

Regla importante:

- un reporte no debe modificar datos;
- todo reporte debe respetar `tenant_id`;
- todo reporte sensible debe requerir permiso.

### Audit

Responsabilidad:

- registrar eventos importantes de negocio;
- guardar usuario, tenant, entidad, valores, IP y user agent.

Archivos principales:

- `app/Modules/Audit/Models/AuditLog.php`
- `app/Modules/Audit/Services/AuditLogger.php`

## Modulos planificados

### Purchases

- documentos de compra;
- items de compra;
- aprobacion de compra;
- al aprobar, generar movimientos `purchase`.

### Sales

- documentos de venta;
- items de venta;
- confirmacion/cancelacion de venta;
- al confirmar, generar movimientos `sale`.

### POS

- flujo rapido de punto de venta;
- caja, metodo de pago, recibo;
- ventas en `USD` o `VES`;
- integracion con tasas y ventas.

### Currency

- monedas soportadas;
- tasas de cambio por tenant;
- fuente de tasa;
- conversion entre `USD` y `VES`.

### AI

- interpretacion de instrucciones naturales;
- consultas autorizadas;
- sugerencias;
- borradores de acciones;
- nunca ejecutar acciones criticas sin permisos, validacion y confirmacion.

## Reglas de mantenimiento

- Si una funcionalidad tiene logica propia, debe vivir en su modulo.
- Si una API pertenece a un modulo, sus rutas deben estar en `app/Modules/<Modulo>/routes.php`.
- `routes/api.php` solo carga rutas modulares.
- Las migraciones siguen en `database/migrations` por convencion Laravel, pero deben nombrarse claramente.
- Los tests deben estar agrupados por area en `tests/Feature/<Area>`.
- Toda documentacion debe escribirse en espanol.
- Cada cambio importante debe quedar registrado en `docs/IMPLEMENTATION_LOG.md`.
