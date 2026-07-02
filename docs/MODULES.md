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
- definir si un producto se controla por cantidad o por unidades serializadas;
- precio base en `USD`, moneda de venta y tipo de tasa sugerido.

Archivos principales:

- `app/Modules/Products/Models/Product.php`
- `app/Modules/Products/Policies/ProductPolicy.php`
- `app/Modules/Products/Controllers/ProductController.php`
- `app/Modules/Products/Requests/StoreProductRequest.php`
- `app/Modules/Products/Requests/UpdateProductRequest.php`
- `app/Modules/Products/Resources/ProductResource.php`
- `app/Modules/Products/Resources/ProductPriceResource.php`
- `app/Modules/Products/Services/ProductPriceService.php`
- `app/Modules/Products/routes.php`

Regla importante:

- `tracking_type = quantity` se usa para productos normales por cantidad;
- `tracking_type = serialized` se usa para productos que requieren IMEI, serial u otro identificador unico por unidad.
- `base_price` se guarda en `USD`;
- `sale_currency` define si la cotizacion preferida sale en `USD` o `VES`;
- `sale_exchange_rate_type_id` permite que un producto use `BCV`, `PARALELO` u otro tipo de tasa.

### Branches

Responsabilidad:

- sucursales por tenant;
- API de administracion de sucursales.

Archivos principales:

- `app/Modules/Branches/Models/Branch.php`
- `app/Modules/Branches/Policies/BranchPolicy.php`
- `app/Modules/Branches/Controllers/BranchController.php`
- `app/Modules/Branches/Requests/StoreBranchRequest.php`
- `app/Modules/Branches/Requests/UpdateBranchRequest.php`
- `app/Modules/Branches/Resources/BranchResource.php`
- `app/Modules/Branches/routes.php`

Regla importante:

- `code` es unico por tenant;
- desactivar una sucursal usa `status = inactive`, no borrado fisico.

### Warehouses

Responsabilidad:

- almacenes por tenant y sucursal;
- API de administracion de almacenes.

Archivos principales:

- `app/Modules/Warehouses/Models/Warehouse.php`
- `app/Modules/Warehouses/Policies/WarehousePolicy.php`
- `app/Modules/Warehouses/Controllers/WarehouseController.php`
- `app/Modules/Warehouses/Requests/StoreWarehouseRequest.php`
- `app/Modules/Warehouses/Requests/UpdateWarehouseRequest.php`
- `app/Modules/Warehouses/Resources/WarehouseResource.php`
- `app/Modules/Warehouses/routes.php`

Regla importante:

- `code` es unico por tenant;
- un almacen no puede apuntar a una sucursal de otra empresa;
- desactivar un almacen usa `status = inactive`, no borrado fisico.

### Currency

Responsabilidad:

- tipos de tasa por empresa, por ejemplo `BCV`, `PARALELO` o `TIENDA`;
- historial de tasas `USD` a `VES`;
- consulta de tasas activas actuales;
- activacion controlada de tasas.

Archivos principales:

- `app/Modules/Currency/Models/ExchangeRateType.php`
- `app/Modules/Currency/Models/ExchangeRate.php`
- `app/Modules/Currency/Policies/ExchangeRateTypePolicy.php`
- `app/Modules/Currency/Policies/ExchangeRatePolicy.php`
- `app/Modules/Currency/Controllers/ExchangeRateTypeController.php`
- `app/Modules/Currency/Controllers/ExchangeRateController.php`
- `app/Modules/Currency/Services/ExchangeRateActivationService.php`
- `app/Modules/Currency/routes.php`

Regla importante:

- una empresa puede tener `BCV` y `PARALELO` activos al mismo tiempo;
- activar una nueva tasa solo reemplaza la tasa activa del mismo tipo y par de monedas;
- las ventas futuras deben guardar el tipo de tasa y el valor exacto usado.

### Customers

Responsabilidad:

