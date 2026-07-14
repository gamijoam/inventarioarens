# Frontend — Roadmap por Fases

> **Estado (2026-07-13)**: Roadmap de implementación del nuevo frontend web SPA.
> **Stack**: Vite + React 18 + TypeScript + TanStack Query/Router + Tailwind 4 + Radix UI + Zustand
> (ver `docs/FRONTEND_ARQUITECTURA.md`).
>
> **Premisa**: cada fase debe ser entregable y testeable de forma independiente. No bloqueamos
> fases en cascada — una fase puede comenzar antes de que termine la anterior si la feature está
> definida.

---

## Vista general

| Fase | Nombre | Estado | Alcance | Permisos |
|---|---|---|---|---|
| **0** | Setup base | ☐ Pendiente | Vite + React + TS + Tailwind + Radix + TanStack + estructura | — |
| **1** | Auth + Inventario | ☐ Pendiente | Login + multi-tenant + Dashboard + Centro de Inventario | `auth.*`, `products.*`, `inventory.*`, `price_lists.*` |
| **2** | Compras + POS + Caja | ☐ Pendiente | Compras, POS, Caja registradora | `purchases.*`, `sales.*`, `pos.*`, `cash_register.*` |
| **3** | Traslados + Terceros | ☐ Pendiente | Traslados, Clientes, Proveedores, CxC, CxP | `inventory_transfers.*`, `customers.*`, `suppliers.*`, `accounts_*.*` |
| **4** | Access Control | ☐ Pendiente | Usuarios, Roles, Permisos, Scopes | `users.*`, `roles.*`, `permissions.*` |
| **5** | SaaS Master | ☐ Pendiente | Platform Admin: grupos, spinoffs, admins | Platform Admin token |
| **6** | PWA + Offline | ☐ Pendiente | Service Worker, instalable, offline-first | — |
| **7** | Reportes + Analytics | ☐ Pendiente | Reportes avanzados, dashboards analíticos | `reports.*`, `finance_reports.*` |

**Actualizar estado**: cambiar ☐ → 🔄 cuando inicia, → ✅ cuando se entrega con tests + verificación
manual.

---

## Fase 0 — Setup base

**Objetivo**: tener un proyecto funcional que arranca con `pnpm dev` y muestra un "Hola mundo"
diseñado, con toda la infra de tooling lista.

### Entregables

- [ ] Carpeta `frontend/` con `package.json`, `tsconfig.json`, `vite.config.ts`, `tailwind.config.ts`.
- [ ] Estructura de carpetas según `docs/FRONTEND_ARQUITECTURA.md` §3.
- [ ] App.tsx con `<QueryClientProvider>` + `<RouterProvider>` + `<PermissionContext>` + `<ThemeProvider>`.
- [ ] Página `/` con "Hola, INVENTARIOARENS" + link a `/login`.
- [ ] ESLint + Prettier configurados.
- [ ] Husky + lint-staged configurados.
- [ ] CI workflow (`.github/workflows/frontend-ci.yml`) que corre lint + typecheck + build.
- [ ] Vitest + Testing Library configurados (un test dummy pasa).
- [ ] Tema light/dark funcional.
- [ ] `frontend/README.md` con instrucciones de setup.

### Stack instalado

