# Registro de implementación

## 2026-07-02 - Caja base

### Implementado

- Se agrego el modulo `CashRegister`.
- Se agrego la tabla `cash_register_sessions`.
- Se agrego la tabla `cash_register_movements`.
- Se agregaron modelos `CashRegisterSession` y `CashRegisterMovement`.
- Se agrego `CashRegisterSessionPolicy`.
- Se agrego `CashRegisterService`.
- Se agrego `CashRegisterSessionController`.
- Se agregaron endpoints para listar sesiones, abrir caja, ver una sesion, registrar movimientos y cerrar caja.
- La caja maneja montos en `USD` o `VES` con snapshot de tasa cuando aplica.
- El cierre guarda monto esperado, monto contado y diferencia.
- Se evita que un cajero tenga dos cajas abiertas al mismo tiempo.

### Pruebas

- Se ejecutaron pruebas especificas de caja en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/CashRegister/CashRegisterApiTest.php`: 6 pruebas pasadas, 31 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 77 pruebas pasadas, 325 aserciones.

### Notas de seguridad

- Caja es tenant-scoped.
- Las sesiones solo aceptan sucursales de la empresa actual.
- Los movimientos no pueden agregarse a una caja cerrada.
- POS seguira siendo el modulo de venta; caja sera el modulo de apertura, movimientos, arqueo y cierre.

## 2026-07-02 - POS base

### Implementado

- Se agrego el modulo `POS`.
- Se agrego la tabla `pos_orders`.
- Se agrego la tabla `pos_payments`.
- Se agregaron modelos `PosOrder` y `PosPayment`.
- Se agrego `PosOrderPolicy`.
- Se agrego `PosCheckoutService`.
- Se agrego `PosOrderController`.
- Se agregaron endpoints para listar ordenes POS, crear checkouts y ver una orden POS.
- El POS crea una venta usando `Sales`, registra pagos y confirma la venta solo si los pagos capturados cubren el total.
- Los pagos pueden estar en `USD` o `VES`.
- Los pagos en `VES` guardan tipo de tasa, codigo y valor exacto usado.
- Los pagos con estado `pending`, como financiadoras externas futuras, no cierran la venta ni descuentan inventario.

### Pruebas

- Se ejecutaron pruebas especificas de POS en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php`: 5 pruebas pasadas, 28 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 71 pruebas pasadas, 294 aserciones.

### Notas de seguridad

- POS es tenant-scoped.
- POS no mueve inventario directamente; delega la confirmacion a `Sales`.
- Los items solo aceptan productos y almacenes de la empresa actual.
- Las ordenes POS solo son visibles dentro de la empresa actual.
- Los pagos quedan modelados desde el inicio para metodos futuros como pago movil, tarjeta, transferencia, Zelle y financiadoras externas.

## 2026-07-02 - Ventas base

### Implementado

- Se agrego la tabla `sales`.
- Se agrego la tabla `sale_items`.
- Se agregaron modelos `Sale` y `SaleItem`.
- Se agrego `SalePolicy`.
- Se agrego `SaleService`.
- Se agrego `SaleController`.
- Se agregaron endpoints para listar, crear, ver, confirmar y cancelar ventas.
- Crear una venta genera un borrador y copia precio/tasa historica.
- Confirmar una venta descuenta inventario y enlaza movimientos.
- Cancelar solo aplica a ventas en borrador en esta fase.

### Pruebas

- Se ejecutaron pruebas especificas de ventas en PostgreSQL con `docker compose run --rm app_test php artisan test tests/Feature/Sales/SalesApiTest.php`: 6 pruebas pasadas, 27 aserciones.
- Se ejecuto la suite completa en PostgreSQL con `docker compose run --rm app_test php artisan test`: 66 pruebas pasadas, 266 aserciones.

### Notas de seguridad

- Las ventas son tenant-scoped.
- Los items solo aceptan productos y almacenes de la empresa actual.
- La venta confirmada guarda historia de precio y tasa.
- POS futuro debe usar ventas, no mover inventario directamente.

## 2026-07-02 - Precios de productos con tasas

### Implementado

- Se agrego `base_price` a productos como precio base interno en `USD`.
- Se agrego `sale_currency` para indicar si el producto se cotiza en `USD` o `VES`.
- Se agrego `sale_exchange_rate_type_id` para asignar tipos de tasa como `BCV` o `PARALELO`.
- Se agrego `ProductPriceService`.
- Se agrego `ProductPriceResource`.
- Se agrego `GET /api/products/{product}/price`.
- Se valido que el tipo de tasa asignado al producto pertenezca al tenant actual.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 11 pruebas pasaron, 47 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 60 pruebas pasaron, 239 assertions.

