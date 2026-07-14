# Inventory Catalog API (Productos, Marcas, Categorías, Tags)

> Contrato API completo para el **frontend del modulo de inventario** (catalogos y stock min/max).
> Esta documentacion describe los endpoints, los shape de los JSON, los filtros del listado y los
> campos calculados (WAC, alerts). Consumir tal cual desde Postman o React/Vue.

---

## 1. Tablas agregadas

### 1.1. Migraciones nuevas (ordenadas)

| Archivo | Tabla | Proposito |
|---|---|---|
| `2026_07_14_010000_enhance_products_for_catalog.php` | `products` (alter) | Agrega `barcode`, `description`, `long_description`, `unit_of_measure`, `track_stock`, `min_stock`, `max_stock`, `reorder_quantity`, `average_cost`, `image_url` |
| `2026_07_14_010100_create_brands_table.php` | `brands` | Nueva tabla: marca del producto |
| `2026_07_14_010200_create_categories_table.php` | `categories` | Nueva tabla: categoria jerarquica (parent_id recursivo) |
| `2026_07_14_010300_create_tags_table.php` | `tags` | Nueva tabla: etiquetas |
| `2026_07_14_010400_create_product_tag_table.php` | `product_tag` | Pivot product <-> tag |
| `2026_07_14_010500_create_product_category_table.php` | `product_category` | Pivot product <-> category |
| `2026_07_14_010600_add_brand_id_to_products.php` | `products` (alter) | FK a `brands` (nullable, nullOnDelete) |

### 1.2. Nuevos modelos

| Modelo | Namespace | Relaciones clave |
|---|---|---|
| `App\Modules\Products\Models\Brand` | — | `hasMany(Product)` |
| `App\Modules\Products\Models\Category` | — | `belongsTo(parent)`, `hasMany(children)`, `belongsToMany(Product)` |
| `App\Modules\Products\Models\Tag` | — | `belongsToMany(Product)` |
| `App\Modules\Products\Models\Product` | (actualizado) | + `belongsTo(Brand)`, + `belongsToMany(Category)`, + `belongsToMany(Tag)` |

### 1.3. Nuevos servicios

| Servicio | Ubicacion | Funcion |
|---|---|---|
| `InventoryValuationService` | `app/Modules/Inventory/Services/` | Recalcula el **WAC** (Weighted Average Cost) de un producto desde los `stock_movements` |

---

## 2. Campos del modelo `Product` (catalogo completo)

```json
{
  "id": 1,
  "tenant_id": 1,
  "name": "iPhone 15",
  "description": "Smartphone Apple",
  "long_description": "<p>Flagship 2023</p>",
  "sku": "IPH15-128",
  "barcode": "0194253714750",
  "image_url": "https://example.com/iphone.jpg",

  "tracking_type": "serialized",          // "quantity" | "serialized"
  "unit_of_measure": "unit",               // "unit" | "kg" | "lt" | "m"
  "track_stock": true,

  "brand_id": 5,
  "brand": { "id": 5, "name": "Apple", "slug": "apple" },

  "categories": [
    { "id": 9, "name": "Phones", "slug": "phones", "full_path": "Electronica / Phones" }
  ],
  "tags": [
    { "id": 3, "name": "5G", "slug": "5g", "color": "#FF5500" }
  ],

  "base_price": 799.0,
  "sale_currency": "USD",
  "sale_exchange_rate_type_id": 1,
  "sale_exchange_rate_type": { "id": 1, "code": "BCV", "name": "...", "is_default": true, "is_active": true },

  "min_stock": 5,
  "max_stock": 100,
  "reorder_quantity": 50,
  "suggested_purchase": 95,         // solo presente en inventory-center (recalc en backend)

  "average_cost": 720.5,             // WAC recalculado por InventoryValuationService
  "average_cost_visible": true,      // false si el user no tiene permiso `finance.costs.view`

  "warranty_policy_id": 2,
  "warranty_policy": { "id": 2, "name": "Apple 1 ano", "duration_days": 365, "coverage_type": "manufacturer" },

  "can_change_tracking_type": false,
  "units_count": 0,
  "is_active": true,
  "created_at": "2026-07-14T...",
  "updated_at": "2026-07-14T..."
}
```

> **Nota de seguridad**: `average_cost` solo se muestra si el usuario tiene el permiso `finance.costs.view`.
> El campo `average_cost_visible` indica si el usuario actual puede verlo.

---

## 3. Endpoints del catalogo

### 3.1. `GET /api/products` — Listar productos

**Query params** (todos opcionales):

