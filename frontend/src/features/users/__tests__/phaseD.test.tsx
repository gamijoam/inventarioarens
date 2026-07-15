/**
 * Tests de los componentes de Fase D:
 *   - UserOverridesTab: render, draft, dirty state, picker
 *   - UserScopesTab: render, toggle, dirty state
 *   - PermissionPicker: search, effect toggle, onPick
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

interface QueryResult { data: unknown; isLoading: boolean; isError?: boolean; }
interface MutationResult { mutateAsync: () => Promise<unknown>; isPending: boolean; }
const mockMutateAsync = vi.fn();
const mockUseUserOverrides = vi.fn<(_: number) => QueryResult>();
const mockUseReplaceUserOverrides = vi.fn<() => MutationResult>();
const mockUseUserScopes = vi.fn<(_: number) => QueryResult>();
const mockUseReplaceAllScopes = vi.fn<() => MutationResult>();
const mockUsePermissionCatalog = vi.fn<() => QueryResult>();
const mockUseScopesCatalog = vi.fn<() => { data: unknown; isLoading: boolean }>();
const mockUseEffectivePermissions = vi.fn<(_: number) => QueryResult>();
const mockUseUser = vi.fn<(_: number) => QueryResult>();
const mockUseSessionStore = vi.fn<(selector: (s: { tenant?: { id: number } | null }) => unknown) => unknown>();

// Sobreescribir el session store para que siempre tenga un tenant_id.
vi.mock('@/stores/session', () => ({
  useSessionStore: <T,>(selector: (s: { tenant?: { id: number } | null }) => T): T => {
    return mockUseSessionStore(selector) as T;
  },
}));

vi.mock('@/features/access/api', () => ({
  useRoles: vi.fn(),
  useRole: vi.fn(),
  useRolePreview: vi.fn(),
  usePermissionCatalog: () => mockUsePermissionCatalog(),
  useCreateRole: vi.fn(),
  useUpdateRole: vi.fn(),
  useDeleteRole: vi.fn(),
  useUpdateRolePermissions: vi.fn(),
  useDuplicateRole: vi.fn(),
  roleKeys: { all: ['roles'], lists: () => ['roles','list'], list: () => ['roles','list',{}], details: () => ['roles','detail'], detail: (id:number) => ['roles','detail',id], previews: () => ['roles','preview'], preview: (id:number) => ['roles','preview',id] },
  permissionCatalogKey: ['permissions','catalog'],
}));

vi.mock('@/features/users/api', () => ({
  useUsers: vi.fn(),
  useUser: (id: number) => mockUseUser(id),
  useCreateUser: vi.fn(),
  useUpdateUser: vi.fn(),
  useUpdateUserRoles: vi.fn(),
  useUpdateUserStatus: vi.fn(),
  useUserOverrides: (userId: number) => mockUseUserOverrides(userId),
  useReplaceUserOverrides: () => mockUseReplaceUserOverrides(),
  useRemoveUserOverride: vi.fn(),
  useUserScopes: (userId: number) => mockUseUserScopes(userId),
  useReplaceAllScopes: () => mockUseReplaceAllScopes(),
  useScopesCatalog: () => mockUseScopesCatalog(),
  useEffectivePermissions: (userId: number) => mockUseEffectivePermissions(userId),
  userKeys: { all: ['users'], lists: () => ['users','list'], list: () => ['users','list',{}], details: () => ['users','detail'], detail: (id:number) => ['users','detail',id] },
  userOverrideKeys: { all: ['user-overrides'], list: (tenantId: number, userId: number) => ['user-overrides', tenantId, userId] },
  userScopeKeys: { all: ['user-scopes'], detail: (tenantId: number, userId: number) => ['user-scopes', tenantId, userId] },
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { UserOverridesTab } from '../UserOverridesTab';
import { UserScopesTab } from '../UserScopesTab';
import { PermissionPicker } from '../PermissionPicker';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeOverridesResponse = {
  user_id: 5,
  tenant_id: 2,
  items: [
    { permission: 'sales.cancel', effect: 'allow' as const, created_at: '2026-07-15T10:00:00Z', updated_at: '2026-07-15T10:00:00Z' },
    { permission: 'inventory.adjust', effect: 'deny' as const, created_at: '2026-07-15T10:00:00Z', updated_at: '2026-07-15T10:00:00Z' },
  ],
  extra_count: 1,
  deny_count: 1,
  extras: ['sales.cancel'],
  denied: ['inventory.adjust'],
};

const fakeCatalog = {
  modules: [
    { module: 'sales', label: 'Ventas', verb_count: 3, actions: [
      { verb: 'view', label: 'Ver ventas', permission: 'sales.view' },
      { verb: 'create', label: 'Crear ventas', permission: 'sales.create' },
      { verb: 'cancel', label: 'Cancelar ventas', permission: 'sales.cancel' },
    ] },
    { module: 'products', label: 'Productos', verb_count: 1, actions: [
      { verb: 'view', label: 'Ver productos', permission: 'products.view' },
    ] },
  ],
  verbs: [{ name: 'view', label: 'Ver' }, { name: 'create', label: 'Crear' }, { name: 'cancel', label: 'Cancelar' }],
  total_permissions: 4,
  total_modules: 2,
};

beforeEach(() => {
  mockMutateAsync.mockReset();
  mockMutateAsync.mockResolvedValue(undefined);
  mockUseSessionStore.mockImplementation((sel) => sel({ tenant: { id: 2 } }));
  mockUseUserOverrides.mockReset();
  mockUseReplaceUserOverrides.mockReset();
  mockUseUserScopes.mockReset();
  mockUseReplaceAllScopes.mockReset();
  mockUsePermissionCatalog.mockReset();
  mockUsePermissionCatalog.mockReturnValue({ data: fakeCatalog, isLoading: false });
  mockUseScopesCatalog.mockReset();
  mockUseScopesCatalog.mockReturnValue({
    data: {
      branches: [{ id: 1, name: 'Sucursal Centro', code: 'BR-C' }],
      warehouses: [{ id: 1, name: 'Almacen Principal', code: 'WH-1' }],
      customerGroups: [{ id: 1, name: 'VIP', code: 'CG-VIP' }],
      vendors: [{ id: 1, name: 'Distribuidora X', tax_id: 'J-123' }],
    },
    isLoading: false,
  });
});

describe('UserOverridesTab', () => {
  it('renderiza los overrides existentes', async () => {
    mockUseUserOverrides.mockReturnValue({ data: fakeOverridesResponse, isLoading: false, isError: false });
    mockUseReplaceUserOverrides.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserOverridesTab userId={5} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(screen.getByText('sales.cancel')).toBeTruthy();
      expect(screen.getByText('inventory.adjust')).toBeTruthy();
    });
    expect(screen.getAllByText('allow').length).toBeGreaterThan(0);
    expect(screen.getAllByText('deny').length).toBeGreaterThan(0);
  });

  it('muestra estado vacio si no hay overrides', async () => {
    mockUseUserOverrides.mockReturnValue({
      data: { ...fakeOverridesResponse, items: [], extras: [], denied: [] },
      isLoading: false, isError: false,
    });
    mockUseReplaceUserOverrides.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserOverridesTab userId={5} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Sin overrides/)).toBeTruthy();
    });
  });

  it('muestra error si la query falla', async () => {
    mockUseUserOverrides.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    mockUseReplaceUserOverrides.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserOverridesTab userId={5} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No se pudo cargar los overrides/)).toBeTruthy();
    });
  });
});

describe('UserScopesTab', () => {
  const fakeScopes = {
    user_id: 5,
    tenant_id: 2,
    scope_status: 'custom',
    branches: [1],
    warehouses: [],
    customer_groups: [],
    vendor_of: [],
  };

  it('renderiza con tabs de cada tipo de scope', () => {
    mockUseUserScopes.mockReturnValue({ data: fakeScopes, isLoading: false });
    mockUseReplaceAllScopes.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserScopesTab userId={5} />, { wrapper: makeWrapper() });
    expect(screen.getAllByText('Sucursales').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Almacenes').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Grupos clientes').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Proveedores').length).toBeGreaterThan(0);
  });

  it('marca los items ya asignados como checked', () => {
    mockUseUserScopes.mockReturnValue({ data: fakeScopes, isLoading: false });
    mockUseReplaceAllScopes.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserScopesTab userId={5} />, { wrapper: makeWrapper() });
    // Sucursal id=1 esta asignada, debe estar checked.
    const checkbox = screen.getByTestId('scope-branches-1');
    expect(checkbox).toBeChecked();
  });

  it('toggle deselecciona un item', async () => {
    mockUseUserScopes.mockReturnValue({ data: fakeScopes, isLoading: false });
    mockUseReplaceAllScopes.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserScopesTab userId={5} />, { wrapper: makeWrapper() });
    const checkbox = screen.getByTestId('scope-branches-1');
    await userEvent.click(checkbox);
    expect(checkbox).not.toBeChecked();
  });

  it('muestra "Guardar scopes" como disabled cuando no hay cambios', () => {
    mockUseUserScopes.mockReturnValue({ data: fakeScopes, isLoading: false });
    mockUseReplaceAllScopes.mockReturnValue({ mutateAsync: mockMutateAsync, isPending: false });
    render(<UserScopesTab userId={5} />, { wrapper: makeWrapper() });
    const saveBtn = screen.getByText(/Guardar scopes/);
    expect(saveBtn).toBeDisabled();
  });
});

describe('PermissionPicker', () => {
  it('muestra los permisos del catalogo', async () => {
    render(
      <PermissionPicker
        open
        onOpenChange={vi.fn()}
        onPick={vi.fn()}
        existingPermissions={new Set()}
      />,
      { wrapper: makeWrapper() }
    );
    await waitFor(() => {
      expect(screen.getByTestId('permission-picker-item-sales.view')).toBeTruthy();
      expect(screen.getByTestId('permission-picker-item-products.view')).toBeTruthy();
    });
  });

  it('filtra permisos al buscar', async () => {
    render(
      <PermissionPicker
        open
        onOpenChange={vi.fn()}
        onPick={vi.fn()}
        existingPermissions={new Set()}
      />,
      { wrapper: makeWrapper() }
    );
    await userEvent.type(screen.getByTestId('permission-picker-search'), 'products');
    expect(screen.queryByTestId('permission-picker-item-sales.view')).toBeNull();
    expect(screen.getByTestId('permission-picker-item-products.view')).toBeTruthy();
  });

  it('cambia el effect (allow/deny)', async () => {
    render(
      <PermissionPicker
        open
        onOpenChange={vi.fn()}
        onPick={vi.fn()}
        existingPermissions={new Set()}
      />,
      { wrapper: makeWrapper() }
    );
    const allowBtn = screen.getByTestId('permission-picker-effect-allow');
    const denyBtn = screen.getByTestId('permission-picker-effect-deny');
    expect(allowBtn.className).toContain('bg-primary');
    await userEvent.click(denyBtn);
    expect(denyBtn.className).toContain('bg-danger');
  });

  it('deshabilita items ya configurados (allow o deny)', () => {
    render(
      <PermissionPicker
        open
        onOpenChange={vi.fn()}
        onPick={vi.fn()}
        existingPermissions={new Set(['sales.view:allow'])}
      />,
      { wrapper: makeWrapper() }
    );
    const item = screen.getByTestId('permission-picker-item-sales.view');
    expect(item).toBeDisabled();
  });
});