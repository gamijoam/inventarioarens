# Arquitectura de Inventory Arens

## Documentos de referencia rapida

- `docs/MODULES.md`: mapa modular del proyecto, responsabilidades actuales y modulos planificados.
- `docs/API.md`: catalogo de APIs actuales, clasificado por modulo.

Regla actual de rutas:

- `routes/api.php` solo carga archivos de rutas modulares.
- Las rutas de inventario viven en `app/Modules/Inventory/routes.php`.
- Las rutas de reportes viven en `app/Modules/Reports/routes.php`.

## Reportes iniciales

Las rutas API de reportes viven en `app/Modules/Reports/routes.php` y son cargadas desde `routes/api.php` con los middleware `auth` y `tenant`.

Prefijo:

```txt
/api/reports
```

Endpoints iniciales:

- `GET /api/reports/stock`: stock actual por almacen y producto.
- `GET /api/reports/stock/low`: productos con bajo stock segun umbral.
- `GET /api/reports/movements`: movimientos de inventario filtrables.

Filtros iniciales:

- `warehouse_id`
- `product_id`
- `threshold` para bajo stock
- `type` para movimientos
- `date_from`
- `date_to`

Reglas:

- todos los reportes requieren `reports.view`;
- los filtros `warehouse_id` y `product_id` se validan contra el tenant actual;
- los reportes usan modelos tenant-scoped, por lo que una empresa no ve stock ni movimientos de otra;
- los reportes de stock usan `stock_balances`;
- los reportes de movimientos usan `stock_movements`.

Prueba asociada:

- `tests/Feature/Reports/InventoryReportApiTest.php`

## Auditoria

Las acciones importantes de negocio deben quedar registradas en `audit_logs`.

Campos principales:

- `tenant_id`: empresa a la que pertenece el evento;
- `user_id`: usuario que ejecuto la accion, si existe;
- `action`: nombre estable de la accion;
- `entity_type`: clase de la entidad afectada;
- `entity_id`: id de la entidad afectada;
- `old_values`: valores anteriores cuando aplique;
- `new_values`: valores nuevos o datos relevantes;
- `ip_address`: IP de la peticion cuando exista;
- `user_agent`: user agent de la peticion cuando exista;
- `created_at`: fecha del evento.

Los movimientos de inventario registran auditoria con la accion:

```txt
inventory.movement.created
```

Reglas:

- los audit logs son tenant-scoped y usan `BelongsToTenant`;
- los logs se filtran por tenant igual que los demas datos de negocio;
- una empresa no debe ver logs de otra empresa;
- los movimientos creados por API deben registrar usuario, IP y user agent.

Prueba asociada:

- `tests/Feature/Audit/InventoryAuditTest.php`

## Moneda y tasas para Venezuela

El sistema se disena para operar en Venezuela, donde las operaciones pueden manejarse en dolares estadounidenses y bolivares.

Decision inicial:

- moneda base interna del inventario: `USD`;
- moneda local operativa: `VES`;
- los productos podran venderse en `USD` o `VES`;
- cada venta, compra o movimiento monetario futuro debe guardar la moneda de la transaccion y la tasa usada;
- los costos historicos no deben recalcularse con la tasa nueva del dia;
- la tasa del dia servira para mostrar equivalencias, cotizar y reportar, pero no para alterar movimientos historicos.

Campos sugeridos para compras, ventas y movimientos monetarios futuros:

- `currency_code`: moneda original de la operacion, por ejemplo `USD` o `VES`;
- `exchange_rate`: tasa usada en la operacion;
- `base_currency_code`: moneda base interna, inicialmente `USD`;
- `unit_amount`: monto unitario en la moneda original;
- `base_unit_amount`: monto unitario convertido a la moneda base;
- `total_amount`: total en la moneda original;
- `base_total_amount`: total convertido a la moneda base.

Cada tenant podra configurar su fuente de tasa preferida mas adelante, por ejemplo BCV, manual, paralelo o tasa propia de tienda.

