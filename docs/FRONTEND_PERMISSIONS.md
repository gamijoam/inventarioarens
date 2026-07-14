# Frontend — Sistema de Permisos

> **Para:** El agente opencode (o cualquier desarrollador) que mantenga el frontend web.
> **Fecha:** 2026-07-13.
> **Backend:** Laravel 13 con 101 permisos organizados jerárquicamente + 6 roles base + overrides por
> usuario + scopes por recurso + field masking automático.

Este documento define **cómo el frontend web debe consumir, interpretar y representar el sistema de
permisos del backend**. Es la fuente de verdad para implementar la capa de permisos del frontend.

---

## 1. Visión general

El backend Laravel implementa un sistema de **3 niveles de permisos + scopes + field masking**. El
frontend debe consumirlo de forma **declarativa**, no calcular capabilities en cliente.

### Los 5 mecanismos del backend

| # | Mecanismo | Qué hace | Cómo lo consume el frontend |
|---|---|---|---|
| 1 | **Catálogo de permisos** | Lista jerárquica de 101 permisos agrupados por módulo | `GET /api/access/permission-catalog` |
| 2 | **Roles base** | 6 roles predefinidos con permisos pre-asignados | Se incluyen en `GET /api/auth/me` |
| 3 | **Permisos efectivos** | Lo que el user realmente puede hacer (roles + extras - denies) | `GET /api/auth/me` → `permissions[]` |
| 4 | **Overrides por usuario** | Permisos extra o denegados específicos del user | `GET/PUT/DELETE /api/tenants/{tenant}/users/{user}/overrides` |
| 5 | **Field masking** | Backend oculta campos sensibles según permisos | Si el campo viene `null`, el user no tiene el permiso |
| 6 | **Scopes por recurso** | Filtra qué branches/warehouses/grupos ve el user | `GET/PUT /api/tenants/{tenant}/users/{user}/scopes` |

### Lo que el frontend **NO debe hacer**

- ❌ **No calcular capabilities en cliente** (siempre consultar el backend).
- ❌ **No filtrar manualmente campos sensibles** (el backend ya lo hace).
- ❌ **No hardcodear el catálogo de permisos** (siempre consumir `/api/access/permission-catalog`).
- ❌ **No cachear el scope del user** (puede cambiar en cualquier momento).
- ❌ **No asumir default-deny** — el default es **allow** (default-allow).

---

## 2. Catálogo de permisos

### 2.1 Estructura jerárquica

El backend expone el catálogo en formato **árbol**:

```typescript
interface PermissionCatalogModule {
  module: string;                 // 'sales', 'inventory_transfers', 'products'
  label: string;                  // 'Ventas', 'Traslados', 'Productos'
  verb_count: number;
  actions: PermissionCatalogAction[];
}

interface PermissionCatalogAction {
  verb: string;                   // 'view', 'create', 'cancel', 'update', 'delete', ...
  label: string;                  // 'Ver', 'Crear', 'Cancelar', ...
  permission: string;             // 'sales.view', 'inventory_transfers.cancel', ...
  danger?: 'high' | 'medium';     // marca para UI (acciones peligrosas)
}

interface PermissionCatalog {
  modules: PermissionCatalogModule[];
  verbs: { name: string; label: string }[];
  total_permissions: number;      // 101
  total_modules: number;          // 33
}
```

### 2.2 Consumo

```typescript
// src/permissions/usePermissionCatalog.ts
import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import { PermissionCatalogSchema } from './schemas';

export function usePermissionCatalog() {
  return useQuery({
    queryKey: ['permissions', 'catalog'],
    queryFn: async () => {
      const response = await api.get('/api/access/permission-catalog');
      return PermissionCatalogSchema.parse(response.data.data);
    },
    staleTime: Infinity,           // cambia solo cuando se publica nueva versión del backend
  });
}
```

### 2.3 Render en UI

```tsx
// Componente de admin para asignar permisos a un rol
function RolePermissionsEditor({ roleId }: { roleId: number }) {
  const { data: catalog } = usePermissionCatalog();
  const { data: rolePermissions } = useRolePermissions(roleId);

  if (!catalog) return <Skeleton />;

  return (
    <Accordion>
      {catalog.modules.map((mod) => (
        <AccordionItem key={mod.module} title={
          <span>
            {mod.label} <Badge>{mod.verb_count}</Badge>
          </span>
        }>
          <div className="grid grid-cols-2 gap-2">
            {mod.actions.map((action) => (
              <Checkbox
                key={action.permission}
                checked={rolePermissions?.includes(action.permission)}
                onChange={(checked) => togglePermission(roleId, action.permission, checked)}
              >
                {action.label}
                {action.danger === 'high' && <Badge variant="danger">Peligroso</Badge>}
              </Checkbox>
            ))}
          </div>
        </AccordionItem>
      ))}
    </Accordion>
  );
}
```

