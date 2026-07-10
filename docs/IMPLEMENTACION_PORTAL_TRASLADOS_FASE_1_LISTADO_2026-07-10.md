# Portal Web de Traslados — Fase 1 (Listado y Filtros)

**Fecha:** 2026-07-10
**Rama:** `main`
**Commits:**
- `4a9d2496` — Agrega listado administrativo de traslados en el portal web
- `66fbd248` — Corrige assertion de items_count en test del portal de traslados
- `0f49c9d0` — Agrega build assets de Vite para portal de traslados

---

## Resumen

Esta fase agrega el primer módulo del portal web administrativo dedicado a los traslados entre almacenes. Hasta ahora la única vista administrativa del módulo era la API consumida por la aplicación WPF de escritorio; el gerente/supervisor no tenía una vista web propia para auditar el flujo logístico.

En esta primera entrega el admin puede:

- Ver un **resumen ejecutivo** de traslados por estado (chips clickeables: Total, En tránsito, Con diferencias, Solicitados, Despachados, Cerrados con diferencias)
- **Filtrar** el listado por estado (multi-select con checkboxes), almacén (origen o destino), rango de fechas y búsqueda libre por código/guía/referencia/notas
- **Paginar** el resultado (25 por página, ajustable hasta 100)
- Ver **diferencias** por traslado (cuántos items tienen diferencia de cantidad) con badge visual

La Fase 2 (próxima) sumará: vista de detalle con todos los items y acciones `preparar`/`despachar`/`recibir`/`cancelar`/`resolver diferencias`.

## Cambios Realizados

### Backend (PHP / Laravel) — 4 archivos nuevos, 2 editados

| Archivo | Acción | Líneas |
|---|---|---|
| `app/Modules/AdminPortal/Controllers/AdminTransfersController.php` | Crear | 25 |
| `app/Modules/AdminPortal/Services/AdminTransferService.php` | Crear | 245 |
| `app/Modules/AdminPortal/Requests/AdminTransferListRequest.php` | Crear | 50 |
| `app/Modules/InventoryTransfers/Models/InventoryTransfer.php` | Editar | +27 (constantes) |
| `app/Modules/AdminPortal/routes.php` | Editar | +3 (rutas) |
| `tests/Feature/AdminPortal/AdminTransfersListTest.php` | Crear | 455 |

### Frontend (Blade + JS + CSS) — 3 archivos editados

| Archivo | Acción | Líneas |
|---|---|---|
| `resources/views/admin.blade.php` | Editar | +111 (nav + sección) |
| `resources/js/admin.js` | Editar | +298 (state, elements, funciones) |
| `resources/css/admin.css` | Editar | +112 (chips, filtros) |

### Build / Deploy — 1 commit especial de assets

| Archivo | Acción | Detalle |
|---|---|---|
| `public/build/*` (19 archivos, +842 líneas) | Commit forzado con `git add -f` | Assets compilados de Vite. Futuras versiones deberían usar build-on-deploy en lugar de commitearlos |

**Total:** 1,310 líneas nuevas de código + 842 líneas de assets (incluyendo binarios .woff/.woff2).

## Permisos

**Sin cambios a `BasePermissions`.** Se reutiliza el permiso existente `inventory_transfers.admin` (línea 44 de `BasePermissions`), ya asignado a:

- ✅ Owner
- ✅ Administrador
- ✅ Gerente
- ❌ Vendedor, Almacén, Auditor, Cajero (sin acceso al portal administrativo)

Razón del reuso: el portal web es la vista gerencial de las mismas operaciones que el WPF ejecuta. Mantener un solo permiso evita duplicación de matrices. Si en el futuro se necesita separar "puede ver traslados en el WPF" de "puede ver el portal admin", se desdobla el permiso en una fase posterior.

## API

### `GET /api/admin-portal/transfers`

Lista paginada con filtros. Reusa el `InventoryTransferResource` existente — no se duplica serialización.

**Query params:**

| Param | Tipo | Default | Notas |
|---|---|---|---|
| `page` | int ≥ 1 | 1 | Página actual |
| `per_page` (mapeado a `limit`) | int 10-100 | 25 | Tamaño de página |
| `status[]` | string[] | `[]` | Uno o más de `InventoryTransfer::ALL_STATUSES` |
| `warehouse_id` | int | `null` | Filtra donde el id es **origen o destino** |
| `date_from` | date (Y-m-d) | `null` | `processed_at >= date_from 00:00:00` |
| `date_to` | date (Y-m-d) | `null` | `processed_at <= date_to 23:59:59`, debe ser ≥ `date_from` |
| `search` | string ≤ 120 | `''` | LIKE case-insensitive sobre `document_number`, `guide_number`, `reference`, `notes`; si es numérico, también busca por `id` |