| Param | Tipo | Descripcion |
|---|---|---|
| `search` | string | Busca en `name`, `sku` y `barcode` (case-insensitive) |
| `brand_id` | int | Filtra por marca |
| `category_id` | int | Filtra por categoria (muestra productos en esa categoria o subcategorias NO, solo esa) |
| `tag_id` | int | Filtra por tag |
| `tracking_type` | string | `quantity` o `serialized` |
| `is_active` | bool | Default: `true` (solo activos). Pasar `false` para incluir inactivos |
| `page` | int | Default 1 |
| `limit` | int | 1-100, default 25 |

**Response 200**:
```json
{
  "data": [ { ...product }, { ...product } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 25, "to": 25, "total": 120 }
}
```

### 3.2. `POST /api/products` — Crear producto

```http
POST /api/products
Authorization: Bearer <token>
X-Tenant: mi-empresa
Content-Type: application/json
```

```json
{
  "name": "iPhone 15",
  "description": "Smartphone Apple",
  "long_description": "<p>Flagship 2023</p>",
  "sku": "IPH15-128",                     // opcional: si se omite se autogenera
  "barcode": "0194253714750",             // opcional, unico por tenant
  "image_url": "https://example.com/iphone.jpg", // opcional

  "tracking_type": "serialized",          // opcional, default "quantity"
  "unit_of_measure": "unit",              // opcional, default "unit"
  "track_stock": true,                    // opcional, default true

  "brand_id": 5,                          // opcional
  "category_ids": [9, 12],                // opcional
  "tag_ids": [3],                         // opcional

  "base_price": 799.00,                   // opcional
  "min_stock": 5,                         // opcional
  "max_stock": 100,                       // opcional
  "reorder_quantity": 50,                 // opcional

  "sale_currency": "USD",                 // opcional, default "USD"
  "sale_exchange_rate_type_id": 1,        // opcional
  "warranty_policy_id": 2,                // opcional

  "is_active": true                       // opcional, default true
}
```

> **NO** se puede asignar `average_cost` manualmente (es `prohibited`). El WAC se calcula solo.

### 3.3. `GET /api/products/{id}` — Detalle

**Response 200**: el objeto `Product` completo (ver seccion 2).

### 3.4. `PATCH /api/products/{id}` — Actualizar

Cualquier subconjunto de campos. Mismas reglas de validacion que el create.

### 3.5. `DELETE /api/products/{id}` — Soft delete

Marca `is_active=false` (no borra el registro). Requiere permiso `products.delete`.

### 3.6. `PATCH /api/products/{id}/categories` — Reemplazar categorias

```json
{
  "category_ids": [9, 10, 11]
}
```

Borra las anteriores y deja solo las listadas. Devuelve el array nuevo.

### 3.7. `PATCH /api/products/{id}/tags` — Reemplazar tags

Misma mecanica que categorias pero con `tag_ids`.

---

## 4. Endpoints de catalogos auxiliares

### 4.1. Marcas (`/api/brands`)

```
GET    /api/brands              -> lista paginada (search, is_active, limit)
POST   /api/brands              -> crea { name, slug, description?, is_active? }
GET    /api/brands/{id}         -> detalle + products_count
PATCH  /api/brands/{id}         -> edita
DELETE /api/brands/{id}         -> borra (cascade: products.brand_id = null)
```

**Response shape**:
```json
{
  "id": 5,
  "name": "Apple",
  "slug": "apple",
  "description": "Marca surcoreana",
  "is_active": true,
  "products_count": 12,
  "created_at": "...",
  "updated_at": "..."
}
```

### 4.2. Categorias (`/api/categories`)

```
GET    /api/categories          -> lista plana (search, parent_id, is_active, roots_only, limit)
GET    /api/categories/tree     -> arbol jerarquico con children[]
POST   /api/categories          -> crea { name, slug, parent_id?, description?, sort_order?, is_active? }
GET    /api/categories/{id}     -> detalle + parent + children
PATCH  /api/categories/{id}     -> edita
DELETE /api/categories/{id}     -> borra (cascade: products pierden la categoria)
```

**Response tree**:
```json
{
  "data": [
    {
      "id": 1, "parent_id": null, "name": "Electronica", "slug": "electronica",
      "sort_order": 0, "is_active": true,
      "parent": null,
      "children": [
        { "id": 9, "parent_id": 1, "name": "Phones", "slug": "phones", ... }
      ],
      "products_count": 8
    }
  ]
}
```

### 4.3. Tags (`/api/tags`)

