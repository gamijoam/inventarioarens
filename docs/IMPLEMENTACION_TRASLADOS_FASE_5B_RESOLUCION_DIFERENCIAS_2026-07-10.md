# Implementacion Traslados Fase 5B Resolucion de Diferencias

Fecha: 2026-07-10

## Resumen

Se agrego la fase final del flujo logistico de traslados: la resolucion administrativa de diferencias. Cuando un traslado se recibe con faltantes, sobrantes o danos, ahora el supervisor puede registrar la decision del negocio sobre cada item con diferencia y cerrar el caso sin perder trazabilidad.

Esta fase cubre el caso "ya recibimos la mercaderia, hay faltantes documentados, el supervisor decidio que hacer con ellos" y deja el sistema listo para el portal web administrativo que mostrara estos traslados y permitira gestionarlos desde la nube.

## Cambios Realizados

- Se agrego la migration `2026_07_10_020000_add_resolution_to_inventory_transfer_tables` con los campos:
  - En `inventory_transfers`: `resolution_status`, `resolution_notes`, `resolved_at`, `resolved_by` e indice por tenant.
  - En `inventory_transfer_items`: `resolution_status`, `resolution_notes`, `resolved_at`, `resolved_by`.
- Se actualizo el modelo `InventoryTransfer` con las constantes `RESOLUTION_UNRESOLVED`, `RESOLUTION_PARTIAL`, `RESOLUTION_RESOLVED` y la relacion `resolver()`.
- Se actualizo el modelo `InventoryTransferItem` con las constantes de estado de resolucion por item, las acciones de cierre (`RESOLUTION_CLOSE_ACTIONS`) y la relacion `resolver()`.
- Se agrego el metodo `resolveDifferences(User, InventoryTransfer, array)` en `InventoryTransferService` que:
  - valida que el `validation_mode = logistics` y el `status = completed_with_differences`;
  - exige al menos un item con `difference_quantity > 0` y `resolution_status = unresolved`;
  - por cada item aplica la accion indicada:
    - `investigate`: marca el item y el traslado como en investigacion, sin tocar stock.
    - `accept_loss`: registra la decision como perdida, sin tocar stock adicional (el stock destino ya refleja la realidad de la cantidad recibida). Si el item es serializado, las unidades no recibidas se marcan como `STATUS_REMOVED` referenciando el `out_stock_movement_id` del item.
    - `manual_adjustment`: crea un `adjustment_out` en destino por la cantidad libre que indique el supervisor, emite evento de sync, y si el item es serializado marca las unidades faltantes como `STATUS_REMOVED`.
    - `returned_to_origin`: accion declarada en constantes pero todavia no implementada, devuelve 422 con mensaje claro.
  - recalcula el `resolution_status` del traslado segun los items resultantes:
    - `unresolved`: quedan items con `difference_quantity > 0` y `resolution_status = unresolved`.
    - `partial`: todos los items con diff tienen accion pero alguno esta en `investigating`.
    - `resolved`: todos los items con diff estan cerrados con `accept_loss`, `adjusted_manually` o `returned_to_origin`.
  - cuando el `resolution_status` pasa a `resolved`, el `status` del traslado pasa a `completed` y se sella `resolved_at`/`resolved_by`.
  - registra `audit_logs` con la accion `inventory_transfer.differences_resolved` y un resumen de los movimientos y unidades afectadas.
  - emite eventos de sync para cada movimiento de stock y cada unidad serializada modificada.
- Se creo `App\Modules\InventoryTransfers\Requests\ResolveInventoryTransferRequest` con validacion por accion: `quantity` es obligatoria solo cuando la accion es `manual_adjustment`.
- Se agrego el metodo `resolveDifferences` en `InventoryTransferPolicy` con el permiso `inventory_transfers.resolve_differences`.
- Se agrego el endpoint `POST /api/inventory-transfers/{inventoryTransfer}/resolve-differences` en `app/Modules/InventoryTransfers/routes.php`.
- El `InventoryTransferController` ahora expone el metodo `resolveDifferences` con `ResolveInventoryTransferRequest` y `Gate::authorize('resolveDifferences', $inventoryTransfer)`.
- El `InventoryTransferResource` ahora expone `resolution_status`, `resolution_notes`, `resolved_at`, `resolved_by` y la relacion `resolver` cuando esta cargada.
- El `InventoryTransferItemResource` ahora expone `resolution_status`, `resolution_notes`, `resolved_at`, `resolved_by` y la relacion `resolver` cuando esta cargada.

## Permisos

El permiso `inventory_transfers.resolve_differences` ya existia en `App\Support\Permissions\BasePermissions` y lo tienen los roles `Owner`, `Administrador`, `Gerente` y `Almacen`. La policy valida pertenencia al tenant y que el usuario pertenezca activamente a la empresa actual.

## API

```txt
POST /api/inventory-transfers/{inventoryTransfer}/resolve-differences
```

Cabeceras:

- `Authorization: Bearer <token>`
- `X-Tenant: <slug-del-tenant>`

Body:

```json
{
  "notes": "Tras auditoria se confirman 2 unidades perdidas en transito.",
  "items": [
    {
      "inventory_transfer_item_id": 12,
      "action": "accept_loss",
      "notes": "Robo parcial en el transporte."
    },
    {
      "inventory_transfer_item_id": 15,
      "action": "manual_adjustment",
      "quantity": 1.5,
      "notes": "Merma adicional detectada por el supervisor."
    },
    {
      "inventory_transfer_item_id": 18,
      "action": "investigating",
      "notes": "Coordinando con el transportista para confirmar el faltante."
    }
  ]
}
```