---

## 3. Permisos efectivos (sesión)

### 3.1 Estructura retornada por `/api/auth/me`

```typescript
interface SessionData {
  user: User;
  tenant: Tenant;
  token: string;
  expires_at: string;
  roles: Role[];                          // roles asignados al user en este tenant
  permissions: string[];                  // permisos EFECTIVOS (101 permisos disponibles, filtrados)
  scope_status: 'none' | 'allow' | 'restrict';
  scopes: {
    branches: number[];
    warehouses: number[];
    customer_groups: number[];
    vendor_of: number[];
    branches_count: number;
    warehouses_count: number;
    customer_groups_count: number;
    vendor_of_count: number;
  };
}
```

### 3.2 Almacenamiento

```typescript
// src/stores/session.ts
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface SessionState {
  token: string | null;
  user: User | null;
  tenant: Tenant | null;
  permissions: Set<string>;               // ← Set<string> para O(1) lookup
  roles: Role[];
  scopeStatus: 'none' | 'allow' | 'restrict';
  scopes: ScopeMap;

  setSession: (data: SessionData) => void;
  clearSession: () => void;
}

export const useSessionStore = create<SessionState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      tenant: null,
      permissions: new Set(),
      roles: [],
      scopeStatus: 'none',
      scopes: { branches: [], warehouses: [], customer_groups: [], vendor_of: [] },

      setSession: (data) =>
        set({
          token: data.token,
          user: data.user,
          tenant: data.tenant,
          permissions: new Set(data.permissions),
          roles: data.roles,
          scopeStatus: data.scope_status,
          scopes: data.scopes,
        }),

      clearSession: () =>
        set({
          token: null,
          user: null,
          tenant: null,
          permissions: new Set(),
          roles: [],
          scopeStatus: 'none',
          scopes: { branches: [], warehouses: [], customer_groups: [], vendor_of: [] },
        }),
    }),
    {
      name: 'inventory_session',
      partialize: (state) => ({
        // Solo persistir token + tenant + user (NO permissions/scopes, se rehidratan al login)
        token: state.token,
        user: state.user,
        tenant: state.tenant,
      }),
    }
  )
);
```

### 3.3 Refresh al cambiar de tenant

Al hacer `POST /api/auth/switch-tenant`, el backend devuelve nueva sesión con permisos recalculados
para el tenant destino. El store se actualiza en `onSuccess`.

---

## 4. Hooks de permisos

### 4.1 `useCan(permission)`

```typescript
// src/permissions/useCan.ts
import { useSessionStore } from '@/stores/session';

export function useCan(permission: string): boolean {
  const permissions = useSessionStore((s) => s.permissions);
  return permissions.has(permission);
}

// Versión con dependencias reactivas para casos avanzados
export function useCanWithMeta(permission: string): {
  allowed: boolean;
  reason: 'role' | 'override' | 'denied' | 'unknown';
} {
  const { permissions, extras, denied } = usePermissionMeta();
  if (denied.includes(permission)) return { allowed: false, reason: 'denied' };
  if (extras.includes(permission)) return { allowed: true, reason: 'override' };
  if (permissions.has(permission)) return { allowed: true, reason: 'role' };
  return { allowed: false, reason: 'unknown' };
}
```

### 4.2 `useCanAny(permissions)`

```typescript
// src/permissions/useCanAny.ts
export function useCanAny(permissions: string[]): boolean {
  const userPerms = useSessionStore((s) => s.permissions);
  return permissions.some((p) => userPerms.has(p));
}
```

### 4.3 `useCanAll(permissions)`

```typescript
// src/permissions/useCanAll.ts
export function useCanAll(permissions: string[]): boolean {
  const userPerms = useSessionStore((s) => s.permissions);
  return permissions.every((p) => userPerms.has(p));
}
```

### 4.4 `useCanFor(action, resource)`

```typescript
// src/permissions/useCanFor.ts
// Para acciones que requieren múltiples permisos o condiciones sobre el recurso
export function useCanFor(
  action: 'create' | 'view' | 'update' | 'delete',
  resource: 'product' | 'sale' | 'purchase' | 'transfer' | ...
): boolean {
  const permission = `${resource}.${action}`;
  return useCan(permission);
}

// Ejemplo:
// const canEdit = useCanFor('update', 'product');
```