```
GET    /api/tags                -> lista (search, limit)
POST   /api/tags                -> crea { name, slug, color? }    color = #RRGGBB
GET    /api/tags/{id}           -> detalle + products_count
PATCH  /api/tags/{id}           -> edita
DELETE /api/tags/{id}           -> borra
```

**Color**: codigo hexadecimal `#RRGGBB` (max 7 chars). Ej: `#FF5500`.

---

## 5. Costo promedio ponderado (WAC)

`InventoryValuationService::recalculate(Product $product)` recalcula `products.average_cost`
desde los `stock_movements` con `unit_cost` no nulo:

```
WAC = SUM(unit_cost * quantity para entradas) - SUM(unit_cost * quantity para salidas)
    dividido entre
    SUM(quantity para entradas)
```

Tipos de movimiento considerados: `purchase`, `purchase_return`, `adjustment_in`,
`adjustment_out`, `transfer_in`, `transfer_out`, `return_in`, `return_out`.

**Disparo automatico**: el caller debe invocar el service despues de cualquier `StockMovement`
de tipo `purchase` o `purchase_return`. Por ahora no se hace automaticamente (es opt-in para no
impactar performance). Recomendacion: dispararlo desde `PosCheckoutService::recordOrderSyncEvent`
o un Observer de `StockMovement`.

**Uso**:
```php
app(\App\Modules\Inventory\Services\InventoryValuationService::class)
    ->recalculate($product);
```

---

## 6. Permisos RBAC

Todos los endpoints requieren permisos. Los 4 roles base que los usan:

| Permiso | Operaciones |
|---|---|
| `products.view` | GET /products, GET /products/{id}, GET /brands, GET /categories, GET /tags |
| `products.create` | POST /products, POST /brands, POST /categories, POST /tags |
| `products.update` | PATCH /products, PATCH /brands/categories/tags, POST syncCategories/syncTags |
| `products.delete` | DELETE /products, /brands, /categories, /tags |
| `finance.costs.view` | Ver `average_cost` en el resource (campo `average_cost_visible` lo confirma) |

Los **6 roles predefinidos** tienen esta distribucion:

| Rol | products.* | finance.costs.view |
|---|---|---|
| Owner | todos | si |
| Administrador | todos | si |
| Gerente | view, create, update | no |
| Vendedor | view | no |
| Almacen | view | no |
| Auditor | view | no |

---

## 7. Contrato para el frontend (lo que tenes que implementar)

### 7.1. Pantalla de producto (form)

Inputs requeridos (marcados con \*):

- **Nombre** \* (string 1-255)
- **SKU** (string, autogenerado si se omite)
- **Barcode** (string, unico por tenant; usar lector de codigo de barras)
- **Descripcion corta** (textarea opcional)
- **Descripcion larga** (HTML opcional, max 50000)
- **Imagen URL** (URL opcional)
- **Marca** (select opcional, cargar de `/api/brands?is_active=true`)
- **Categorias** (multi-select opcional, tree de `/api/categories/tree`)
- **Tags** (multi-select opcional, typeahead de `/api/tags`)
- **Tipo de control** (radio: Cantidad / Serializado/IMEI)
- **Unidad de medida** (select: unit/kg/lt/m)
- **Trackear stock** (checkbox, default true)
- **Precio base** (number, default 0)
- **Moneda de venta** (select: USD/VES)
- **Tipo de tasa de venta** (select opcional, de `/api/currency/rate-types`)
- **Stock minimo** (number opcional, alerta cuando available <= min)
- **Stock maximo** (number opcional, alerta cuando available > max)
- **Cantidad a reordenar** (number opcional, sugerido de compra)
- **Politica de garantia** (select opcional, de `/api/warranty-policies`)
- **Activo** (checkbox, default true)

Validaciones en frontend (ademas del backend):

- `barcode` debe ser unico en el tenant (verificar con `GET /api/products?search=<barcode>`)
- `max_stock >= min_stock` si ambos estan definidos
- `reorder_quantity <= max_stock - min_stock` (si ambos estan definidos)

### 7.2. Pantalla de listado

- Usar `GET /api/products` con filtros server-side
- Mostrar badges visuales por estado de stock (out, low, critical, available, overstock)
- Columna "Costo promedio" solo si `average_cost_visible == true`

### 7.3. Pantalla de detalle

- Mostrar todas las relaciones: brand, categorias (con breadcrumb full_path), tags, garantia
- Mostrar `suggested_purchase` (cuanto comprar para llegar al max)
- Boton "Editar categorias" abre modal con tree + multi-select
- Boton "Editar tags" abre modal con typeahead

---

## 8. Ejemplos curl

