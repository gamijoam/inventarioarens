# Inventory Phase 3 API (Cycle Count + Locations + Alert History)

> Contrato API para la **fase 3 del modulo de inventario**: ubicaciones fisicas en almacen,
> conteo fisico (cycle count) e historial de alertas. Documenta los endpoints, los shape de los
> JSON y los flujos completos (snapshot -> start -> capture -> complete).

---

## 1. Tablas agregadas (Fase 3)

| Archivo | Tabla | Proposito |
|---|---|---|
| `2026_07_14_020000_create_warehouse_locations_table.php` | `warehouse_locations` | Ubicaciones fisicas en almacen (jerarquicas: pasillo > estante > cajon) |
| `2026_07_14_020100_add_location_id_to_stock_balances.php` | `stock_balances` (alter) | FK nullable a `warehouse_locations`, indice UNIQUE parcial con/sin location |
| `2026_07_14_020200_create_stock_counts_table.php` | `stock_counts` | Cabecera de conteo fisico (status, scheduled_at, started/completed_at, creator, approver) |
| `2026_07_14_020300_create_stock_count_items_table.php` | `stock_count_items` | Items del conteo con system_quantity vs counted_quantity vs variance |
| `2026_07_14_020400_create_alert_history_table.php` | `alert_history` | Historial de alertas (low_stock, out_of_stock) con payload JSON y dismissed_at |

---

## 2. Ubicaciones fisicas (Warehouse Locations)

### 2.1. Modelo de datos

Una `WarehouseLocation` pertenece a un `Warehouse` y puede tener un `parent_id` para formar
jerarquia (e.g. `Pasillo A` > `Estante 1` > `Cajon 3`). Cada `StockBalance` puede tener una
`location_id` opcional para saber donde esta fisicamente el stock.

```
warehouse (Warehouse)
└── aisle (WarehouseLocation, parent_id=null)
    └── rack (WarehouseLocation, parent_id=aisle)
        └── bin (WarehouseLocation, parent_id=rack)
```

### 2.2. Endpoints

Todos bajo `/api/warehouses/{warehouse}/locations` con `api.auth + tenant`:

```
GET    /api/warehouses/{warehouse}/locations
POST   /api/warehouses/{warehouse}/locations
GET    /api/warehouses/{warehouse}/locations/{location}
PATCH  /api/warehouses/{warehouse}/locations/{location}
PUT    /api/warehouses/{warehouse}/locations/{location}
DELETE /api/warehouses/{warehouse}/locations/{location}
```

### 2.3. Filtros del listado

| Param | Descripcion |
|---|---|
| `search` | Busca en `name` y `code` (case-insensitive) |
| `is_active` | true/false (default todos) |
| `roots_only` | Solo ubicaciones raiz (parent_id null) |

### 2.4. Crear ubicacion

```json
POST /api/warehouses/1/locations
{
  "name": "Pasillo A",
  "code": "A",
  "aisle": "A",
  "rack": "1",
  "bin": "3",
  "level": "2",
  "capacity": 100,
  "parent_id": null
}
```

**Response 201**:
```json
{
  "data": {
    "id": 5,
    "warehouse_id": 1,
    "parent_id": null,
    "name": "Pasillo A",
    "code": "A",
    "aisle": "A",
    "rack": "1",
    "bin": "3",
    "level": "2",
    "capacity": 100,
    "is_active": true,
    "full_path": "Pasillo A",
    "parent": null,
    "children": []
  }
}
```

`full_path` concatena jerarquia: `"Pasillo A / Estante 1 / Cajon 3"`.

### 2.5. Ubicaciones anidadas

```json
POST /api/warehouses/1/locations
{
  "name": "Estante 1",
  "code": "A-E1",
  "parent_id": 5
}
```

### 2.6. Reglas de validacion

- `code` debe ser unico por tenant + warehouse (no global).
- `parent_id` debe pertenecer al mismo warehouse (si no, 422).
- `capacity` >= 0.
- `aisle`/`rack`/`bin`/`level` son opcionales (max 20 chars cada uno).

---

## 3. Cycle Count (conteo fisico)

### 3.1. Concepto

Permite hacer **inventarios fisicos** con el siguiente flujo:

1. **Crear** el conteo (status=`draft`).
2. **Snapshot** (opcional): copia el stock actual a `stock_count_items` para tener la base de comparacion.
3. **Start** (status=`capturing`): marca cuando el operario empieza a contar.
4. **Capture**: el operario registra el conteo fisico (counted_quantity por item).
5. **Complete**: genera automaticamente `StockMovement` de tipo `adjustment_in` o `adjustment_out`
   por la diferencia entre `system_quantity` y `counted_quantity`. Marca el conteo como
   `completed`.

### 3.2. Endpoints

Todos bajo `/api/stock-counts` con `api.auth + tenant`:

```
GET    /api/stock-counts
POST   /api/stock-counts
GET    /api/stock-counts/{count}
PATCH  /api/stock-counts/{count}
DELETE /api/stock-counts/{count}                       <- cancela el conteo

POST   /api/stock-counts/{count}/snapshot              <- copia stock a items
POST   /api/stock-counts/{count}/start                 <- draft -> capturing
POST   /api/stock-counts/{count}/capture               <- bulk captura
POST   /api/stock-counts/{count}/complete              <- genera adjustments
```

### 3.3. Filtros del listado

| Param | Descripcion |
|---|---|
| `warehouse_id` | int |
| `status` | `draft`, `capturing`, `completed`, `cancelled` |
| `count_type` | `full`, `category`, `spot` |

### 3.4. Crear conteo

```json
POST /api/stock-counts
{
  "warehouse_id": 1,
  "code": "CC-2026-Q3-001",
  "name": "Conteo completo Q3 2026",
  "count_type": "full",
  "scheduled_at": "2026-09-30",
  "notes": "Conteo mensual programado"
}
```

**Response 201**:
```json
{
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "code": "CC-2026-Q3-001",
    "name": "Conteo completo Q3 2026",
    "status": "draft",
    "count_type": "full",
    "scheduled_at": "2026-09-30",
    "stats": {
      "total_items": 0,
      "pending_items": 0,
      "counted_items": 0,
      "adjusted_items": 0,
      "with_variance": 0
    }
  }
}
```

### 3.5. Snapshot (opcional)

```
POST /api/stock-counts/1/snapshot
```

**Response 200**:
```json
{ "data": { "items_created": 28 } }
```

Crea un `stock_count_items` por cada (warehouse, product, location) con `quantity_available > 0` del
momento. Status del item: `pending`.

### 3.6. Start

```
POST /api/stock-counts/1/start
```

Cambia status de `draft` a `capturing`, setea `started_at = now()`.

### 3.7. Capture (bulk)

```
POST /api/stock-counts/1/capture
{
  "captures": [
    { "item_id": 12, "counted_quantity": 12, "notes": "OK" },
    { "item_id": 15, "counted_quantity": 8, "notes": "Faltaron 2 unidades" },
    { "item_id": 22, "counted_quantity": 0, "notes": "Sin stock" }
  ]
}
```

**Response 200**:
```json
{ "data": { "items_captured": 3 } }
```

Calcula automaticamente `variance = counted - system` y marca los items como `counted`.

### 3.8. Complete (genera adjustments)

```
POST /api/stock-counts/1/complete
```

**Response 200**:
```json
{
  "data": {
    "adjustments": {
      "in": 2,
      "out": 1,
      "skipped": 25
    },
    "completed_at": "2026-09-30T15:45:23+00:00"
  }
}
```

Por cada item contado con `variance != 0`:
- `variance > 0` (sobrante) -> crea `StockMovement` tipo `adjustment_in` con `quantity = variance`.
- `variance < 0` (faltante) -> crea `StockMovement` tipo `adjustment_out` con `quantity = |variance|`.
- `variance == 0` -> se skipea, no genera movimiento.

Todos los `StockMovement` quedan con `reference_type='stock_count'` y `reference_id={count.id}` para
trazabilidad. Tambien `reason = "Cycle count CC-..."`.

### 3.9. Cancelar

```
DELETE /api/stock-counts/1
```

Cambia status a `cancelled`. Solo se permite si no esta `completed`.

### 3.10. Recorrido completo de un conteo

