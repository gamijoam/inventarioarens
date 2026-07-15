/**
 * Tests del modulo de Acceso (Fase C):
 *   - PermissionTree: render, expand/collapse, busqueda, toggle, "todos"
 *   - RoleEditor: crear / editar nombre, validacion, modo protegido
 *   - DuplicateRoleDialog: pre-rellena con "(copia)", submit
 *
 * Mockeamos @/features/access/api y sonner.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

const mockMutateAsync = vi.fn();
const mockUseRoles = vi.fn();
const mockUseRole = vi.fn();
const mockUseRolePreview = vi.fn();
const mockUsePermissionCatalog = vi.fn();
const mockUseCreateRole = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseUpdateRole = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseDeleteRole = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseUpdateRolePermissions = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseDuplicateRole = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));

vi.mock('@/features/access/api', () => ({
  useRoles: () => mockUseRoles() as unknown as { data: unknown; isLoading: boolean; isError: boolean },
  useRole: (id: number) => mockUseRole(id) as unknown as { data: unknown; isLoading: boolean; isError: boolean },
  useRolePreview: (id: number) => mockUseRolePreview(id) as unknown as { data: unknown; isLoading: boolean },
  usePermissionCatalog: () => mockUsePermissionCatalog() as unknown as { data: unknown; isLoading: boolean },
  useCreateRole: () => mockUseCreateRole(),
  useUpdateRole: () => mockUseUpdateRole(),
  useDeleteRole: () => mockUseDeleteRole(),
  useUpdateRolePermissions: () => mockUseUpdateRolePermissions(),
  useDuplicateRole: () => mockUseDuplicateRole(),
  roleKeys: { all: ['roles'], lists: () => ['roles','list'], list: () => ['roles','list',{}], details: () => ['roles','detail'], detail: (id:number) => ['roles','detail',id], previews: () => ['roles','preview'], preview: (id:number) => ['roles','preview',id] },
  permissionCatalogKey: ['permissions','catalog'],
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { PermissionTree } from '../PermissionTree';
import { RoleEditor } from '../RoleEditor';
import { DuplicateRoleDialog } from '../DuplicateRoleDialog';
import type { Role } from '../api';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeRoles: Role[] = [
  { id: 1, name: 'Owner', is_protected: true, permissions: ['users.view'] },
  { id: 2, name: 'Gerente', is_protected: true, permissions: ['sales.view'] },
  { id: 3, name: 'Vendedor', is_protected: true, permissions: [] },
  { id: 4, name: 'Cajero Senior (custom)', is_protected: false, permissions: ['sales.create'] },
];

const fakeCatalog = {
  modules: [
    {
      module: 'sales',
      label: 'Ventas',
      verb_count: 2,
      actions: [
        { verb: 'view', label: 'Ver ventas', permission: 'sales.view' },
        { verb: 'create', label: 'Crear ventas', permission: 'sales.create', danger: 'high' as const },
      ],
    },
    {
      module: 'products',
      label: 'Productos',
      verb_count: 2,
      actions: [
        { verb: 'view', label: 'Ver productos', permission: 'products.view' },
        { verb: 'create', label: 'Crear productos', permission: 'products.create' },
      ],
    },
  ],
  verbs: [{ name: 'view', label: 'Ver' }, { name: 'create', label: 'Crear' }],
  total_permissions: 4,
  total_modules: 2,
};

beforeEach(() => {
  mockMutateAsync.mockReset();
  mockMutateAsync.mockResolvedValue({ id: 99, name: 'Nuevo', is_protected: false, permissions: [] });
  mockUseRoles.mockReset();
  mockUseRoles.mockReturnValue({
    data: { data: fakeRoles, meta: { current_page: 1, last_page: 1, per_page: 25, total: 4 } },
    isLoading: false,
    isError: false,
  });
  mockUseRole.mockReset();
  mockUseRole.mockReturnValue({ data: fakeRoles[1], isLoading: false, isError: false });
  mockUseRolePreview.mockReset();
  mockUseRolePreview.mockReturnValue({
    data: { data: { role_id: 2, name: 'Gerente', permission_count: 78, module_count: 25, modules: [], wildcards_count: 0, protected: true } },
  });
  mockUsePermissionCatalog.mockReset();
  mockUsePermissionCatalog.mockReturnValue({ data: fakeCatalog, isLoading: false });
});

describe('PermissionTree', () => {
  it('renderiza todos los modulos', () => {
    render(
      <PermissionTree modules={fakeCatalog.modules} selected={new Set()} onToggle={vi.fn()} />,
      { wrapper: makeWrapper() }
    );
    expect(screen.getByTestId('permission-tree-module-sales')).toBeTruthy();
    expect(screen.getByTestId('permission-tree-module-products')).toBeTruthy();
  });

  it('muestra el contador de permisos seleccionados por modulo', () => {
    render(
      <PermissionTree
        modules={fakeCatalog.modules}
        selected={new Set(['sales.view'])}
        onToggle={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    );
    // El modulo sales deberia mostrar "1/2".
    const salesBtn = screen.getByTestId('permission-tree-module-sales');
    expect(salesBtn.textContent).toContain('1/2');
  });

  it('expande/colapsa un modulo al click', async () => {
    render(
      <PermissionTree modules={fakeCatalog.modules} selected={new Set()} onToggle={vi.fn()} />,
      { wrapper: makeWrapper() }
    );
    // Inicialmente colapsado: no hay checkboxes de permisos.
    expect(screen.queryByTestId('permission-tree-permission-sales.view')).toBeNull();
    // Expandir.
    await userEvent.click(screen.getByTestId('permission-tree-module-sales'));
    expect(screen.getByTestId('permission-tree-permission-sales.view')).toBeTruthy();
    // Colapsar.
    await userEvent.click(screen.getByTestId('permission-tree-module-sales'));
    expect(screen.queryByTestId('permission-tree-permission-sales.view')).toBeNull();
  });

  it('filtra modulos al buscar', async () => {
    render(
      <PermissionTree modules={fakeCatalog.modules} selected={new Set()} onToggle={vi.fn()} />,
      { wrapper: makeWrapper() }
    );
    await userEvent.type(screen.getByTestId('permission-tree-search'), 'products');
    expect(screen.queryByTestId('permission-tree-module-sales')).toBeNull();
    expect(screen.getByTestId('permission-tree-module-products')).toBeTruthy();
  });

  it('toggle individual llama a onToggle', async () => {
    const onToggle = vi.fn();
    render(
      <PermissionTree
        modules={fakeCatalog.modules}
        selected={new Set()}
        onToggle={onToggle}
      />,
      { wrapper: makeWrapper() }
    );
    await userEvent.click(screen.getByTestId('permission-tree-module-sales'));
    await userEvent.click(screen.getByTestId('permission-tree-permission-sales.view'));
    expect(onToggle).toHaveBeenCalledWith('sales.view', true);
  });

  it('"Seleccionar todos" toggle todos los permisos del modulo', async () => {
    const onToggle = vi.fn();
    render(
      <PermissionTree
        modules={fakeCatalog.modules}
        selected={new Set()}
        onToggle={onToggle}
      />,
      { wrapper: makeWrapper() }
    );
    await userEvent.click(screen.getByTestId('permission-tree-module-sales'));
    await userEvent.click(screen.getByTestId('permission-tree-toggle-all-sales'));
    expect(onToggle).toHaveBeenCalledWith('sales.view', true);
    expect(onToggle).toHaveBeenCalledWith('sales.create', true);
  });
});

describe('RoleEditor', () => {
  it('abre vacio en modo crear', () => {
    render(<RoleEditor open onOpenChange={vi.fn()} role={null} />, { wrapper: makeWrapper() });
    const input = screen.getByTestId('role-editor-name');
    expect(input).toHaveValue('');
  });

  it('abre con el nombre en modo editar', () => {
    render(
      <RoleEditor open onOpenChange={vi.fn()} role={fakeRoles[3]} />,
      { wrapper: makeWrapper() }
    );
    const input = screen.getByTestId('role-editor-name');
    expect(input).toHaveValue('Cajero Senior (custom)');
  });

  it('muestra error de validacion con nombre vacio', async () => {
    render(<RoleEditor open onOpenChange={vi.fn()} role={null} />, { wrapper: makeWrapper() });
    await userEvent.click(screen.getByTestId('role-editor-submit'));
    expect(mockMutateAsync).not.toHaveBeenCalled();
    expect(screen.getByText('Requerido.')).toBeTruthy();
  });

  it('llama a useCreateRole al crear', async () => {
    render(<RoleEditor open onOpenChange={vi.fn()} role={null} />, { wrapper: makeWrapper() });
    await userEvent.type(screen.getByTestId('role-editor-name'), 'Nuevo Rol');
    await userEvent.click(screen.getByTestId('role-editor-submit'));
    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
    expect(mockMutateAsync.mock.calls[0]?.[0].name).toBe('Nuevo Rol');
  });

  it('deshabilita el input y submit para roles base (is_protected)', () => {
    render(
      <RoleEditor open onOpenChange={vi.fn()} role={fakeRoles[0]} />,
      { wrapper: makeWrapper() }
    );
    expect(screen.getByTestId('role-editor-name')).toBeDisabled();
    expect(screen.getByTestId('role-editor-submit')).toBeDisabled();
  });
});

describe('DuplicateRoleDialog', () => {
  it('pre-rellena con "<nombre> (copia)"', () => {
    render(
      <DuplicateRoleDialog open onOpenChange={vi.fn()} sourceRole={fakeRoles[3]} />,
      { wrapper: makeWrapper() }
    );
    expect(screen.getByTestId('duplicate-role-name')).toHaveValue('Cajero Senior (custom) (copia)');
  });

  it('muestra error si el nombre esta vacio', async () => {
    render(
      <DuplicateRoleDialog open onOpenChange={vi.fn()} sourceRole={fakeRoles[3]} />,
      { wrapper: makeWrapper() }
    );
    await userEvent.clear(screen.getByTestId('duplicate-role-name'));
    await userEvent.click(screen.getByTestId('duplicate-role-submit'));
    expect(mockMutateAsync).not.toHaveBeenCalled();
    expect(screen.getByText('Requerido.')).toBeTruthy();
  });

  it('llama a useDuplicateRole con el id y nombre correctos', async () => {
    render(
      <DuplicateRoleDialog open onOpenChange={vi.fn()} sourceRole={fakeRoles[3]} />,
      { wrapper: makeWrapper() }
    );
    await userEvent.clear(screen.getByTestId('duplicate-role-name'));
    await userEvent.type(screen.getByTestId('duplicate-role-name'), 'Cajero VIP');
    await userEvent.click(screen.getByTestId('duplicate-role-submit'));
    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
    const call = mockMutateAsync.mock.calls[0]?.[0];
    expect(call.id).toBe(4);
    expect(call.values.name).toBe('Cajero VIP');
  });
});