```jsonc
{
  "dependencies": {
    "react": "^18.3.0",
    "react-dom": "^18.3.0",
    "@tanstack/react-router": "^1.x",
    "@tanstack/react-query": "^5.x",
    "@tanstack/react-table": "^8.x",
    "axios": "^1.x",
    "zustand": "^5.x",
    "react-hook-form": "^7.x",
    "@hookform/resolvers": "^3.x",
    "zod": "^3.x",
    "@radix-ui/react-dialog": "^1.x",
    "@radix-ui/react-dropdown-menu": "^2.x",
    "@radix-ui/react-popover": "^1.x",
    "@radix-ui/react-select": "^2.x",
    "@radix-ui/react-tabs": "^1.x",
    "@radix-ui/react-tooltip": "^1.x",
    "@radix-ui/react-checkbox": "^1.x",
    "@radix-ui/react-radio-group": "^1.x",
    "@radix-ui/react-switch": "^1.x",
    "@radix-ui/react-toast": "^1.x",
    "lucide-react": "^0.4x",
    "sonner": "^1.x",
    "next-themes": "^0.3.x",
    "clsx": "^2.x",
    "tailwind-merge": "^2.x",
    "date-fns": "^3.x",
    "class-variance-authority": "^0.7.x"
  },
  "devDependencies": {
    "@types/react": "^18.x",
    "@types/react-dom": "^18.x",
    "@vitejs/plugin-react": "^4.x",
    "vite": "^6.x",
    "typescript": "^5.x",
    "tailwindcss": "^4.x",
    "@tailwindcss/vite": "^4.x",
    "postcss": "^8.x",
    "vitest": "^2.x",
    "@testing-library/react": "^16.x",
    "@testing-library/jest-dom": "^6.x",
    "@playwright/test": "^1.x",
    "eslint": "^9.x",
    "@typescript-eslint/eslint-plugin": "^8.x",
    "@typescript-eslint/parser": "^8.x",
    "prettier": "^3.x",
    "husky": "^9.x",
    "lint-staged": "^15.x"
  }
}
```

### Criterio de aceptación

- `pnpm install && pnpm dev` arranca la app en `http://localhost:5173`.
- `pnpm build` produce bundle sin errores.
- `pnpm lint && pnpm typecheck` pasan limpios.
- `pnpm test` corre los tests dummy y pasan.
- El botón de tema light/dark funciona.
- Layout responsivo básico (sidebar colapsable en mobile).

---

## Fase 1 — Auth + Inventario

**Objetivo**: usuario puede iniciar sesión, seleccionar empresa, ver dashboard y gestionar inventario
completo. Esta es la **fase MVP** — suficiente para reemplazar el portal admin anterior en su
función core.

### Entregables

#### Auth
- [ ] Pantalla de login (`/login`) con email + lista de tenants del user.
- [ ] Endpoint `POST /api/auth/tenants {email}` para autocomplete del selector.
- [ ] Endpoint `POST /api/auth/login {email, password}` con `X-Tenant`.
- [ ] Almacenamiento seguro de token + sesión en Zustand (persistido en localStorage).
- [ ] Pantalla de loading mientras se valida sesión existente al cargar la app.
- [ ] TenantSwitcher en topbar (botón con dropdown de tenants disponibles).
- [ ] UserMenu (avatar + dropdown con "Cambiar empresa", "Cerrar sesión").
- [ ] `<RequireAuth>` guard que redirige a `/login` si no hay sesión.
- [ ] Página `/select-tenant` si el user pertenece a varios tenants.

#### Permisos (esqueleto base)
- [ ] `PermissionContext` + `useCan` + `useCanAny` + `useCanAll`.
- [ ] `<Can>`, `<CanAny>`, `<PermissionDenied>` componentes.
- [ ] Carga de `permissions` desde `/api/auth/me`.
- [ ] Refresh de permisos al hacer switch-tenant.
- [ ] `formatCost` helper para field masking.

#### Layout principal
- [ ] `<AppShell>` con sidebar + topbar + main area.
- [ ] `<Sidebar>` con navegación por módulos.
- [ ] Cada item del menú envuelto en `<Can I="...">`.
- [ ] `<Topbar>` con breadcrumb, search global, tenant switcher, user menu.
- [ ] `<PageLayout>` estándar con título + acciones + breadcrumbs.

#### Dashboard
- [ ] Página `/dashboard` consumiendo `GET /api/dashboard/summary`.
- [ ] Tarjetas de métricas: ventas confirmadas, POS cobrado, cajas abiertas, stock bajo, alertas.
- [ ] Lista de top 5 productos con bajo stock.
- [ ] Lista de últimos movimientos.
- [ ] Auto-refresh cada 30s via `refetchInterval`.

#### Centro de Inventario (CORE)
- [ ] Página `/inventory` (listado de productos) con:
  - Tabla densa (`TanStack Table`) con columnas: SKU, nombre, tracking_type, stock disponible, stock reservado, dañado, precio base, estado.
  - Filtros: search, tracking_type, stock_status, has_price, has_warranty.
  - Paginación server-side (50 productos por página).
  - Acciones por fila: ver detalle, editar (si tiene `products.update`).
  - Bulk actions: activar/desactivar (si tiene `products.update`).
  - Botón "Nuevo producto" (si tiene `products.create`).
