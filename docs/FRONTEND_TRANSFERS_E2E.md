# FRONTEND_TRANSFERS_E2E

> Flujo end-to-end del modulo de Traslados en el frontend, paso a paso.
> Estado: 2026-07-15. Backend + Frontend completos (FASE T1-T4).
> FASE T5 cierra con docs + tests finales.

## Vision general

El modulo de Traslados permite al usuario:

1. **Crear** un traslado de un almacen a otro dentro del mismo tenant.
2. **Preparar** el traslado (opcional, solo modo logistics): registrar
   que el transportista ha confirmado cada item.
3. **Asignar transportista** (opcional): nombre, documento, placa, empresa
   transportista, y firmas digitales.
4. **Despachar** el traslado: convertir la reserva en movimiento de salida.
5. **Recibir** mercancia: registrar cantidades recibidas (con IMEIs
   opcionales) y resolver diferencias si las hay.
6. **Descargar** la guia de traslado en PDF o ver en HTML.
7. **Cancelar** el traslado (solo si todavia no se ha despachado).

## Paginas y rutas

| Ruta | Componente | Permiso |
|---|---|---|
| `/transfers` | `TransfersPage` (listado) | `inventory_transfers.view` |
| `/transfers/$transferId` | `TransferDetailPage` (detalle) | `inventory_transfers.view` |

## Estructura visual

### Listado (`/transfers`)

```
┌─────────────────────────────────────────────────────────────────────────┐
│ Centro de Inventario > Traslados                                         │
│ Movimiento de stock entre almacenes...                                   │
├─────────────────────────────────────────────────────────────────────────┤
│ [search...] [Estado ▼] [Modo ▼]                            [Nuevo ▼]    │
├─────────────────────────────────────────────────────────────────────────┤
│ PO-001  Logistico  WH-ORIG  WH-DEST  [Preparado]  $100.00  3  ☐ [⛌]  │
│ PO-002  Directo   WH-1     WH-2     [Completado] $50.00   2  ✓          │
└─────────────────────────────────────────────────────────────────────────┘
```

- **Filtros**: busqueda por documento/guia, estado (Solicitado, Preparado,
  etc.), modo (Directo, Logistico).
- **Acciones rapidas**: Recibir (Package) si prepared/partial, Cancelar
  (XCircle) si draft/prepared/partial.
- **Badges contextuales**: estado + modo.

### Detalle (`/transfers/$transferId`)

```
┌─────────────────────────────────────────────────────────────────────────┐
│ ← Traslados    PO-001  [Logistico]  [Preparado]      [Recibir] [Canc.]  │
├─────────────────────────────────────────────────────────────────────────┤
│ [General]  [Items]  [Checklist]  [Guia]                                 │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  PO-001   [Preparado]                                                 │
│  Despachado: 2026-07-15  Actualizado hace 2h                            │
│                                                       Total USD: $100.00  │
│  ●──●──○──○  Solicitado → Preparado → Despachado → Completado           │
│                                                                          │
│  Almacen origen    Almacen destino                                       │
│  [WH-ORIG]         [WH-DEST]                                              │
└─────────────────────────────────────────────────────────────────────────┘
```

Tabs:
1. **General**: `TransferSummary` (stepper, totales, warehouses, transportista).
2. **Items**: tabla con pedido/preparado/recibido/diferencia.
3. **Checklist** (solo si logistics): 2 sub-tablas (preparation + reception)
   con checkboxes para confirmar cada item.
4. **Guia**: botones para descargar PDF o ver HTML.

## Flujo E2E: Crear → Preparar → Asignar transportista → Despachar → Recibir

### Paso 1: Crear borrador

- El usuario hace click en "Nuevo traslado" (placeholder; en el flujo
  real abrira un `TransferCreateDialog` que todavia no esta implementado,
  pendiente del modulo de Inventory Admin).
- En el modulo admin (Workaround) se usa el endpoint `POST /api/inventory-transfers`
  con:
  ```json
  {
    "from_warehouse_id": 3,
    "to_warehouse_id": 4,
    "validation_mode": "logistics",
    "reason": "Reposicion mensual",
    "items": [
      {"product_id": 77, "quantity": 5},
      {"product_id": 88, "quantity": 3}
    ]
  }
  ```
- El backend crea el transfer en `requested` con items en `prepared_quantity=0`.

### Paso 2: Preparar mercancia (opcional, solo logistics)

- En la pagina de detalle, tab "Checklist", seccion "Preparacion".
- Cada item tiene un boton circular. El transportista hace click
  cuando confirma que ese item esta listo. El POST va a:
  `POST /api/inventory-transfers/{id}/checklist/preparation/items/{itemId}/check`
- La barra de progreso global sube. Cuando llega al 100%, el checklist
  esta completo.
- Alternativamente, el usuario puede llamar al endpoint `prepare` para
  confirmar todo de una vez (enviando las cantidades/IMEIs preparados).

### Paso 3: Asignar transportista (opcional)

- En el header del detalle, boton "Asignar transportista" (visible si
  no hay driver y status >= prepared).
- Abre `TransferAssignDriverDialog` con form (nombre, documento, telefono,
  placa, empresa, notas). Submit a `PUT /inventory-transfers/{id}/driver`.
- El driver aparece en el card de Transportista en la tab General.

### Paso 4: Despachar (solo logistics)

