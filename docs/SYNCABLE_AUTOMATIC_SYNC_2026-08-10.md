# Syncable — Sincronización automática de modelos (2026-08-10)

> Trait Eloquent que emite eventos de sync automáticamente cuando un modelo de
> negocio se crea, actualiza o elimina. Elimina la causa raíz de los bugs de
> sincronización: **olvidarse de llamar al outbox manualmente**.

---

## 1. Problema

Hasta el 2026-08-10, la emisión de eventos de sync era **manual**: cada controller
o servicio debía acordarse de llamar a `SyncCatalogOutboxService`. Esto produjo
múltiples brechas de sincronización:

- `ProductVariantController::store` no emitía `product_variant.created` (solo
  update/delete) → variantes que no llegaban a la nube.
- **Cuentas por pagar (CxP): 0 eventos emitidos** → la nube nunca sabía que
  existía una cuenta por pagar.
- **Cuentas por cobrar (CxC):** solo emitía `payment_registered`, no la creación.
- Compra recibida en local quedaba "pendiente" en la nube (duplicación de stock).

La causa no era el transporte (polling HTTP con outbox/inbox, que funciona), sino
la **disciplina de emisión**.

## 2. Solución: trait `Syncable`

`app/Support/Sync/Syncable.php` — un trait que se agrega al modelo. Escucha los
hooks de Eloquent (`created`, `updated`, `deleted`) y emite automáticamente.

### Cómo usarlo

```php
use App\Support\Sync\Syncable;

class AccountsPayable extends Model
{
    use BelongsToTenant, Syncable;

    protected function syncOutboxMethod(string $action): ?string
    {
        return match ($action) {
            'created' => 'accountsPayableCreated',
            'updated' => 'accountsPayableUpdated',
            default => null,
        };
    }
}
```

El método devuelve el nombre del método de `SyncCatalogOutboxService` a invocar,
o `null` si esa acción no debe sincronizar.

### Garantías

- **Emisión automática**: el modelo se sincroniza solo, sin importar si el cambio
  vino de un controller, un comando artisan, un seeder o un data-fix.
- **Sin bucles**: el applier de la nube usa `DB::table()` (query builder puro), por
  lo que NO dispara observers ni genera ciclos local<->nube.
- **Sin tenant = sin evento**: si `TenantManager::current()` es null (creación
  fuera de un request tenant-scoped), no emite. Evita errores "No current tenant".
- **Idempotencia**: la resuelve `SyncOutboxService` (mismo aggregate + versión ->
  misma `idempotency_key` -> se deduplica).

### Suspender emisión (backfills / imports masivos)

```php
AccountsPayable::syncableSuspended(function () {
    // Creaciones/actualizaciones aquí NO emiten eventos.
});
```

## 3. Modelos que ya usan Syncable (2026-08-10)

| Modelo | Eventos emitidos |
|---|---|
| `AccountsPayable` | `accounts_payable.created`, `accounts_payable.updated` |
| `AccountsPayablePayment` | `accounts_payable.payment_registered` |
| `AccountsReceivable` | `accounts_receivable.created`, `accounts_receivable.updated` |
| `Sale` | `sale.confirmed` (solo ventas del módulo Sales SIN PosOrder; las del POS viajan con `pos.order.*`) |
| `User` (vía `AccessControlService`) | `user.roles.synced` (datos del user + membresía `tenant_user` + roles asignados) |
| `Branch` | `branch.created`, `branch.updated` |
| `Warehouse` | `warehouse.created`, `warehouse.updated` |

> Los modelos de catálogo (Product, Variant, Customer, Supplier, etc.) ya emiten
> eventos manualmente desde sus controllers desde antes. NO se les agregó
> `Syncable` para evitar doble emisión. Si en el futuro se migra a `Syncable`,
> hay que quitar las llamadas manuales correspondientes.

## 4. Applier — eventos financieros nuevos aplicables en la nube

`SyncEventApplier` ahora conoce estos event types (antes `ignored`):