El modulo `Currency` permite que una empresa maneje varios tipos de tasa para el mismo par `USD` a `VES`, por ejemplo `BCV` y `PARALELO`. Esto permite que ciertos productos, listas de precio o ventas futuras usen un tipo de tasa distinto sin cambiar la moneda base del inventario.

Reglas adicionales:

- `exchange_rate_types` define tipos de tasa por tenant;
- `exchange_rates` guarda el historial de valores por tipo de tasa;
- una empresa puede tener `BCV` y `PARALELO` activos al mismo tiempo;
- solo una tasa queda activa por tenant, tipo de tasa, moneda base y moneda cotizada;
- las ventas futuras deben guardar el tipo de tasa, codigo, valor exacto y fecha usados para no recalcular historia.

Los productos pueden definir precio base en `USD`, moneda preferida de venta y tipo de tasa sugerido. La API de precio calculado no crea ventas; solo cotiza el producto usando la tasa activa actual. Cuando exista POS o ventas, el documento de venta debe copiar precio, moneda, tipo de tasa y valor exacto usados.

El modulo `Sales` crea ventas primero como `draft`. En esa fase copia precio, moneda, tipo de tasa y valor de tasa desde el producto, pero no mueve inventario. La confirmacion de una venta valida stock disponible y genera movimientos `sale` en inventario con referencia a la venta. En esta fase, las ventas confirmadas no se cancelan directamente; una devolucion o reverso controlado se modelara mas adelante.

El modulo `POS` es la capa operativa de caja. POS crea una venta mediante `Sales`, registra pagos y solo confirma la venta cuando los pagos capturados cubren el total. Los pagos pueden registrarse en `USD` o `VES`; si el pago es en bolivares se guarda el tipo de tasa, codigo y valor exacto usado. Pagos pendientes, como una financiadora externa futura, quedan registrados pero no descuentan inventario hasta que se capturen.

## Objetivo

Inventory Arens es un monolito Laravel diseñado como un sistema de inventario SaaS modular. Todo registro de negocio debe pertenecer a un tenant mediante `tenant_id`.

## Reglas de multitenancy

- Se usa una sola base de datos PostgreSQL compartida por todos los tenants.
- Toda tabla de negocio debe incluir `tenant_id`.
- Las consultas de modelos que pertenecen a un tenant deben usar `BelongsToTenant`.
- `BelongsToTenant` agrega un global scope de Eloquent y asigna `tenant_id` automáticamente al crear registros.
- Crear datos de negocio sin un tenant resuelto debe fallar rápido.
- Las claves únicas de negocio deben estar limitadas por tenant, por ejemplo `tenant_id + sku`.

## Flujo de una petición

1. `ResolveTenant` lee el tenant desde `X-Tenant`, ruta/query `tenant`, o dominio.
2. `TenantManager` guarda el tenant actual durante la petición.
3. Spatie Permission recibe el mismo tenant id como team id.
4. Los modelos que pertenecen a tenant se filtran automáticamente con `TenantScope`.
5. Las policies y permisos validan la intención del usuario antes de ejecutar acciones críticas.

## Módulos

Los módulos viven en `app/Modules`.

Estructura sugerida por módulo:

```txt
ModuleName/
|-- Actions/
|-- DTOs/
|-- Models/
|-- Policies/
|-- Services/
|-- Controllers/
|-- Requests/
|-- Resources/
|-- routes.php
`-- ModuleServiceProvider.php
```

Módulos implementados inicialmente:

- `Tenancy`: tenants, resolución de tenant y aislamiento de petición.
- `Products`: catalogo de productos, API de productos, policy tenant-aware y tipo de control por cantidad o serializado.
- `Branches`: sucursales tenant-scoped con API, permisos y codigo unico por tenant.
- `Warehouses`: almacenes tenant-scoped con API, permisos y validacion de sucursal del mismo tenant.
- `Inventory`: movimientos y balances de stock tenant-scoped.

## Permisos

Spatie Laravel Permission está configurado con teams habilitado. En este proyecto la clave de team es `tenant_id`, de forma que un mismo usuario pueda tener roles distintos en tenants distintos.

Los permisos base y el mapa inicial de roles viven en `App\Support\Permissions\BasePermissions`.

Las policies deben comprobar permiso y pertenencia al tenant. Tener un permiso no basta para acceder a datos tenant-owned.

El primer patrón de policy es `App\Modules\Products\Policies\ProductPolicy`:

- comprueba que exista un tenant actual;
- comprueba que el usuario pertenezca activamente al tenant actual;
- comprueba el permiso granular requerido;
- comprueba que los productos pertenezcan al tenant actual antes de ver, actualizar o eliminar.

## Pruebas de seguridad actuales

La suite de pruebas debe ejecutarse contra PostgreSQL mediante Docker Compose. No se debe usar SQLite como base de confianza para pruebas de multitenancy, integridad referencial, decimales o claves compuestas.

Configuración de testing:

- servicio de app para pruebas: `app_test`;
- servicio Docker: `postgres_test`;
- base de datos: `inventory_arens_testing`;
- conexión PHPUnit: `pgsql`;
- host interno: `postgres_test`;
- puerto interno: `5432`.

Comando oficial de pruebas:

```bash
docker compose run --rm app_test php artisan test
```

`tests/Feature/Tenancy/TenantIsolationTest.php` verifica:

- las consultas tenant-scoped solo devuelven datos del tenant actual;
- los registros tenant-owned no se pueden crear sin tenant actual;
- el mismo SKU puede existir en tenants distintos.

`tests/Feature/Permissions/ProductPolicyTest.php` verifica:

- los permisos de productos funcionan solo dentro del tenant actual;
- los roles asignados en un tenant no se filtran hacia otro tenant;
- crear requiere membresía activa en el tenant y permiso;
- actualizar y eliminar requieren que el recurso pertenezca al tenant actual.

`tests/Feature/Inventory/InventorySchemaIsolationTest.php` verifica:

- sucursales y almacenes solo se leen dentro del tenant actual;
- movimientos y balances de stock solo se leen dentro del tenant actual;
- códigos y balances son únicos por tenant, no globales;
- un movimiento de inventario no puede referenciar productos de otro tenant.

## Inventario base

El inventario se construye sobre movimientos, no sobre una columna `stock` en productos.

Tablas base:

- `branches`: sucursales por tenant.
- `warehouses`: almacenes por tenant y sucursal.
- `stock_movements`: verdad histórica del inventario.
- `stock_balances`: lectura rápida del saldo actual por tenant, almacén y producto.
- `product_units`: unidades fisicas serializadas por tenant, producto y almacen.

Reglas de integridad:

- `warehouses` referencia `branches` usando `tenant_id + branch_id`.
- `stock_movements` referencia `warehouses` usando `tenant_id + warehouse_id`.
- `stock_movements` referencia `products` usando `tenant_id + product_id`.
- `stock_balances` referencia `warehouses` y `products` con claves compuestas por tenant.
- `products`, `branches` y `warehouses` exponen claves únicas compuestas `tenant_id + id` para permitir esas referencias seguras.

## Productos serializados, IMEI y seriales

Un producto representa el modelo comercial, no cada unidad fisica. Por ejemplo, `Samsung A06` es un producto; cada telefono individual con un IMEI distinto vive como una fila en `product_units`.

Tipos de control:

- `quantity`: producto controlado solo por cantidad.
- `serialized`: producto que requiere trazabilidad por unidad fisica.

Reglas:

- los IMEIs, seriales, VIN u otros identificadores unicos viven en `product_units`;
- un mismo producto puede tener muchas unidades, por ejemplo 30 IMEIs para `Samsung A06`;
- cada unidad pertenece a un tenant, producto y almacen actual;
- `serial_type` permite distinguir `imei`, `serial` u otro tipo futuro;
- `serial_number` es unico por tenant y tipo de serial;
- una empresa no puede registrar unidades sobre productos o almacenes de otra empresa;
- los estados iniciales de unidad son `available`, `reserved`, `sold`, `damaged` y `removed`.

Esta base sirve para telefonos y tambien para cualquier producto que requiera seguimiento individual.

## Servicio de movimientos de inventario

`App\Modules\Inventory\Services\InventoryMovementService` centraliza las operaciones que modifican inventario.

Operaciones iniciales:

- `purchase`: registra entrada por compra y aumenta disponible.
- `sale`: registra salida por venta y reduce disponible.
- `adjustmentIn`: registra ajuste positivo.
- `adjustmentOut`: registra ajuste negativo.
- `reserve`: mueve cantidad de disponible a reservado.
- `release`: mueve cantidad de reservado a disponible.
- `markDamaged`: mueve cantidad de disponible a dañado.
- `transfer`: genera `transfer_out` y `transfer_in` entre almacenes del mismo tenant.

Reglas del servicio:

- toda operación corre dentro de una transacción;
- toda cantidad debe ser mayor que cero;
- el almacén y producto deben pertenecer al tenant actual;
- las salidas requieren stock disponible suficiente;
- liberar requiere stock reservado suficiente;
- las transferencias actualizan ambos almacenes y generan dos movimientos;
- `stock_movements` guarda la historia y `stock_balances` se actualiza como lectura rápida.

## Regla de documentación

Toda documentación del proyecto debe escribirse en español. Cada cambio importante debe quedar registrado en `docs/IMPLEMENTATION_LOG.md` con:

- qué se implementó;
- qué pruebas se ejecutaron;
- qué riesgo o error evita.

## Siguiente fase

## Autorizacion de inventario

`App\Modules\Inventory\Policies\InventoryPolicy` valida permisos y pertenencia al tenant para operaciones de inventario.

`App\Modules\Inventory\Services\AuthorizedInventoryMovementService` es la capa que deben usar controladores, jobs e IA cuando una operacion venga de un usuario. Este servicio autoriza primero y luego delega en `InventoryMovementService`.

Abilities internos:

- `inventory.view-operation`
- `inventory.receive-operation`
- `inventory.sale-operation`
- `inventory.adjust-operation`
- `inventory.transfer-operation`

Estos nombres no coinciden exactamente con permisos Spatie como `inventory.adjust` o `inventory.transfer` de forma intencional. Asi se evita que Spatie conceda el permiso antes de que nuestra policy valide recursos y tenant.

Permisos revisados por la policy:

- `inventory.view` para consultar inventario.
- `purchases.create` para entradas por compra.
- `sales.create` para salidas por venta.
- `inventory.adjust` para ajustes, reservas, liberaciones y danados.
- `inventory.transfer` para transferencias entre almacenes.

Prueba asociada:

- `tests/Feature/Inventory/InventoryAuthorizationTest.php`

## API de inventario

Las rutas API de inventario viven en `app/Modules/Inventory/routes.php` y son cargadas desde `routes/api.php` con los middleware `auth` y `tenant`.

Prefijo:

```txt
/api/inventory
```

Endpoints iniciales:

- `POST /api/inventory/purchases`
- `POST /api/inventory/sales`
- `POST /api/inventory/adjustments/in`
- `POST /api/inventory/adjustments/out`
- `POST /api/inventory/reservations`
- `POST /api/inventory/releases`
- `POST /api/inventory/damages`
- `POST /api/inventory/transfers`

Reglas:

- toda peticion debe incluir tenant, por ejemplo header `X-Tenant`;
- el usuario debe estar autenticado;
- el usuario debe pertenecer activamente al tenant;
- los ids de almacenes y productos se validan contra el tenant actual;
- las operaciones usan `AuthorizedInventoryMovementService`;
- las respuestas de movimientos usan `StockMovementResource`.

Prueba asociada:

- `tests/Feature/Inventory/InventoryApiTest.php`

La siguiente fase debe agregar:

- auditoría para acciones de negocio.
