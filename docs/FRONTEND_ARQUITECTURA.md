# Frontend — Arquitectura

> **Estado (2026-07-13)**: Documento de diseño previo a la implementación. El frontend aún no existe en
> el repo; este doc define el stack, la estructura y los patrones que se seguirán en Fase 1+.
>
> **Contexto**: este frontend consume el backend Laravel descrito en `docs/API.md`. El backend provee
> un sistema de permisos complejo (101 permisos + overrides + scopes + field masking) — la arquitectura
> del frontend está diseñada específicamente para integrarse limpiamente con ese sistema.

---

## 1. Visión y objetivos

Construir una **aplicación web moderna SPA** que reemplace al portal administrativo anterior (Blade +
JS vanilla) eliminado el 2026-07-13. El nuevo cliente:

- **Funciona en navegador** — tanto en una PC local (acceso al backend local) como en la nube
  (acceso al backend en el VPS).
- **Es denso y administrativo** — pensado para uso operativo y gerencial, no para marketing.
- **Respeta el sistema de permisos** del backend — la UI se adapta en tiempo real a lo que el user
  puede o no puede hacer.
- **Es mantenible y modular** — fácil de extender con nuevos módulos sin reescribir.

### No-objetivos

- **No es SSR** — es admin interno, no necesita SEO ni first-paint optimizado para usuarios anónimos.
- **No es PWA (todavía)** — se puede agregar en Fase 6 si el uso offline es prioritario.
- **No es mobile-first** — la densidad de datos está pensada para desktop. Será responsive pero no
  mobile-first.
- **No comparte código con el backend** — son dos repos lógicos separados (aunque vivan en la misma
  carpeta del monorepo). No intentamos reusar PHP/TS.

---

## 2. Stack tecnológico

### 2.1 Core

| Capa | Elección | Justificación |
|---|---|---|
| **Lenguaje** | TypeScript 5.x estricto | Tipos end-to-end con backend (validación cruzada en compile time). |
| **Framework** | React 18 | Ecosistema más maduro para auth patterns + state management complejo. |
| **Bundler** | Vite 6 | HMR instantáneo, build optimizado, dev experience rápido. |
| **Package manager** | pnpm 9 | Eficiente en disco, workspaces, monorepo-friendly. |

### 2.2 Routing y data

| Capa | Elección | Justificación |
|---|---|---|
| **Router** | TanStack Router v1 | Type-safe file-based routing con typegen automático desde las rutas. |
| **Server state** | TanStack Query v5 | Cache, refetch, optimistic updates, polling. Crítico para los catálogos que cambian. |
| **HTTP** | Axios con interceptors | Bearer + X-Tenant automáticos, manejo de 401/403/422, retries opcionales. |
| **Forms** | React Hook Form + Zod | Sin re-renders innecesarios. Zod schemas reusables para validación client+server. |
| **Tablas densas** | TanStack Table v8 | Estándar para tablas data-dense (filtros, sort, paginación server-side, columnas configurables). |

### 2.3 UI y UX

| Capa | Elección | Justificación |
|---|---|---|
| **CSS framework** | Tailwind CSS 4 | Utility-first, sin CSS-in-JS runtime, tree-shakeable. |
| **Primitives accesibles** | Radix UI primitives | Focus management, ARIA, keyboard nav — sin estilos impuestos. |
| **Componentes propios** | Construidos sobre Radix + Tailwind | Botones, modales, sheets, dropdowns, etc. con identidad visual del proyecto. |
| **Iconos** | Lucide React | Tree-shakeable, miles de iconos, estilo consistente. |
| **Toasts** | Sonner | Accesible, sin config, API simple. |
| **Tema** | next-themes (light/dark) | Persistencia en localStorage, respeta `prefers-color-scheme`. |
| **Fechas** | date-fns | Tree-shakeable, sin Moment.js. |
| **Money** | Implementación propia | Formateo USD/VES con snapshot de tasa (ver §10). |

### 2.4 State