### Notas de seguridad

- La cotizacion de precio no mueve inventario ni crea ventas.
- Si un producto vende en `VES`, debe existir una tasa activa.
- Las ventas futuras deben copiar precio, moneda, tipo de tasa y valor exacto usado.

## 2026-07-02 - Cierre de APIs de tasas

### Implementado

- Se confirmo que `POST /api/currency/rates` es la API para crear una nueva tasa.
- Se agrego `PATCH /api/currency/rates/{rate}/deactivate`.
- Se documento la diferencia entre crear tasa, activar tasa y desactivar tasa.
- La desactivacion de una tasa individual conserva el historial.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Currency/CurrencyApiTest.php`.
- Resultado: 6 pruebas pasaron, 37 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 55 pruebas pasaron, 215 assertions.

### Notas de seguridad

- Desactivar una tasa requiere `currency.manage`.
- La tasa no se elimina fisicamente porque las ventas futuras deben conservar historia monetaria.

## 2026-07-02 - Modulo Currency con tasas BCV y paralelo

### Implementado

- Se agrego la tabla `exchange_rate_types`.
- Se agrego la tabla `exchange_rates`.
- Se agregaron modelos `ExchangeRateType` y `ExchangeRate`.
- Se agregaron policies para tipos de tasa y tasas.
- Se agregaron permisos `currency.view` y `currency.manage`.
- Se agrego `ExchangeRateActivationService`.
- Se agregaron APIs para tipos de tasa.
- Se agregaron APIs para historial, tasas actuales y activacion de tasas.
- Se documento que una empresa puede tener `BCV` y `PARALELO` activos al mismo tiempo.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Currency/CurrencyApiTest.php`.
- Resultado: 5 pruebas pasaron, 32 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 54 pruebas pasaron, 210 assertions.

### Notas de seguridad

- Los tipos de tasa y tasas son tenant-scoped.
- Una tasa no puede apuntar a un tipo de tasa de otra empresa.
- Activar una tasa solo reemplaza tasas activas del mismo tipo y par de monedas.
- Las ventas futuras deben guardar el valor exacto de la tasa usada.

## 2026-07-02 - API de sucursales y almacenes

### Implementado

- Se agrego `BranchController`.
- Se agregaron requests y resource para sucursales.
- Se agrego `app/Modules/Branches/routes.php`.
- Se agrego `WarehouseController`.
- Se agregaron requests y resource para almacenes.
- Se agrego `app/Modules/Warehouses/routes.php`.
- Se agregaron `BranchPolicy` y `WarehousePolicy`.
- Se agregaron permisos `branches.*` y `warehouses.*`.
- Se expusieron endpoints para listar, crear, ver, actualizar y desactivar sucursales y almacenes.
- Se valido `code` unico por tenant en sucursales y almacenes.
- Se valido que `branch_id` de almacenes pertenezca al tenant actual.
- La eliminacion por API desactiva usando `status = inactive`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Locations/BranchWarehouseApiTest.php`.
- Resultado: 5 pruebas pasaron, 33 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 49 pruebas pasaron, 178 assertions.

### Notas de seguridad

- Todos los endpoints usan `auth` y `tenant`.
- Las APIs usan policies para validar permisos y pertenencia al tenant actual.
- Los listados no mezclan datos entre empresas.
- Un almacen no puede apuntar a una sucursal de otra empresa.

## 2026-07-02 - API de productos

### Implementado

- Se agrego `ProductController`.
- Se agregaron requests para crear y actualizar productos.
- Se agrego `ProductResource`.
- Se agrego `app/Modules/Products/routes.php`.
- Se expusieron endpoints para listar, crear, ver, actualizar y desactivar productos.
- Se valido `sku` unico por tenant.
- Se valido `tracking_type` con soporte para `quantity` y `serialized`.
- Se bloqueo el cambio de `tracking_type` cuando el producto ya tiene unidades serializadas.
- La eliminacion por API desactiva el producto con `is_active = false`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Products/ProductApiTest.php`.
- Resultado: 6 pruebas pasaron, 23 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 44 pruebas pasaron, 145 assertions.

### Notas de seguridad