- [ ] Página `/inventory/:id` (detalle):
  - Tabs: General / Stock / Seriales / Movimientos / Precios / Auditoría.
  - Tab General: nombre, SKU, descripción, tracking_type, precio base, moneda, estado, garantía.
  - Tab Stock: stock por almacén (de `inventory-center/products/{id}/stock-by-warehouse`).
  - Tab Seriales: IMEIs/seriales disponibles (de `inventory-center/products/{id}/serials`).
  - Tab Movimientos: últimos 50 movimientos (de `inventory-center/products/{id}/movements`).
  - Tab Precios: precios por lista (de `products/{id}/prices`), con edición inline si tiene permiso.
  - Tab Auditoría: historial de cambios (de `inventory-center/products/{id}/audits`).
  - Sheet lateral de edición rápida (sin cambiar de página).
- [ ] Formulario de creación de producto (POST /api/products).
- [ ] Formulario de edición (PATCH /api/products/{id}).
- [ ] Confirmación de soft-delete (DELETE /api/products/{id}).
- [ ] Acciones masivas (POST /api/inventory-center/products/bulk-action):
  - activate / deactivate
  - assign_warranty_policy
  - assign_exchange_rate_type
  - fill_missing_price_list
  - update_price_list

#### Precios por lista
- [ ] Editor inline de precios por lista dentro del detalle del producto.
- [ ] Botón "Copiar base" para rellenar listas vacías con el precio base.
- [ ] Listado de listas de precio (GET /api/price-lists?active_only=1) para el selector.

#### Almacenes y sucursales (lectura)
- [ ] Listado de almacenes (GET /api/warehouses) — solo lectura para Fase 1.
- [ ] Listado de sucursales (GET /api/branches) — solo lectura para Fase 1.

#### Catálogo de sucursal (combo de pruebas)
- [ ] Sucursales cargadas en dropdowns donde aplique (ej: selector de almacén).
- [ ] Almacenes cargados en dropdowns donde aplique.

### Criterio de aceptación

- Login funciona con un user demo del seeder.
- Cambio de tenant funciona.
- Cada botón en el inventario respeta permisos (oculto si no tiene).
- `unit_cost` se muestra como "—" si el user no tiene `finance.costs.view`.
- Filtros y paginación funcionan.
- La app es navegable solo con teclado (Tab, Enter, Esc).
- Responsive básico: en mobile (< 768px) el sidebar se colapsa.
- `pnpm build` produce bundle sin warnings.
- Tests E2E del flujo login → inventario → editar producto pasan.

### Permisos del backend que necesitamos

```
products.view, products.create, products.update, products.delete
price_lists.view, price_lists.create, price_lists.update
warehouses.view, branches.view
inventory.view
```

### Documentación adicional a crear

- `frontend/README.md` — setup, comandos, estructura.
- `docs/FRONTEND_PERMISSIONS.md` (ya creado en esta fase).
- `docs/FRONTEND_DESIGN_TOKENS.md` — colores, tipografía, spacing.

---

## Fase 2 — Compras + POS + Caja

**Objetivo**: usuario puede registrar compras, operar el POS (con búsqueda de productos, cobro
mixto USD/VES, IMEI), y abrir/cerrar cajas registradoras.

### Entregables

#### Compras
- [ ] Página `/purchases` (listado con filtros: status, supplier, fechas).
- [ ] Página `/purchases/new` (crear orden de compra en draft).
- [ ] Página `/purchases/:id` (detalle: header + items + recepción).
- [ ] Recepción de compra (PATCH /api/purchases/{id}/receive) — actualiza inventario + genera CxP.
- [ ] Cancelación de compra (PATCH /api/purchases/{id}/cancel) — solo en estado draft.
- [ ] Creación rápida de proveedor desde el formulario de compra.