### 4.5 Constantes de permisos

Para evitar typos y tener autocompletado:

```typescript
// src/permissions/constants.ts
export const PERMISSIONS = {
  // Products
  PRODUCTS_VIEW: 'products.view',
  PRODUCTS_CREATE: 'products.create',
  PRODUCTS_UPDATE: 'products.update',
  PRODUCTS_DELETE: 'products.delete',

  // Sales
  SALES_VIEW: 'sales.view',
  SALES_CREATE: 'sales.create',
  SALES_CONFIRM: 'sales.confirm',
  SALES_CANCEL: 'sales.cancel',

  // POS
  POS_VIEW: 'pos.view',
  POS_CHECKOUT: 'pos.checkout',
  POS_CANCEL: 'pos.cancel',

  // Cash Register
  CASH_REGISTER_VIEW: 'cash_register.view',
  CASH_REGISTER_OPEN: 'cash_register.open',
  CASH_REGISTER_CLOSE: 'cash_register.close',

  // Inventory
  INVENTORY_VIEW: 'inventory.view',
  INVENTORY_ADJUST: 'inventory.adjust',
  INVENTORY_TRANSFER: 'inventory.transfer',

  // Transfers
  INVENTORY_TRANSFERS_VIEW: 'inventory_transfers.view',
  INVENTORY_TRANSFERS_CREATE: 'inventory_transfers.create',
  INVENTORY_TRANSFERS_PREPARE: 'inventory_transfers.prepare',
  INVENTORY_TRANSFERS_DISPATCH: 'inventory_transfers.dispatch',
  INVENTORY_TRANSFERS_RECEIVE: 'inventory_transfers.receive',
  INVENTORY_TRANSFERS_CANCEL: 'inventory_transfers.cancel',
  INVENTORY_TRANSFERS_RESOLVE_DIFFERENCES: 'inventory_transfers.resolve_differences',
  INVENTORY_TRANSFERS_ADMIN: 'inventory_transfers.admin',

  // Customers
  CUSTOMERS_VIEW: 'customers.view',
  CUSTOMERS_CREATE: 'customers.create',
  CUSTOMERS_UPDATE: 'customers.update',
  CUSTOMERS_DELETE: 'customers.delete',

  // Suppliers
  SUPPLIERS_VIEW: 'suppliers.view',
  SUPPLIERS_CREATE: 'suppliers.create',
  SUPPLIERS_UPDATE: 'suppliers.update',
  SUPPLIERS_DELETE: 'suppliers.delete',

  // Purchases
  PURCHASES_VIEW: 'purchases.view',
  PURCHASES_CREATE: 'purchases.create',
  PURCHASES_RECEIVE: 'purchases.receive',
  PURCHASES_CANCEL: 'purchases.cancel',

  // Accounts
  ACCOUNTS_RECEIVABLE_VIEW: 'accounts_receivable.view',
  ACCOUNTS_RECEIVABLE_COLLECT: 'accounts_receivable.collect',
  ACCOUNTS_PAYABLE_VIEW: 'accounts_payable.view',
  ACCOUNTS_PAYABLE_PAY: 'accounts_payable.pay',

  // Reports
  REPORTS_VIEW: 'reports.view',
  FINANCE_REPORTS_VIEW: 'finance_reports.view',

  // Currency
  CURRENCY_VIEW: 'currency.view',
  CURRENCY_UPDATE: 'currency.update',

  // Warranties
  WARRANTY_POLICIES_VIEW: 'warranty_policies.view',
  WARRANTY_CLAIMS_VIEW: 'warranty_claims.view',
  WARRANTY_CLAIMS_REVIEW: 'warranty_claims.review',
  WARRANTY_CLAIMS_RESOLVE: 'warranty_claims.resolve',

  // Branches
  BRANCHES_VIEW: 'branches.view',
  BRANCHES_CREATE: 'branches.create',
  BRANCHES_UPDATE: 'branches.update',
  BRANCHES_DELETE: 'branches.delete',

  // Warehouses
  WAREHOUSES_VIEW: 'warehouses.view',
  WAREHOUSES_CREATE: 'warehouses.create',
  WAREHOUSES_UPDATE: 'warehouses.update',
  WAREHOUSES_DELETE: 'warehouses.delete',

  // Access Control
  USERS_VIEW: 'users.view',
  USERS_CREATE: 'users.create',
  USERS_UPDATE: 'users.update',
  USERS_DELETE: 'users.delete',
  USERS_STATUS: 'users.status',
  USERS_ROLES: 'users.roles',
  ROLES_VIEW: 'roles.view',
  ROLES_CREATE: 'roles.create',
  ROLES_UPDATE: 'roles.update',
  ROLES_DELETE: 'roles.delete',

  // Finance
  FINANCE_COSTS_VIEW: 'finance.costs.view',

  // Settings
  SETTINGS_MANAGE: 'settings.manage',
  AI_CONFIGURE: 'ai.configure',

  // Sync
  SYNC_VIEW: 'sync.view',
  SYNC_MANAGE: 'sync.manage',

  // Tenants (Platform Admin)
  TENANTS_VIEW: 'tenants.view',
} as const;

export type PermissionName = typeof PERMISSIONS[keyof typeof PERMISSIONS];
```