| Capa | Elección | Justificación |
|---|---|---|
| **UI state global** | Zustand | Sidebar, modales, tema. Sin providers, sin boilerplate. |
| **Estado de permisos** | React Context (PermissionContext) | Accesible desde cualquier componente via hooks. |
| **Estado de scopes** | React Context (ScopeContext) | Similar a PermissionContext. |
| **Server state** | TanStack Query | Cache, refetch automático, integration con axios. |
| **URL state** | TanStack Router search params | Filtros compartibles, navegación con state preservado. |

### 2.5 Tooling

| Capa | Elección | Justificación |
|---|---|---|
| **Linter** | ESLint + plugin de TanStack Router | Reglas estrictas de hooks, tipos, imports. |
| **Formatter** | Prettier | Formato consistente, sin discusión. |
| **Git hooks** | Husky + lint-staged | Lint + format solo en archivos modificados. |
| **Tests** | Vitest + Testing Library + Playwright | Vitest es nativo de Vite. Playwright para E2E. |
| **Tipos desde backend** | `php artisan types:export` (a crear en Fase 1) | Genera tipos TS desde FormRequests/Resources del backend. |

### 2.6 Lo que NO usamos (y por qué)

- **Next.js / Remix / SSR** — no necesitamos SEO, no hay contenido público.
- **Redux / Redux Toolkit** — Zustand cubre todo lo que necesitamos con menos boilerplate.
- **Apollo / GraphQL** — el backend es REST bien tipado. GraphQL agregaría una capa innecesaria.
- **CSS-in-JS (styled-components, emotion)** — Tailwind + CSS variables es suficiente.
- **UI kits cerrados (MUI, Chakra, Ant Design)** — atan el diseño a una librería. Queremos identidad
  visual propia con Radix primitives.

---

## 3. Estructura del proyecto