- clientes por tenant;
- documentos fiscales o personales del cliente;
- cliente generico para ventas de mostrador;
- asociacion opcional de clientes con ventas y ordenes POS;
- base para facturacion, credito, historial comercial y reportes por cliente.

Archivos principales:

- `app/Modules/Customers/Models/Customer.php`
- `app/Modules/Customers/Policies/CustomerPolicy.php`
- `app/Modules/Customers/Controllers/CustomerController.php`
- `app/Modules/Customers/Requests/StoreCustomerRequest.php`
- `app/Modules/Customers/Requests/UpdateCustomerRequest.php`
- `app/Modules/Customers/Resources/CustomerResource.php`
- `app/Modules/Customers/routes.php`

Regla importante:

- `document_type + document_number` es unico por tenant, no global;
- un cliente de una empresa no puede usarse en ventas o POS de otra empresa;
- la eliminacion API desactiva al cliente con `is_active = false`;
- `customer_id` en ventas y POS es opcional para permitir ventas rapidas y cliente generico.

### Suppliers

Responsabilidad:

- proveedores por tenant;
- documentos fiscales o personales del proveedor;
- datos de contacto, direccion fiscal y notas;
- base para compras, cuentas por pagar y reportes por proveedor.

Archivos principales:

- `app/Modules/Suppliers/Models/Supplier.php`
- `app/Modules/Suppliers/Policies/SupplierPolicy.php`
- `app/Modules/Suppliers/Controllers/SupplierController.php`
- `app/Modules/Suppliers/Requests/StoreSupplierRequest.php`
- `app/Modules/Suppliers/Requests/UpdateSupplierRequest.php`
- `app/Modules/Suppliers/Resources/SupplierResource.php`
- `app/Modules/Suppliers/routes.php`

Regla importante:

- `document_type + document_number` es unico por tenant, no global;
- un proveedor de una empresa no puede usarse en compras de otra empresa;
- la eliminacion API desactiva al proveedor con `is_active = false`.

### Purchases

Responsabilidad:

- compras en borrador;
- recepcion de compras;
- asociacion opcional con proveedores;
- costos en `USD` o `VES` con snapshot de tasa;
- generacion de movimientos `purchase` al recibir;
- creacion de unidades serializadas, como IMEIs, al recibir productos serializados.

Archivos principales:

- `app/Modules/Purchases/Models/PurchaseOrder.php`
- `app/Modules/Purchases/Models/PurchaseItem.php`
- `app/Modules/Purchases/Policies/PurchaseOrderPolicy.php`
- `app/Modules/Purchases/Controllers/PurchaseOrderController.php`
- `app/Modules/Purchases/Requests/StorePurchaseOrderRequest.php`
- `app/Modules/Purchases/Resources/PurchaseOrderResource.php`
- `app/Modules/Purchases/Resources/PurchaseItemResource.php`
- `app/Modules/Purchases/Services/PurchaseOrderService.php`
- `app/Modules/Purchases/routes.php`

Regla importante:

- crear una compra no mueve inventario;
- recibir una compra genera movimientos `purchase` usando `InventoryMovementService`;
- compras recibidas no se cancelan directamente en esta fase;
- productos serializados requieren un serial o IMEI por unidad comprada;
- los seriales recibidos se crean en `product_units` como disponibles y enlazados al movimiento de compra.

### Sales

Responsabilidad:

- ventas en borrador;
- confirmacion de ventas;
- cancelacion de ventas en borrador;
- asociacion opcional con clientes del modulo `Customers`;
- copia historica de precio, moneda, tipo de tasa y valor de tasa;
- descuento de inventario al confirmar.

Archivos principales:

- `app/Modules/Sales/Models/Sale.php`
- `app/Modules/Sales/Models/SaleItem.php`
- `app/Modules/Sales/Policies/SalePolicy.php`
- `app/Modules/Sales/Controllers/SaleController.php`
- `app/Modules/Sales/Requests/StoreSaleRequest.php`
- `app/Modules/Sales/Resources/SaleResource.php`
- `app/Modules/Sales/Resources/SaleItemResource.php`
- `app/Modules/Sales/Services/SaleService.php`
- `app/Modules/Sales/routes.php`

