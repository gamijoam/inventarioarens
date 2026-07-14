# Inventory Alerts API (Stock Min/Max, Alertas, Reorder Suggestions)

> Contrato API para el dashboard de alertas del modulo de inventario y las recomendaciones de compra.
> Documenta los endpoints de estado de stock por producto, sugerencias de reorden y resumen de alertas.

---

## 1. Conceptos clave

### 1.1. Estados de stock (por producto)

| Estado | Codigo | Condicion |
|---|---|---|
| Sin stock | `out` | `available <= 0` |
| Critico | `critical` | `available > 0 AND available <= min_stock / 2` (cuando tiene `min_stock`) |
| Stock bajo | `low` | `available <= min_stock` (cuando tiene `min_stock`) o `available <= fallback_threshold` |
| Disponible | `available` | `available > min_stock` (o > threshold si no hay min_stock) |
| Sobre-stock | `overstock` | `available > max_stock` (cuando tiene `max_stock`) |

> Si el producto NO tiene `min_stock` configurado, se usa el `fallback_threshold` (default 3) que se pasa
> en el query string o en el body.

### 1.2. Cantidad sugerida de compra

```
suggested_purchase = max_stock - available    (si tiene max_stock)
suggested_purchase = min_stock - available    (si solo tiene min_stock, sin max)
suggested_purchase = reorder_quantity         (si tiene reorder_quantity pero no max/min)
suggested_purchase = null                    (sin configuracion de stock)
```

---

## 2. Endpoints

### 2.1. `GET /api/inventory-center/products/{id}/stock-status`

Estado detallado del stock de un producto especifico (suma de todos los almacenes).

**Response 200**:
```json
{
  "data": {
    "product_id": 1,
    "product_name": "iPhone 15",
    "sku": "IPH15-128",

    "available": 3.0,
    "reserved": 1.0,
    "damaged": 0.0,
    "physical": 4.0,

    "min_stock": 5,
    "max_stock": 100,
    "reorder_quantity": 50,

    "suggested_purchase": 97,
    "status": "critical",
    "status_label": "Critico",

    "has_min_stock": true,
    "has_max_stock": true
  }
}
```

**Para el frontend**: usar `status` para mostrar badge de color:
- `out` → rojo
- `critical` → rojo
- `low` → amarillo
- `available` → verde
- `overstock` → gris

### 2.2. `GET /api/inventory-center/reorder-suggestions`

Lista priorizada de productos a reordenar. Ordena por criticidad
(menor ratio `available / min_stock` primero).

**Query params**:

| Param | Tipo | Default | Descripcion |
|---|---|---|---|
| `warehouse_id` | int | null | Filtrar por un almacen especifico |
| `limit` | int | 50 | Maximo 200 |

**Response 200**:
```json
{
  "data": {
    "data": [
      {
        "product_id": 1,
        "product_name": "iPhone 15",
        "sku": "IPH15-128",
        "available": 3,
        "reserved": 0,
        "min_stock": 5,
        "max_stock": 100,
        "reorder_quantity": null,
        "suggested_purchase": 97,
        "status": "critical",
        "status_label": "Critico",
        "gap_to_min": 2
      }
    ],
    "summary": {
      "total_suggestions": 1,
      "critical_count": 1,
      "low_count": 0,
      "out_count": 0
    }
  }
}
```

**Notas**:
- Solo incluye productos activos con `track_stock=true` que tengan `min_stock` configurado.
- `gap_to_min` = `min_stock - available` (cuanto falta para llegar al minimo).
- `suggested_purchase` ya calculado segun las reglas de la seccion 1.2.

### 2.3. `GET /api/inventory-center/alerts-summary`

Resumen global de alertas para el dashboard. Ideal para cards de resumen en el header.

**Query params**:

| Param | Tipo | Default | Descripcion |
|---|---|---|---|
| `fallback_threshold` | float | 3 | Threshold para productos sin min_stock propio |

**Response 200**:
```json
{
  "data": {
    "out_count": 3,
    "low_count": 5,
    "with_min_stock_count": 28,
    "fallback_threshold": 3
  }
}
```

**Para el frontend**:
- `out_count` → badge rojo critico
- `low_count` → badge amarillo
- `with_min_stock_count` → cantidad de productos configurados con minimo

### 2.4. `GET /api/inventory-center/summary` (existente, actualizado)

Este endpoint ya existia pero ahora considera `min_stock` y `max_stock` por producto.

**Query params**:

| Param | Tipo | Default | Descripcion |
|---|---|---|---|
| `search` | string | null | Busca en `name`, `sku`, `barcode`, y `product_units.serial_number` |
| `tracking_type` | string | null | `quantity` o `serialized` |
| `stock_status` | string | `all` | `available`, `low`, `out`, `overstock`, `all` |
| `active_status` | string | `active` | `active`, `inactive`, `all` |
| `low_stock_threshold` | float | 3 | Fallback para productos sin min_stock |
| `page` | int | 1 | |
| `limit` | int | 24 | 1-50 |

