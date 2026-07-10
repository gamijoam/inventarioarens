# Portal Web de Traslados — Fase 2 (Detalle y Acciones)

**Fecha:** 2026-07-10
**Rama:** `main`
**Commits:**
- `9c345f6` — `fix(sync-worker):` oculta ventana negra del Scheduled Task con wrapper VBS (paralelo)
- `f70c612` — `feat(admin-portal):` agrega detalle y acciones de traslados (Fase 2)
- `c1b5dee` — `feat(admin-portal):` agrega drawer de detalle con acciones de traslado
- `601f79c` — `chore(build):` regenera assets admin con drawer de traslados

> **Nota:** El commit `9c345f6` (fix ventana negra del sync-worker) se incluye en esta entrega porque se descubrió y resolvió durante el desarrollo de la Fase 2. Es ortogonal al portal pero es trabajo de la misma sesión.

---

## Resumen

Esta fase cierra el ciclo operativo del portal de traslados. Donde la Fase 1 dejó al admin como espectador del flujo (listado + filtros), la Fase 2 lo convierte en operador: desde el navegador puede ver el detalle completo de un traslado y ejecutar las 5 acciones que hasta ahora solo existían en la app de escritorio WPF o vía API.

Acciones cubiertas en el drawer:

| Acción | Permission requerida | Estados donde se ofrece |
|---|---|---|
| Preparar | `inventory_transfers.prepare` | `requested`, `in_preparation` |
| Despachar | `inventory_transfers.dispatch` | `prepared`, `prepared_with_differences` |
| Recibir | `inventory_transfers.receive` | `dispatched`, `in_reception` |
| Cancelar | `inventory_transfers.cancel` | todos los previos a `completed_with_differences` |
| Resolver diferencias | `inventory_transfers.resolve_differences` | `completed_with_differences` |

## Cambios Realizados

### Backend (PHP / Laravel) — 1 archivo nuevo, 3 editados, 1 test nuevo

| Archivo | Acción | Líneas |
|---|---|---|
| `app/Modules/AdminPortal/Controllers/AdminTransfersController.php` | Editar | 25 → 123 (+98) |
| `app/Modules/AdminPortal/Services/AdminTransferService.php` | Editar | 245 → 304 (+59) |
| `app/Modules/AdminPortal/routes.php` | Editar | +6 (rutas Fase 2) |
| `app/Modules/AdminPortal/Requests/AdminTransferActionRequest.php` | Crear | 22 |
| `tests/Feature/AdminPortal/AdminTransferActionsTest.php` | Crear | 470 (14 tests) |

### Frontend (Blade + JS + CSS) — 3 archivos editados

| Archivo | Acción | Líneas |
|---|---|---|
| `resources/views/admin.blade.php` | Editar | +57 (drawer) |
| `resources/js/admin.js` | Editar | +426 (state, elements, funciones del drawer) |
| `resources/css/admin.css` | Editar | +194 (estilos del drawer) |

### Build / Deploy — 1 commit de assets

| Archivo | Acción | Detalle |
|---|---|---|
| `public/build/assets/admin-Cs6gfPCy.css` | Renombrar (87% similar) | Incluye estilos del drawer |
| `public/build/assets/admin-SrT4A3DS.js` | Crear | 161.84 kB (minificado) / 33.36 kB gzip |

### Fix ortogonal — sync worker

| Archivo | Acción | Líneas |
|---|---|---|
| `scripts/run-sync-hidden.vbs` | Crear | 32 (wrapper VBS con `intWindowStyle=0`) |
| `scripts/sync-worker-task.ps1` | Editar | `Install-WorkerTask` ahora arma el task con `New-ScheduledTaskAction -Execute wscript.exe` apuntando al VBS, en vez de `schtasks /Create /TR "archivo.cmd"` |

## Permisos

**Sin cambios a `BasePermissions`.** Se reutiliza el set existente:

| Permission | Capa | Cubre |
|---|---|---|
| `inventory_transfers.admin` | Acceso al portal | Todos los endpoints `/api/admin-portal/transfers/*` |
| `inventory_transfers.prepare` | Acción específica | POST `/admin-portal/transfers/{id}/prepare` |
| `inventory_transfers.dispatch` | Acción específica | POST `/admin-portal/transfers/{id}/dispatch` |
| `inventory_transfers.receive` | Acción específica | POST `/admin-portal/transfers/{id}/receive` |
| `inventory_transfers.cancel` | Acción específica | POST `/admin-portal/transfers/{id}/cancel` |
| `inventory_transfers.resolve_differences` | Acción específica | POST `/admin-portal/transfers/{id}/resolve-differences` |