```
frontend/
├── src/
│   ├── api/                          # Cliente HTTP + endpoints tipados
│   │   ├── client.ts                 # Axios instance con interceptors
│   │   ├── endpoints/
│   │   │   ├── auth.ts               # /api/auth/*
│   │   │   ├── inventory-center.ts   # /api/inventory-center/*
│   │   │   ├── products.ts           # /api/products/*
│   │   │   ├── price-lists.ts
│   │   │   ├── users.ts
│   │   │   ├── roles.ts
│   │   │   ├── permissions.ts
│   │   │   └── ...                   # uno por módulo del backend
│   │   └── types.ts                  # tipos compartidos (Money, Paginated, etc.)
│   │
│   ├── auth/                         # Flujo de login + sesión
│   │   ├── LoginPage.tsx
│   │   ├── TenantPicker.tsx
│   │   ├── ChangePasswordPage.tsx    # (futuro)
│   │   ├── session.ts                # Zustand store de sesión actual
│   │   └── guards.tsx                # <RequireAuth>, <RequireTenant>
│   │
│   ├── components/                   # Componentes UI base reusables
│   │   ├── ui/                       # Botones, inputs, modales (shadcn-style)
│   │   │   ├── Button.tsx
│   │   │   ├── Input.tsx
│   │   │   ├── Select.tsx
│   │   │   ├── Modal.tsx
│   │   │   ├── Sheet.tsx
│   │   │   ├── Toast.tsx             # wrapper de Sonner
│   │   │   ├── Tooltip.tsx
│   │   │   ├── Tabs.tsx
│   │   │   ├── Checkbox.tsx
│   │   │   ├── RadioGroup.tsx
│   │   │   ├── Switch.tsx
│   │   │   ├── Combobox.tsx
│   │   │   ├── DataTable.tsx         # wrapper de TanStack Table + estilos
│   │   │   ├── DatePicker.tsx
│   │   │   ├── NumberInput.tsx
│   │   │   ├── SearchInput.tsx
│   │   │   ├── EmptyState.tsx
│   │   │   ├── Skeleton.tsx
│   │   │   ├── Spinner.tsx
│   │   │   └── ErrorBoundary.tsx
│   │   ├── layout/                   # Layout de la app
│   │   │   ├── AppShell.tsx          # sidebar + topbar + main
│   │   │   ├── Sidebar.tsx
│   │   │   ├── Topbar.tsx
│   │   │   ├── TenantSwitcher.tsx
│   │   │   ├── UserMenu.tsx
│   │   │   ├── PageHeader.tsx
│   │   │   └── PageLayout.tsx
│   │   └── permissions/              # Componentes de permisos (ver docs/FRONTEND_PERMISSIONS.md)
│   │       ├── Can.tsx               # <Can I="inventory.update">...
│   │       ├── HasScope.tsx
│   │       └── PermissionDenied.tsx
│   │
│   ├── features/                     # Código por módulo de negocio
│   │   ├── dashboard/
│   │   │   ├── api.ts                # hooks de TanStack Query
│   │   │   ├── pages/
│   │   │   │   └── DashboardPage.tsx
│   │   │   ├── components/
│   │   │   │   ├── MetricsCards.tsx
│   │   │   │   ├── AlertsPanel.tsx
│   │   │   │   └── RecentActivity.tsx
│   │   │   └── schemas.ts            # Zod schemas del módulo
│   │   ├── inventory-center/
│   │   │   ├── api.ts
│   │   │   ├── pages/
│   │   │   │   ├── InventoryListPage.tsx
│   │   │   │   └── ProductDetailPage.tsx
│   │   │   ├── components/
│   │   │   │   ├── ProductTable.tsx
│   │   │   │   ├── ProductFilters.tsx
│   │   │   │   ├── ProductSheet.tsx
│   │   │   │   ├── StockByWarehouse.tsx
│   │   │   │   ├── SerialsTable.tsx
│   │   │   │   ├── PriceListsEditor.tsx
│   │   │   │   ├── MovementsTable.tsx
│   │   │   │   └── BulkActionsMenu.tsx
│   │   │   └── schemas.ts
│   │   ├── sales/                    # (Fase 2)
│   │   ├── pos/                      # (Fase 2)
│   │   ├── cash-register/            # (Fase 2)
│   │   ├── customers/
│   │   ├── suppliers/
│   │   ├── purchases/
│   │   ├── inventory-transfers/
│   │   ├── accounts-receivable/
│   │   ├── accounts-payable/
│   │   ├── currency/
│   │   ├── reports/
│   │   ├── access-control/           # usuarios, roles, permisos, scopes (Fase 4)
│   │   └── saas-master/              # Platform Admin (Fase 5)
│   │
│   ├── hooks/                        # Hooks globales
│   │   ├── useAuth.ts                # acceso al store de sesión
│   │   ├── useDebounce.ts
│   │   ├── useMediaQuery.ts
│   │   └── useLocalStorage.ts
│   │
│   ├── lib/                          # Utilidades puras
│   │   ├── cn.ts                     # classnames helper
│   │   ├── format.ts                 # formatMoney, formatDate, formatNumber
│   │   ├── money.ts                  # Money type + helpers con snapshot de tasa
│   │   ├── api-error.ts              # mapeo de errores HTTP a mensajes user-friendly
│   │   └── slugify.ts
│   │
│   ├── permissions/                  # Sistema de permisos (ver docs/FRONTEND_PERMISSIONS.md)
│   │   ├── PermissionContext.tsx     # Provider + useContext
│   │   ├── useCan.ts                 # useCan(permission)
│   │   ├── useCanAny.ts              # useCanAny([...permissions])
│   │   ├── useCanAll.ts              # useCanAll([...permissions])
│   │   ├── usePermissionCatalog.ts   # TanStack Query: GET /api/access/permission-catalog
│   │   ├── formatCost.ts             # field masking helper
│   │   ├── formatProfit.ts           # field masking helper
│   │   └── constants.ts              # nombres de permisos como constantes
│   │
│   ├── scopes/                       # Sistema de scopes por recurso
│   │   ├── ScopeContext.tsx
│   │   ├── useScopeStatus.ts
│   │   ├── useHasScope.ts            # useHasScope('branches', id)
│   │   └── useUserScopes.ts          # GET /api/tenants/{tenant}/users/{user}/scopes
│   │
│   ├── routes/                       # Configuración de TanStack Router
│   │   ├── __root.tsx                # Layout raíz
│   │   ├── _authed.tsx               # Layout autenticado (sidebar+topbar)
│   │   ├── _authed.dashboard.tsx
│   │   ├── _authed.inventory.tsx
│   │   └── ...
│   │
│   ├── stores/                       # Zustand stores globales
│   │   ├── session.ts                # sesión actual (token, user, tenant)
│   │   ├── ui.ts                     # sidebar colapsado, modales globales, tema
│   │   └── filters.ts                # filtros persistentes por página
│   │
│   ├── styles/                       # CSS global
│   │   ├── globals.css               # @tailwind base + tokens
│   │   └── tokens.css                # variables CSS custom (colores, fuentes, spacing)
│   │
│   ├── types/                        # Tipos globales
│   │   ├── api.ts                    # tipos compartidos (Paginated<T>, ApiError)
│   │   ├── money.ts                  # Money, Currency
│   │   └── tenant.ts                 # Tenant, User
│   │
│   ├── App.tsx                       # Providers + Router
│   └── main.tsx                      # entrypoint
│
├── public/
│   ├── favicon.svg
│   └── locales/                      # (futuro i18n)
│
├── index.html
├── package.json
├── pnpm-lock.yaml
├── tsconfig.json
├── tsconfig.node.json
├── vite.config.ts
├── tailwind.config.ts
├── postcss.config.js
├── components.json                   # shadcn-style config
├── .eslintrc.cjs
├── .prettierrc
├── .gitignore
├── README.md
└── ARCHITECTURE.md                   # (referencia a docs/FRONTEND_ARQUITECTURA.md)
```