- Todos los endpoints usan `auth` y `tenant`.
- La API usa `ProductPolicy` para validar permisos y pertenencia al tenant actual.
- El listado de productos no mezcla datos entre empresas.
- Los productos serializados quedan preparados para asociar IMEIs o seriales en `product_units`.
- No se permite perder trazabilidad cambiando a cantidad un producto que ya tiene IMEIs o seriales registrados.

## 2026-07-02 - Base para productos serializados e IMEI

### Implementado

- Se agrego `tracking_type` a productos para distinguir productos por cantidad y productos serializados.
- Se agrego la tabla `product_units` para IMEI, seriales u otros identificadores unicos por unidad fisica.
- Se agrego el modelo `ProductUnit`.
- Se agrego relacion `Product::units()`.
- Se agrego una clave unica compuesta `tenant_id + id` en `stock_movements` para permitir referencias seguras desde unidades serializadas.
- Se documento que `Samsung A06` es el producto y cada IMEI es una unidad asociada.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/SerializedProductUnitTest.php`.
- Resultado: 4 pruebas pasaron, 8 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 38 pruebas pasaron, 122 assertions.

### Notas de seguridad

- Los seriales son unicos por tenant y tipo de serial.
- Las unidades serializadas usan `tenant_id` y no pueden apuntar a productos o almacenes de otra empresa.
- Las unidades serializadas tampoco pueden apuntar a movimientos de stock de otra empresa.
- Esta base aplica a telefonos con IMEI y a otros productos con serial unico.

## 2026-07-02 - Organizacion modular y catalogo de APIs

### Implementado

- Se agrego `docs/MODULES.md` como mapa modular del proyecto.
- Se agrego `docs/API.md` como catalogo de APIs actuales, clasificado por seccion.
- Se movieron las rutas de inventario a `app/Modules/Inventory/routes.php`.
- Se movieron las rutas de reportes a `app/Modules/Reports/routes.php`.
- `routes/api.php` quedo como cargador de rutas modulares con middleware `auth` y `tenant`.
- Se actualizo `docs/ARCHITECTURE.md` para apuntar a la estructura modular actual.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryApiTest.php tests/Feature/Reports/InventoryReportApiTest.php`.
- Resultado: 8 pruebas pasaron, 33 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 34 pruebas pasaron, 114 assertions.

### Notas de seguridad

- Separar rutas por modulo ayuda a ubicar fallos o mejoras sin mezclar responsabilidades.
- Los middleware `auth` y `tenant` siguen aplicandose desde `routes/api.php`.
- Las APIs futuras, como POS, deben tener su propio archivo `app/Modules/POS/routes.php`.

## 2026-07-02 - Reportes iniciales de inventario

### Implementado

- Se agrego `InventoryReportController`.
- Se agregaron requests de reportes de stock y movimientos.
- Se agregaron resources para respuestas de stock y movimientos.
- Se agregaron endpoints de stock actual, bajo stock y movimientos.
- Se agregaron filtros por almacen, producto, tipo de movimiento y fechas.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Reports/InventoryReportApiTest.php`.
- Resultado: 4 pruebas pasaron, 18 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 34 pruebas pasaron, 114 assertions.

### Notas de seguridad

- Los reportes requieren `reports.view`.
- Se probaron varias empresas para confirmar que los reportes no mezclan stock ni movimientos.
- Los filtros de producto y almacen se validan contra el tenant actual.
- Los reportes consultan modelos tenant-scoped.

## 2026-07-02 - Auditoria de movimientos de inventario

### Implementado

- Se agrego la tabla `audit_logs`.
- Se agrego el modelo `AuditLog` con aislamiento por tenant.
- Se agrego `AuditLogger`.
- Se integro auditoria en `InventoryMovementService`.
- Cada movimiento de inventario crea un audit log con accion `inventory.movement.created`.
- Los movimientos creados por API registran usuario, IP y user agent cuando existen.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Audit/InventoryAuditTest.php`.
- Resultado: 2 pruebas pasaron, 20 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 30 pruebas pasaron, 96 assertions.

### Notas de seguridad

- Los audit logs tienen `tenant_id` y usan el mismo aislamiento que el resto de datos de negocio.
- Se probaron varias empresas para confirmar que productos, balances y logs no se mezclan.
- La auditoria se registra desde el servicio de inventario, no desde el controlador, para cubrir API y futuros jobs/IA.

## 2026-07-02 - Decision de moneda para Venezuela

### Implementado

- Se documento que el inventario usara `USD` como moneda base interna.
- Se documento que los productos podran venderse en `USD` o `VES`.
- Se dejo definido que las operaciones monetarias futuras deben guardar moneda original y tasa usada.