Regla importante:

- crear una venta no mueve inventario;
- si se envia cliente, debe pertenecer al tenant actual;
- confirmar una venta descuenta inventario;
- cada item guarda precio y tasa exacta usada;
- ventas confirmadas no se cancelan directamente en esta fase.

### POS

Responsabilidad:

- punto de venta operativo;
- checkout rapido desde caja;
- registro de pagos en `USD` o `VES`;
- soporte inicial para pagos capturados y pendientes;
- integracion con `Sales` para crear y confirmar ventas;
- asociacion opcional con clientes del modulo `Customers`;
- base para metodos de pago, financiadoras externas y conciliaciones futuras.

Archivos principales:

- `app/Modules/POS/Models/PosOrder.php`
- `app/Modules/POS/Models/PosPayment.php`
- `app/Modules/POS/Policies/PosOrderPolicy.php`
- `app/Modules/POS/Controllers/PosOrderController.php`
- `app/Modules/POS/Requests/StorePosCheckoutRequest.php`
- `app/Modules/POS/Resources/PosOrderResource.php`
- `app/Modules/POS/Resources/PosPaymentResource.php`
- `app/Modules/POS/Services/PosCheckoutService.php`
- `app/Modules/POS/routes.php`

Regla importante:

- POS no debe descontar inventario directamente;
- POS debe usar `Sales` para crear y confirmar la venta;
- POS puede asociar un `customer_id`, pero solo si pertenece al tenant actual;
- POS debe estar asociado a una sesion de caja abierta;
- POS solo puede usar la caja del cajero autenticado;
- solo pagos `captured` cuentan para cerrar una orden POS;
- cada pago `captured` crea un movimiento `pos_payment` en `CashRegister`;
- pagos `pending`, como financiadoras externas, dejan la orden abierta y la venta en borrador;
- cada pago en `VES` debe guardar la tasa exacta usada.

### CashRegister

Responsabilidad:

- apertura de caja;
- movimientos manuales de entrada y salida;
- cierre de caja;
- arqueo, monto esperado, monto contado y diferencias;
- base para asociar pagos POS a sesiones de caja abiertas.

Archivos principales:

- `app/Modules/CashRegister/Models/CashRegisterSession.php`
- `app/Modules/CashRegister/Models/CashRegisterMovement.php`
- `app/Modules/CashRegister/Policies/CashRegisterSessionPolicy.php`
- `app/Modules/CashRegister/Controllers/CashRegisterSessionController.php`
- `app/Modules/CashRegister/Requests/OpenCashRegisterSessionRequest.php`
- `app/Modules/CashRegister/Requests/StoreCashRegisterMovementRequest.php`
- `app/Modules/CashRegister/Requests/CloseCashRegisterSessionRequest.php`
- `app/Modules/CashRegister/Resources/CashRegisterSessionResource.php`
- `app/Modules/CashRegister/Resources/CashRegisterMovementResource.php`
- `app/Modules/CashRegister/Services/CashRegisterService.php`
- `app/Modules/CashRegister/routes.php`

Regla importante:

- Caja no crea ventas;
- POS no debe absorber el cierre de caja;
- un cajero no puede tener dos sesiones abiertas al mismo tiempo;
- una caja cerrada no acepta movimientos;
- cada movimiento en `VES` debe guardar la tasa exacta usada;
- POS asocia pagos capturados a una sesion de caja abierta;
- varias cajas pueden operar en paralelo, pero cada caja mantiene sus movimientos y cierre independiente.

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

### Sales

- documentos de venta;
- items de venta;
- confirmacion/cancelacion de venta;
- al confirmar, generar movimientos `sale`.

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
- Los datos demo persistentes se documentan en `docs/DEMO_DATA.md`.