---

## 4. Convenciones de código

### 4.1 Naming

| Elemento | Convención | Ejemplo |
|---|---|---|
| Componentes | PascalCase | `ProductTable.tsx` |
| Hooks | camelCase con prefijo `use` | `useCan.ts` |
| Utilidades | camelCase | `formatMoney.ts` |
| Constantes | UPPER_SNAKE_CASE | `PERMISSION_INVENTORY_UPDATE` |
| Tipos/Interfaces | PascalCase | `interface ProductDetail` |
| Archivos de componentes | mismo nombre que el componente | `ProductTable.tsx` |
| Archivos de hooks | `use` + camelCase | `usePermissionCatalog.ts` |
| Rutas (URL) | kebab-case | `/inventory-center` |
| Endpoints (constantes) | UPPER_SNAKE_CASE | `ENDPOINT_PRODUCTS_LIST` |

### 4.2 Imports

- **Absolutos** vía alias `@/` que apunta a `src/`.
- **Orden** (auto-organizado por ESLint): externos → internos → tipos → estilos.
- **Sin barrel files** excepto `components/ui/index.ts` para shadcn-style.

### 4.3 Estilo

- **No emojis** en código a menos que el usuario lo pida.
- **No comentarios obvios** — el código debe ser self-explanatory.
- **Comentarios solo donde la lógica no es trivial** (multi-tenancy, snapshot de tasa, etc.) en español.
- **Funciones puras** cuando sea posible.
- **Props explícitas** con tipos TS, no `any`, no `unknown` salvo casos justificados.

### 4.4 Manejo de errores

- **Axios interceptor** captura 401 → limpia sesión + redirige a `/login`.
- **Axios interceptor** captura 403 → toast "No tienes permiso para esta acción".
- **Axios interceptor** captura 422 → extrae `errors` field y los expone via context al formulario.
- **Errores no controlados** → ErrorBoundary muestra UI de fallback + reporta a consola.
- **Errores de TanStack Query** → `error` state del hook + `ErrorState` component.

---

## 5. Patrón de capas

