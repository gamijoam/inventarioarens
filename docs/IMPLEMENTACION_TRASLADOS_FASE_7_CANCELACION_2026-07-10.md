# Implementacion Traslados Fase 7 Cancelacion

Fecha: 2026-07-10

## Resumen

Se agrego la cancelacion de traslados logisticos para que un operador o un supervisor pueda detener un traslado solicitado o preparado sin afectar el stock disponible y sin perder la trazabilidad del motivo. Esta fase cubre el caso "se solicito o se empezo a preparar el traslado pero ya no se va a despachar" y deja explicito que un traslado despachado o recibido ya no se puede cancelar porque representa una mercancia fisicamente en transito o un movimiento historico.

## Cambios Realizados

- Se agrego la migration `2026_07_10_010000_add_cancellation_to_inventory_transfers_table` con los campos `cancelled_at` y `cancelled_by` (FK users con `nullOnDelete`).
- Se actualizo el modelo `InventoryTransfer` con los nuevos campos `Fillable`, el cast para `cancelled_at` y la relacion `canceller`.
- Se creo `App\Modules\InventoryTransfers\Requests\CancelInventoryTransferRequest` que exige el campo `cancellation_reason` (motivo obligatorio, minimo 5 caracteres, maximo 1000) y permite opcionalmente `cancelled_at` para fijar la fecha de cancelacion.
- Se agrego el metodo `cancel(User, InventoryTransfer, array)` en `InventoryTransferService` que:
  - valida el `validation_mode = logistics`;
  - valida que el `status` este en `requested`, `prepared` o `prepared_with_differences`;
  - si el traslado esta preparado, libera el stock reservado de cada item usando `InventoryMovementService::release()` con `referenceType/Id` del traslado;
  - si el item tiene IMEIs o seriales en `prepared_product_unit_ids`, restaura cada unidad a `STATUS_AVAILABLE` y limpia `released_stock_movement_id`;
  - actualiza el traslado a `STATUS_CANCELLED`, `cancelled_at` y `cancelled_by`;
  - registra un evento de auditoria `inventory_transfer.cancelled` con valores anteriores y nuevos (incluye `cancellation_reason`, `released_items_count` y `released_units_count`).
- El constructor del service ahora inyecta `AuditLogger` para registrar la cancelacion.
- Se actualizo `InventoryTransferPolicy` con la ability `cancel` ligada al permiso `inventory_transfers.cancel`.
- Se agrego el endpoint `POST /api/inventory-transfers/{inventoryTransfer}/cancel` en `app/Modules/InventoryTransfers/routes.php`.
- El `InventoryTransferController` ahora expone el metodo `cancel` con `CancelInventoryTransferRequest` y `Gate::authorize('cancel', $inventoryTransfer)`.
- El `InventoryTransferResource` ahora expone `cancelled_at`, `cancelled_by` y la relacion `canceller` cuando esta cargada.
- Se inyecta `SyncCatalogOutboxService` en `InventoryTransferService` para emitir `stock_movement.created` (uno por cada `release`) y `product_unit.updated` (uno por cada IMEI liberado).

## Permisos

El permiso `inventory_transfers.cancel` ya existia en `App\Support\Permissions\BasePermissions` y lo tienen los roles `Owner`, `Administrador`, `Gerente` y `Almacen`. La policy valida pertenencia al tenant y que el usuario pertenezca activamente a la empresa actual.

## API

```txt
POST /api/inventory-transfers/{inventoryTransfer}/cancel
```

Cabeceras:

- `Authorization: Bearer <token>`
- `X-Tenant: <slug-del-tenant>`

Body:

```json
{
  "cancellation_reason": "Cliente cancelo el pedido antes de preparar.",
  "cancelled_at": "2026-07-10T10:00:00-04:00"
}
```

`cancelled_at` es opcional; si no se envia se usa la fecha actual. `cancellation_reason` es obligatorio.

Respuesta (extracto):

```json
{
  "data": {
    "id": 1,
    "status": "cancelled",
    "cancelled_at": "2026-07-10T14:00:00.000000Z",
    "cancelled_by": 5,
    "canceller": { "id": 5, "name": "Almacen Caracas" }
  }
}
```

## Reglas de Negocio

| Estado actual del traslado | Se puede cancelar | Efecto en stock | Efecto en IMEIs |
|---|---|---|---|
| `requested` | Si | Ninguno (no hay reservado) | Ninguno |
| `prepared` | Si | Libera `prepared_quantity` de cada item (reservado pasa a disponible) | IMEIs en `reserved` vuelven a `available` |
| `prepared_with_differences` | Si | Idem `prepared` | Idem `prepared` |
| `dispatched` | No (422) | La mercancia esta en transito fisico | La mercancia esta en transito fisico |
| `completed` | No (422) | El movimiento es historico | El movimiento es historico |
| `completed_with_differences` | No (422) | Idem `completed` | Idem `completed` |
| `cancelled` | No (422) | Ya cancelado | Ya cancelado |
| `validation_mode = simple` | No (422) | Los traslados simples no se cancelan | Los traslados simples no se cancelan |

## Mensajes de Error

- Traslado simple: `Los traslados simples no se cancelan desde este endpoint.`
- Traslado despachado: `El traslado ya fue despachado y esta en transito. Espere la recepcion o gestione las diferencias.`
- Traslado completado o con diferencias: `El traslado ya fue completado y es historico; no se puede cancelar.`
- Traslado ya cancelado: `El traslado ya esta cancelado.`
- Traslado rechazado: `El traslado ya fue rechazado.`
- Sin motivo: `Debe indicar un motivo para cancelar el traslado.`

## Eventos Generados

- `audit_logs` con `action = inventory_transfer.cancelled` y `entity_type = InventoryTransfer`.
- `sync_outbox` con `event_type = stock_movement.created` y `type = released` (uno por cada item con `prepared_quantity > 0`).
- `sync_outbox` con `event_type = product_unit.updated` (uno por cada IMEI restaurado a `available`).

## Pruebas Ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/InventoryTransfers/InventoryTransferApiTest.php
```

Resultado:

- 29 pruebas pasadas.
- 240 aserciones.
- Base PostgreSQL local de pruebas.
- 11 pruebas nuevas para cancelacion:
  - `test_user_can_cancel_requested_logistic_transfer_without_affecting_stock`
  - `test_user_can_cancel_prepared_logistic_transfer_and_release_reserved_stock`
  - `test_user_can_cancel_prepared_with_differences_logistic_transfer`
  - `test_user_cannot_cancel_dispatched_logistic_transfer`
  - `test_user_cannot_cancel_completed_logistic_transfer`
  - `test_user_cannot_cancel_simple_transfer`
  - `test_cancel_requires_cancellation_reason`
  - `test_cancel_releases_serialized_units_back_to_available`
  - `test_cancel_emits_sync_outbox_events_for_released_stock_and_units`
  - `test_cancel_rejects_user_without_permission`
  - `test_cancel_isolated_per_tenant`

## Pendiente Para Fases Siguientes

- Boton "Cancelar traslado" en la vista WPF de traslados.
- Vista web administrativa con traslados cancelados para auditoria.
- Modelo de resolucion de diferencias para traslados `completed_with_differences` y para revertir cancelaciones cuando aplique.