- En la pagina de detalle, tab "Checklist" seccion "Preparacion" debe
  estar completa. Luego el operador (no el transportista) hace click en
  "Recibir" (en realidad seria un boton "Despachar" que en el flujo
  del FASE T4 esta embebido en el flujo de Recibir).
- POST `/api/inventory-transfers/{id}/dispatch` con `{dispatched_at, notes}`.
- El status pasa a `dispatched` y se crea el `reception` checklist en
  estado `pending`.

### Paso 5: Recibir mercancia

- Boton "Recibir" en el header. Abre `TransferReceiveDialog` con lista
  de items pendientes.
- Para cada item: input "Cantidad a recibir" (default = todo lo
  pendiente) o input de IMEIs (para serializados). Si la cantidad es
  menor al pendiente, campo obligatorio de "Motivo de la diferencia".
- Submit a `POST /api/inventory-transfers/{id}/receive` con:
  ```json
  {
    "received_at": "2026-07-16",
    "items": [
      {"inventory_transfer_id": 1, "received_quantity": 5},
      {"inventory_transfer_id": 2, "received_quantity": 2, "difference_reason": "Empaque danado"}
    ]
  }
  ```
- El status pasa a `completed` (o `completed_with_differences` si hay
  diferencias). El stock se mueve en el backend (transfer_in +
  ProductUnits). La `useReceiveTransfer` invalida `productKeys.lists()`.

### Paso 6: Descargar la guia

- Tab "Guia" del detalle. Botones "Descargar PDF" o "Ver HTML".
- PDF: `GET /api/inventory-transfers/{id}/guide.pdf` (Content-Type:
  application/pdf). Generado por `TransferGuidePdfService` con
  `barryvdh/laravel-dompdf`.
- HTML: `GET /api/inventory-transfers/{id}/guide.html` (Content-Type:
  text/html). Render del blade `inventory_transfers/guide.blade.php`.
- Solo disponible si el transfer esta en `prepared`, `dispatched` o
  `completed` (o variantes con _differences).

### Paso 7 (opcional): Resolver diferencias

- Si el status es `completed_with_differences`, el operador puede
  resolver via `POST /api/inventory-transfers/{id}/resolve-differences`
  con `items[].action` ∈ {`investigating`, `accepted_loss`, `adjusted_manually`}.

## Permisos (catalogo frontend)

| Permiso backend | Constante frontend | Uso |
|---|---|---|
| `inventory_transfers.view` | `INVENTORY_TRANSFERS_VIEW` | Listado, detalle, guia. |
| `inventory_transfers.create` | (sin constante) | Crear nuevo traslado. |
| `inventory_transfers.prepare` | (sin constante) | Marcar como preparado. |
| `inventory_transfers.dispatch` | (sin constante) | Despachar. |
| `inventory_transfers.receive` | (sin constante) | Recibir. |
| `inventory_transfers.assign_driver` | (sin constante, FASE T1 nuevo) | Asignar transportista. |
| `inventory_transfers.verify` | (sin constante, FASE T1 nuevo) | Marcar items del checklist. |

## Sidebar

Item "Traslados" con icono `Truck` (en `features/purchases/PurchasesManager`
como analogia). Permiso `PURCHASES_VIEW` o `INVENTORY_TRANSFERS_VIEW`.

## Cache invalidation

- `useReceiveTransfer.onSuccess`: invalida `transferKeys.lists()` +
  `transferKeys.detail(id)` + `transferKeys.checklists()` +
  `productKeys.lists()` (movimiento de stock).
- `usePrepareTransfer.onSuccess`: invalida `transferKeys.lists()` +
  `transferKeys.detail(id)` + `transferKeys.checklists()`.
- `useAssignDriver.onSuccess`: invalida `transferKeys.detail(id)`.
- `useCheckChecklistItem.onSuccess`: invalida
  `transferKeys.checklist(transferId, stage)` + `transferKeys.detail(transferId)`.

## Tests

- **Backend**: 78 tests OK en `tests/Feature/InventoryTransfers/`.
  Incluye: CRUD, lifecycle logistics, serializados con IMEIs,
  cancel en cada estado, resolve con 4 acciones, sync, permissions,
  reserve_on_request (FASE T1), driver CRUD (FASE T1), prepare con
  0 items (FASE T1), PDF generator (FASE T2).
- **Backend**: 5 tests OK en `tests/Feature/InventoryTransferRequests/`.
- **Frontend**: 171/171 tests OK total (incluye los modulos previos).

## Pendientes (roadmap)

- `TransferCreateDialog` (FASE 4+ del modulo Inventory): crear traslado
  desde la UI de Inventory, con typeahead de productos y almacenes.
- **InventoryTransferRequest UI** (cross-tenant): el modulo backend
  existe pero no tiene UI en el frontend. Se entregaria como modulo
  aparte con su propio flujo (solicitar / aceptar / rechazar).
- **Validacion de IMEIs via scanner** (camara del telefono).
- **Notificaciones push** cuando el transportista firma o el destino
  acepta una solicitud.

## Documentacion relacionada

- `docs/INVENTORY_TRANSFERS_MODULE.md`: vision completa del modulo.
- `docs/AUDIT_2026-07-11/06_TRASLADOS.md`: audit original.
- `docs/PLAN_MODULO_TRASLADOS_LOGISTICOS_2026-07-09.md`: plan original.