```
+-------------------------------------+
|           Pages (rutas)             |  ← Componentes conectados a TanStack Query
+-------------------------------------+
                ↓ usa
+-------------------------------------+
|      Feature components            |  ← Tablas, forms, sheets específicos
+-------------------------------------+
                ↓ usa
+-------------------------------------+
|     UI components (genéricos)       |  ← Button, Input, Modal, etc.
+-------------------------------------+
                ↓ consume
+-------------------------------------+
|       API layer (axios + types)     |  ← Endpoints tipados con Zod schemas
+-------------------------------------+
                ↓ HTTP
+-------------------------------------+
|      Laravel Backend (JSON)         |  ← /api/* con Bearer + X-Tenant
+-------------------------------------+
```

**Reglas**:
- Las pages nunca llaman directamente a `axios.get(...)`. Siempre van vía hooks de TanStack Query.
- Los feature components no contienen lógica de fetching — reciben props.
- Los UI components no saben nada del dominio — son agnósticos de inventory/sales/etc.
- Los endpoints en `api/endpoints/` son funciones puras que retornan tipos validados con Zod.

---

## 6. Sistema de tipos

### 6.1 Tipos desde el backend

El backend expone tipos via:
- `App\Http\Requests\...` (validación)
- `App\Http\Resources\...` (shape de respuesta)
- `app/Modules/.../Models/...` (modelos Eloquent)

**Fase 1**: escribir manualmente los tipos TS en `src/types/` y `src/api/endpoints/` basados en
`docs/API.md` y los Resources del backend.

**Fase 2+ (opcional)**: crear un comando Artisan `php artisan types:export` que lea los Resources
y genere los tipos TS automáticamente. Mantiene sincronizado el contrato.

### 6.2 Zod schemas

Cada endpoint importante tiene un Zod schema en `src/api/endpoints/<modulo>.ts`:

```typescript
import { z } from 'zod';

export const ProductSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int().positive(),
  sku: z.string().min(1).max(64),
  name: z.string().min(1).max(255),
  tracking_type: z.enum(['quantity', 'serialized']),
  base_price: z.string().regex(/^\d+\.\d{2,4}$/),
  // ...
});

export type Product = z.infer<typeof ProductSchema>;
```

**Uso**:
- Validación de respuestas del backend (en interceptor o en cada query).
- Validación de formularios (React Hook Form + zodResolver).
- Documentación ejecutable del contrato.

### 6.3 Tipos compartidos

```typescript
// src/types/money.ts
export interface Money {
  amount: string;              // decimal como string para evitar float issues
  currency: 'USD' | 'VES';
}

export interface MoneyWithSnapshot extends Money {
  exchange_rate_type_id?: number;
  exchange_rate_type_code?: string;
  exchange_rate?: string;      // snapshot del rate usado
  base_amount?: string;        // en USD si la transacción fue en VES
  base_currency?: 'USD';
}

// src/types/api.ts
export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
  links?: {
    first?: string;
    last?: string;
    prev?: string;
    next?: string;
  };
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}
```

---

## 7. Routing (TanStack Router)

### 7.1 File-based

```
src/routes/
├── __root.tsx                 # <Outlet /> + ErrorBoundary + Providers
├── index.tsx                  # GET / → redirige a /dashboard o /login
├── login.tsx                  # GET /login
├── _authed.tsx                # Layout: sidebar + topbar (RequireAuth guard)
├── _authed.dashboard.tsx      # GET /dashboard
├── _authed.inventory.tsx
├── _authed.inventory.index.tsx                  # /inventory → InventoryListPage
├── _authed.inventory.$productId.tsx            # /inventory/123 → ProductDetailPage
├── _authed.inventory.$productId.edit.tsx       # /inventory/123/edit
├── _authed.sales.tsx
├── _authed.purchases.tsx
└── ...
```

### 7.2 Guards

```tsx
// src/routes/_authed.tsx
export const Route = createFileRoute('/_authed')({
  beforeLoad: ({ location }) => {
    const session = useSessionStore.getState();
    if (!session.token) {
      throw redirect({ to: '/login', search: { redirect: location.href } });
    }
  },
  component: AuthedLayout,
});
```