| Event | Handler | Comportamiento |
|---|---|---|
| `accounts_payable.created` / `updated` | `applyAccountsPayable` | Upsert CxP por `(tenant_id, document_number)`; supplier por documento |
| `accounts_payable.payment_registered` | `applyPayablePayment` | Registra el pago sobre la CxP sincronizada |
| `accounts_receivable.created` / `updated` | `applyAccountsReceivable` | Upsert CxC por `(tenant_id, document_number)`; customer por documento |
| `sale.confirmed` | `applySale` | Upsert venta por `(tenant_id, sync_source_node_code, sync_source_id)` + replica `sale_items` |
| `user.roles.synced` | `applyUserRoles` | Upsert usuario por email (con password hash), membresía `tenant_user` (active/inactive) y roles por nombre en el tenant |
| `purchase_return.created` | `applyPurchaseReturn` | Devolución de compra: upsert por sync_source, decrementa stock (salida), marca seriales removed y actualiza la CxP |

### Permisos y roles — nuevo (2026-08-10, P0)
- Antes: roles/permisos NO viajaban por sync (diseño documentado en AGENTS.md §5). Un cambio de permiso en el VPS no llegaba al local.
- Ahora: `AccessControlService` emite `user.roles.synced` al crear/adjuntar usuario, cambiar su status y cambiar sus roles. El applier lo aplica en el nodo destino (local o nube) creando/actualizando el usuario (email), su membresía y sus roles.
- El password hash viaja para permitir login local; los roles viajan por nombre y se crean en el destino si faltan.
- TDD: `tests/Feature/Sync/FinancialSyncTest.php` (emisión + aplicación + inactivación). Suite Sync 121 passed/1 skipped, AccessControl 51/51.

### Devoluciones de compra — nuevo (2026-08-10, P1)
- Antes: `purchase_return` no emitía eventos; el retorno de stock al proveedor no se replicaba en la nube.
- Ahora: `PurchaseReturnService::create` emite `purchase_return.created` con los items (sku, warehouse, quantity, seriales). El applier `applyPurchaseReturn` decrementa stock en la nube (salida `purchase_return`), marca los seriales como `removed` y actualiza la CxP vía `AccountsPayableService::applyPurchaseReturn`.
- Migración `2026_08_10_150000_add_sync_source_to_purchase_returns.php` agrega `sync_source_*` a `purchase_returns`/`purchase_return_items`.
- TDD: `tests/Feature/Sync/PurchaseReturnSyncTest.php` (emisión + aplicación con stock + seriales removed). Suite Sync 124 passed/1 skipped.

### Sucursales y almacenes — nuevo (2026-08-10, P1)
- Antes: branch/warehouse solo viajaban en la foto inicial del snapshot. Crear una sucursal o renombrar un almacén en un nodo no se replicaba al otro.
- Ahora: los modelos `Branch` y `Warehouse` usan `Syncable` → emiten `branch.created/updated` y `warehouse.created/updated`. El applier ya tenía handlers (`applyBranch`, `applyWarehouse`).
- TDD: `tests/Feature/Sync/BranchWarehouseSyncTest.php` (emisión + aplicación). Suite Sync 127 passed/1 skipped.
- Nota: no existe un agregado "settings de empresa" (sin tabla/endpoint); el cierre de esta P1 fue replicar la configuración de sucursales/almacenes (la configuración de tienda que el sistema expone hoy).

Esto garantiza que la nube **aplica** los cambios y, cuando el flujo es inverso,
los bajos al local (mismo applier corre en ambos lados).

## 5. Tests

- `tests/Feature/Sync/SyncableTraitTest.php` — trait: created/updated/deleted emiten,
  `syncableSuspended` bloquea, anidamiento, sin tenant no emite.
- `tests/Feature/Sync/FinancialSyncTest.php` — CxP/CxC emiten por trait (creación y
  pago) y el applier los aplica en la nube.

Suite Sync: 115 passed / 1 skipped. Financiera: 27/28 (1 fallo preexistente de
`cash_register_sessions.expected_base_amount`, no relacionado).

## 6. Documento de arquitectura de sincronización

Ver `docs/SINCRONIZACION_LOCAL_NUBE_2026-07-05.md` para el diseño general del
patrón outbox/inbox, y `docs/SYNC_OPERATIONS.md` para la operación.
