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

### Auth

Responsabilidad:

- autenticar usuarios contra correo y clave;
- listar empresas activas disponibles para el usuario antes del login;
- emitir tokens `Bearer` ligados a una empresa;
- devolver usuario, empresa, roles y permisos efectivos para inicializar el frontend;
- cerrar sesion actual o todas las sesiones del usuario en la empresa actual.

Archivos principales:

- `app/Modules/Auth/Models/AuthToken.php`
- `app/Modules/Auth/Middleware/AuthenticateApiToken.php`
- `app/Modules/Auth/Controllers/AuthController.php`
- `app/Modules/Auth/Requests/LoginRequest.php`
- `app/Modules/Auth/Requests/TenantLookupRequest.php`
- `app/Modules/Auth/Resources/AuthSessionResource.php`
- `app/Modules/Auth/Services/AuthService.php`
- `app/Modules/Auth/routes.php`

Regla importante:

- el token se guarda hasheado en `auth_tokens`;
- el token queda asociado a `tenant_id` y `user_id`;
- un token emitido para una empresa no puede usarse con otra empresa;
- las APIs protegidas usan el middleware `api.auth` antes de resolver permisos de negocio;
- el frontend debe tratarse como cliente de APIs, sin autoridad para saltarse permisos.

### Frontend

Responsabilidad:

- mostrar la pantalla inicial de login;
- consultar empresas disponibles para el usuario;
- iniciar sesion contra las APIs del modulo `Auth`;
- conservar la sesion en navegador en esta primera fase;
- mostrar el panel principal cuando la sesion esta activa;
- agrupar la navegacion por modulos operativos;
- filtrar opciones visibles usando los permisos efectivos del usuario;
- consumir datos reales del dashboard y del centro de inventario cuando existe sesion API real.
- permitir crear y editar productos desde el Centro de Inventario usando las APIs del modulo `Products`.

Archivos principales:

- `resources/views/welcome.blade.php`
- `resources/css/app.css`
- `resources/js/app.js`

Regla importante:

- el frontend no reemplaza permisos ni validaciones del backend;
- toda accion real debe pasar por APIs protegidas con `api.auth` y `tenant`;
- el diseno usa la paleta morada/azul del login de referencia, pero ahora como login central profesional sin imagen lateral;
- el panel principal se organiza por Operacion, Inventario, Finanzas y Administracion;
- la visibilidad de menu, accesos rapidos y acciones superiores depende de permisos enviados por Auth;
- `FRONTEND_DEV_BYPASS_LOGIN=true` permite revisar el frontend local sin credenciales, solo en `APP_ENV=local`.
- el Centro de Inventario consume `GET /api/inventory-center/summary` y conserva datos demo solo para el bypass local.
- el formulario de productos usa `POST /api/products`, `GET /api/products/{product}` y `PATCH /api/products/{product}`;
- las opciones de tasa y garantia se leen desde `Currency` y `Warranties` cuando el usuario tiene permiso para verlas;
- el frontend solo oculta o muestra acciones por permisos, pero la autoridad real sigue en policies y validaciones del backend.

### Desktop

Responsabilidad:

- servir como cliente principal de escritorio para el sistema de inventario;
- conectarse al backend Laravel mediante HTTP/JSON;
- manejar login, seleccion de empresa y token Bearer;
- organizar pantallas por modulos operativos igual que el backend;
- mostrar un centro de modulos despues del login para elegir el area de trabajo;
- habilitar o bloquear tarjetas de modulo segun permisos efectivos del usuario;
- consumir APIs existentes sin conectarse directo a PostgreSQL.

Archivos principales:

- `desktop/InventoryDesktop/InventoryDesktop.csproj`
- `desktop/InventoryDesktop/MainWindow.xaml`
- `desktop/InventoryDesktop/Core/Api/ApiClient.cs`
- `desktop/InventoryDesktop/Core/Security/TokenVault.cs`
- `desktop/InventoryDesktop/Modules/Auth/LoginViewModel.cs`
- `desktop/InventoryDesktop/Modules/Auth/AuthDtos.cs`

Regla importante:

- WPF es un cliente de APIs, no una fuente de verdad;
- todas las operaciones reales deben pasar por Laravel con `Authorization: Bearer <token>` y `X-Tenant`;
- el modulo `Auth` del escritorio debe respetar el contrato real de Laravel, donde las respuestas vienen envueltas en `data`;
- las siguientes pantallas de escritorio se implementaran por modulo: Centro de Inventario, Entradas y Salidas, POS, Caja y Reportes.
- el flujo recomendado es `Login -> Centro de modulos -> Modulo seleccionado`.
- la navegacion principal del escritorio queda basada en tarjetas de modulos, sin menu lateral permanente.
- cada modulo debe ofrecer una forma clara de volver al centro de modulos o tener navegacion interna propia.

### Dashboard

Responsabilidad:

- exponer el resumen ejecutivo del negocio;
- entregar datos agregados para la portada del frontend;
- resumir ventas confirmadas, POS cobrado, cajas abiertas, stock bajo y pendientes financieros;
- servir como API unica para evitar que el frontend haga multiples consultas pequenas al cargar la portada.

Archivos principales:

- `app/Modules/Dashboard/Controllers/DashboardController.php`
- `app/Modules/Dashboard/Requests/DashboardSummaryRequest.php`
- `app/Modules/Dashboard/Services/DashboardSummaryService.php`
- `app/Modules/Dashboard/routes.php`

Regla importante:

- el modulo es solo lectura;
- usa sumas y conteos agregados en base de datos;
- la lista de stock bajo se limita a 5 registros y carga solo `product` y `warehouse` necesarios;
- no debe cargar colecciones completas para la portada;
- la autorizacion acepta permisos de lectura operativos como `finance_reports.view`, `reports.view`, `sales.view`, `pos.view`, `products.view` o `cash_register.view`.