### Pruebas

- No aplica ejecucion de tests automatizados porque este cambio solo documenta una decision de arquitectura.

### Notas de seguridad

- No se deben recalcular costos historicos usando la tasa nueva del dia.
- Cada compra, venta o movimiento monetario debe conservar la tasa usada en el momento de la operacion.
- La tasa del dia se usara para equivalencias y reportes, no para modificar la historia.

## 2026-07-02 - API inicial de inventario

### Implementado

- Se agrego `routes/api.php`.
- Se registro el archivo API en `bootstrap/app.php`.
- Se agrego `InventoryMovementController`.
- Se agregaron requests para movimientos y transferencias de inventario.
- Se agrego `StockMovementResource`.
- Se expusieron endpoints para compras, ventas, ajustes, reservas, liberaciones, danados y transferencias.
- Todos los endpoints usan `auth`, `tenant` y `AuthorizedInventoryMovementService`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryApiTest.php`.
- Resultado: 4 pruebas pasaron, 15 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 28 pruebas pasaron, 76 assertions.

### Notas de seguridad

- Los endpoints no llaman directamente a `InventoryMovementService`.
- Los recursos enviados en la peticion se validan contra el tenant actual.
- Si un producto o almacen pertenece a otro tenant, la validacion responde `422`.
- Si el usuario no tiene permisos, la respuesta es `403`.

## 2026-07-02 - Autorizacion de operaciones de inventario

### Implementado

- Se agrego `InventoryPolicy` para validar permisos y pertenencia al tenant en operaciones de inventario.
- Se registraron Gates internos para operaciones de inventario.
- Se agrego `AuthorizedInventoryMovementService` para que controladores, jobs e IA autoricen antes de mover inventario.
- Se separaron los nombres de abilities internos de los nombres de permisos Spatie usando el sufijo `-operation`.

### Pruebas

- Se ejecuto `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryAuthorizationTest.php`.
- Resultado: 5 pruebas pasaron, 16 assertions.
- Se ejecuto la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 24 pruebas pasaron, 61 assertions.

### Notas de seguridad

- No se deben usar directamente abilities con el mismo nombre que permisos Spatie cuando tambien hay que validar modelos o tenant.
- `inventory.adjust-operation` revisa el permiso `inventory.adjust`, pero ademas valida almacen/producto del tenant actual.
- `inventory.transfer-operation` revisa el permiso `inventory.transfer`, pero ademas valida almacen origen, almacen destino y producto.
- La IA y los endpoints futuros deben usar `AuthorizedInventoryMovementService`, no llamar directamente a `InventoryMovementService`.

## 2026-07-02 - Fase 1: base del sistema

### Implementado

- Se creó la base del proyecto Laravel 13.
- Se agregó soporte Docker para la aplicación Laravel y PostgreSQL.
- Se agregó la estructura modular base bajo `app/Modules`.
- Se agregó el módulo `Tenancy` con modelo de tenant, middleware y provider.
- Se agregó `TenantManager` como servicio scoped para guardar el tenant actual durante la petición.
- Se agregaron `BelongsToTenant` y `TenantScope` para automatizar el filtrado por tenant y la asignación de `tenant_id`.
- Se agregaron las migraciones `tenants` y `tenant_user`.
- Se agregó una tabla inicial `products` tenant-scoped para validar el patrón antes de construir el inventario completo.
- Se instaló Spatie Laravel Permission.
- Se configuró Spatie con teams usando `tenant_id` como clave de tenant.
- Se agregaron permisos y roles base.
- Se agregaron pruebas de aislamiento multitenant.

### Pruebas

- Se ejecutó `php artisan test`.
- Resultado: 5 pruebas pasaron.

### Notas de seguridad

- Todo dato de negocio tenant-owned debe usar `BelongsToTenant`.
- Los registros tenant-owned deben fallar rápido si se crean sin tenant actual.
- La unicidad de negocio debe estar limitada por tenant, por ejemplo `tenant_id + sku`.
- La IA debe permanecer fuera del core de inventario y no puede saltarse permisos, validaciones, policies ni auditoría.

## 2026-07-02 - Policies tenant-aware para productos

### Implementado

- Se agregó `ProductPolicy` como primer patrón de policy tenant-aware.
- Se registró la policy de productos en `AppServiceProvider`.
- Se agregó `User::belongsToTenant()` para centralizar la validación de membresía activa por tenant.
- Se reforzó que el acceso a productos requiere permiso granular y pertenencia al tenant actual.

### Pruebas

- Se ejecutó `php artisan test tests/Feature/Permissions/ProductPolicyTest.php`.
- Resultado: 4 pruebas pasaron, 9 assertions.

### Notas de seguridad

- Un rol o permiso válido en un tenant nunca debe otorgar acceso en otro tenant.
- Las policies deben proteger incluso si un recurso fue cargado sin global scopes o ya existe en memoria.
- El backend sigue siendo la autoridad de permisos; las futuras acciones de IA deben pasar por las mismas policies.

## 2026-07-02 - Regla de documentación en español

### Implementado

- Se tradujo la documentación existente a español.
- Se dejó establecido que toda documentación futura debe escribirse en español.
- Se corrigió el árbol de carpetas modular para usar caracteres ASCII legibles.

### Pruebas

- No aplica ejecución de tests automatizados porque este cambio solo afecta documentación.

### Notas de seguridad

- Mantener la documentación en un solo idioma reduce errores de interpretación en decisiones de arquitectura, permisos y multitenancy.

## 2026-07-02 - Base de inventario por movimientos

### Implementado

- Se agregaron las migraciones `branches`, `warehouses`, `stock_movements` y `stock_balances`.
- Se agregaron los modelos `Branch`, `Warehouse`, `StockMovement` y `StockBalance`.
- Se aplicó `BelongsToTenant` a todos los modelos nuevos de negocio.
- Se agregó una clave única compuesta `tenant_id + id` en `products` para permitir referencias seguras desde inventario.
- Se agregaron claves foráneas compuestas para impedir referencias cruzadas entre tenants.
- Se mantuvo el principio de inventario basado en movimientos: `stock_movements` es la verdad histórica y `stock_balances` es una lectura rápida.

### Pruebas

- Se ejecutó `php artisan test tests/Feature/Inventory/InventorySchemaIsolationTest.php`.
- Resultado: 4 pruebas pasaron, 12 assertions.

### Notas de seguridad

- Un almacén no puede apuntar a una sucursal de otro tenant.
- Un movimiento o balance de stock no puede apuntar a productos o almacenes de otro tenant.
- Los códigos de sucursal y almacén son únicos por tenant, no globales.
- El stock no se guarda en productos; eso evita inconsistencias futuras cuando existan varios almacenes.

## 2026-07-02 - Pruebas con PostgreSQL

### Implementado

- Se cambió `phpunit.xml` para que PHPUnit use PostgreSQL en lugar de SQLite.
- Se agregó el servicio `postgres_test` en `docker-compose.yml`.
- Se agregó el servicio `app_test` para ejecutar PHPUnit contra `postgres_test`.
- Se configuró la base `inventory_arens_testing` para pruebas automatizadas.
- Se agregaron healthchecks a PostgreSQL para que los servicios esperen a que la base esté lista.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test`.
- Resultado: 13 pruebas pasaron, 27 assertions.