#### POS
- [ ] Página `/pos` (operación de caja):
  - Buscador de productos con autocompletado (GET /api/inventory-center/summary?search=).
  - Carrito lateral con items agregados.
  - Para productos serializados: picker de IMEIs disponibles (GET /api/inventory-center/products/{id}/serials).
  - Cotización en tiempo real (GET /api/products/{id}/price?price_list_id=).
  - Selector de cliente (GET /api/customers?search=) + creación rápida de cliente.
  - Selector de lista de precio si hay varias activas.
  - Selector de almacén (si hay varios).
  - Indicador de caja abierta (chip en topbar).
- [ ] Modal de cobro:
  - Pagos rápidos por método de pago (botones grandes).
  - Pagos mixtos USD/VES con conversión en tiempo real.
  - Validación de monto total.
  - Snapshot de tasa de cambio usado.
  - Referencia obligatoria si `requires_reference`.
- [ ] Confirmación → POST /api/pos/checkouts → recibo final.
- [ ] Página `/pos/orders` (órdenes pendientes con pagos parciales).

#### Caja registradora
- [ ] Página `/cash-register` (gestión):
  - Tabs: Abrir turno / Cerrar turno / Cajas físicas / Métricas del turno / Movimientos.
  - Apertura de sesión (POST /api/cash-register/sessions) — seleccionar almacén, caja, monto inicial.
  - Cierre de sesión (PATCH /api/cash-register/sessions/{id}/close) — monto contado vs esperado, diferencia.
  - Creación de caja física (POST /api/cash-register/registers).
  - Movimientos manuales (POST /api/cash-register/sessions/{id}/movements).
  - Indicador global en topbar: "Caja abierta / cerrada / sin caja" con color.

#### Tasas de cambio (consulta)
- [ ] Indicador en topbar de tasa actual (BCV por default).
- [ ] Página `/currency/rates` (consulta de tasas vigentes + historial).

### Criterio de aceptación

- Se puede crear una orden de compra, recibirla (que mueve inventario + crea CxP).
- Se puede operar el POS: buscar, agregar al carrito, cobrar con pagos mixtos, generar orden.
- Productos serializados obligan a seleccionar IMEIs antes de agregar al carrito.
- Caja: solo se puede cobrar si hay caja abierta; al cerrar calcula diferencia.
- Las tasas se muestran en la UI correctamente (USD/VES con snapshot).

### Permisos del backend

```
purchases.view, purchases.create, purchases.receive, purchases.cancel
sales.view, sales.create, sales.confirm, sales.cancel
pos.view, pos.checkout, pos.cancel
cash_register.view, cash_register.open, cash_register.close, cash_register.create
currency.view, currency.update
products.price (cotización)
```

---

## Fase 3 — Traslados + Terceros + CxC/CxP

**Objetivo**: gestión de traslados logísticos (fases preparar/despachar/recibir), CRUD de clientes
y proveedores, y gestión de cuentas por cobrar/pagar.

### Entregables

#### Clientes
- [ ] Página `/customers` (listado con búsqueda, filtros).
- [ ] Página `/customers/new` (crear cliente).
- [ ] Página `/customers/:id` (detalle + historial POS + saldos CxC).
- [ ] Selección de grupo de cliente (customer_groups) con chips.
- [ ] Listado de customer_groups (GET /api/customer-groups) + creación.

#### Proveedores
- [ ] Página `/suppliers` (listado con búsqueda, filtros).
- [ ] Página `/suppliers/new` y `/suppliers/:id`.
- [ ] Similar a clientes pero adaptado a proveedores.

#### Traslados logísticos
- [ ] Página `/transfers` (listado con chips de estado).
- [ ] Drawer lateral con detalle del traslado (similar al portal admin anterior).
- [ ] Acciones operativas (con `permission` checks):
  - Preparar (`inventory_transfers.prepare`) — seleccionar IMEIs si serializado.
  - Despachar (`inventory_transfers.dispatch`).
  - Recibir (`inventory_transfers.receive`) — ajustar cantidad recibida, motivo si hay diferencia.
  - Cancelar (`inventory_transfers.cancel`) — solo en estados tempranos.
  - Resolver diferencias (`inventory_transfers.resolve_differences`).