### 7.3 Search params tipados

```tsx
// /inventory?status=active&tracking=serialized&page=2
const searchSchema = z.object({
  status: z.enum(['active', 'inactive', 'all']).default('all'),
  tracking: z.enum(['quantity', 'serialized', 'all']).default('all'),
  page: z.number().int().positive().default(1),
  search: z.string().default(''),
});

export const Route = createFileRoute('/_authed/inventory/')({
  validateSearch: searchSchema,
  component: InventoryListPage,
});
```

Los filtros son URL-state, compartibles y refrescables.

---

## 8. Server state (TanStack Query)

### 8.1 Configuración global

```typescript
// src/api/query-client.ts
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status === 403) return false;
        return failureCount < 3;
      },
      staleTime: 30_000,         // 30s default
      gcTime: 5 * 60_000,        // 5min
      refetchOnWindowFocus: false,
      refetchOnReconnect: true,
    },
    mutations: {
      retry: false,
    },
  },
});
```

### 8.2 Convenciones

- **Query keys** siempre arrays tipados: `['products', 'list', filters]`, `['product', id]`.
- **Invalidación por jerarquía**: al mutar un producto, invalidar `['products', 'list']` y
  `['product', id]`.
- **Prefetch** en hover de links importantes.
- **Polling** selectivo vía `refetchInterval` solo donde aplica (ej: dashboard, sync status).

### 8.3 Ejemplo

```typescript
// src/features/inventory-center/api.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api/client';
import { ProductSchema, type Product } from './schemas';

export const productKeys = {
  all: ['products'] as const,
  lists: () => [...productKeys.all, 'list'] as const,
  list: (filters: ProductFilters) => [...productKeys.lists(), filters] as const,
  details: () => [...productKeys.all, 'detail'] as const,
  detail: (id: number) => [...productKeys.details(), id] as const,
};

export function useProducts(filters: ProductFilters) {
  return useQuery({
    queryKey: productKeys.list(filters),
    queryFn: async () => {
      const response = await api.get('/api/products', { params: filters });
      return PaginatedSchema(ProductSchema).parse(response);
    },
  });
}

export function useUpdateProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...data }: ProductUpdate) => {
      return api.patch(`/api/products/${id}`, data);
    },
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: productKeys.lists() });
      qc.invalidateQueries({ queryKey: productKeys.detail(id) });
    },
  });
}
```

---

## 9. UI state (Zustand)

### 9.1 Stores globales

```typescript
// src/stores/session.ts
interface SessionState {
  token: string | null;
  user: User | null;
  tenant: Tenant | null;
  permissions: Set<string>;
  roles: Role[];
  scopes: ScopeMap;
  scopeStatus: 'none' | 'allow' | 'restrict';

  setSession(data: LoginResponse): void;
  clearSession(): void;
  switchTenant(slug: string): Promise<void>;
}

export const useSessionStore = create<SessionState>()(
  persist(
    (set, get) => ({
      token: null, user: null, tenant: null,
      permissions: new Set(), roles: [], scopes: emptyScopes, scopeStatus: 'none',
      setSession: (data) => set({ ... }),
      clearSession: () => set({ ... initial }),
      switchTenant: async (slug) => { ... },
    }),
    { name: 'inventory_session' }
  )
);
```

```typescript
// src/stores/ui.ts
interface UiState {
  sidebarCollapsed: boolean;
  theme: 'light' | 'dark';
  activeModal: string | null;

  toggleSidebar(): void;
  setTheme(theme: 'light' | 'dark'): void;
  openModal(id: string): void;
  closeModal(): void;
}
```

### 9.2 Stores de feature

Stores específicos viven en `src/features/<modulo>/store.ts` (si los necesitan).

---

## 10. Sistema visual

### 10.1 Tokens (CSS variables)