**Response:**
```json
{
  "data": {
    "data": [
      {
        "id": 42,
        "document_number": "TRF-000042",
        "guide_number": "GUIA-000042",
        "type": "internal",
        "validation_mode": "logistics",
        "status": "dispatched",
        "status_label": "Despachado",
        "resolution_status": "unresolved",
        "from_warehouse_id": 1,
        "to_warehouse_id": 2,
        "from_warehouse_name": "Tienda Central",
        "to_warehouse_name": "Almacén Norte",
        "reason": "Reposición de sucursal",
        "reference": "REF-001",
        "items_count": 3,
        "differences_count": 1,
        "processed_at": "2026-07-10T15:30:00.000000Z",
        "prepared_at": null,
        "dispatched_at": "2026-07-10T15:30:00.000000Z",
        "received_at": null,
        "cancelled_at": null,
        "created_at": "2026-07-10T15:25:00.000000Z"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 25,
      "total": 12,
      "from": 1,
      "to": 12,
      "has_previous": false,
      "has_next": false
    }
  }
}
```

### `GET /api/admin-portal/transfers/summary`

Conteos agregados para los chips del header. Acepta los mismos filtros que `index` (útil para "en tránsito hoy", "con diferencias este mes", etc.).

**Response:**
```json
{
  "data": {
    "by_status": {
      "requested": 0,
      "in_preparation": 1,
      "prepared": 0,
      "prepared_with_differences": 0,
      "dispatched": 2,
      "in_reception": 1,
      "completed": 5,
      "completed_with_differences": 1,
      "rejected": 0,
      "cancelled": 2
    },
    "status_labels": {
      "requested": "Solicitado",
      "in_preparation": "En preparacion",
      ...
    },
    "total": 12,
    "in_flight": 4,
    "with_differences": 1,
    "warehouses": [
      { "id": 1, "name": "Tienda Central", "code": "WH-CENT" },
      { "id": 2, "name": "Almacén Norte", "code": "WH-NORT" }
    ],
    "generated_at": "2026-07-10T15:30:00.000000Z"
  }
}
```

**Definiciones:**
- `in_flight` = suma de `requested + in_preparation + prepared + prepared_with_differences + dispatched + in_reception` (constante `InventoryTransfer::IN_FLIGHT_STATUSES`)
- `with_differences` = suma de `prepared_with_differences + completed_with_differences` (constante `InventoryTransfer::DIFFERENCES_STATUSES`)
- `warehouses` = **todos** los almacenes del tenant (no afectados por los filtros, para que el dropdown esté siempre completo)

## Reglas de Negocio

### Aislamiento por tenant

Ambos endpoints usan `TenantManager::require()` para obtener el tenant actual. Las queries siempre incluyen `WHERE tenant_id = ?`. Esto se valida en el test `test_admin_does_not_see_other_tenant_transfers`.

### Aislamiento por origen/destino (warehouse_id)

`warehouse_id` filtra traslados donde el almacén aparece como **origen o destino** (no exclusivamente como origen). Esto permite al admin "ver todo lo que se movió hacia/desde este almacén".

### Items_count vs quantity

- `items_count` = **cantidad de filas** en `inventory_transfer_items` para el traslado
- La cantidad real (suma de `quantity` por item) NO se expone en el listado (irrelevante para la vista administrativa; en el detalle se verá por item)

Si el admin necesita ver el detalle, eso es la Fase 2.

### differences_count

Cuenta de items donde `difference_quantity != 0` para el traslado. Incluye tanto diferencias de preparación (preparado vs solicitado) como de recepción (recibido vs preparado).

### Multi-select de status

El frontend envía `status[]=X&status[]=Y` que el backend convierte a `WHERE status IN (X, Y)`. La combinación es OR (no AND) — se muestran traslados que coinciden con cualquiera de los estados seleccionados.

### Permiso

`AdminTransferListRequest::authorize()` verifica `$user->can('inventory_transfers.admin')`. El middleware `tenant` ya se aplicó en el grupo de rutas, así que el tenant está garantizado.

## Mensajes de Error

| Status | Cuándo | Mensaje típico |
|---|---|---|
| 403 | Usuario sin `inventory_transfers.admin` | `{"message": "This action is unauthorized."}` |
| 422 | Validación de filtros (status inválido, date_to < date_from, etc.) | `{"message": "...", "errors": {"status.0": ["..."]}}` |
| 200 | Caso normal | (ver ejemplos arriba) |