- [ ] Picker de IMEIs para traslados serializados.

#### Cuentas por cobrar
- [ ] Página `/accounts-receivable` (listado con filtros: cliente, status, fechas).
- [ ] Página `/accounts-receivable/:id` (detalle + pagos).
- [ ] Formulario de cobro (POST /api/accounts-receivable/{id}/payments) con snapshot de tasa.
- [ ] Listado de payment_receipts del cliente.

#### Cuentas por pagar
- [ ] Similar a CxC pero para proveedores.

### Criterio de aceptación

- Se pueden crear traslados, ejecutar las 3 fases, resolver diferencias.
- IMEIs se reservan al preparar y se mueven de almacén al recibir.
- CRUD completo de clientes/proveedores funciona.
- Cobros/pagos con snapshot de tasa se reflejan en saldos.

### Permisos del backend

```
inventory_transfers.* (view, create, prepare, dispatch, receive, cancel, resolve_differences, admin)
customers.*, customer_groups.view
suppliers.*
accounts_receivable.*, accounts_payable.*
payment_receipts.view, payment_receipts.void
```

---

## Fase 4 — Access Control (gestión de usuarios/permisos)

**Objetivo**: el `Owner`/`Administrador` puede gestionar usuarios, roles, permisos extra y scopes
desde la UI.

### Entregables

#### Usuarios
- [ ] Página `/users` (listado con búsqueda, filtros: estado, rol, scope).
- [ ] Página `/users/new` (crear usuario + asignar roles).
- [ ] Página `/users/:id` (detalle con tabs):
  - **Perfil**: datos básicos + cambio de estado (active/inactive).
  - **Roles**: asignar/quitar roles (multi-select).
  - **Permisos extra y denegados**: editor visual con el catálogo.
  - **Scopes**: editor por categoría (sucursales, almacenes, grupos).
  - **Capabilities**: preview de permisos efectivos (qué puede + qué no puede).
- [ ] Indicador visual de scope status (none / allow / restrict) en el listado.

#### Roles
- [ ] Página `/roles` (listado de roles del tenant).
- [ ] Página `/roles/new` (crear rol desde cero).
- [ ] Página `/roles/:id` (detalle con editor de permisos).
- [ ] Duplicar rol (`POST /api/access/roles/{role}/duplicate`).
- [ ] Preview de capacidades del rol (`GET /api/access/roles/{role}/preview`).
- [ ] Roles protegidos (`is_protected: true`) con candado y sin botón eliminar.

#### Catálogo de permisos
- [ ] Página `/permissions` (vista de solo lectura del catálogo).
- [ ] Tree colapsable por módulo, con badge de cantidad y marker de peligrosos.
- [ ] Búsqueda por nombre de permiso.

#### Auditoría
- [ ] Página `/audit/access` (logs de cambios en usuarios/permisos).
- [ ] Eventos: `access.role.duplicated`, `access.user.overrides_replaced`, etc.

### Criterio de aceptación

- Owner puede crear usuarios, asignar roles, agregar permisos extra, asignar scopes.
- Cambio de tenant muestra scopes del tenant activo.
- Preview de capabilities es claro para el admin.
- Roles protegidos no se pueden borrar.
- Cambios se reflejan en audit log.

### Permisos del backend

```
users.*, roles.*, permissions.view
audit.view (para logs de access)
```

---

## Fase 5 — SaaS Master (Platform Admin)

**Objetivo**: gestión global del SaaS — grupos empresariales, spinoffs, Platform Admins.

### Entregables

- [ ] Login separado para Platform Admin (`POST /api/auth/platform-login`).
- [ ] Layout distinto (sin sidebar de tenant, con sidebar de SaaS).
- [ ] Página `/master/dashboard` (stats globales).
- [ ] Página `/master/groups` (listado de grupos empresariales).
- [ ] Página `/master/groups/new` (crear grupo + setup inicial + group_owner).
- [ ] Página `/master/groups/:id` (detalle: info + spinoffs).
- [ ] Crear spinoff desde grupo (`POST /api/master/groups/{id}/tenants`).
- [ ] Página `/master/admins` (listado de Platform Admins).
- [ ] Página `/master/admins/new` (crear/promover Platform Admin).
- [ ] Reset password de Platform Admin.
- [ ] Switch a vista de tenant (impersonation para soporte).

