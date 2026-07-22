# Data Import API

API REST para importar catalogos desde archivos CSV. Permite a operadores tecnicos
(rol `Administrador` / `Owner`) o platform admins migrar catalogos completos desde
otro sistema de inventario a INVENTARIOARENS en una sola sesion.

## Conceptos

- **Sesion (`data_imports`)**: agrupa N imports por entidad dentro de un tenant.
  Tiene contador global (total/ok/skipped/failed), fechas de inicio/fin y
  archivo de reporte descargable.
- **Entidad (`data_import_entities`)**: una entidad importada dentro de la sesion
  (sucursales, almacenes, marcas, etc.). Cada entidad tiene su propio CSV subido,
  su status y sus contadores.
- **Fila (`data_import_rows`)**: resultado por fila del CSV (`ok`, `skipped`,
  `failed`). Permite descargar reporte detallado para correccion.

## Entidades soportadas

| Entity            | Headers CSV (orden sugerido)                                         |
| ----------------- | -------------------------------------------------------------------- |
| `branches`        | code, name, status                                                  |
| `warehouses`      | code, name, branch_code, status                                     |
| `brands`          | slug, name, description, is_active                                  |
| `categories`      | slug, name, parent_slug, description, sort_order, is_active         |
| `tags`            | slug, name, color                                                   |
| `products`        | sku, name, barcode, description, brand_slug, category_slugs, tag_slugs, unit_of_measure, tracking_type, base_price, sale_currency, min_stock, max_stock, reorder_quantity, is_active, stock_inicial, almacen_codigo, costo_unitario |
| `price_lists`     | code, name, description, is_default, is_active, sort_order, payment_method_codes, prices (JSON) |
| `payment_methods` | code, name, method, currency_mode, requires_reference, is_active, sort_order |
| `customers`       | document_type, document_number, name, phone, email, fiscal_address, is_active |
| `suppliers`       | document_type, document_number, name, phone, email, fiscal_address, notes, is_active |

Separadores aceptados: `,`, `;`, `\t`, `|`. La autodeteccion usa la primera
linea. Encoding: UTF-8, UTF-8-BOM, Windows-1252 (convertido automaticamente).

> **Importante**: cuando una columna contenga JSON (ej. `prices` en
> `price_lists`), usar separador `;` o un caracter que no aparezca dentro del
> JSON. Caso contrario el parser CSV rompe el campo. El backend no intenta
> adivinarlo para evitar corrupcion silenciosa.

## Politica de duplicados

`skip + continuar`: si la clave natural ya existe en el tenant (ej. SKU de
producto, code de sucursal, RIF de cliente), la fila se reporta como
`skipped` en el reporte y la importacion sigue. No se sobreescriben datos
existentes.

## Inventario inicial

La columna `stock_inicial` en `products` (combinada con `almacen_codigo` y
`costo_unitario`) crea un `ProductEntry` automatico por cada fila con stock
> 0. El `costo_unitario` actualiza el `average_cost` del producto via el
flujo normal de entradas (WAC recalculado).

## Endpoints

### Sesiones

```
POST   /api/import/sessions                      crea sesion (vacía)
GET    /api/import/sessions                      lista ultimas 50 sesiones del tenant
GET    /api/import/sessions/{id}                 detalle
DELETE /api/import/sessions/{id}                 elimina (solo si completed/failed/cancelled)
GET    /api/import/sessions/{id}/report          descarga CSV con todas las filas
```

### Wizard por entidad

```
POST   /api/import/sessions/{id}/entities/{entity}/upload
       multipart/form-data: file=@products.csv
       Sube el archivo. Crea/actualiza DataImportEntity.

POST   /api/import/sessions/{id}/entities/{entity}/run
       Ejecuta el import sincrónicamente. Devuelve summary:
         { total, ok, skipped, failed, status, error_summary[] }

GET    /api/import/sessions/{id}/entities/{entity}/rows?per_page=50
       Lista filas individuales con su resultado.
```

### Plantillas dinámicas