```bash
# Crear marca
curl -X POST https://app.miinventariofacil.com/api/brands \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{"name":"Apple","slug":"apple"}'

# Crear categoria con jerarquia
curl -X POST https://app.miinventariofacil.com/api/categories \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{"name":"Phones","slug":"phones","parent_id":1}'

# Crear producto con todo el catalogo
curl -X POST https://app.miinventariofacil.com/api/products \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{
    "name":"iPhone 15",
    "sku":"IPH15-128",
    "barcode":"0194253714750",
    "tracking_type":"serialized",
    "brand_id":5,
    "category_ids":[9],
    "tag_ids":[3],
    "base_price":799.00,
    "min_stock":5,
    "max_stock":100,
    "reorder_quantity":50,
    "sale_currency":"USD"
  }'

# Filtrar productos por categoria + marca + search
curl "https://app.miinventariofacil.com/api/products?category_id=9&brand_id=5&search=iphone&is_active=true&page=1&limit=25" \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa"

# Sincronizar categorias de un producto
curl -X PATCH https://app.miinventariofacil.com/api/products/23/categories \
  -H "Authorization: Bearer $TOKEN" -H "X-Tenant: mi-empresa" \
  -H "Content-Type: application/json" \
  -d '{"category_ids":[9,12]}'
```

---

## 9. Migracion de datos (si ya tienes productos)

Como las columnas nuevas son todas NULLABLE (excepto `unit_of_measure` que tiene default `unit`,
`track_stock` que tiene default `true`), no se necesita backfill obligatorio. Para los productos
existentes que ya tienen stock en `quantity_available`:

1. Asignar `min_stock` segun politica de la empresa (ej. 10% del promedio historico).
2. Asignar `max_stock` (ej. 30 dias de inventario a velocidad de venta).
3. Asignar `brand_id` / `category_ids` / `tag_ids` en bulk via PATCH individual o SQL directo.
4. `barcode` queda null hasta que se escanee con un lector o se importe de un proveedor.

Para recalcular `average_cost` masivamente:

```bash
php artisan tinker --execute='
    foreach (\App\Modules\Products\Models\Product::all() as $p) {
        app(\App\Modules\Inventory\Services\InventoryValuationService::class)->recalculate($p);
    }
'
```

---

## 10. Archivos modificados/creados (resumen para git)

```
database/migrations/
  2026_07_14_010000_enhance_products_for_catalog.php        (new)
  2026_07_14_010100_create_brands_table.php                (new)
  2026_07_14_010200_create_categories_table.php            (new)
  2026_07_14_010300_create_tags_table.php                  (new)
  2026_07_14_010400_create_product_tag_table.php           (new)
  2026_07_14_010500_create_product_category_table.php      (new)
  2026_07_14_010600_add_brand_id_to_products.php          (new)

app/Modules/Products/
  Models/Brand.php                                          (new)
  Models/Category.php                                       (new)
  Models/Tag.php                                            (new)
  Models/Product.php                                        (updated)
  Resources/BrandResource.php                               (new)
  Resources/CategoryResource.php                            (new)
  Resources/TagResource.php                                 (new)
  Resources/ProductResource.php                             (updated)
  Controllers/BrandController.php                           (new)
  Controllers/CategoryController.php                        (new)
  Controllers/TagController.php                             (new)
  Controllers/ProductController.php                         (updated)
  Requests/StoreBrandRequest.php                           (new)
  Requests/UpdateBrandRequest.php                          (new)
  Requests/StoreCategoryRequest.php                        (new)
  Requests/UpdateCategoryRequest.php                       (new)
  Requests/StoreTagRequest.php                             (new)
  Requests/UpdateTagRequest.php                            (new)
  Requests/StoreProductRequest.php                         (updated)
  Requests/UpdateProductRequest.php                        (updated)
  routes_catalog.php                                       (new)

app/Modules/Inventory/Services/InventoryValuationService.php (new)

app/Modules/InventoryCenter/
  Services/InventoryAlertService.php                        (new, ver docs/INVENTORY_ALERTS_API.md)
  Controllers/InventoryCenterController.php                 (updated)
  Requests/ReorderSuggestionsRequest.php                    (new)

tests/Feature/Products/
  BrandApiTest.php                                          (new)
  CategoryApiTest.php                                       (new)
  TagApiTest.php                                            (new)
  ProductCatalogApiTest.php                                 (new)

tests/Feature/Inventory/
  InventoryValuationTest.php                                (new)

tests/Feature/InventoryCenter/
  InventoryAlertsTest.php                                   (new)

docs/INVENTORY_CATALOG_API.md                              (this file)
```