---

## 5. Componentes de permisos

### 5.1 `<Can>`

```typescript
// src/components/permissions/Can.tsx
import type { ReactNode } from 'react';
import { useCan } from '@/permissions/useCan';

interface CanProps {
  I: string;                          // permiso requerido
  fallback?: ReactNode;               // qué mostrar si NO tiene permiso
  children: ReactNode;
}

export function Can({ I, fallback = null, children }: CanProps) {
  return useCan(I) ? <>{children}</> : <>{fallback}</>;
}

// Uso:
// <Can I="products.create">
//   <Button onClick={openCreateModal}>Nuevo producto</Button>
// </Can>
```

### 5.2 `<CanAny>`

```tsx
interface CanAnyProps {
  any: string[];
  fallback?: ReactNode;
  children: ReactNode;
}

export function CanAny({ any: permissions, fallback = null, children }: CanAnyProps) {
  return useCanAny(permissions) ? <>{children}</> : <>{fallback}</>;
}

// <CanAny any={['sales.view', 'reports.view', 'finance_reports.view']}>
//   <NavLink to="/sales">Ventas</NavLink>
// </CanAny>
```

### 5.3 `<PermissionDenied>`

Para usar como fallback informativo:

```tsx
function PermissionDenied({ permission }: { permission: string }) {
  return (
    <div className="rounded-lg border border-dashed border-warning bg-warning/5 p-4 text-sm">
      <div className="flex items-center gap-2 text-warning">
        <Lock className="h-4 w-4" />
        <span>No tienes permiso para esta acción.</span>
      </div>
      <p className="mt-1 text-muted-foreground">
        Permiso requerido: <code className="rounded bg-muted px-1 py-0.5">{permission}</code>
      </p>
    </div>
  );
}

// Uso:
// <Can I="products.delete" fallback={<PermissionDenied permission="products.delete" />}>
//   <DeleteProductButton />
// </Can>
```

---

## 6. Field masking automático

### 6.1 El permiso `finance.costs.view`

Es un permiso **binario** (sin scope) que el backend chequea antes de serializar campos sensibles.
Asignado por default a `Owner`, `Administrador` y `Gerente`.

### 6.2 Recursos que aplican masking

| Resource | Campos ocultos si NO tienes `finance.costs.view` |
|---|---|
| `PurchaseItemResource` | `unit_cost`, `total_cost`, `base_unit_cost`, `base_total_cost` |
| `ProductEntryItemResource` | `unit_cost` |
| `StockMovementResource` | `unit_cost` |
| `KardexService` (movimientos) | `unit_cost` |

**El campo se retorna como `null`** (no se omite) cuando el user no tiene el permiso. Esto permite
al frontend detectar el masking.

### 6.3 Helpers de formateo

```typescript
// src/permissions/formatCost.ts
export function formatCost(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  const num = typeof value === 'string' ? parseFloat(value) : value;
  if (Number.isNaN(num)) return '—';
  return `$${num.toFixed(2)}`;
}

// Uso:
// <TableCell>{formatCost(item.unit_cost)}</TableCell>
//
// Si el VENDEDOR ve esta celda, mostrará "—" (campo null en backend).
// Si el GERENTE ve esta celda, mostrará "$120.50".
```

```typescript
// src/permissions/formatMoney.ts
// Para columnas que SIEMPRE se muestran (no son costo) pero pueden ser sensibles
// (ej: ganancias, márgenes). Misma lógica pero con label configurable.
export function formatRestricted(value: number | string | null | undefined, label = '—'): string {
  if (value === null || value === undefined) return label;
  return formatCost(value);
}
```

