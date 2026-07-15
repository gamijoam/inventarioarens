# INVENTORY_TRANSFERS_MODULE

> Modulo de Traslados (InventoryTransfers intra-tenant +
> InventoryTransferRequests inter-tenant). Estado: 2026-07-15.
> Backend completo (FASE T1+T2) + Frontend completo (FASE T3+T4).
> Documentacion + tests finales: FASE T5.

## Vision general

El modulo de Traslados modela el movimiento de mercancia entre
almacenes. Soporta dos tipos:

- **Interno** (intra-tenant): `InventoryTransfer` mueve stock entre
  dos almacenes del mismo tenant. Modalidad `simple` (directo) o
  `logistics` (4-etapas: preparar → despachar → recibir).
- **Externo** (inter-tenant): `InventoryTransferRequest` solicita
  movimiento entre dos tenants distintos. Solo se mueve stock cuando
  el tenant destino acepta la solicitud.

Ademas, el modulo soporta:

- **Transportista (driver)** opcional: nombre, documento, placa,
  empresa transportista, firmas digitales (driver + receptor).
- **Checklist interactivo** de preparacion y recepcion, item por
  item, con progreso global y por item.
- **Guia de traslado** imprimible (PDF via domPDF, HTML como fallback).
- **TenantTransferSetting** para activar `reserve_on_request`
  (reservar stock al crear) por tenant.

## Backend (FASE 0 pre-existente + FASE 1+T2 agregados)

### Modelos

- `InventoryTransfer` (intra-tenant):
  - Status: `requested`, `prepared`, `prepared_with_differences`,
    `dispatched`, `completed`, `completed_with_differences`, `cancelled`.
  - Relaciones: items, guide (1:1), driver (1:1), warehouses,
    user creator/preparer/dispatcher/receiver/canceller/resolver.
- `InventoryTransferItem`:
  - `quantity`, `requested_quantity`, `prepared_quantity`,
    `received_quantity`, `difference_quantity`.
  - `serial_units`, `prepared_product_unit_ids`,
    `received_product_unit_ids` (arrays de IMEIs/seriales).
  - `out_stock_movement_id`, `in_stock_movement_id` (FKs a
    `stock_movements`).
  - `resolution_status`, `resolution_notes` (para resolver diferencias).
- `InventoryTransferGuide` (1:1): status generados por el flujo.
- `InventoryTransferChecklist` + `InventoryTransferChecklistItem`:
  checklist de preparacion y recepcion con `expected_quantity`,
  `checked_quantity`, `difference_quantity`, `expected_product_unit_ids`,
  `checked_product_unit_ids`.
- `InventoryTransferDriver` (1:1, FASE T1): datos del transportista
  + firmas digitales. Ver `app/Modules/InventoryTransfers/Models/
  InventoryTransferDriver.php`.
- `TenantTransferSetting`: `validation_mode` default,
  `reserve_on_request` (FASE T1 implementado), `require_preparation_checklist`,
  `require_reception_checklist` (settings del tenant).
- `InventoryTransferRequest` (inter-tenant, modelo separado):
  `origin_tenant_id`, `destination_tenant_id`, `requested_by`,
  `responded_by`, status `requested|rejected|cancelled|completed`.

### Endpoints (intra-tenant, `routes.php`)

| Metodo | Ruta | Accion | Permiso |
|---|---|---|---|
| GET | `/api/inventory-transfers` | `index` (paginado + filtros) | `inventory_transfers.view` |
| POST | `/api/inventory-transfers` | `store` (crear draft) | `inventory_transfers.create` |
| GET | `/api/inventory-transfers/{id}` | `show` | `inventory_transfers.view` |
| POST | `/api/inventory-transfers/{id}/prepare` | `prepare` (solo logistics) | `inventory_transfers.prepare` |
| POST | `/api/inventory-transfers/{id}/dispatch` | `dispatch` (solo logistics) | `inventory_transfers.dispatch` |
| POST | `/api/inventory-transfers/{id}/receive` | `receive` (solo logistics) | `inventory_transfers.receive` |
| POST | `/api/inventory-transfers/{id}/cancel` | `cancel` | `inventory_transfers.cancel` |
| POST | `/api/inventory-transfers/{id}/resolve-differences` | `resolveDifferences` | `inventory_transfers.resolve_differences` |
| PUT | `/api/inventory-transfers/{id}/driver` | `assignDriver` (FASE T1) | `inventory_transfers.assign_driver` |
| DELETE | `/api/inventory-transfers/{id}/driver` | `removeDriver` (FASE T1) | `inventory_transfers.assign_driver` |
| GET | `/api/inventory-transfers/{id}/checklist/{stage}` | `showChecklist` (FASE T1) | `inventory_transfers.view` |
| POST | `/api/inventory-transfers/{id}/checklist/{stage}/items/{itemId}/check` | `checkChecklistItem` (FASE T1) | `inventory_transfers.verify` |
| GET | `/api/inventory-transfers/{id}/guide.pdf` | `pdf` (FASE T2) | `inventory_transfers.view` |
| GET | `/api/inventory-transfers/{id}/guide.html` | `html` (FASE T2) | `inventory_transfers.view` |