```
1. POST   /api/stock-counts                -> crear (status=draft)
2. POST   /api/stock-counts/1/snapshot    -> items con system_quantity
3. POST   /api/stock-counts/1/start       -> status=capturing
4. (operario cuenta fisicamente)
5. POST   /api/stock-counts/1/capture     -> bulk captura
6. POST   /api/stock-counts/1/complete    -> genera adjustments + status=completed
```

---

## 4. Historial de alertas (Alert History)

### 4.1. Concepto

Persiste cada alerta detectada para que el usuario pueda ver el historial y descartarlas.
Si la misma alerta se detecta multiples veces en 24h, se deduplica (no se duplica).

### 4.2. Endpoints

Todos bajo `/api/alert-history` con `api.auth + tenant`:

```
GET    /api/alert-history
GET    /api/alert-history/{alert}
POST   /api/alert-history/{alert}/dismiss
```

### 4.3. Filtros del listado

| Param | Descripcion |
|---|---|
| `alert_type` | `product.out_of_stock`, `product.low_stock` |
| `severity` | `info`, `warning`, `danger` |
| `is_dismissed` | true (mostrar solo descartadas) o false (solo activas) |
| `subject_type` + `product_id` | Filtrar por producto especifico |
| `date_from` / `date_to` | Rango de fechas sobre `detected_at` |
| `page` / `limit` | Paginacion |

### 4.4. Shape del item

```json
{
  "data": [
    {
      "id": 1,
      "alert_type": "product.low_stock",
      "severity": "warning",
      "subject_type": "product",
      "subject_id": 42,
      "title": "Stock bajo",
      "message": "iPhone 15 (IPH15-128) tiene 3 unidades disponibles (minimo 5).",
      "payload": { "available": 3, "min_stock": 5 },
      "detected_at": "2026-09-30T15:00:00+00:00",
      "dismissed_at": null,
      "dismissed_by": null,
      "is_dismissed": false,
      "created_at": "2026-09-30T15:00:00+00:00"
    }
  ]
}
```

### 4.5. Dismiss

```
POST /api/alert-history/1/dismiss
```

Marca `dismissed_at = now()` y `dismissed_by = user_id`. Solo si `dismissed_at` es null (sino 409).

### 4.6. Disparar snapshot automatico

`AlertHistoryService::snapshotAlerts($tenantId)` escanea productos activos con stock bajo o
sin stock y crea los registros en `alert_history`. Pensado para llamarse desde:

- **Job programado** (cron diario a las 8am) - pendiente de implementar.
- **Manualmente** via tinker: `app(AlertHistoryService::class)->snapshotAlerts($tenant->id)`.

La deduplicacion es automatica: si la misma `(alert_type, subject_type, subject_id)` ya
existe en las ultimas 24h, no se crea otra.

---

## 5. Permisos RBAC

| Endpoint | Permiso |
|---|---|
| `GET/POST /warehouses/{w}/locations/*` | `warehouses.view` / `warehouses.update` |
| `GET/POST /stock-counts/*` | `inventory.view` / `inventory.adjust` |
| `GET/POST /alert-history/*` | (cualquier user autenticado; el dismiss requiere `inventory.adjust` recomendado) |

Recomendacion: en el frontend, los `Vendedor` no deberian ver cycle counts ni alert history.
Solo `Almacen`, `Gerente`, `Administrador`, `Owner`, `Auditor`.

---

## 6. Ejemplos curl completos

```bash
# Crear ubicacion raiz
curl -X POST "https://app.miinventariofacil.com/api/warehouses/1/locations" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{"name":"Pasillo A","code":"A","aisle":"A","rack":"1"}'

# Crear sub-ubicacion
curl -X POST "https://app.miinventariofacil.com/api/warehouses/1/locations" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{"name":"Estante 1","code":"A-E1","parent_id":5}'

# Crear conteo fisico
curl -X POST "https://app.miinventariofacil.com/api/stock-counts" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{
    "warehouse_id":1,
    "code":"CC-2026-Q3",
    "name":"Conteo Q3",
    "count_type":"full"
  }'

# Snapshot + start + capture + complete
curl -X POST "https://app.miinventariofacil.com/api/stock-counts/1/snapshot" -H "..." -H "..."
curl -X POST "https://app.miinventariofacil.com/api/stock-counts/1/start" -H "..." -H "..."
curl -X POST "https://app.miinventariofacil.com/api/stock-counts/1/capture" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{"captures":[{"item_id":12,"counted_quantity":12},{"item_id":15,"counted_quantity":8}]}'
curl -X POST "https://app.miinventariofacil.com/api/stock-counts/1/complete" -H "..." -H "..."

# Ver historial de alertas
curl "https://app.miinventariofacil.com/api/alert-history?severity=danger&is_dismissed=false" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa"
```