### 6.4 Detectar masking vs dato legítimo null

```typescript
// src/permissions/isRestrictedField.ts
export function isRestrictedField(value: unknown): boolean {
  return value === null && (window as any).__LAST_KNOWN_PERMISSIONS__?.includes('finance.costs.view') === false;
}
```

**Pero esto es raramente necesario**. Por convención: si el campo es `null` en una respuesta del
backend en un campo que la documentación dice ser `number`, probablemente es masking. El helper
`formatCost(null) → '—'` cubre el 99% de los casos.

---

## 7. Scopes por recurso

### 7.1 Estructura

El scope del user **per-tenant** define a qué recursos tiene acceso:

```typescript
interface ScopeMap {
  branches: number[];                 // IDs de sucursales
  warehouses: number[];               // IDs de almacenes
  customer_groups: number[];          // IDs de grupos de cliente
  vendor_of: number[];                // IDs de customer_groups donde es VENDOR
}

type ScopeStatus = 'none' | 'allow' | 'restrict';
```

| `scope_status` | Significado |
|---|---|
| `'none'` | Sin scope asignado, ve TODO (default-allow). |
| `'allow'` | Scope asignado pero vacío `[]`, ve TODO. |
| `'restrict'` | Scope asignado con IDs, ve solo esos. |

### 7.2 Hooks

```typescript
// src/scopes/useScopeStatus.ts
export function useScopeStatus(): ScopeStatus {
  return useSessionStore((s) => s.scopeStatus);
}

// src/scopes/useHasScope.ts
export function useHasScope(category: keyof ScopeMap, resourceId: number): boolean {
  const { scopeStatus, scopes } = useSessionStore((s) => ({ scopeStatus: s.scopeStatus, scopes: s.scopes }));
  if (scopeStatus !== 'restrict') return true;        // default-allow
  return scopes[category].includes(resourceId);
}

// src/scopes/useUserScopes.ts
// Para la UI de admin de scopes (Fase 4)
export function useUserScopes(userId: number) {
  return useQuery({
    queryKey: ['scopes', 'user', userId],
    queryFn: async () => {
      const tenant = useSessionStore.getState().tenant;
      const response = await api.get(`/api/tenants/${tenant!.id}/users/${userId}/scopes`);
      return UserScopesSchema.parse(response.data.data);
    },
  });
}
```

### 7.3 Componentes

```tsx
// src/components/permissions/HasScope.tsx
interface HasScopeProps {
  category: 'branches' | 'warehouses' | 'customer_groups' | 'vendor_of';
  id: number;
  fallback?: ReactNode;
  children: ReactNode;
}

export function HasScope({ category, id, fallback = null, children }: HasScopeProps) {
  return useHasScope(category, id) ? <>{children}</> : <>{fallback}</>;
}

// Uso:
// {branches.map(branch => (
//   <HasScope key={branch.id} category="branches" id={branch.id} fallback={<DisabledCard />}>
//     <BranchCard branch={branch} />
//   </HasScope>
// ))}
```

### 7.4 Banner de scope vacío

```tsx
// Cuando scopeStatus === 'none', mostrar banner educativo en el admin
function ScopeStatusBanner() {
  const status = useScopeStatus();
  const canEdit = useCan(PERMISSIONS.USERS_UPDATE);

  if (status !== 'none' || !canEdit) return null;

  return (
    <Alert variant="warning" className="mb-4">
      <Lock className="h-4 w-4" />
      <AlertTitle>Sin asignación de scopes</AlertTitle>
      <AlertDescription>
        Este usuario ve TODOS los recursos del tenant (default-allow).
        Recomendado asignar scopes para restringir acceso.
      </AlertDescription>
    </Alert>
  );
}
```

---

## 8. Manejo de errores HTTP

### 8.1 Axios interceptor

```typescript
// src/api/client.ts (continuación)
import { ApiError, ValidationError } from './errors';

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiError>) => {
    const status = error.response?.status;
    const data = error.response?.data;

    switch (status) {
      case 401:
        // Token inválido o expirado → limpiar sesión + redirigir
        useSessionStore.getState().clearSession();
        toast.error('Tu sesión expiró. Vuelve a iniciar sesión.');
        window.location.href = '/login';
        break;

      case 403:
        // Permiso insuficiente → toast, NO redirigir
        toast.error(data?.message ?? 'No tienes permiso para esta acción.');
        break;

      case 404:
        // Recurso no encontrado (probablemente borrado o cross-tenant)
        toast.error('Recurso no encontrado.');
        break;

      case 422:
        // Validación fallida → propagar al formulario
        throw new ValidationError(data?.message ?? 'Datos inválidos', data?.errors ?? {});
        break;

      case 500:
        toast.error('Error del servidor. Por favor intenta de nuevo.');
        // Opcional: reportar a Sentry
        break;

      default:
        toast.error('Error de red. Verifica tu conexión.');
    }

    return Promise.reject(error);
  }
);
```