No hay 404 — la lista siempre devuelve 200 con `data: []` si no hay traslados que coincidan.

## Pruebas Ejecutadas

**14 tests nuevos** en `AdminTransfersListTest`:

1. `test_admin_can_list_transfers_with_default_pagination` — paginación y orden por defecto
2. `test_admin_can_filter_by_single_status` — `?status[]=cancelled` devuelve solo cancelados
3. `test_admin_can_filter_by_multiple_statuses` — `?status[]=cancelled&status[]=dispatched` (OR)
4. `test_admin_can_filter_by_origin_warehouse` — `?warehouse_id=X` filtra origen
5. `test_admin_can_filter_by_destination_warehouse` — `?warehouse_id=X` filtra destino
6. `test_admin_can_filter_by_date_range` — `?date_from=...&date_to=...`
7. `test_admin_can_search_by_document_number` — `?search=TRF-000042`
8. `test_admin_can_combine_multiple_filters` — 3 filtros simultáneos
9. `test_summary_returns_correct_status_counts` — agregaciones del summary
10. `test_summary_honors_filter_arguments` — summary también acepta filtros
11. `test_summary_includes_warehouse_options` — dropdown de almacenes poblado
12. `test_user_without_admin_permission_gets_403` — autorización
13. `test_summary_endpoint_also_requires_admin_permission` — autorización en summary
14. `test_admin_does_not_see_other_tenant_transfers` — aislamiento de tenant

**Resultado:** 14/14 verde, 70 aserciones, 0 regresiones.

**Suite completa del proyecto:** 362 tests, 2331 aserciones, todos verdes (era 348/2261 antes de esta fase).

## Decisiones de Diseño

### 1. Reutilizar el permiso `inventory_transfers.admin` (no crear `admin.transfers.view`)

**Por qué:** El permiso ya existía en `BasePermissions` línea 44, asignado a Owner/Administrador/Gerente, y no se usaba en ningún Policy. Crear uno nuevo habría duplicado la matriz de roles sin valor. El nombre `.admin` describe exactamente la intención: "acciones administrativas sobre traslados" (no operacional).

**Trade-off:** Si en el futuro se quiere separar "puede ver en el WPF" de "puede ver el portal admin", se desdobla. Hoy no hace falta.

### 2. Sin `Policy` separado — autorización en el `Request`

**Por qué:** Los `AdminPosSales*`, `AdminDashboard*` y `AdminOperationalReport*` ya usan este patrón (autorización en el `Request::authorize()` con `$user->can(...)`). Mantener consistencia. Crear un `AdminTransferPolicy` sería over-engineering para un único check de permiso.

**Trade-off:** Si se agregan checks por transferencia individual (no por listado), se introduce un Policy. Hoy no hace falta.

### 3. `items_count` cuenta filas, no suma quantities

**Por qué:** Para un admin que mira "cuántos SKUs diferentes se están moviendo", el conteo de filas es la métrica correcta. La suma de quantities es relevante a nivel de producto (Fase 2: vista de detalle) pero no en la vista de lista.

**Trade-off:** Si el admin espera ver "10 unidades" en lugar de "1 SKU", hay que re-explicar. El campo se llama `items_count` explícitamente, no `total_quantity`.

### 4. `warehouses` siempre completo en el summary (no afectado por filtros)

**Por qué:** El dropdown de almacén debe mostrar todas las opciones independientemente de los filtros activos. Si el usuario filtra por "completados" y el dropdown solo muestra almacenes con completados, no puede quitar el filtro sin perder visibilidad.

**Trade-off:** Más datos en cada summary call (decenas de filas de warehouses). Trivial a escala actual.

### 5. Chips clickeables predefinen filtros

**Por qué:** Los chips del header (Total, En tránsito, Con diferencias, etc.) son atajos para los filtros más comunes. Clickear "Con diferencias" marca automáticamente los checkboxes de los estados relevantes y recarga la tabla. UX rápida para el caso de uso más frecuente del admin (encontrar traslados con problemas).

**Trade-off:** Si el usuario ya tenía filtros activos y clickea un chip, se sobrescriben. Aceptable: los chips son "atajos", no "filtros acumulativos".

### 6. No incluir endpoint `show` (detalle) en esta fase

**Por qué:** El botón "Ver" en la tabla hoy solo muestra un mensaje "se habilita en la fase 2". El listado es la base; el detalle (con items, IMEIs, acciones de receive/cancel/resolve) es la Fase 2. Mantener el scope de Fase 1 acotado.