---

## 7. Contrato para el frontend (recomendaciones)

### 7.1. Pantalla de "Ubicaciones" en el detalle de un almacen

```
GET /api/warehouses/{id}/locations
```

Mostrar:
- Tree jerarquico con `parent_id` recursivo
- `full_path` como breadcrumb
- Boton "Nueva ubicacion" abre modal con campos `name, code, aisle, rack, bin, level, capacity`
- Drag & drop para reordenar (futuro, hoy solo PATCH)

### 7.2. Pantalla de "Conteo fisico"

```
GET /api/stock-counts
GET /api/stock-counts/{id}  -> con items loaded
```

Mostrar:
- Lista de conteos con status pill (draft/capturing/completed/cancelled)
- Detalle con tabs: Items (tabla con system vs counted vs variance) | Acciones
- Acciones contextuales segun status:
  - draft: [Snapshot] [Start] [Editar] [Cancelar]
  - capturing: [Capture Bulk] [Complete]
  - completed: [Ver] [Exportar CSV]

### 7.3. Pantalla de "Historial de alertas"

```
GET /api/alert-history
```

Mostrar:
- Lista con badges de severidad (info/warning/danger)
- Filtros: tipo, severidad, dismissed
- Accion "Dismiss" en cada alerta activa
- Accion "Dismiss All" bulk (futuro)

### 7.4. Inventario fisico desde el celular

Para operarios en almacen, idealmente:
- Vista mobile-first
- Escaneo de codigo de barras para ubicar el item
- Input numerico grande para `counted_quantity`
- Boton "Guardar y siguiente" automatico

---

## 8. Archivos creados / modificados (resumen para git)

```
database/migrations/
  2026_07_14_020000_create_warehouse_locations_table.php          (new)
  2026_07_14_020100_add_location_id_to_stock_balances.php        (new)
  2026_07_14_020200_create_stock_counts_table.php                (new)
  2026_07_14_020300_create_stock_count_items_table.php            (new)
  2026_07_14_020400_create_alert_history_table.php                (new)

app/Modules/Warehouses/
  Models/WarehouseLocation.php                                    (new)
  Resources/WarehouseLocationResource.php                         (new)
  Controllers/WarehouseLocationController.php                     (new)
  Requests/StoreWarehouseLocationRequest.php                     (new)
  Requests/UpdateWarehouseLocationRequest.php                    (new)

app/Modules/Inventory/
  Models/StockBalance.php                                         (updated — location_id + location() relation)
  Models/StockCount.php                                           (new)
  Models/StockCountItem.php                                       (new)
  Models/AlertHistory.php                                         (new)
  Services/StockCountService.php                                  (new)
  Services/AlertHistoryService.php                                (new)
  Resources/StockCountResource.php                                (new)
  Resources/StockCountItemResource.php                            (new)
  Resources/AlertHistoryResource.php                              (new)
  Controllers/StockCountController.php                            (new)
  Controllers/AlertHistoryController.php                          (new)
  Requests/StoreStockCountRequest.php                            (new)
  Requests/UpdateStockCountRequest.php                           (new)
  Requests/CaptureStockCountRequest.php                          (new)
  Requests/ListAlertHistoryRequest.php                           (new)
  routes_phase3.php                                              (new)

routes/api.php                                                    (updated — phase3 routes)

tests/Feature/Warehouses/
  WarehouseLocationApiTest.php                                    (new, 5 tests)

tests/Feature/Inventory/
  StockCountApiTest.php                                           (new, 6 tests)
  AlertHistoryServiceTest.php                                     (new, 4 tests)

docs/INVENTORY_PHASE3.md                                         (this file)
```