```
GET    /api/import/templates/{entity}
       Devuelve CSV con headers + 2-3 filas de ejemplo + (cuando aplica)
       3 valores reales del tenant (sucursales, marcas, etc.) para que el
       operador los referencie sin inventarlos.
       Separador: ',' por defecto. Para price_lists usa ';'.
```

### Platform admin (master)

```
POST   /api/master/import/tenants/{tenant}/sessions
POST   /api/master/import/tenants/{tenant}/sessions/{id}/entities/{entity}/upload
POST   /api/master/import/tenants/{tenant}/sessions/{id}/entities/{entity}/run
GET    /api/master/import/tenants/{tenant}/sessions/{id}/report
GET    /api/master/import/tenants/{tenant}/templates/{entity}
```

Permite a un platform admin importar en nombre de cualquier tenant sin
necesidad de membership. Pensado para soporte y onboarding corporativo.

## Permisos

- `data_import.view`     (todos los roles que pueden ver catalogos)
- `data_import.create`   (Gerente+)
- `data_import.execute`  (Gerente+)
- `data_import.delete`   (Gerente+)

Roles predefinidos:
- Owner, Administrador: todos los permisos.
- Gerente: view, create, execute.
- Auditor: solo view.
- Vendedor, Almacen: sin acceso (catalogos solo lectura implicita).

## Limites y validaciones

- Archivo: maximo 5 MB.
- Filas por archivo: maximo 5.000 (configurable via `CsvParser::MAX_ROWS`).
- Tiempo: ejecucion sincrona. Estimado < 30s para 5.000 filas simples.

## Reporte CSV descargable

Columnas: `fila,entidad,estado,clave_natural,id_resultado,errores`.

Las filas se listan en el orden en que fueron procesadas. Errores por campo se
unen con ` | ` para facil busqueda.

## Cleanup automatico

El comando `imports:cleanup --days=30` corre diariamente a las 03:00 AM (via
scheduler). Elimina sesiones completadas/fallidas/canceladas mas viejas que
30 dias, sus archivos en `storage/app/imports/`, y todas las filas asociadas.

Para correrlo manualmente:

```bash
php artisan imports:cleanup --days=30
php artisan imports:cleanup --days=7 --dry-run
```

## Frontend

La UI del wizard vive en `/import` (ruta autenticada). Componentes:

- `frontend/src/features/data-import/ImportWizard.tsx` — wizard de 4 pasos.
- `frontend/src/features/data-import/DataImportPage.tsx` — tabs nuevo/historial.
- `frontend/src/features/data-import/ImportSessionList.tsx` — tabla de sesiones.
- `frontend/src/features/data-import/ImportPreviewTable.tsx` — preview de las primeras 10 filas.
- `frontend/src/features/data-import/ImportRunResult.tsx` — cards de resultado + descarga.

Flujo UX:

1. Operador entra a `/import`.
2. Selecciona tipo de dato (ej. "Productos").
3. Descarga plantilla dinamica con valores reales del tenant.
4. Completa el CSV en Excel/LibreOffice.
5. Arrastra el archivo al wizard.
6. Ve preview de las primeras 10 filas.
7. Pulsa "Ejecutar importacion".
8. Ve cards con conteos OK / SKIP / FAIL.
9. Descarga reporte CSV si hubo errores.

## Tests

- `tests/Feature/DataImport/DataImportPermissionTest.php` — cross-tenant, permisos.
- `tests/Feature/DataImport/SimpleImportersTest.php` — Branch, Warehouse, Brand, Category, Tag.
- `tests/Feature/DataImport/CustomerProductSupplierImportersTest.php` — Customer, Supplier, Product.
- `tests/Feature/DataImport/PriceListPaymentImportersTest.php` — PriceList, PaymentMethod.
- `tests/Feature/DataImport/DataImportWizardTest.php` — flujo completo wizard.
- `tests/Feature/DataImport/TemplateDownloadTest.php` — plantillas dinamicas.
- `tests/Unit/DataImport/CsvSupportTest.php` — CsvParser, CsvReportWriter, ImportRowResult.

Total: **53 tests verdes** en backend + build limpio del frontend.
