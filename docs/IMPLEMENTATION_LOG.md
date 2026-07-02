# Registro de implementación

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