**Patrón de doble check:** el controller primero verifica `inventory_transfers.admin` (acceso al portal) y luego `Gate::authorize(<action>, $transfer)` (permiso específico). Si el usuario tiene solo `.admin` pero no `.prepare`, la acción preparar retorna 403 aunque la UI le muestre el botón (defensa en profundidad).

Tests E2E cubren este caso (`test_prepare_requires_specific_prepare_permission` y similares).

## Backend

### Controller — `AdminTransfersController`

```php
public function show(AdminTransferActionRequest $request, InventoryTransfer $inventoryTransfer, AdminTransferService $transfers): JsonResponse
public function prepare(PrepareInventoryTransferRequest $request, InventoryTransfer $inventoryTransfer, InventoryTransferService $service): JsonResponse
public function dispatch(DispatchInventoryTransferRequest $request, InventoryTransfer $inventoryTransfer, InventoryTransferService $service): JsonResponse
public function receive(ReceiveInventoryTransferRequest $request, InventoryTransfer $inventoryTransfer, InventoryTransferService $service): JsonResponse
public function cancel(CancelInventoryTransferRequest $request, InventoryTransfer $inventoryTransfer, InventoryTransferService $service): JsonResponse
public function resolveDifferences(ResolveInventoryTransferRequest $request, InventoryTransfer $inventoryTransfer, InventoryTransferService $service): JsonResponse
```

Todos siguen el mismo patrón:

1. `AdminTransferActionRequest` (para `show`) o el `Request` específico de la acción (para las demás) — el primero solo chequea `.admin` y deja pasar data; los demás aplican las reglas de validación de la acción.
2. `$this->authorizeAdmin($request)` — helper que verifica `inventory_transfers.admin`.
3. `Gate::authorize(<action>, $inventoryTransfer)` — verifica el permiso específico Y la policy (`InventoryTransferPolicy`).
4. Llamada al `InventoryTransferService` con `$request->user()` + el modelo + `$request->validated()`.
5. `respondWithTransfer()` — helper que retorna `InventoryTransferResource` con todas las relaciones cargadas.

### Service — `AdminTransferService`

Dos métodos nuevos:

- `detail(InventoryTransfer $transfer): array` — Reusa el `baseQuery()` y `mapTransfer()` de la Fase 1 (consistencia: el header del detalle se ve igual que una fila del listado) y agrega:
  - `items`: array con todos los items del traslado (producto, cantidades solicitada/preparada/recibida, diferencia, motivo, notas, estado de resolución, IMEIs si aplica).
  - `available_actions`: array de strings (`prepare`, `dispatch`, `receive`, `cancel`, `resolve_differences`) según el estado actual.
  - `canceller`, `resolver`: objetos `{id, name}` si los hay.
- `availableActionsFor(string $status): array` — Método público (separado del `detail()`) que mapea estado → acciones permitidas. Lo usa la UI para mostrar/ocultar botones. Mantiene al frontend libre de lógica de negocio.

### Routes — `app/Modules/AdminPortal/routes.php`

```php
Route::get('transfers/{inventoryTransfer}', [AdminTransfersController::class, 'show']);
Route::post('transfers/{inventoryTransfer}/prepare', [AdminTransfersController::class, 'prepare']);
Route::post('transfers/{inventoryTransfer}/dispatch', [AdminTransfersController::class, 'dispatch']);
Route::post('transfers/{inventoryTransfer}/receive', [AdminTransfersController::class, 'receive']);
Route::post('transfers/{inventoryTransfer}/cancel', [AdminTransfersController::class, 'cancel']);
Route::post('transfers/{inventoryTransfer}/resolve-differences', [AdminTransfersController::class, 'resolveDifferences']);
```

Todas dentro del grupo `prefix('admin-portal')` que ya tiene el middleware de tenant + sesión.

### Tests — `AdminTransferActionsTest`

14 tests, ~470 líneas, total AdminPortal: 44 tests / 354 asserts / todos verdes.