```css
/* src/styles/tokens.css */
:root {
  /* Paleta principal */
  --color-primary: 77 53 255;          /* #4D35FF */
  --color-primary-hover: 58 40 204;
  --color-primary-foreground: 255 255 255;

  /* Estados */
  --color-success: 22 163 74;          /* #16A34A */
  --color-warning: 245 158 11;         /* #F59E0B */
  --color-danger: 220 38 38;           /* #DC2626 */
  --color-info: 14 165 233;            /* #0EA5E9 */

  /* Neutros (light) */
  --color-bg: 250 250 250;              /* #FAFAFA */
  --color-surface: 255 255 255;
  --color-border: 229 229 229;          /* #E5E5E5 */
  --color-text-primary: 23 23 23;
  --color-text-secondary: 64 64 64;
  --color-text-muted: 115 115 115;

  /* Tipografía */
  --font-sans: 'Inter', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;

  /* Spacing */
  --space-1: 0.25rem;
  --space-2: 0.5rem;
  --space-3: 0.75rem;
  --space-4: 1rem;
  --space-6: 1.5rem;
  --space-8: 2rem;

  /* Border radius */
  --radius-sm: 4px;
  --radius: 6px;
  --radius-md: 8px;
  --radius-lg: 12px;
}

[data-theme='dark'] {
  --color-bg: 10 10 10;
  --color-surface: 23 23 23;
  --color-border: 38 38 38;
  --color-text-primary: 250 250 250;
  --color-text-secondary: 212 212 212;
  --color-text-muted: 163 163 163;
}
```

### 10.2 Densidad

- Texto base: **14px**.
- Tablas: **12-13px**.
- Inputs: altura **36px** (no 40px como libs mobile-first).
- Padding: **8-12px** en cards, **6-8px** en filas de tabla.
- Sidebar: **240px** expandida, **64px** colapsada.

### 10.3 Iconografía

`Lucide React` con tamaño estándar:
- En línea con texto: **16px**.
- Botones standalone: **18-20px**.
- Empty states: **48-64px**.

---

## 11. Manejo de dinero

### 11.1 Tipos

```typescript
// src/types/money.ts
export type Currency = 'USD' | 'VES';

export interface Money {
  amount: string;                    // "1234.56"
  currency: Currency;
}

// Para mostrar equivalencias (lo que el backend devuelve con snapshot)
export interface MoneyWithRate extends Money {
  base_amount?: string;              // equivalente en USD
  base_currency?: 'USD';
  exchange_rate?: string;            // snapshot del rate al momento de la transacción
  exchange_rate_type_code?: string;  // "BCV", "PARALELO", "TIENDA"
}
```

### 11.2 Helpers

```typescript
// src/lib/money.ts
export function formatMoney(money: Money | string | number, options?: { showCurrency?: boolean }): string {
  const amount = typeof money === 'object' ? money.amount : String(money);
  const currency = typeof money === 'object' ? money.currency : 'USD';
  const num = parseFloat(amount);
  if (isNaN(num)) return '—';
  const formatted = new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(num);
  return options?.showCurrency === false
    ? formatted
    : currency === 'USD' ? `$${formatted}` : `Bs ${formatted}`;
}

export function formatMoneyWithRate(money: MoneyWithRate): string {
  if (!money.base_amount) return formatMoney(money);
  const base = parseFloat(money.base_amount);
  const local = parseFloat(money.amount);
  return `${formatMoney(money)} (${formatMoney(base, { showCurrency: false })} USD @ ${money.exchange_rate})`;
}
```

### 11.3 Field masking

Si el backend devuelve `unit_cost: null` (porque el user no tiene `finance.costs.view`), el helper
muestra `"—"`. **El frontend NO filtra manualmente**, confía en el response.

---

## 12. Conexión con el backend

### 12.1 Variables de entorno

```env
# frontend/.env.local
VITE_API_BASE_URL=http://127.0.0.1:8000/api   # local
VITE_APP_NAME=Sistema de Inventario
```

```env
# frontend/.env.production
VITE_API_BASE_URL=https://app.miinventariofacil.com/api
```

### 12.2 Cliente Axios