### Notas de seguridad

- SQLite no debe usarse como fuente principal de confianza para este proyecto.
- PostgreSQL es obligatorio para validar claves foráneas compuestas, decimales e integridad multitenant como se comportarán en producción.

## 2026-07-02 - Servicio de movimientos de inventario

### Implementado

- Se agregó `InventoryMovementService` para centralizar operaciones de inventario.
- Se implementaron entradas por compra, ventas, ajustes positivos, ajustes negativos, reservas, liberaciones, dañados y transferencias.
- Se agregaron excepciones específicas para cantidad inválida, stock insuficiente y referencias cruzadas entre tenants.
- Cada operación crea registros en `stock_movements`.
- Cada operación actualiza `stock_balances` dentro de una transacción.
- Las transferencias crean dos movimientos: `transfer_out` y `transfer_in`.

### Pruebas

- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/Inventory/InventoryMovementServiceTest.php`.
- Resultado: 6 pruebas pasaron, 18 assertions.
- Se ejecutó la suite completa con `docker compose run --rm app_test php artisan test`.
- Resultado final: 19 pruebas pasaron, 45 assertions.

### Notas de seguridad

- El servicio rechaza modelos que no pertenezcan al tenant actual antes de escribir en base de datos.
- Las salidas no pueden dejar stock disponible negativo.
- Las liberaciones no pueden dejar stock reservado negativo.
- Las operaciones críticas de inventario quedan preparadas para integrarse con permisos, policies y auditoría.