### AdminPortal

Responsabilidad:

- exponer APIs de lectura para el futuro portal web administrativo;
- servir la primera interfaz web del portal administrativo en `/admin`;
- resumir ventas, POS, caja, inventario y sincronizacion por empresa;
- servir como contrato de datos para metricas gerenciales en la nube;
- evitar que la web consulte PostgreSQL directamente;
- entregar alertas operativas sin cargar listas completas;
- administrar productos, precios y permisos usando las APIs protegidas del backend.

Archivos principales:

- `app/Modules/AdminPortal/Controllers/AdminDashboardController.php`
- `app/Modules/AdminPortal/Requests/AdminDashboardRequest.php`
- `app/Modules/AdminPortal/Services/AdminDashboardService.php`
- `app/Modules/AdminPortal/routes.php`
- `resources/views/admin.blade.php`
- `resources/css/admin.css`
- `resources/js/admin.js`

Regla importante:

- el modulo no consulta PostgreSQL directamente; lee y escribe mediante APIs protegidas;
- requiere permisos de lectura gerencial u operativa como `reports.view`, `finance_reports.view`, `sales.view`, `products.view` o `cash_register.view`;
- todas las consultas filtran por tenant actual;
- usa agregados SQL para evitar N+1 y no enviar catalogos completos al portal web.
- la interfaz web consume APIs protegidas; no consulta PostgreSQL directamente ni reemplaza permisos del backend.
- el portal administrativo debe seguir la guia de alta densidad documentada en `docs/GUIA_UI_ALTA_DENSIDAD_PORTAL_ADMIN_2026-07-07.md`: textos de 12px a 14px, tablas compactas, controles delgados y minimo espacio vacio.

### InventoryCenter

Responsabilidad:

- exponer una lectura agregada para la pantalla Centro de Inventario;
- entregar metricas de productos activos, serializados, stock disponible, reservado, danado, bajo y sin stock;
- entregar un listado limitado de productos con stock agregado por producto;
- entregar el detalle operativo de un producto con stock por almacen, seriales/IMEIs y movimientos recientes;
- entregar historial global paginado de movimientos de inventario para el portal administrativo;
- permitir busqueda por nombre o SKU y filtros por tipo de control o estado de stock;
- devolver paginacion real para catalogos grandes;
- evitar que el frontend haga multiples consultas pequenas o caiga en problemas N+1.
- actuar como pantalla operativa del catalogo, delegando creacion y edicion al modulo `Products`.

Archivos principales:

- `app/Modules/InventoryCenter/Controllers/InventoryCenterController.php`
- `app/Modules/InventoryCenter/Requests/InventoryCenterSummaryRequest.php`
- `app/Modules/InventoryCenter/Requests/InventoryCenterProductMovementsRequest.php`
- `app/Modules/InventoryCenter/Services/InventoryCenterMovementService.php`
- `app/Modules/InventoryCenter/Services/InventoryCenterProductDetailService.php`
- `app/Modules/InventoryCenter/Services/InventoryCenterSummaryService.php`
- `app/Modules/InventoryCenter/routes.php`

Regla importante:

- el modulo es solo lectura;
- requiere `products.view` o `inventory.view`;
- usa `stock_balances` agregado por producto en base de datos;
- el listado se limita a 50 productos como maximo por llamada;
- la pantalla frontend permite alternar entre tarjetas y lista sin cambiar el contrato de permisos;
- no mezcla productos ni saldos entre empresas.
- no escribe productos directamente; las altas y modificaciones se hacen por `Products`.
- el detalle de producto limita seriales a 50 y movimientos a 10 para evitar cargas grandes en inventarios reales.
- el historial global de movimientos usa paginacion, filtros y relaciones precargadas para evitar N+1 en el portal web.

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
- precio base en `USD`, moneda de venta y tipo de tasa sugerido;
- listas de precio por empresa y precios especificos por producto/lista para POS.

Archivos principales:

- `app/Modules/Products/Models/Product.php`
- `app/Modules/Products/Models/PriceList.php`
- `app/Modules/Products/Models/ProductPrice.php`
- `app/Modules/Products/Policies/ProductPolicy.php`
- `app/Modules/Products/Controllers/ProductController.php`
- `app/Modules/Products/Controllers/PriceListController.php`
- `app/Modules/Products/Requests/StoreProductRequest.php`
- `app/Modules/Products/Requests/UpdateProductRequest.php`
- `app/Modules/Products/Requests/StorePriceListRequest.php`
- `app/Modules/Products/Requests/UpdatePriceListRequest.php`
- `app/Modules/Products/Requests/SyncProductPricesRequest.php`
- `app/Modules/Products/Resources/ProductResource.php`
- `app/Modules/Products/Resources/ProductPriceResource.php`
- `app/Modules/Products/Resources/PriceListResource.php`
- `app/Modules/Products/Resources/ProductPriceListResource.php`
- `app/Modules/Products/Services/ProductPriceService.php`
- `app/Modules/Products/routes.php`

Regla importante:

- `tracking_type = quantity` se usa para productos normales por cantidad;
- `tracking_type = serialized` se usa para productos que requieren IMEI, serial u otro identificador unico por unidad.
- `base_price` se guarda en `USD`;
- `sale_currency` define si la cotizacion preferida sale en `USD` o `VES`;
- `sale_exchange_rate_type_id` permite que un producto use `BCV`, `PARALELO` u otro tipo de tasa.
- `price_lists` define listas como `MAYOR`, `DETAL` o `TECNICO`;
- `product_prices` define el precio de un producto en cada lista, con moneda `USD` o `VES` y tasa opcional;
- una lista de precio puede restringir qué métodos de pago acepta mediante `price_list_payment_method`;
- el POS debe enviar `price_list_id` por item cuando quiera usar una lista concreta;
- las ventas copian historicamente la lista, precio, moneda y tasa usada.
- la respuesta del producto puede incluir `sale_exchange_rate_type` y `warranty_policy` cuando el controller los carga para formularios o detalle.
- crear o actualizar un producto desde web genera eventos de sincronizacion en `sync_outbox`.
- eliminar un producto en web es borrado logico: `DELETE /api/products/{product}` cambia `is_active=false`, conserva historico y tambien genera evento para locales.
- reactivar un producto en web usa `PATCH /api/products/{product}` con `is_active=true` y genera evento para locales.
- el portal web debe exponer filtros rapidos de productos activos/inactivos para evitar duplicar SKU cuando un producto solo estaba desactivado.
- el portal web incluye un detalle operativo compacto por producto para revisar stock por almacen, precios por lista y actividad reciente sin salir de la tabla.

### PaymentMethods

Responsabilidad:

- administrar los métodos de pago disponibles por empresa;
- definir si un método opera en dólares, bolívares o de forma flexible;
- indicar si un método requiere referencia obligatoria;
- permitir que una lista de precio limite los métodos aceptados;
- servir como base para reglas de cobro en POS, caja y futuras conciliaciones.

Archivos principales:

- `app/Modules/PaymentMethods/Models/PaymentMethod.php`
- `app/Modules/PaymentMethods/Controllers/PaymentMethodController.php`
- `app/Modules/PaymentMethods/Requests/StorePaymentMethodRequest.php`
- `app/Modules/PaymentMethods/Requests/UpdatePaymentMethodRequest.php`
- `app/Modules/PaymentMethods/Resources/PaymentMethodResource.php`
- `app/Modules/PaymentMethods/routes.php`

### Sync

Responsabilidad:

- registrar nodos de sincronizacion por empresa;
- recibir eventos externos en `sync_inbox` sin duplicarlos;
- entregar eventos pendientes desde `sync_outbox`;
- confirmar a la nube solo eventos que ya quedaron aplicados o ignorados localmente;
- exponer estado operativo de sincronizacion por nodo, empresa e instalacion local;
- servir como base para la futura sincronizacion local-nube y nube-local.

Archivos principales:

- `app/Modules/Sync/Controllers/SyncController.php`
- `app/Modules/Sync/Requests/RegisterSyncNodeRequest.php`
- `app/Modules/Sync/Requests/PushSyncEventsRequest.php`
- `app/Modules/Sync/Requests/PullSyncEventsRequest.php`
- `app/Modules/Sync/Requests/AcknowledgeSyncEventRequest.php`
- `app/Modules/Sync/Requests/SyncReadinessRequest.php`
- `app/Modules/Sync/Commands/RunSyncCommand.php`
- `app/Modules/Sync/Commands/RunSyncDaemonCommand.php`
- `app/Modules/Sync/Commands/IssueSyncTokenCommand.php`
- `app/Modules/Sync/Services/SyncEventApplier.php`
- `app/Modules/Sync/Services/SyncCatalogOutboxService.php`
- `app/Modules/Sync/Services/SyncOutboxService.php`
- `app/Modules/Sync/Services/SyncReadinessService.php`
- `app/Modules/Sync/Services/SyncTransportService.php`
- `app/Modules/Sync/Services/SyncWorkerService.php`
- `app/Modules/Sync/routes.php`
- `database/migrations/2026_07_06_130000_create_sync_tenant_readiness_table.php`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml.cs`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerViewModel.cs`
- `scripts/sync-worker.ps1`
- `scripts/sync-worker.cmd`
- `scripts/sync-smoke-test.ps1`
- `scripts/cloud-api-bootstrap-vps.sh`
- `scripts/configure-sync-cloud-local.ps1`
- `docs/SYNC_WORKER_WINDOWS_2026-07-05.md`
- `docs/SYNC_SMOKE_TEST_LOCAL_NUBE_2026-07-05.md`
- `docs/SYNC_CONTROL_WPF_2026-07-05.md`
- `docs/SYNC_OUTBOX_INVENTARIO_PRECIOS_2026-07-06.md`
- `docs/SYNC_CONFIGURACION_NUBE_OPERATIVA_2026-07-06.md`
- `docs/SYNC_PRODUCTOS_WEB_GARANTIAS_ACK_2026-07-07.md`

APIs:

- `POST /api/sync/nodes`
- `POST /api/sync/events/push`
- `GET /api/sync/events/pull`
- `POST /api/sync/events/{event_uuid}/ack`
- `GET /api/sync/status`
- `POST /api/sync/tokens`
- `GET /api/sync/local-readiness`
- `POST /api/sync/local-readiness`
- `php artisan sync:run`
- `php artisan sync:daemon`
- `php artisan sync:issue-token`
- `.\scripts\sync-worker.ps1 start|stop|status`
- `.\scripts\sync-worker.cmd start|stop|status`
- `.\scripts\sync-smoke-test.ps1 -CloudDbPassword "CLAVE_POSTGRES_DEL_VPS"`
- `.\scripts\configure-sync-cloud-local.ps1 -CloudUrl "URL_API_NUBE" -Token "TOKEN"`

Regla importante:

- las rutas requieren `api.auth` y `tenant`;
- productos, listas de precio, precios por producto, entradas y salidas ya registran eventos en `sync_outbox`;
- productos, listas de precio y precios por producto ya pueden aplicarse desde `sync_inbox` usando claves de negocio como `sku` y `price_list_code`;
- las entradas y salidas se suben como eventos operativos, pero la aplicacion automatica de stock entre nodos queda para una fase posterior con reglas anti-duplicacion;
- `event_uuid` es obligatorio para evitar doble procesamiento;
- `sync_inbox` recibe eventos externos y protege contra duplicados;
- `sync_outbox` entrega eventos pendientes hasta que el receptor confirme con `ack`;
- un nodo no recibe eventos originados por si mismo;
- el worker puede aplicar eventos soportados de productos, precios, tasas y metodos de pago;
- la app WPF muestra un semaforo de sincronizacion por empresa e instalacion local en el centro de modulos;
- la app WPF permite iniciar, detener y revisar estado/log del worker desde el modulo tecnico `Sincronizacion`;
- `sync_tenant_readiness` registra si una empresa esta pendiente, sincronizando, lista, con advertencia o error en una instalacion local;
- ningun evento se comparte entre empresas.

Regla importante:

- cada método pertenece a una empresa;
- `code` es único por empresa y se normaliza a mayúsculas;
- `method` define el tipo operativo usado por POS: `cash`, `card`, `mobile_payment`, `transfer`, `zelle`, `external_financing` u `other`;
- `currency_mode = USD` permite solo cobros en dólares;
- `currency_mode = VES` permite solo cobros en bolívares;
- `currency_mode = flexible` permite cobros en dólares o bolívares;
- si `requires_reference` está activo, el POS debe enviar referencia;
- eliminar un método por API lo desactiva, no lo borra físicamente;
- si una lista de precio tiene métodos asociados, POS solo puede cobrar con esos métodos;
- si una lista de precio no tiene métodos asociados, se considera abierta y mantiene compatibilidad con el checkout existente.

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
- la eliminacion API desactiva al proveedor con `is_active = false`;
- el portal administrativo permite buscar por nombre, documento, telefono o correo;
- el portal administrativo permite crear, editar, desactivar y reactivar proveedores sin borrar historicos.

### Purchases

Responsabilidad:

- compras en borrador;
- recepcion parcial o total de compras;
- asociacion opcional con proveedores;
- factura/documento de proveedor con fecha de emision y vencimiento;
- costos en `USD` o `VES` con snapshot de tasa;
- generacion de movimientos `purchase` al recibir;
- creacion de unidades serializadas, como IMEIs, al recibir productos serializados.
- vista web compacta en el portal administrativo para listar compras, crear borradores, recibir mercancia y anular compras no recibidas.

Archivos principales:

- `app/Modules/Purchases/Models/PurchaseOrder.php`
- `app/Modules/Purchases/Models/PurchaseItem.php`
- `app/Modules/Purchases/Policies/PurchaseOrderPolicy.php`
- `app/Modules/Purchases/Controllers/PurchaseOrderController.php`
- `app/Modules/Purchases/Requests/StorePurchaseOrderRequest.php`
- `app/Modules/Purchases/Requests/ReceivePurchaseOrderRequest.php`
- `app/Modules/Purchases/Resources/PurchaseOrderResource.php`
- `app/Modules/Purchases/Resources/PurchaseItemResource.php`
- `app/Modules/Purchases/Services/PurchaseOrderService.php`
- `app/Modules/Purchases/routes.php`

Regla importante:

- crear una compra no mueve inventario;
- recibir una compra genera movimientos `purchase` usando `InventoryMovementService`;
- una compra parcialmente recibida queda en `partially_received`;
- una compra totalmente recibida queda en `received`;
- recibir una compra crea o actualiza una cuenta por pagar mediante `AccountsPayable` solo por el monto recibido;
- compras recibidas no se cancelan directamente en esta fase;
- productos serializados requieren un serial o IMEI por unidad comprada;
- los seriales recibidos se crean en `product_units` como disponibles y enlazados al movimiento de compra.
- la primera pantalla web de compras no recibe seriales/IMEI; esos productos deben entrar por el flujo especializado hasta cerrar la fase web de seriales.

### PurchaseReturns

Responsabilidad:

- devoluciones de compras recibidas;
- documento historico de devolucion a proveedor;
- validacion de cantidad devuelta contra cantidad comprada;
- generacion de movimientos `purchase_return`;
- retiro de unidades serializadas devueltas al proveedor.

Archivos principales:

- `app/Modules/PurchaseReturns/Models/PurchaseReturn.php`
- `app/Modules/PurchaseReturns/Models/PurchaseReturnItem.php`
- `app/Modules/PurchaseReturns/Policies/PurchaseReturnPolicy.php`
- `app/Modules/PurchaseReturns/Controllers/PurchaseReturnController.php`
- `app/Modules/PurchaseReturns/Requests/StorePurchaseReturnRequest.php`
- `app/Modules/PurchaseReturns/Resources/PurchaseReturnResource.php`
- `app/Modules/PurchaseReturns/Resources/PurchaseReturnItemResource.php`
- `app/Modules/PurchaseReturns/Services/PurchaseReturnService.php`
- `app/Modules/PurchaseReturns/routes.php`

Regla importante:

- una devolucion a proveedor no borra ni cancela la compra original;
- solo se devuelven compras recibidas;
- no se puede devolver mas de lo comprado;
- productos serializados requieren indicar unidades especificas;
- las unidades serializadas devueltas quedan como `removed`;
- si existe cuenta por pagar de la compra, la devolucion reduce el saldo pendiente;
- toda devolucion debe respetar tenant y permisos.

### AccountsPayable

Responsabilidad:

- cuentas por pagar por proveedor y compra recibida;
- creacion automatica de deuda al recibir compras;
- registro de pagos parciales o totales;
- saldo principal en `USD` base con snapshot local en `VES` cuando aplica;
- rebaja automatica de saldo por devoluciones a proveedor;
- control de estados `pending`, `partial`, `paid` y `overdue`.
- portal web administrativo para filtrar saldos, revisar pagos y registrar abonos a proveedor.

Archivos principales:

- `app/Modules/AccountsPayable/Models/AccountsPayable.php`
- `app/Modules/AccountsPayable/Models/AccountsPayablePayment.php`
- `app/Modules/AccountsPayable/Policies/AccountsPayablePolicy.php`
- `app/Modules/AccountsPayable/Controllers/AccountsPayableController.php`
- `app/Modules/AccountsPayable/Requests/RegisterAccountsPayablePaymentRequest.php`
- `app/Modules/AccountsPayable/Resources/AccountsPayableResource.php`
- `app/Modules/AccountsPayable/Resources/AccountsPayablePaymentResource.php`
- `app/Modules/AccountsPayable/Services/AccountsPayableService.php`
- `app/Modules/AccountsPayable/routes.php`

Regla importante:

- no se crea deuda manual desde API en esta fase;
- una compra recibida genera una cuenta por pagar idempotente;
- una devolucion a proveedor reduce `returned_base_amount` y el saldo;
- los pagos no pueden superar el saldo pendiente;
- los pagos en bolivares guardan tasa exacta usada;
- toda cuenta y pago debe respetar tenant y permisos;
- la vista web no crea deuda manual: solo opera cuentas nacidas desde compras recibidas.

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
- confirmar una venta genera una cuenta por cobrar mediante `AccountsReceivable`;
- cada item guarda precio y tasa exacta usada;
- los items de productos serializados guardan `product_unit_ids` para saber que IMEI o serial salio;
- al confirmar una venta serializada, el IMEI debe estar disponible en el almacen y queda `sold`;
- ventas confirmadas no se cancelan directamente en esta fase.

### AccountsReceivable

Responsabilidad:

- cuentas por cobrar por cliente y venta confirmada;
- creacion automatica de deuda al confirmar ventas;
- registro de cobros parciales o totales;
- saldo principal en `USD` base con snapshot local en `VES` cuando aplica;
- rebaja automatica de saldo por devoluciones de venta;
- control de estados `pending`, `partial`, `paid` y `overdue`.

Archivos principales:

- `app/Modules/AccountsReceivable/Models/AccountsReceivable.php`
- `app/Modules/AccountsReceivable/Models/AccountsReceivablePayment.php`
- `app/Modules/AccountsReceivable/Policies/AccountsReceivablePolicy.php`
- `app/Modules/AccountsReceivable/Controllers/AccountsReceivableController.php`
- `app/Modules/AccountsReceivable/Requests/RegisterAccountsReceivablePaymentRequest.php`
- `app/Modules/AccountsReceivable/Resources/AccountsReceivableResource.php`
- `app/Modules/AccountsReceivable/Resources/AccountsReceivablePaymentResource.php`
- `app/Modules/AccountsReceivable/Services/AccountsReceivableService.php`
- `app/Modules/AccountsReceivable/routes.php`

Regla importante:

- no se crea deuda manual desde API en esta fase;
- una venta confirmada genera una cuenta por cobrar idempotente;
- una devolucion de venta reduce `returned_base_amount` y el saldo;
- los cobros no pueden superar el saldo pendiente;
- los cobros en bolivares guardan tasa exacta usada;
- toda cuenta y cobro debe respetar tenant y permisos.

### FinanceReports

Responsabilidad:

- reportes financieros basicos de gerencia;
- resumen de cuentas por cobrar y cuentas por pagar;
- totales de cobros de clientes y pagos a proveedores por periodo;
- balance neto en `USD` base;
- listados filtrables de cuentas por cobrar y cuentas por pagar;
- lectura financiera sin crear ni modificar datos.

Archivos principales:

- `app/Modules/FinanceReports/Controllers/FinanceReportController.php`
- `app/Modules/FinanceReports/Requests/FinanceReportRequest.php`
- `app/Modules/FinanceReports/Services/FinanceReportService.php`
- `app/Modules/FinanceReports/routes.php`

Regla importante:

- requiere permiso `finance_reports.view`;
- usa `USD` como moneda base de resumen;
- los filtros `customer_id` y `supplier_id` se validan contra el tenant actual;
- no mezcla datos entre empresas;
- no reemplaza contabilidad formal, es una primera vista financiera operativa.

### FinancialAdjustments

Responsabilidad:

- notas o ajustes financieros sobre cuentas por cobrar y por pagar;
- descuentos posteriores a documentos;
- diferencias por redondeo;
- ajustes autorizados de saldo;
- reduccion de saldo sin movimiento fisico de inventario.

Archivos principales:

- `app/Modules/FinancialAdjustments/Models/FinancialAdjustment.php`
- `app/Modules/FinancialAdjustments/Policies/FinancialAdjustmentPolicy.php`
- `app/Modules/FinancialAdjustments/Controllers/FinancialAdjustmentController.php`
- `app/Modules/FinancialAdjustments/Requests/StoreFinancialAdjustmentRequest.php`
- `app/Modules/FinancialAdjustments/Resources/FinancialAdjustmentResource.php`
- `app/Modules/FinancialAdjustments/Services/FinancialAdjustmentService.php`
- `app/Modules/FinancialAdjustments/routes.php`

Regla importante:

- si hay devolucion fisica de mercancia, se usa `SalesReturns` o `PurchaseReturns`;
- si solo hay ajuste de dinero o saldo, se usa `FinancialAdjustments`;
- el ajuste no crea comprobante de pago porque no hay cobro ni pago real;
- el ajuste no puede superar el saldo pendiente;
- todo ajuste debe respetar tenant y permisos.

### PaymentReceipts

Responsabilidad:

- comprobantes historicos de cobros de clientes y pagos a proveedores;
- correlativo por empresa;
- snapshot de tercero, moneda, monto, tasa, metodo y referencia;
- consulta de comprobantes emitidos;
- anulacion documental sin revertir la transaccion financiera original.

Archivos principales:

- `app/Modules/PaymentReceipts/Models/PaymentReceipt.php`
- `app/Modules/PaymentReceipts/Policies/PaymentReceiptPolicy.php`
- `app/Modules/PaymentReceipts/Controllers/PaymentReceiptController.php`
- `app/Modules/PaymentReceipts/Requests/VoidPaymentReceiptRequest.php`
- `app/Modules/PaymentReceipts/Resources/PaymentReceiptResource.php`
- `app/Modules/PaymentReceipts/Services/PaymentReceiptService.php`
- `app/Modules/PaymentReceipts/routes.php`

Regla importante:

- los comprobantes se emiten automaticamente desde `AccountsReceivable` y `AccountsPayable`;
- los pagos POS capturados quedan cubiertos porque generan cobros en `AccountsReceivable`;
- el comprobante no reemplaza el pago ni la cuenta, solo documenta la operacion;
- anular un comprobante no revierte caja, inventario, cuenta ni pago;
- todo comprobante debe respetar tenant y permisos.

### SalesReturns

Responsabilidad:

- devoluciones de ventas confirmadas;
- documento historico de devolucion;
- validacion de cantidad devuelta contra cantidad vendida;
- generacion de movimientos `sale_return`;
- restauracion de unidades serializadas como disponibles o danadas.

Archivos principales:

- `app/Modules/SalesReturns/Models/SalesReturn.php`
- `app/Modules/SalesReturns/Models/SalesReturnItem.php`
- `app/Modules/SalesReturns/Policies/SalesReturnPolicy.php`
- `app/Modules/SalesReturns/Controllers/SalesReturnController.php`
- `app/Modules/SalesReturns/Requests/StoreSalesReturnRequest.php`
- `app/Modules/SalesReturns/Resources/SalesReturnResource.php`
- `app/Modules/SalesReturns/Resources/SalesReturnItemResource.php`
- `app/Modules/SalesReturns/Services/SalesReturnService.php`
- `app/Modules/SalesReturns/routes.php`

Regla importante:

- una devolucion no borra ni cancela la venta original;
- solo se devuelven ventas confirmadas;
- no se puede devolver mas de lo vendido;
- productos serializados requieren indicar unidades especificas;
- las unidades serializadas devueltas deben pertenecer a los `product_unit_ids` vendidos en ese item;
- si existe cuenta por cobrar de la venta, la devolucion reduce el saldo pendiente;
- toda devolucion debe respetar tenant y permisos.

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
- `desktop/InventoryDesktop/Modules/POS/PosView.xaml`
- `desktop/InventoryDesktop/Modules/POS/PosViewModel.cs`

Regla importante:

- POS no debe descontar inventario directamente;
- POS debe usar `Sales` para crear y confirmar la venta;
- POS envia `product_unit_ids` a `Sales` cuando vende productos serializados;
- POS puede asociar un `customer_id`, pero solo si pertenece al tenant actual;
- POS debe estar asociado a una sesion de caja abierta;
- POS solo puede usar la caja del cajero autenticado;
- solo pagos `captured` cuentan para cerrar una orden POS;
- cada pago `captured` crea un movimiento `pos_payment` en `CashRegister`;
- cada pago `captured` de una venta confirmada crea un cobro automatico en `AccountsReceivable`;
- pagos `pending`, como financiadoras externas, dejan la orden abierta y la venta en borrador;
- pagos `pending` no generan cobros automaticos ni cuenta por cobrar porque la venta sigue en borrador;
- cada pago en `VES` debe guardar la tasa exacta usada.
- La primera fase WPF arma carrito local y cotiza productos, pero no crea ventas ni pagos hasta integrar checkout y caja.

### CashRegister

Responsabilidad:

- apertura de caja;
- administracion de cajas fisicas por sucursal;
- movimientos manuales de entrada y salida;
- cierre de caja;
- arqueo, monto esperado, monto contado y diferencias;
- base para asociar pagos POS a sesiones de caja abiertas.

Archivos principales:

- `app/Modules/CashRegister/Models/CashRegister.php`
- `app/Modules/CashRegister/Models/CashRegisterSession.php`
- `app/Modules/CashRegister/Models/CashRegisterMovement.php`
- `app/Modules/CashRegister/Policies/CashRegisterPolicy.php`
- `app/Modules/CashRegister/Policies/CashRegisterSessionPolicy.php`
- `app/Modules/CashRegister/Controllers/CashRegisterController.php`
- `app/Modules/CashRegister/Controllers/CashRegisterSessionController.php`
- `app/Modules/CashRegister/Requests/StoreCashRegisterRequest.php`
- `app/Modules/CashRegister/Requests/UpdateCashRegisterRequest.php`
- `app/Modules/CashRegister/Requests/OpenCashRegisterSessionRequest.php`
- `app/Modules/CashRegister/Requests/StoreCashRegisterMovementRequest.php`
- `app/Modules/CashRegister/Requests/CloseCashRegisterSessionRequest.php`
- `app/Modules/CashRegister/Resources/CashRegisterResource.php`
- `app/Modules/CashRegister/Resources/CashRegisterSessionResource.php`
- `app/Modules/CashRegister/Resources/CashRegisterMovementResource.php`
- `app/Modules/CashRegister/Services/CashRegisterService.php`
- `app/Modules/CashRegister/routes.php`

Regla importante:

- Caja no crea ventas;
- POS no debe absorber el cierre de caja;
- una caja fisica pertenece a una sucursal;
- una caja fisica no puede tener dos sesiones abiertas al mismo tiempo;
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

### ProductEntries

Responsabilidad:

- entradas operativas o manuales de productos;
- carga inicial o reposicion de inventario sin flujo formal de compra;
- entrada multi-producto en un mismo documento;
- carga masiva de IMEIs o seriales para productos serializados;
- generacion de movimientos `purchase` mediante `InventoryMovementService`.

