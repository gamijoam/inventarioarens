# Arquitectura de Inventory Arens

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
- `Products`: primer modelo tenant-scoped usado para probar el patrón de aislamiento.
- `Branches`: sucursales tenant-scoped.
- `Warehouses`: almacenes tenant-scoped vinculados a sucursales del mismo tenant.
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

Reglas de integridad:

- `warehouses` referencia `branches` usando `tenant_id + branch_id`.
- `stock_movements` referencia `warehouses` usando `tenant_id + warehouse_id`.
- `stock_movements` referencia `products` usando `tenant_id + product_id`.
- `stock_balances` referencia `warehouses` y `products` con claves compuestas por tenant.
- `products`, `branches` y `warehouses` exponen claves únicas compuestas `tenant_id + id` para permitir esas referencias seguras.

## Regla de documentación

Toda documentación del proyecto debe escribirse en español. Cada cambio importante debe quedar registrado en `docs/IMPLEMENTATION_LOG.md` con:

- qué se implementó;
- qué pruebas se ejecutaron;
- qué riesgo o error evita.

## Siguiente fase

La siguiente fase debe agregar:

- servicios de inventario para entradas, salidas, ajustes y transferencias;
- validaciones de tipos de movimiento;
- actualización controlada de `stock_balances` desde `stock_movements`;
- auditoría para acciones de negocio.