### Endpoints (inter-tenant, `InventoryTransferRequest`)

Ver `docs/AUDIT_2026-07-11/06_TRASLADOS.md` seccion 5.

## Frontend (FASE T3+T4)

### Estructura

```
frontend/src/features/transfers/
├── schemas.ts                  # Zod: TransferSchema, TransferItemSchema,
│                              #   StoreTransferSchema, ReceiveTransferSchema,
│                              #   AssignDriverSchema, CheckChecklistItemSchema,
│                              #   ChecklistPayloadSchema, TransferListFiltersSchema.
├── api.ts                      # 13 hooks: useTransfers, useTransfer, useCreate,
│                              #   usePrepare, useDispatch, useReceive,
│                              #   useCancel, useResolve, useAssignDriver,
│                              #   useRemoveDriver, useChecklist, useCheckChecklistItem,
│                              #   useTransferDriver.
├── TransfersManager.tsx        # Listado + filtros + cancelar (dialog inline).
└── components/
    ├── TransferSummary.tsx            # Card visual del transfer (Stepper + totales).
    ├── TransferReceiveDialog.tsx      # Dialog de recibir (cantidad o IMEIs).
    ├── TransferAssignDriverDialog.tsx # Dialog de asignar transportista.
    ├── TransferChecklistTab.tsx       # Checklist interactivo con checkboxes.
    └── TransferGuidePanel.tsx         # Botones descargar PDF / ver HTML.

frontend/src/routes/_authed/transfers/
├── transfers.tsx               # Pagina listado.
└── $transferId.tsx             # Pagina detalle con tabs.

frontend/src/lib/apiBaseUrl.ts   # Helper para construir URLs del API.
```

### Permisos (catalogo frontend)

| Permiso backend | Constante frontend | Uso |
|---|---|---|
| `inventory_transfers.view` | `INVENTORY_TRANSFERS_VIEW` | Listado, detalle, ver PDF. |
| `inventory_transfers.create` | `INVENTORY_TRANSFERS_CREATE` | Crear nuevo traslado. |
| `inventory_transfers.prepare` | `INVENTORY_TRANSFERS_PREPARE` | Marcar como preparado. |
| `inventory_transfers.dispatch` | `INVENTORY_TRANSFERS_DISPATCH` | Despachar. |
| `inventory_transfers.receive` | `INVENTORY_TRANSFERS_RECEIVE` | Recibir mercancia. |
| `inventory_transfers.cancel` | (sin constante, usa `INVENTORY_TRANSFERS_CREATE`) | Cancelar. |
| `inventory_transfers.resolve_differences` | (sin constante) | Resolver diferencias. |
| `inventory_transfers.assign_driver` | (sin constante, FASE T1 nuevo) | Asignar/quitar transportista. |
| `inventory_transfers.verify` | (sin constante, FASE T1 nuevo) | Marcar items del checklist. |

## Gaps cerrados (audit 2026-07-11)

- **C1 (estados fantasma)**: removidos `in_preparation`, `in_reception`,
  `rejected` del modelo. `AdminTransferService::availableActionsFor()` y
  `statusLabels()` actualizados.
- **C3 (`reserve_on_request`)**: implementado. Cuando el
  `TenantTransferSetting.reserve_on_request` esta activo, al crear
  un logistics se reserva el stock inmediatamente.
- **H2 (prepare con 0 items)**: bloquea prepare si todos los items
  tienen prepared_quantity = 0. Permite partial-zero (al menos uno > 0).
- **H4 + H5**: documentacion sincronizada con codigo.

## Nuevos componentes (FASE T1+T2)

- `InventoryTransferDriver` (modelo + tabla + Resource + endpoints PUT/DELETE).
- `InventoryTransferChecklist` endpoints GET / POST para manipular
  item por item.
- `TransferGuidePdfService` + `InventoryTransferGuideController` para
  generar PDFs via `barryvdh/laravel-dompdf`.
- `resources/views/inventory_transfers/guide.blade.php` (template de
  la guia con header, items, transportista, firmas).

## Variantes y modos