```typescript
// src/api/client.ts
import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { useSessionStore } from '@/stores/session';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  timeout: 30_000,
  headers: { Accept: 'application/json' },
});

// Request interceptor: inyecta Bearer + X-Tenant
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const { token, tenant } = useSessionStore.getState();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  if (tenant?.slug) config.headers['X-Tenant'] = tenant.slug;
  return config;
});

// Response interceptor: maneja 401/403/422
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiError>) => {
    const status = error.response?.status;
    const data = error.response?.data;

    if (status === 401) {
      useSessionStore.getState().clearSession();
      window.location.href = '/login';
    }

    if (status === 403) {
      toast.error(data?.message ?? 'No tienes permiso para esta acción.');
    }

    if (status === 422 && data?.errors) {
      // Propagar errors al formulario via contexto
      throw new ValidationError(data.message, data.errors);
    }

    throw error;
  }
);
```

### 12.3 Tipos de respuesta del backend

El backend Laravel envuelve todas las respuestas en `{ data: ... }`. El cliente Axios por defecto
accede a `response.data.data`. Vamos a documentar esto claramente:

```typescript
// El backend retorna:
// { "data": Product, "meta": {...} }
// { "data": Paginated<Product>, "links": {...} }
// { "data": [Product1, Product2] }
// { "message": "error", "errors": {...} }

// El frontend accede a:
// response.data.data → Product | Paginated<Product> | array
// response.data.data.data → array de elementos (en paginated)
// response.data.message → mensaje de error
// response.data.errors → errores de validación por campo
```

---

## 13. Testing

### 13.1 Unit (Vitest)

- Funciones puras (`formatMoney`, `useCan`, etc.)
- Hooks sin red (`renderHook` + mocks)
- Reducers / stores

### 13.2 Integration (Testing Library)

- Componentes con sus providers (PermissionProvider, QueryClientProvider, Router)
- Formularios completos (submit, validación, errores)
- Permisos: el componente se oculta/muestra según `useCan`

### 13.3 E2E (Playwright)

- Flujo de login completo
- Listado de inventario con filtros
- Crear producto + ver en lista
- Cambio de tenant

### 13.4 Tests cross-tenant

Igual que el backend: tests E2E con dos tenants y verificar aislamiento.

---

## 14. Build y deploy

### 14.1 Build local

```bash
cd frontend
pnpm install
pnpm dev           # http://localhost:5173 con proxy a backend en :8000
pnpm build         # genera dist/
pnpm preview       # sirve dist/ localmente para verificar
```

### 14.2 Configuración Vite (proxy en dev)

```typescript
// frontend/vite.config.ts
export default defineConfig({
  plugins: [react(), tanstackRouter(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    rollupOptions: {
      output: {
        manualChunks: {
          'react-vendor': ['react', 'react-dom'],
          'tanstack': ['@tanstack/react-query', '@tanstack/react-router'],
        },
      },
    },
  },
});
```

### 14.3 Deploy

El frontend se construye a bundle estático (`dist/`) y se sirve desde Nginx/Cloudflare Pages.

**Opción A — mismo dominio que el backend** (recomendado):
- Nginx sirve `/api/*` → backend PHP-FPM.
- Nginx sirve `/*` → bundle estático del frontend.
- Una sola URL pública (`https://app.miinventariofacil.com`), routing por path.

**Opción B — subdominio separado**:
- `https://app.miinventariofacil.com` → frontend (Cloudflare Pages).
- `https://api.miinventariofacil.com` → backend (VPS).
- CORS habilitado en backend para el dominio del frontend.

Para la fase inicial usamos **Opción A** porque ya tenemos Nginx configurado.

---

## 15. Referencias cruzadas

- **Backend API**: ver `docs/API.md` (catálogo completo de endpoints).
- **Contrato API para el frontend**: ver `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md`.
- **Sistema de permisos**: ver `docs/FRONTEND_PERMISSIONS.md` (este repo).
- **Fases de implementación**: ver `docs/FRONTEND_FASES.md`.
- **Permisos y scopes**: ver `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md` y `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` (contratos originales del backend).