### 8.2 Errores en formularios

```tsx
// src/features/products/components/ProductForm.tsx
function ProductForm({ onSubmit }: { onSubmit: SubmitHandler<ProductFormData> }) {
  const form = useForm<ProductFormData>({
    resolver: zodResolver(productSchema),
  });

  const mutation = useCreateProduct();

  return (
    <Form onSubmit={form.handleSubmit((data) =>
      mutation.mutate(data, {
        onError: (error) => {
          if (error instanceof ValidationError) {
            // Mapear errors del backend a campos del formulario
            for (const [field, messages] of Object.entries(error.errors)) {
              form.setError(field as keyof ProductFormData, {
                type: 'server',
                message: messages[0],
              });
            }
          }
        },
      })
    )}>
      {/* ... */}
    </Form>
  );
}
```

---

## 9. UI de administración de permisos (Fase 4)

### 9.1 Editor de permisos de un rol

```tsx
// src/features/access-control/pages/RolePermissionsPage.tsx
function RolePermissionsPage({ roleId }: { roleId: number }) {
  const { data: catalog } = usePermissionCatalog();
  const { data: role } = useRole(roleId);
  const updatePermissions = useUpdateRolePermissions();

  const [selected, setSelected] = useState<Set<string>>(new Set());

  useEffect(() => {
    if (role) setSelected(new Set(role.permissions));
  }, [role]);

  if (!catalog || !role) return <Skeleton />;

  const handleSave = () => {
    updatePermissions.mutate(
      { roleId, permissions: Array.from(selected) },
      { onSuccess: () => toast.success('Permisos actualizados') }
    );
  };

  return (
    <PageLayout title={`Permisos: ${role.name}`} actions={
      <Button onClick={handleSave} disabled={!role.can_update}>
        <Save className="h-4 w-4 mr-2" />
        Guardar
      </Button>
    }>
      <Alert variant={role.is_protected ? 'info' : 'default'}>
        {role.is_protected && <><Lock className="h-4 w-4" /> Este rol es protegido y no puede eliminarse.</>}
      </Alert>

      <Tabs>
        <TabsList>
          <TabsTrigger value="permissions">Permisos</TabsTrigger>
          <TabsTrigger value="preview">Preview</TabsTrigger>
        </TabsList>

        <TabsContent value="permissions">
          <PermissionTree catalog={catalog} selected={selected} onChange={setSelected} disabled={!role.can_update} />
        </TabsContent>

        <TabsContent value="preview">
          <RolePreview roleId={roleId} />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}
```

### 9.2 Editor de overrides por usuario

```tsx
// src/features/access-control/pages/UserOverridesPage.tsx
function UserOverridesPage({ userId }: { userId: number }) {
  const { data: overrides, refetch } = useUserOverrides(userId);
  const { data: catalog } = usePermissionCatalog();
  const updateOverrides = useUpdateUserOverrides();

  const [extras, setExtras] = useState<string[]>([]);
  const [denied, setDenied] = useState<string[]>([]);

  useEffect(() => {
    if (overrides) {
      setExtras(overrides.extras);
      setDenied(overrides.denied);
    }
  }, [overrides]);

  const handleSave = () => {
    const items = [
      ...extras.map((p) => ({ permission: p, effect: 'allow' as const })),
      ...denied.map((p) => ({ permission: p, effect: 'deny' as const })),
    ];
    updateOverrides.mutate({ userId, items }, {
      onSuccess: () => { toast.success('Overrides guardados'); refetch(); }
    });
  };

  return (
    <PageLayout title="Permisos extra y denegados">
      <Tabs>
        <TabsList>
          <TabsTrigger value="extras">Permisos extra ({extras.length})</TabsTrigger>
          <TabsTrigger value="denied">Permisos denegados ({denied.length})</TabsTrigger>
        </TabsList>

        <TabsContent value="extras">
          <PermissionPicker
            catalog={catalog}
            selected={extras}
            onChange={setExtras}
            exclude={denied}                            // un permiso no puede ser extra Y denied
          />
        </TabsContent>

        <TabsContent value="denied">
          <PermissionPicker
            catalog={catalog}
            selected={denied}
            onChange={setDenied}
            exclude={extras}
          />
        </TabsContent>
      </Tabs>

      <Button onClick={handleSave}>Guardar overrides</Button>
    </PageLayout>
  );
}
```

