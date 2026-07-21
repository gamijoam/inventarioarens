# Imagenes de producto - Nivel 2 (multi-imagen con galeria)

> **Status**: Fase 1 (backend core) completa. Falta Fase 2 (frontend) y Fase 3
> (download worker en local). La subida, persistencia, generacion de 3 variantes
> WebP, outbox events y aplicador de sync estan funcionando.

## Arquitectura

```
[Upload UI]                       [Cloud / VPS]                       [Local node]
  POST /api/products/{id}/images
  (multipart image[])
                  │
                  ▼
        ┌─────────────────────────────────────────┐
        │ ProductImageController                  │
        │  - validate (UploadProductImageRequest) │
        │  - authorize (ProductPolicy@update)      │
        │  - dispatch ProductImageService         │
        └────────────────┬────────────────────────┘
                         ▼
        ┌─────────────────────────────────────────┐
        │ ProductImageService                      │
        │  1) ImageProcessor::analyze             │
        │     - sha256, dims, mime                 │
        │  2) Dedup check                          │
        │  3) ImageProcessor::generateVariants    │
        │     - original 2048px max                │
        │     - medium 800x800 cover crop          │
        │     - thumb 200x200 cover crop           │
        │     - formato: WebP (fallback JPG)       │
        │  4) DB transaction:                      │
        │     - ProductImage.create (uuid auto)   │
        │     - 3 ProductImageVariant.create       │
        │     - 4 files en disk 'product-images'  │
        │  5) SyncCatalogOutboxService.imageUploaded │
        └────────────────┬────────────────────────┘
                         │
                         ▼
   DB product_images     sync_outbox event     storage/app/public/products/{tid}/{yyyy}/{mm}/{uuid}.webp
                         │
                         ▼
              (Fase 3 pendiente) SyncDownloadService en local
              descarga el archivo y crea fila local
```

## Stack

| Componente | Tecnologia |
|---|---|
| Disk storage (VPS) | `local` driver, `storage/app/public/products`, symlink public/storage |
| Disk storage (local node sync cache) | `local` driver, `storage/app/synced-images/products` (Fase 3) |
| Variants engine | GD nativo PHP 8.4 (`gd2` con WebP), sin Intervention |
| MIME types aceptados | `image/jpeg`, `image/png`, `image/webp` |
| Max input | 5 MB, 4096x4096 px (configurable via `ImageProcessor`) |
| Original max lado | 2048 px (resize manteniendo aspect ratio) |
| Medium | 800x800 (cover crop, WebP q=80) |
| Thumb | 200x200 (cover crop, WebP q=75) |
| Fallback | Si GD WebP no esta disponible: JPG (misma calidad) |

## Tablas

### `product_images`
```sql
id, uuid (unique global), tenant_id, product_id, uploaded_by,
storage_path, mime, size, original_name, width, height, sha256,
alt, sort, is_primary, deleted_at, created_at, updated_at

indices: (tenant_id, product_id, sort), (tenant_id, sha256), (tenant_id, deleted_at)
unique: (tenant_id, id)  -- necesario para FKs hijas
FK compuesta: (tenant_id, product_id) -> products(tenant_id, id) cascadeOnDelete
```

### `product_image_variants`
```sql
id, tenant_id, product_image_id, variant (original|medium|thumb),
storage_path, mime, size, width, height, deleted_at, created_at, updated_at

indices: unique(tenant_id, product_image_id, variant), (tenant_id, variant)
FK compuesta: (tenant_id, product_image_id) -> product_images(tenant_id, id) cascadeOnDelete
```

## Permisos

- `products.image.upload` - puede subir y modificar imagenes.
- `products.image.delete` - puede eliminar imagenes.
- Asignados a: `Owner`, `Administrador`, `Gerente`, `Almacen`.

## Endpoints

```
GET    /api/products/{product}/images           - index (galeria del producto)
POST   /api/products/{product}/images           - upload (multipart 'image')
PATCH  /api/products/{product}/images/reorder    - reorder por array 'ordered_ids'
PATCH  /api/products/{product}/images/{image}   - update (alt/sort/is_primary)
DELETE /api/products/{product}/images/{image}   - destroy (soft delete)
```

## Sync events (Fase 1 outbox, Fase 3 download local)

- `product.image.uploaded` - replica al local con cloud_url + sha256 + variants.
- `product.image.updated`   - replica cambios de metadata (alt/sort/is_primary).
- `product.image.deleted`   - replica soft delete.

Payload ejemplo de `product.image.uploaded`:
```json
{
  "uuid": "37e4b97e-c7c9-4dca-...",
  "product_id": 1,
  "cloud_url": "https://app.miinventariofacil.com/storage/products/2/2026/07/abc.webp",
  "mime": "image/webp", "size": 11358, "width": 2048, "height": 1365,
  "sha256": "568e265c...",
  "alt": "demo alt",
  "sort": 0, "is_primary": true,
  "variants": {
    "thumb":   {"cloud_url": ".../thumb.webp", "size": 244, "width": 200, "height": 200},
    "medium":  {"cloud_url": ".../medium.webp", "size": 2154, "width": 800, "height": 800},
    "original":{"cloud_url": ".../abc.webp", "size": 11358, "width": 2048, "height": 1365}
  }
}
```

## Pendiente por fase

### Fase 2 (frontend) - NO implementada en este commit
- `frontend/src/features/inventory-center/components/ImagePicker.tsx` (file input + drag-drop + paste).
- `frontend/src/features/inventory-center/components/ImageGallery.tsx` (drag-drop reorder + set primary + delete).
- `frontend/src/features/inventory-center/components/ProductImage.tsx` (variant-aware).
- Integracion en `ProductForm.tsx` (reemplaza el `<Field name="image_url">` actual).
- Thumbnail en `ProductSearchPanel` del POS.

### Fase 3 (proxy + download) - NO implementada en este commit
- `LocalImageProxyController` (`GET /api/images/{uuid}`): busca en `Storage::disk('synced-images')`,
  fallback 302 al cloud si no esta cacheado. Requiere UFW abierto al host loopback (ya hecho en VPS nuevo).
- `SyncDownloadService::downloadImage(tenantId, imageUuid)`: HTTP GET al cloud_url, verifica sha256,
  escribe en `synced-images`. Idempotente (sha256 match = skip).
- `images:download` artisan command: corre cada N minutos via systemd timer, descarga las que falten.
- Storage proxy via `app/Modules/Products/Controllers/LocalImageProxyController.php` + ruta en `routes/api.php`.

### Fase 4 (cleanup job) - NO implementada
- Job diario que borra archivos con `deleted_at > 30 days`.

## Tests

- `tests/Feature/Products/ProductImageApiTest.php` - 7 tests cubriendo upload happy path,
  index, set-primary, soft delete, cross-tenant 403, permission gate, validation 422.
  (No se corrieron en este entorno por falta de DB de testing; el codigo fue validado con
  upload manual via tinker + sync applier end-to-end via psql + artisan.)