| Test | Cubre |
|---|---|
| `test_admin_can_view_transfer_detail` | Happy path: GET /transfers/{id} retorna transfer + items + available_actions |
| `test_view_detail_requires_admin_permission` | 403 sin `inventory_transfers.admin` |
| `test_view_detail_returns_404_for_other_tenant` | 404 cross-tenant (aislamiento) |
| `test_admin_can_prepare_transfer_via_admin_portal` | Happy path preparar via admin portal |
| `test_prepare_requires_specific_prepare_permission` | 403 sin `.prepare` (aunque tenga `.admin`) |
| `test_admin_can_dispatch_transfer_via_admin_portal` | Happy path despachar |
| `test_dispatch_requires_specific_dispatch_permission` | 403 sin `.dispatch` |
| `test_admin_can_receive_transfer_via_admin_portal` | Happy path recibir |
| `test_receive_requires_specific_receive_permission` | 403 sin `.receive` |
| `test_admin_can_cancel_transfer_via_admin_portal` | Happy path cancelar |
| `test_cancel_requires_specific_cancel_permission` | 403 sin `.cancel` |
| `test_cancel_requires_reason_min_length` | 422 con `cancellation_reason` corto (regla `min:5`) |
| `test_admin_can_resolve_differences_via_admin_portal` | Happy path completo: preparar con diff → despachar → recibir con diff → resolver |
| `test_resolve_differences_requires_specific_permission` | 403 sin `.resolve_differences` |

## Frontend

### Drawer (Blade) — `admin.blade.php`

Nuevo `<aside class="transfers-drawer" id="admin-transfer-drawer" hidden>` después del módulo de traslados. Estructura:

- **Header**: badge "Traslado" + título (código del documento) + subtítulo (número de guía · referencia) + botón cerrar (×).
- **Status pill**: badge con el estado actual (`Solicitado`, `Despachado`, `Con diferencias`, etc.) usando el mismo helper de tonos que el listado.
- **Meta** (grid 2x5): origen, destino, referencia, motivo, solicitado, preparado, despachado, recibido, cancelado. Si un timestamp es null, muestra "—".
- **Sección Productos**: una `<div class="transfers-drawer__item">` por item con nombre + SKU, estadísticas (solicitado / preparado / recibido / diferencia), y bloque de motivo/notas/resolución si hay diferencia.
- **Action bar** (`#admin-transfer-drawer-actions`): botones dinámicos según `available_actions` retornado por el backend. Cancelar usa `danger-button`, el resto `primary-button`.
- **Form dinámico** (`#admin-transfer-drawer-form`, oculto por default): se muestra cuando el usuario hace click en un botón de acción. Render distinto por acción (ver abajo).
- **Feedback** (`#admin-transfer-drawer-feedback`): mensajes de éxito/error del submit, con `setStatus()` (info/success/error/neutral).

### JS — `admin.js`

State:

```js
state.transfers.detail = { id, data, activeAction, loading }
```

Elementos nuevos en el objeto `elements` (con prefijo `transferDrawer*`).

Funciones nuevas:

| Función | Responsabilidad |
|---|---|
| `openTransferDrawer(transferId)` | Valida sesión y permiso `.admin`, muestra el drawer, dispara `loadTransferDetail()`. |
| `closeTransferDrawer()` | Oculta el drawer, limpia `state.transfers.detail`, restaura `body.overflow`. |
| `loadTransferDetail()` | GET `/api/admin-portal/transfers/{id}`, guarda en state, llama `renderTransferDetail()`. |
| `renderTransferDetail(payload)` | Llena header, status pill, meta, items (vía `drawerItemCard`), action bar (vía `buildActionButtons`). |
| `drawerItemCard(item)` | Crea el card visual de cada producto con sus cantidades y diff. |
| `buildActionButtons(available)` | Genera los botones según `available_actions` (1 por acción + "no admite acciones" si vacío). |
| `showTransferActionForm(action)` | Setea `activeAction`, muestra el form, llama `buildActionForm()`. |
| `buildActionForm(action, data)` | Renderiza el form específico de la acción. |
| `buildQuantityItemBlock` / `buildResolveItemBlock` / `buildNotesField` | Bloques reutilizables del form. |
| `collectActionPayload(action)` | Lee los inputs del form y arma el payload JSON para el POST. |
| `readFieldValue(action, field, itemId)` | Helper para leer inputs del form. |
| `submitTransferAction(action)` | POST al endpoint, validaciones client-side, refresh de `loadTransferDetail` + `loadTransfers` + `loadTransferSummary` en éxito. |
| `actionEndpoint(action)` | Mapea `resolve_differences` → `resolve-differences` (kebab-case en la URL). |

Event listeners nuevos:

- Click en el botón "Ver" de cada fila del listado → `openTransferDrawer(transferId)`.
- Click en cualquier elemento con `data-admin-transfer-drawer-close` (backdrop, botón ×, footer "Cerrar") → `closeTransferDrawer()`.
- Tecla `Escape` con el drawer abierto → `closeTransferDrawer()`.

#### Formularios por acción

