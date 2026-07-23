import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import { PermissionContext, type PermissionContextValue } from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

const mockUseTenantGroups = vi.fn();
const mockUseUnreadTransferRequestsCount = vi.fn();

vi.mock('@tanstack/react-router', () => ({
  Link: ({ to, search, className, title, children, ...props }: any) => (
    <a href={typeof to === 'string' ? to : '#'} className={className} title={title} {...props}>
      {children}
      {search ? null : null}
    </a>
  ),
  useRouterState: () => ({ location: { pathname: '/dashboard' } }),
}));

vi.mock('@/features/access/tenantGroupsApi', () => ({
  useTenantGroups: () => mockUseTenantGroups(),
}));

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useUnreadTransferRequestsCount: () => mockUseUnreadTransferRequestsCount(),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: (selector: (state: { tenant: { id: number } | null }) => unknown) =>
    selector({ tenant: { id: 1 } }),
}));

import { Sidebar } from '../Sidebar';

function makeWrapper(perms: string[]) {
  const value: PermissionContextValue = {
    permissions: new Set(perms),
    roles: [],
    scopeStatus: 'none',
    scopes: {
      branches: [],
      warehouses: [],
      customer_groups: [],
      vendor_of: [],
      branches_count: 0,
      warehouses_count: 0,
      customer_groups_count: 0,
      vendor_of_count: 0,
    },
  };

  return ({ children }: { children: ReactNode }) => (
    <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>
  );
}

beforeEach(() => {
  mockUseTenantGroups.mockReset();
  mockUseUnreadTransferRequestsCount.mockReset();
  mockUseUnreadTransferRequestsCount.mockReturnValue({ data: 0 });
});

describe('<Sidebar>', () => {
  it('respeta el orden operativo principal del menu', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const labels = screen
      .getAllByRole('link')
      .map((link) => link.textContent?.trim())
      .filter(Boolean);

    expect(labels).toEqual([
      'Dashboard',
      'POS',
      'Ventas',
      'Devoluciones',
      'Clientes',
      'Cajas',
      'Cuentas por cobrar',
      'Inventario',
      'Compras',
      'Proveedores',
      'Cuentas por pagar',
      'Traslados',
      'Solicitudes inter-empresa',
      'Garantías',
      'Reportes',
      'Impresion',
      'Metodos de pago',
      'Importar datos',
      'Acceso',
    ]);
  });

  it('muestra Acceso con permisos alternativos y oculta Organizaciones sin grupos propios', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(
      <Sidebar />,
      { wrapper: makeWrapper([PERMISSIONS.ROLES_VIEW, PERMISSIONS.TENANTS_VIEW]) },
    );

    expect(screen.getByRole('link', { name: 'Acceso' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Organizaciones' })).toBeNull();
  });
});
