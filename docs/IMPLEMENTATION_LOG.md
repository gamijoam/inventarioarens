# Registro de implementación

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