**Response 200** (resumen):
```json
{
  "data": {
    "filters": { ... },
    "metrics": {
      "total_products": 28,
      "serialized_products": 5,
      "quantity_products": 23,
      "available_quantity": 1520.5,
      "reserved_quantity": 12.0,
      "damaged_quantity": 3.0,
      "low_stock_count": 5,
      "without_stock_count": 3,
      "with_min_stock_count": 28
    },
    "alerts": [
      {
        "type": "low_stock",
        "severity": "warning",
        "title": "Stock bajo",
        "count": 5,
        "message": "Productos por debajo del minimo operativo.",
        "action": "Revisar reposicion o traslado.",
        "product_names": ["iPhone 15", "Samsung A06", "Cable USB-C"]
      },
      {
        "type": "without_stock",
        "severity": "danger",
        "title": "Sin stock",
        "count": 3,
        "message": "Productos activos sin disponibilidad.",
        "action": "Reponer o desactivar si ya no se venden.",
        "product_names": ["Xiaomi Serial", "Cargador 20W", "Audifonos"]
      }
    ],
    "products": [ ... ],   // paginado
    "pagination": { ... }
  }
}
```

**Cambios importantes**:
- `metrics.low_stock_count` ahora usa `min_stock` por producto (no threshold global).
- `products[].stock.status` puede ser ahora uno de: `available`, `low`, `critical`, `out`, `overstock`.
- `products[].stock.suggested_purchase` indica cuanto comprar.

---

## 3. Ejemplos curl

```bash
# Ver estado de stock de un producto
curl https://app.miinventariofacil.com/api/inventory-center/products/1/stock-status \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa"

# Ver sugerencias de reorden
curl "https://app.miinventariofacil.com/api/inventory-center/reorder-suggestions?warehouse_id=1&limit=20" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa"

# Ver resumen de alertas
curl https://app.miinventariofacil.com/api/inventory-center/alerts-summary \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa"

# Filtrar productos sobre-stock
curl "https://app.miinventariofacil.com/api/inventory-center/summary?stock_status=overstock" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa"
```

---

## 4. Permisos RBAC

| Endpoint | Permiso requerido |
|---|---|
| `GET /inventory-center/products/{id}/stock-status` | `products.view` (Gate::authorize en el controller) |
| `GET /inventory-center/reorder-suggestions` | `inventory.view` |
| `GET /inventory-center/alerts-summary` | `inventory.view` |
| `GET /inventory-center/summary` | `inventory.view` |

---

## 5. Contrato para el frontend (recomendaciones de UI)

### 5.1. Dashboard principal

```tsx
// Card: Resumen de alertas
GET /api/inventory-center/alerts-summary
→ <AlertCard severity="danger" count={alerts.out_count} label="Sin stock" />
→ <AlertCard severity="warning" count={alerts.low_count} label="Stock bajo" />
→ <MetricCard label="Con min configurado" value={alerts.with_min_stock_count} />
```

### 5.2. Tab de "Reorden" en el dashboard

```tsx
// Lista priorizada
GET /api/inventory-center/reorder-suggestions
→ <ReorderTable rows={data.data} />  // ordenado por criticidad
→ <SummaryRow summary={data.summary} />
```

Cada fila debe mostrar:
- Producto (nombre + SKU + link al detalle)
- Stock actual (`available` / `min_stock`)
- Gap (`gap_to_min`)
- Cantidad sugerida (`suggested_purchase`)
- Badge de estado (`status` con color segun `status_label`)

### 5.3. Tab "Stock bajo" en el inventario

```tsx
GET /api/inventory-center/summary?stock_status=low&page=1
→ <ProductsTable rows={data.products} />
   filtros: paginacion server-side, search, stock_status
```

### 5.4. Indicador visual en la lista de productos

Cada producto del listado puede tener un badge de stock status:
- `out` → rojo solido
- `critical` → rojo outline
- `low` → amarillo
- `available` → (sin badge)
- `overstock` → gris (opcional)

### 5.5. Acciones sugeridas

- Boton "Crear OC" en cada fila de reorden (futuro, hoy no hay endpoint)
- Boton "Ver Kardex" abre `/api/kardex/products/{id}?date_from=YYYY-MM-DD`
- Boton "Ajustar stock" abre flujo de ajuste manual (`POST /api/inventory/adjustments/in`)

---

## 6. Archivos del backend

```
app/Modules/InventoryCenter/
  Services/InventoryAlertService.php          (new — logica central)
  Controllers/InventoryCenterController.php   (updated — 3 endpoints nuevos)
  Requests/ReorderSuggestionsRequest.php      (new)

app/Modules/Inventory/Services/InventoryValuationService.php (new — WAC)

tests/Feature/InventoryCenter/InventoryAlertsTest.php   (new — 7 tests)

docs/INVENTORY_ALERTS_API.md                             (this file)
```

### Cambios en InventoryCenterSummaryService

- `metrics.low_stock_count`: usa `min_stock` por producto, con fallback al threshold global solo
  para productos sin `min_stock` configurado.
- `products[].stock.status`: ahora incluye `critical` (mitad del min) y `overstock` (sobre el max).
- `products[].stock.suggested_purchase`: nuevo campo calculado server-side.
- `products[].min_stock` / `max_stock`: ahora vienen en cada fila del summary.
- `metrics.with_min_stock_count`: nuevo contador.
- Filtro `stock_status=overstock`: nuevo.
- `search`: incluye `barcode` en la busqueda.