### Criterio de aceptación

- Platform Admin puede ver todos los grupos y spinoffs.
- Crear grupo asigna automáticamente un group_owner.
- Crear spinoff genera empresa nueva con admin inicial.
- Platform Admin no tiene permisos de tenant (solo master).

### Notas

- Esta fase requiere la lógica de Platform Admin ya implementada en el backend
  (`/api/master/*` + `EnsurePlatformAdmin` middleware).
- La UI debe distinguir visualmente "Platform Admin" vs "Admin de empresa".

---

## Fase 6 — PWA + Offline (futuro)

**Objetivo**: la app es instalable como PWA y funciona offline para uso básico (consultar
catálogo, ver tasas activas).

### Entregables

- [ ] `manifest.json` con iconos y nombre.
- [ ] Service Worker con estrategia Cache-First para assets estáticos.
- [ ] Cache del catálogo de productos + tasas en IndexedDB.
- [ ] Fallback offline: "Estás offline. Mostrando datos en cache."
- [ ] Sincronización en background cuando vuelve online.
- [ ] Indicador online/offline en topbar.

### Notas

- Requiere decisión previa sobre qué datos son cacheables (catálogo de productos, tasas) y
  cuáles no (CxC, ventas).
- El sync worker PHP sigue siendo la fuente de verdad para escritura.

---

## Fase 7 — Reportes + Analytics (futuro)

**Objetivo**: dashboards avanzados, reportes exportables, visualizaciones.

### Entregables

- [ ] Página `/reports` con tabs por tipo de reporte:
  - Stock general / bajo stock / movimientos.
  - Ventas por período / sucursal / cajero / método de pago.
  - CxC aging / CxP aging.
  - Financiero: balance neto, márgenes.
- [ ] Exportación CSV de cada reporte (ya soportado por el backend).
- [ ] Charts (con Recharts o similar):
  - Línea de tiempo de ventas.
  - Barras de ventas por sucursal.
  - Pie chart de métodos de pago.
- [ ] Filtros avanzados con date range picker.

### Notas

- Requiere revisar `docs/REPORTES_WEB_VENTAS_POS_*` (borrado) y `docs/API.md` sección Reports.

---

## Convenciones de fase

### Reglas generales

1. **Cada fase termina con tests pasando** (Vitest unit + Testing Library integration + Playwright
   E2E para flujos clave).
2. **Cada fase termina con `pnpm build` limpio** (sin warnings de TS, ESLint, o bundle size
   excesivo).
3. **Cada fase actualiza este documento**: cambiar ☐ → ✅ en la fila correspondiente.
4. **Cada fase se entrega con un PR** que incluye:
   - Código de la fase.
   - Tests asociados.
   - Notas en `docs/IMPLEMENTATION_LOG.md`.
   - Capturas o video corto del flujo principal (opcional pero recomendado).
5. **Permisos nunca se asumen** — cada feature declara explícitamente qué permiso necesita y lo
   valida con `<Can>`.

### Branch naming

```
feature/fase-N-<nombre-corto>
fix/<descripcion-corta>
chore/<descripcion-corta>
```

### Commits

Conventional commits en español cuando sean específicos del proyecto:
```
feat(inventory): agregar sheet de edición rápida
fix(pos): cotizar precio al cambiar lista
chore(deps): actualizar TanStack Query a v5.50
docs(permissions): documentar field masking
test(pos): cubrir flujo de pagos mixtos
```

---

## Referencias cruzadas

- **Arquitectura del frontend**: `docs/FRONTEND_ARQUITECTURA.md`.
- **Sistema de permisos**: `docs/FRONTEND_PERMISSIONS.md`.
- **API del backend**: `docs/API.md`.
- **Contrato API para frontend**: `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md`.
- **Auditoría**: `docs/AUDIT_2026-07-11/`.
- **Permisos originales**: `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md`,
  `docs/INSTRUCCIONES_FRONTEND_SCOPES.md`.