Archivos principales:

- `app/Modules/ProductEntries/Models/ProductEntry.php`
- `app/Modules/ProductEntries/Models/ProductEntryItem.php`
- `app/Modules/ProductEntries/Policies/ProductEntryPolicy.php`
- `app/Modules/ProductEntries/Controllers/ProductEntryController.php`
- `app/Modules/ProductEntries/Requests/StoreProductEntryRequest.php`
- `app/Modules/ProductEntries/Resources/ProductEntryResource.php`
- `app/Modules/ProductEntries/Resources/ProductEntryItemResource.php`
- `app/Modules/ProductEntries/Services/ProductEntryService.php`
- `app/Modules/ProductEntries/routes.php`

Regla importante:

- si el producto es serializado, la cantidad debe ser entera y debe existir un IMEI o serial por cada unidad;
- si el producto es por cantidad, no acepta seriales;
- los seriales no se pueden repetir dentro de la entrada ni existir previamente en la empresa;
- la entrada no crea cuenta por pagar ni proveedor;
- para compra formal con proveedor se usa `Purchases`.

### ProductExits

Responsabilidad:

- salidas operativas o manuales de productos;
- merma, perdida, consumo interno, garantia, salida administrativa u otros retiros autorizados;
- salida multi-producto en un mismo documento;
- seleccion de IMEIs o seriales especificos para productos serializados;
- generacion de movimientos `adjustment_out` o `damaged` mediante `InventoryMovementService`.

Archivos principales:

- `app/Modules/ProductExits/Models/ProductExit.php`
- `app/Modules/ProductExits/Models/ProductExitItem.php`
- `app/Modules/ProductExits/Policies/ProductExitPolicy.php`
- `app/Modules/ProductExits/Controllers/ProductExitController.php`
- `app/Modules/ProductExits/Requests/StoreProductExitRequest.php`
- `app/Modules/ProductExits/Resources/ProductExitResource.php`
- `app/Modules/ProductExits/Resources/ProductExitItemResource.php`
- `app/Modules/ProductExits/Services/ProductExitService.php`
- `app/Modules/ProductExits/routes.php`

Regla importante:

- si la salida es venta, se usa `Sales` o `POS`;
- si la salida es devolucion a proveedor, se usa `PurchaseReturns`;
- si el producto es serializado, se deben indicar unidades disponibles especificas;
- un IMEI vendido, removido, danado o de otro almacen no puede salir por este modulo;
- el motivo `damaged` mueve cantidad a danado; los demas motivos retiran disponible.

### InventoryTransfers

Responsabilidad:

- transferencias internas de inventario entre almacenes de una misma empresa;
- transferencia multi-producto en un mismo documento;
- seleccion de IMEIs o seriales especificos cuando el producto es serializado;
- generacion de movimientos `transfer_out` y `transfer_in` mediante `InventoryMovementService`;
- trazabilidad del documento origen para Kardex y auditoria.

Archivos principales:

- `app/Modules/InventoryTransfers/Models/InventoryTransfer.php`
- `app/Modules/InventoryTransfers/Models/InventoryTransferItem.php`
- `app/Modules/InventoryTransfers/Policies/InventoryTransferPolicy.php`
- `app/Modules/InventoryTransfers/Controllers/InventoryTransferController.php`
- `app/Modules/InventoryTransfers/Requests/StoreInventoryTransferRequest.php`
- `app/Modules/InventoryTransfers/Resources/InventoryTransferResource.php`
- `app/Modules/InventoryTransfers/Resources/InventoryTransferItemResource.php`
- `app/Modules/InventoryTransfers/Services/InventoryTransferService.php`
- `app/Modules/InventoryTransfers/routes.php`

Regla importante:

- en esta fase solo existe `internal`;
- origen y destino deben pertenecer al mismo tenant;
- un traslado interno no vende ni retira mercancia, solo cambia su almacen;
- los IMEIs trasladados deben estar disponibles en el almacen origen;
- las transferencias entre empresas se implementaran como solicitud interempresa con aceptacion/rechazo.

### InventoryTransferRequests

Responsabilidad:

- solicitudes de transferencia entre empresas independientes;
- busqueda de empresa destino por slug o correo de usuario activo;
- aceptacion, rechazo o cancelacion de solicitudes pendientes;
- salida de inventario en la empresa origen solo al aceptar;
- entrada de inventario en la empresa destino solo al aceptar;
- snapshot de IMEIs para crear unidades disponibles en la empresa destino.

Archivos principales:

- `app/Modules/InventoryTransferRequests/Models/InventoryTransferRequest.php`
- `app/Modules/InventoryTransferRequests/Models/InventoryTransferRequestItem.php`
- `app/Modules/InventoryTransferRequests/Policies/InventoryTransferRequestPolicy.php`
- `app/Modules/InventoryTransferRequests/Controllers/InventoryTransferRequestController.php`
- `app/Modules/InventoryTransferRequests/Requests/StoreInventoryTransferRequestRequest.php`
- `app/Modules/InventoryTransferRequests/Requests/AcceptInventoryTransferRequestRequest.php`
- `app/Modules/InventoryTransferRequests/Requests/RejectInventoryTransferRequestRequest.php`
- `app/Modules/InventoryTransferRequests/Resources/InventoryTransferRequestResource.php`
- `app/Modules/InventoryTransferRequests/Resources/InventoryTransferRequestItemResource.php`
- `app/Modules/InventoryTransferRequests/Services/InventoryTransferRequestService.php`
- `app/Modules/InventoryTransferRequests/routes.php`

Regla importante:

- crear solicitud no mueve stock;
- solo la empresa destino puede aceptar o rechazar;
- solo la empresa origen puede cancelar;
- la aceptacion exige producto destino compatible;
- si el stock origen ya no esta disponible al aceptar, la solicitud no se completa;
- una tercera empresa no puede ver ni responder solicitudes ajenas.

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