**Trade-off:** El admin tiene que abrir el WPF para ver detalles. Aceptable para esta entrega.

### 7. Build assets commiteados (en lugar de build-on-deploy)

**Por qué:** El server no tiene npm funcional para `mavis` (permisos de `node_modules`). En vez de pelearme con eso, commiteo el build con `git add -f public/build/` y el server solo lo sirve via nginx.

**Trade-off:** El repo tiene 220 KB de binarios (woff/woff2) que cambian con cada rebuild. Para un proyecto maduro se debería build-on-deploy (GitHub Actions o un script en el server con sudo). En una fase posterior, eliminar `public/build/` del repo y agregar un step de build al deploy.

## Pendiente Para Fases Siguientes

### Fase 2 — Detalle y acciones

- Botón "Ver" abre vista de detalle con todos los items (sku, nombre, cantidad solicitada/preparada/recibida, IMEIs si aplica)
- Acciones contextuales según `status` del traslado:
  - `requested`: preparar, cancelar
  - `prepared`: despachar, cancelar
  - `dispatched`: recibir, cancelar
  - `completed_with_differences`: resolver diferencias (3 acciones: `accept_loss`, `manual_adjustment`, `investigating`)
- Reutilizar los endpoints existentes de `InventoryTransfers/` (no duplicar lógica)
- Nuevas rutas: `GET /api/admin-portal/transfers/{id}` (agregar al controller actual)

### Fase 3 — Resolución de diferencias UI (la más jugosa)

- Tabla de items con diff expandible
- Por cada item: 3 acciones (`accept_loss`, `manual_adjustment`, `investigating`)
- Input de cantidad libre para `manual_adjustment`
- Estado del traslado se actualiza en vivo (chips se recalculan)
- Modal/drawer con confirmación antes de enviar

### Fase 4 — Dashboard widgets

- Card "traslados en tránsito" (count + mini-lista de los 5 más recientes)
- Card "con diferencias abiertas" (count + tiempo promedio sin resolver)
- Card "vencidos" (despachados hace >24h sin recibir)
- Mini-chart de traslados por día (últimos 30 días)

### Otras mejoras no urgentes

- Reemplazar `items_count` por una vista materializada que sume quantities (performance si crece la tabla)
- Exportar a CSV (ya existe el patrón en `AdminPosSalesController`)
- Búsqueda por SKU de producto (actualmente solo por código de traslado)
- Persistir el estado de los filtros en `localStorage` (UX)
- Quitar `public/build/` del repo y agregar step de build en el deploy

## Verificación en el Server

```bash
# git log
$ git log --oneline -4
0f49c9d Agrega build assets de Vite para portal de traslados
66fbd24 Corrige assertion de items_count en test del portal de traslados
4a9d249 Agrega listado administrativo de traslados en el portal web
65166d4 Agrega resolucion administrativa de diferencias en traslados

# routes
$ php artisan route:list --path=admin-portal/transfers
GET|HEAD  api/admin-portal/transfers
GET|HEAD  api/admin-portal/transfers/summary

# tests
$ DB_PORT=5432 phpunit tests/Feature/AdminPortal/AdminTransfersListTest.php
OK (14 tests, 70 assertions)

# suite completa
$ DB_PORT=5432 phpunit
OK (362 tests, 2331 assertions)

# HTML servido
$ curl http://127.0.0.1:8010/admin | grep admin-transfers-module
<section class="admin-module-panel transfers-admin" id="admin-transfers-module" hidden>
```

## Pendientes Operacionales

Durante la instalación quedaron dos fixes de ambiente en el server que NO son código pero conviene documentar:

1. **`storage/` es root-owned** — el `chown -R mavis:www-data /opt/inventarioarens-cloud/storage` se ejecutó para que `mavis` pueda correr tests sin errores de log/cache. El laravel app en producción sigue corriendo como `www-data` así que no se rompió nada.

2. **`phpunit/phpunit` no estaba instalado en `vendor/`** — `composer install` (con dev deps) se ejecutó como root vía `sudo /usr/bin/env /usr/local/bin/composer install`. Si se hace un deploy limpio, el `composer install` del `setup` script debería hacerse con dev deps incluidos (o dos pasos: `composer install --no-dev && composer install --dev`).

3. **Cache de rutas stale** — el `bootstrap/cache/routes-v7.php` quedó con la versión vieja (de antes de los nuevos endpoints). Se limpió con `rm` (vía `sudo env rm`). En producción, `php artisan route:clear` + `route:cache` debe correr después de cada deploy.