### 9.3 Editor de scopes por usuario

```tsx
// src/features/access-control/pages/UserScopesPage.tsx
function UserScopesPage({ userId }: { userId: number }) {
  const { data: scopes } = useUserScopes(userId);
  const { data: branches } = useBranches();
  const { data: warehouses } = useWarehouses();
  const { data: customerGroups } = useCustomerGroups();
  const updateScopes = useUpdateUserScopes();

  const [branchIds, setBranchIds] = useState<number[]>([]);
  // ... similar para warehouses, customer_groups, vendor_of

  useEffect(() => {
    if (scopes) setBranchIds(scopes.branches);
  }, [scopes]);

  const handleSave = () => {
    updateScopes.mutate({
      userId,
      scopes: { branch_ids: branchIds, /* ... */ }
    });
  };

  const showEmptyBanner = (count: number) =>
    count === 0 && (
      <Alert variant="warning">
        <AlertTitle>Sin asignación</AlertTitle>
        <AlertDescription>El usuario ve TODOS los recursos (default-allow).</AlertDescription>
      </Alert>
    );

  return (
    <PageLayout title="Scopes del usuario">
      <Tabs>
        <TabsList>
          <TabsTrigger value="branches">Sucursales ({branchIds.length})</TabsTrigger>
          <TabsTrigger value="warehouses">Almacenes</TabsTrigger>
          <TabsTrigger value="customer_groups">Grupos de cliente</TabsTrigger>
          <TabsTrigger value="vendor_of">Vendor de grupos</TabsTrigger>
        </TabsList>

        <TabsContent value="branches">
          {showEmptyBanner(branchIds.length)}
          <MultiSelect
            options={branches?.map(b => ({ value: b.id, label: `${b.code} - ${b.name}` })) ?? []}
            value={branchIds}
            onChange={setBranchIds}
          />
        </TabsContent>

        {/* Similar para otros tabs */}
      </Tabs>

      <Button onClick={handleSave}>Guardar scopes</Button>
    </PageLayout>
  );
}
```

---

## 10. Página de usuario con tabs

### 10.1 Estructura completa

```tsx
function UserDetailPage({ userId }: { userId: number }) {
  const { data: user } = useUser(userId);
  const { data: effective } = useUserEffectivePermissions(userId);
  const { data: overrides } = useUserOverrides(userId);
  const { data: scopes } = useUserScopes(userId);

  if (!user) return <Skeleton />;

  return (
    <PageLayout title={user.name} subtitle={user.email}>
      <UserHeader user={user} effective={effective} scopes={scopes} />

      <Tabs>
        <TabsList>
          <TabsTrigger value="profile">Perfil</TabsTrigger>
          <TabsTrigger value="roles">Roles ({user.roles.length})</TabsTrigger>
          <TabsTrigger value="overrides">
            Extras/Denegados ({overrides?.extra_count ?? 0}/{overrides?.deny_count ?? 0})
          </TabsTrigger>
          <TabsTrigger value="scopes">Scopes</TabsTrigger>
          <TabsTrigger value="capabilities">Capabilities</TabsTrigger>
        </TabsList>

        <TabsContent value="profile">
          <UserProfile user={user} />
        </TabsContent>

        <TabsContent value="roles">
          <UserRolesEditor user={user} />
        </TabsContent>

        <TabsContent value="overrides">
          <UserOverridesEditor userId={userId} />
        </TabsContent>

        <TabsContent value="scopes">
          <UserScopesEditor userId={userId} />
        </TabsContent>

        <TabsContent value="capabilities">
          <CapabilityPreview effective={effective} overrides={overrides} />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}

function CapabilityPreview({ effective, overrides }: { effective: UserEffectivePermissions; overrides?: UserOverrides }) {
  return (
    <div className="space-y-4">
      <div>
        <h3>Permisos efectivos ({effective.permission_count})</h3>
        <ul className="text-sm">
          {effective.permissions.map((p) => <li key={p}><code>{p}</code></li>)}
        </ul>
      </div>

      {effective.extras.length > 0 && (
        <Alert variant="success">
          <Plus className="h-4 w-4" />
          <AlertTitle>Permisos extra (asignados individualmente)</AlertTitle>
          <AlertDescription>{effective.extras.join(', ')}</AlertDescription>
        </Alert>
      )}

      {effective.denied.length > 0 && (
        <Alert variant="warning">
          <Minus className="h-4 w-4" />
          <AlertTitle>Permisos denegados (override)</AlertTitle>
          <AlertDescription>{effective.denied.join(', ')}</AlertDescription>
        </Alert>
      )}

      <div>
        <h3>Roles asignados</h3>
        <ul>{effective.roles.map(r => <li key={r}>{r}</li>)}</ul>
      </div>
    </div>
  );
}
```