### Kardex

Responsabilidad:

- historial cronologico de inventario por producto;
- calculo de saldo inicial y saldo final por periodo;
- calculo de saldo corrido por movimiento;
- filtros por almacen y fechas;
- lectura de `stock_movements` sin duplicar datos.

Archivos principales:

- `app/Modules/Kardex/Controllers/KardexController.php`
- `app/Modules/Kardex/Requests/KardexProductRequest.php`
- `app/Modules/Kardex/Services/KardexService.php`
- `app/Modules/Kardex/routes.php`

Regla importante:

- Kardex no escribe inventario;
- Kardex no reemplaza `InventoryMovementService`;
- Kardex usa `stock_movements` como fuente historica;
- todo filtro debe respetar tenant;
- el permiso requerido es `kardex.view`.

### AccessControl

Responsabilidad:

- usuarios vinculados a una empresa;
- estado del usuario dentro de cada empresa;
- roles por empresa;
- permisos agrupados por modulo;
- asignacion de roles sin mezclar tenants.

Archivos principales:

- `app/Modules/AccessControl/Controllers/TenantUserController.php`
- `app/Modules/AccessControl/Controllers/RoleController.php`
- `app/Modules/AccessControl/Controllers/PermissionCatalogController.php`
- `app/Modules/AccessControl/Requests/StoreTenantUserRequest.php`
- `app/Modules/AccessControl/Requests/UpdateTenantUserRequest.php`
- `app/Modules/AccessControl/Requests/UpdateTenantUserStatusRequest.php`
- `app/Modules/AccessControl/Requests/UpdateTenantUserRolesRequest.php`
- `app/Modules/AccessControl/Requests/StoreRoleRequest.php`
- `app/Modules/AccessControl/Requests/UpdateRoleRequest.php`
- `app/Modules/AccessControl/Requests/UpdateRolePermissionsRequest.php`
- `app/Modules/AccessControl/Resources/TenantUserResource.php`
- `app/Modules/AccessControl/Resources/RoleResource.php`
- `app/Modules/AccessControl/Services/AccessControlService.php`
- `app/Modules/AccessControl/routes.php`

Regla importante:

- un mismo usuario puede pertenecer a varias empresas;
- el estado `active` o `inactive` vive en `tenant_user` y aplica solo a esa empresa;
- los roles usan Spatie Permission con `tenant_id`;
- los permisos disponibles salen de `App\Support\Permissions\BasePermissions`;
- no se pueden eliminar roles base del sistema;
- no se puede inactivar ni degradar el ultimo `Owner` o `Administrador` activo de una empresa;
- los cambios sensibles se auditan con `AuditLogger` en `audit_logs`;
- toda pantalla futura de usuarios, roles y permisos debe consumir este modulo.

### Warranties

Responsabilidad:

- politicas de garantia por empresa;
- asignacion de politicas a productos;
- snapshot de garantia en items de venta;
- base para futuros casos de garantia, reemplazos, revisiones y reembolsos.

Archivos principales:

- `app/Modules/Warranties/Models/WarrantyPolicy.php`
- `app/Modules/Warranties/Models/WarrantyClaim.php`
- `app/Modules/Warranties/Controllers/WarrantyPolicyController.php`
- `app/Modules/Warranties/Controllers/WarrantyClaimController.php`
- `app/Modules/Warranties/Requests/StoreWarrantyPolicyRequest.php`
- `app/Modules/Warranties/Requests/UpdateWarrantyPolicyRequest.php`
- `app/Modules/Warranties/Requests/StoreWarrantyClaimRequest.php`
- `app/Modules/Warranties/Requests/ReviewWarrantyClaimRequest.php`
- `app/Modules/Warranties/Requests/ResolveWarrantyClaimRequest.php`
- `app/Modules/Warranties/Requests/DeliverWarrantyClaimRequest.php`
- `app/Modules/Warranties/Resources/WarrantyPolicyResource.php`
- `app/Modules/Warranties/Resources/WarrantyClaimResource.php`
- `app/Modules/Warranties/Services/WarrantyClaimService.php`
- `app/Modules/Warranties/routes.php`

Regla importante:

- una politica de garantia pertenece a un tenant;
- un producto puede tener `warranty_policy_id`;
- una venta copia la garantia del producto en `sale_items`;
- el snapshot de venta no debe cambiar si luego se actualiza la politica;
- la confirmacion de venta define inicio y vencimiento de garantia;
- los casos de garantia parten del item vendido y opcionalmente del IMEI/serial vendido;
- para productos serializados, la unidad en garantia debe estar registrada en `sale_items.product_unit_ids`;
- un caso recibido no resuelve dinero ni inventario contable por si solo;
- los productos serializados recibidos por garantia quedan en `warranty_hold`;
- resolver con `replacement` entrega un IMEI disponible, lo marca `sold` y genera movimiento `adjustment_out`;
- resolver con `rejected` cierra el caso y devuelve la unidad original a `sold` si estaba retenida;
- resolver con `refund` puede crear salida de caja o ajuste contra saldo pendiente;
- un reembolso por garantia no puede aplicar caja y rebaja de saldo en la misma accion;
- las acciones de recibir, revisar y entregar se auditan en `audit_logs`.

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
- Las rutas protegidas deben usar `api.auth` y `tenant`.
- Las migraciones siguen en `database/migrations` por convencion Laravel, pero deben nombrarse claramente.
- Los tests deben estar agrupados por area en `tests/Feature/<Area>`.
- Toda documentacion debe escribirse en espanol.
- Cada cambio importante debe quedar registrado en `docs/IMPLEMENTATION_LOG.md`.
- Los datos demo persistentes se documentan en `docs/DEMO_DATA.md`.