**`prepare` / `receive`** — Por cada item del traslado: campo "Cantidad preparada/recibida" (prefill con la cantidad solicitada), "Motivo diferencia" (obligatorio si hay diferencia) y "Notas del item" (opcional). Al final: textarea de notas globales.

**`dispatch`** — Solo un textarea de notas (opcional).

**`cancel`** — Textarea obligatorio con `minlength=5` y motivo mínimo de 5 caracteres (validación client-side + backend).

**`resolve_differences`** — Solo items con `difference_quantity != 0`: select de acción (`investigating` / `accepted_loss` / `adjusted_manually`) + cantidad (obligatoria si es `adjusted_manually`) + notas. Al final: notas globales.

Validación client-side antes de submit:
- Cancelar con motivo < 5 chars → error.
- Resolver con `adjusted_manually` y cantidad vacía/0 → error.
- Resolver con items sin acción seleccionada → error.
- Preparar/recibir con cantidad < solicitada sin motivo de diferencia → error.

### CSS — `admin.css`

~194 líneas nuevas. Variables del sistema (`--panel`, `--ink`, `--line`, `--primary`, `--ease`, etc.). Bloque principal `.transfers-drawer` con `position: fixed; inset: 0; z-index: 80;`, panel deslizante desde la derecha con `transform: translateX(24px)` + animación `transfers-drawer-slide` (220ms con `cubic-bezier(.2, .8, .2, 1)`). Backdrop con `rgba(8, 20, 45, 0.45)` + `backdrop-filter: blur(2px)`. Mobile (< 720px): meta en una sola columna.

## Fix ortogonal — sync worker ventana negra

**Problema:** el Scheduled Task `SistemaInventarioSync-demo-valencia` (cada 5 min) abría una ventana de cmd/powershell visible cada vez que se disparaba.

**Causa:** el `sync-worker.ps1` ya lanzaba el `php artisan sync:daemon` con `-WindowStyle Hidden` (correcto), pero el `.cmd` padre invocado por el Task Scheduler se ejecutaba en una consola visible, y el `powershell.exe` que ese cmd llamaba también. Resultado: 2 ventanas negras que parpadeaban ~500ms cada 5 min.

**Fix:** Crear `scripts/run-sync-hidden.vbs` (32 líneas, todas con comentario explicativo) que usa `WScript.Shell.Run` con `intWindowStyle=0` (vbHide) + `bWaitOnReturn=False` (fire-and-forget). Actualizar el Scheduled Task in-place (`Execute = wscript.exe`, `Arguments = "...\run-sync-hidden.vbs" "...\sync-task-demo-valencia.cmd"`). Patchear `scripts/sync-worker-task.ps1` para que `Install-WorkerTask` use `New-ScheduledTaskAction` apuntando al VBS, en vez de `schtasks /Create /TR "archivo.cmd"`. Así futuros `install` no revierten el fix.

**Validación:** Stop worker (PID 15960 muere) → `Start-ScheduledTask` → aparece nuevo worker (PID 12416), log "Ciclo 1", procesos con ventana visible: solo Chrome, Discord, League, MiniMax Code, PowerShell del usuario. **0 cmd / powershell del sync.**

## Tests

```bash
# Local (Laragon)
php vendor/bin/phpunit tests/Feature/AdminPortal/AdminTransferActionsTest.php
# 14 tests, 56 asserts, all green

# Suite completa de AdminPortal
php vendor/bin/phpunit tests/Feature/AdminPortal/
# 44 tests, 354 asserts, all green
```

> **Nota:** Correr la suite completa local con `RefreshDatabase` puede tirar errores de "duplicate table" / "undefined table" por concurrencia de la DB de testing (Postgres 5434 en Laragon). En el server (Postgres 5432, MySQL-like setup) no se reproduce. Los tests pasan cuando se corren archivo por archivo o con `--process-isolation`.

## Deploy

```bash
# En el server (VPS 217.216.80.158)
cd /opt/inventarioarens-cloud
sudo /usr/bin/env git pull
npm run build
php artisan optimize:clear
# NUNCA php artisan view:cache (cachea vistas y oculta cambios del blade)
```

Si en el server se rebuildea la imagen Docker, asegurarse de que la nueva build de Vite quede commiteada (commit `601f79c` ya la incluye).

## Pendiente para Fase 3 (futuro)

- IMEIs y seriales en el drawer de receive (hoy solo en desktop).
- Logs de auditoría por traslado (quién preparó, quién despachó, etc.).
- Notificación en tiempo real al admin cuando se crea un traslado desde desktop.
- Exportar listado a CSV (botón ya existe en otras secciones del portal, falta en traslados).