| Variante | Status | Soporte | Como |
|---|---|---|---|
| **Interno (intra-tenant) `simple`** | unico paso: `created → completed` | ✅ | `validation_mode=simple` al crear. |
| **Interno (intra-tenant) `logistics`** | 4-etapas: `requested → prepared → dispatched → completed` | ✅ | `validation_mode=logistics` al crear. |
| **Externo (inter-tenant)** | `requested → completed` (via accept) | ✅ (modulo `InventoryTransferRequest`) | `destination_tenant_slug` o `destination_user_email` al crear. |
| **Transportista (driver) opcional** | datos + firmas | ✅ | PUT `/inventory-transfers/{id}/driver`. |
| **Checklist interactivo (preparation + reception)** | required si `require_*_checklist` esta activo | ✅ | GET + POST `/checklist/{stage}/items/{itemId}/check`. |
| **Guia PDF / HTML** | disponible si status >= prepared | ✅ | GET `/guide.pdf` o `/guide.html`. |

## Frontend UX

### Listado (`/transfers`)

Tabla con busqueda + filtros (status, validation_mode) + acciones
rapidas: Recibir (si prepared/partial), Cancelar (si draft/prepared).
Badges contextuales por status.

### Detalle (`/transfers/$transferId`)

Tabs:
- **General**: `TransferSummary` con stepper, totales, info de
  warehouses, transportista (si existe), items.
- **Items**: tabla con pedido/preparado/recibido/diferencia por item.
- **Checklist** (solo si logistics): 2 secciones (preparation +
  reception) con `TransferChecklistTab`. Cada item tiene un checkbox
  o UnitCounter que al clickear envia POST a `/checklist/{stage}/items/{itemId}/check`.
- **Guia**: `TransferGuidePanel` con botones para descargar PDF o ver
  HTML (solo si status >= prepared).

Acciones del header (visibles segun estado):
- **Recibir**: abre `TransferReceiveDialog` (si status permite).
- **Asignar transportista**: abre `TransferAssignDriverDialog`
  (si no tiene driver).
- **Cancelar**: dialog inline con motivo obligatorio (min 5 chars).

## Fases del desarrollo

| Fase | Commit | Descripcion |
|---|---|---|
| 0 (pre-existente) | varios | Backend completo del modulo (controllers, services, policies, tests, sync). |
| T1 | `722e79a` | Fixes + gaps: estados fantasma, H2, reserve_on_request, driver, checklist. |
| T2 | `fd0811` | Generador de PDF guia (domPDF + blade). |
| T3 | `ad4cb8` | Frontend schemas + API + manager (listado + filtros). |
| T4 | `802ddd5` | Frontend detalle + checklist + recibir + PDF download. |
| T5 | (este doc) | Documentacion + tests finales. |

## Tests

### Backend (`tests/Feature/InventoryTransfers/`)

- `InventoryTransferApiTest.php` (48): CRUD, lifecycle logistics,
  serializados, cross-tenant, cancel, resolve, sync, permissions.
- `TenantTransferSettingModelTest.php` (5).
- `NextSequenceRaceConditionFixTest.php` (6).
- `DispatchGuardTest.php` (4): dispatch con 0 items rechazado.
- `PrepareZeroGuardTest.php` (3, FASE T1): prepare con 0 items.
- `InventoryTransferDriverApiTest.php` (6, FASE T1): CRUD driver.
- `ReserveOnRequestTest.php` (2, FASE T1): reserva automatica.
- `TransferGuidePdfTest.php` (4, FASE T2): HTML/PDF render, driver data,
  escape XSS, magic bytes PDF.

**Total InventoryTransfers: 78 tests OK.**

### Backend (`tests/Feature/InventoryTransferRequests/`)

- `InventoryTransferRequestApiTest.php` (5): cross-tenant flow,
  isolation, reject, cancel.

### Frontend (`frontend/src/features/transfers/`)

- `schemas.test.ts` (futuro): tests de los schemas Zod.

## Pendientes (roadmap del modulo)

- **Imprimir PO** con logo de la empresa (FASE futura).
- **Validacion de IMEIs via scanner** (camara) en la UI.
- **Notificaciones push** cuando el transportista firma o el destino
  acepta una solicitud cross-tenant.
- **Audit log visual** de todas las transiciones de estado (similar a
  AdminPortal pero en el portal del tenant).
- **InventoryTransferRequest UI** (cross-tenant): el modulo existe en
  backend pero no tiene UI. Se entregaria como modulo aparte.

## Commits relacionados

- `722e79a` FASE T1.
- `fd0811` FASE T2.
- `ad4cb8` FASE T3.
- `802ddd5` FASE T4.

## Documentacion relacionada

- `docs/AUDIT_2026-07-11/06_TRASLADOS.md`: audit original con score 7.0/10.
- `docs/PLAN_MODULO_TRASLADOS_LOGISTICOS_2026-07-09.md`: plan original.
- `docs/FRONTEND_TRANSFERS_E2E.md`: flujo end-to-end del usuario.