`notes` global es opcional. Cada item exige `inventory_transfer_item_id` y `action`; `quantity` solo es obligatoria cuando la accion es `manual_adjustment`.

Respuesta (extracto):

```json
{
  "data": {
    "id": 1,
    "status": "completed",
    "resolution_status": "partial",
    "resolved_at": null,
    "resolved_by": 5,
    "items": [
      {
        "id": 12,
        "difference_quantity": 2,
        "resolution_status": "accepted_loss"
      },
      {
        "id": 15,
        "difference_quantity": 1,
        "resolution_status": "adjusted_manually"
      },
      {
        "id": 18,
        "difference_quantity": 3,
        "resolution_status": "investigating"
      }
    ]
  }
}
```

## Reglas de Negocio

| Accion por item | Efecto en stock destino | IMEIs faltantes | Estado final del item |
|---|---|---|---|
| `investigating` | Ninguno | Ninguno | `investigating` |
| `accept_loss` | Ninguno (el stock ya refleja la cantidad recibida) | Marcados como `STATUS_REMOVED` con `released_stock_movement_id` igual al `out_stock_movement_id` del item | `accepted_loss` |
| `manual_adjustment` | `adjustment_out` por la cantidad indicada en el payload | Marcados como `STATUS_REMOVED` con `released_stock_movement_id` igual al `out_stock_movement_id` del item | `adjusted_manually` |
| `returned_to_origin` | No implementado en esta fase (422 con mensaje explicito) | No implementado | No implementado |

### Estado del traslado segun resolucion de items

| Items con `difference_quantity > 0` restantes | Estado del traslado |
|---|---|
| Al menos uno con `resolution_status = unresolved` | `resolution_status = unresolved` |
| Todos resueltos y al menos uno en `investigating` | `resolution_status = partial` |
| Todos resueltos y ninguno en `investigating` | `resolution_status = resolved` |

Cuando el `resolution_status` del traslado pasa a `resolved`, su `status` pasa automaticamente a `STATUS_COMPLETED` y se sellan `resolved_at` y `resolved_by`. Si queda en `partial` o `unresolved`, el `status` se mantiene en `STATUS_COMPLETED_WITH_DIFFERENCES`.

## Mensajes de Error

- Traslado simple: `Los traslados simples no tienen diferencias que resolver.`
- Estado incorrecto: `Solo se pueden resolver diferencias en traslados completados con diferencias.`
- Sin diferencias pendientes: `El traslado no tiene diferencias pendientes por resolver.`
- Item invalido: `El item no pertenece al traslado.`
- Item sin diferencia: `El item no tiene diferencias pendientes por resolver.`
- Cantidad manual invalida: `La cantidad de ajuste manual debe ser mayor que cero.`
- Accion no soportada: `La devolucion al origen no esta habilitada en esta fase.` o `Accion de resolucion no soportada.`

## Eventos Generados

- `audit_logs` con `action = inventory_transfer.differences_resolved` y resumen de movimientos y unidades removidas.
- `sync_outbox` con `event_type = stock_movement.created` y `type = adjustment_out` (uno por cada item con accion `manual_adjustment`).
- `sync_outbox` con `event_type = product_unit.updated` (uno por cada IMEI marcado como `removed`).

## Pruebas Ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test
```

Resultado:

- 348 pruebas pasadas.
- 2261 aserciones.
- Base PostgreSQL local de pruebas.
- 9 pruebas nuevas para resolucion de diferencias:
  - `test_user_can_resolve_differences_with_accept_loss_and_adjust_stock`
  - `test_user_can_resolve_differences_with_investigate_and_keep_stock`
  - `test_user_can_resolve_differences_with_manual_adjustment_and_custom_quantity`
  - `test_user_can_resolve_differences_with_mixed_actions_and_partial_status`
  - `test_user_can_resolve_differences_and_marks_missing_serial_units_as_removed`
  - `test_user_cannot_resolve_transfer_without_differences`
  - `test_user_cannot_resolve_transfer_that_is_not_completed_with_differences`
  - `test_resolve_differences_rejects_user_without_permission`
  - `test_resolve_differences_emits_sync_outbox_events`

## Decisiones de Diseno

- `accept_loss` no crea un `adjustment_out` adicional porque el stock destino ya refleja la cantidad recibida. La perdida queda implícita en la diferencia entre lo esperado y lo recibido; aceptar la perdida es un acto administrativo, no un movimiento fisico adicional.
- `manual_adjustment` si crea un `adjustment_out` porque es una correccion del stock destino que va mas alla de la diferencia documentada.
- Para IMEIs despachados pero no recibidos, se marcan como `STATUS_REMOVED` con el `out_stock_movement_id` del item como referencia, evitando perdida de trazabilidad entre el movimiento que los despacho y la baja definitiva.
- La accion `returned_to_origin` queda como constante en el modelo pero todavia no implementada. Se prefiere documentar la intencion en esta fase para que la UI pueda mostrarla y el backend rechace con un mensaje claro.
- Cuando el traslado pasa a `resolution_status = resolved`, se sella con `resolved_at` y `resolved_by`; en `partial` o `unresolved` estos campos quedan nulos.

## Pendiente Para Fases Siguientes

- Boton "Resolver diferencias" en la vista WPF de traslados, con un dialog por item.
- Vista web administrativa con filtros de traslados con diferencias pendientes, parciales o resueltos.
- Endpoint para reabrir una resolucion ya aplicada (caso excepcional).
- Implementacion de la accion `returned_to_origin` con creacion de movimiento de transferencia inversa.
- Dashboard de diferencias frecuentes por producto o proveedor.