---

## 11. Testing de permisos

### 11.1 Unit tests

```typescript
// src/permissions/useCan.test.ts
import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useCan } from './useCan';
import { useSessionStore } from '@/stores/session';

describe('useCan', () => {
  it('returns true when permission is in session', () => {
    useSessionStore.setState({
      permissions: new Set(['products.view', 'products.create']),
    });
    const { result } = renderHook(() => useCan('products.create'));
    expect(result.current).toBe(true);
  });

  it('returns false when permission is not in session', () => {
    useSessionStore.setState({
      permissions: new Set(['products.view']),
    });
    const { result } = renderHook(() => useCan('products.delete'));
    expect(result.current).toBe(false);
  });
});
```

### 11.2 Integration tests con componentes

```tsx
// src/components/permissions/Can.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Can } from './Can';
import { useSessionStore } from '@/stores/session';

describe('<Can>', () => {
  it('renders children when user has permission', () => {
    useSessionStore.setState({ permissions: new Set(['products.create']) });
    render(<Can I="products.create"><button>Crear</button></Can>);
    expect(screen.getByText('Crear')).toBeInTheDocument();
  });

  it('renders fallback when user lacks permission', () => {
    useSessionStore.setState({ permissions: new Set() });
    render(
      <Can I="products.create" fallback={<span>No autorizado</span>}>
        <button>Crear</button>
      </Can>
    );
    expect(screen.queryByText('Crear')).not.toBeInTheDocument();
    expect(screen.getByText('No autorizado')).toBeInTheDocument();
  });
});
```

### 11.3 E2E (Playwright)

```typescript
// tests/e2e/permissions.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Permisos', () => {
  test('Vendedor no ve el botón de crear producto', async ({ page }) => {
    await loginAs(page, 'vendedor@demo.test');
    await page.goto('/inventory');
    await expect(page.locator('[data-testid="create-product"]')).not.toBeVisible();
  });

  test('Gerente sí ve el botón de crear producto', async ({ page }) => {
    await loginAs(page, 'gerente@demo.test');
    await page.goto('/inventory');
    await expect(page.locator('[data-testid="create-product"]')).toBeVisible();
  });

  test('Vendedor no ve el campo unit_cost', async ({ page }) => {
    await loginAs(page, 'vendedor@demo.test');
    await page.goto('/inventory/123');
    await expect(page.locator('[data-testid="unit-cost"]')).toContainText('—');
  });
});
```

### 11.4 Cross-tenant tests

Igual que el backend: dos tenants, verificar que un user de A no ve datos de B.

---

## 12. Reglas de oro

1. **SIEMPRE consumir el backend para permisos** — nunca hardcodear capacidades en cliente.
2. **SIEMPRE usar `<Can>` o `useCan`** — nunca `if (role === 'admin')` en componentes.
3. **SIEMPRE mostrar fallback en permisos faltantes** — UX clara, no botones escondidos silenciosamente.
4. **SIEMPRE usar `formatCost()` para campos sensibles** — el backend enmascara con `null`.
5. **SIEMPRE confiar en el field masking del backend** — no filtrar manualmente en cliente.
6. **SIEMPRE refrescar permisos al cambiar tenant** — son per-tenant.
7. **SIEMPRE manejar 401/403/422 con feedback claro** — toast o inline.
8. **NUNCA cachear scopes** — pueden cambiar en cualquier momento.

---

## 13. Referencias cruzadas

- **Backend design completo**: `docs/PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md`
- **Scopes design**: `docs/SCOPES_DESIGN_2026-07-13.md`
- **Contrato API original para IA**: `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md`, `docs/INSTRUCCIONES_FRONTEND_SCOPES.md`
- **API reference**: `docs/API.md`
- **Auditoría**: `docs/AUDIT_2026-07-11/02_AUTH_SEGURIDAD.md`
- **Frontend arquitectura**: `docs/FRONTEND_ARQUITECTURA.md`