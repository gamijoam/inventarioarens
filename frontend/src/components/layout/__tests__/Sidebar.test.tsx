import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import type { ReactNode } from 'react';

import { PermissionContext, type PermissionContextValue } from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

const mockUseTenantGroups = vi.fn();
const mockUseUnreadTransferRequestsCount = vi.fn();
const mockSessionState = {
  tenant: { id: 1 },
  capabilities: new Set<string>(),
};

vi.mock('@tanstack/react-router', () => ({
  Link: ({ to, search, className, title, children, ...props }: any) => (
    <a href={typeof to === 'string' ? to : '#'} className={className} title={title} {...props}>
      {children}
      {search ? null : null}
    </a>
  ),
  useRouterState: () => ({ location: { pathname: '/dashboard' } }),
  useNavigate: () => vi.fn(),
}));

vi.mock('@/features/access/tenantGroupsApi', () => ({
  useTenantGroups: () => mockUseTenantGroups(),
}));

vi.mock('@/features/inventory-transfer-notifications/api', () => ({
  useUnreadIntercompanyNotificationsCount: () => mockUseUnreadTransferRequestsCount(),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: (selector: (state: typeof mockSessionState) => unknown) =>
    selector(mockSessionState),
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
  mockSessionState.capabilities = new Set();
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
      'Cajas',
      'Ventas',
      'Cotizaciones',
      'Devoluciones',
      'Promociones',
      'Comisiones',
      'Clientes',
      'Inventario',
      'Compras',
      'Proveedores',
      'Traslados',
      'Solicitudes inter-empresa',
      'Garantías',
      'Taller',
      'Cuentas por cobrar',
      'Cuentas por pagar',
      'Métodos de pago',
      'Reportes',
      'Importar datos',
      'Impresión',
      'Acceso',
      'Configuración',
    ]);
  });

  it('muestra el submenu de inventario con opciones agrupadas y un control de expansion grande', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    const { container } = render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });
    fireEvent.mouseEnter(container.firstChild as HTMLElement);
    const inventoryLink = screen.getByRole('link', { name: 'Inventario' });
    const disclosure = inventoryLink.parentElement?.querySelector('button') as HTMLButtonElement;
    expect(disclosure.className).toContain('size-10');
    fireEvent.click(disclosure);

    expect(screen.getByRole('link', { name: 'Productos' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Movimientos' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Catálogos de productos' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Tasas de cambio' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Listas de precios' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Ubicaciones y garantías' })).toBeTruthy();
    expect(disclosure).toHaveAttribute('aria-expanded', 'true');
  });

  it('oculta modulos deshabilitados por las capacidades del tenant', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });
    mockSessionState.capabilities = new Set([
      'dashboard',
      'catalog',
      'inventory',
      'customers',
      'suppliers',
    ]);

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    expect(screen.queryByRole('link', { name: 'POS' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Ventas' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Inventario' })).toBeTruthy();
  });

  it('aplica la escala tipografica del menu y un objetivo de toque comodo', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    const { container } = render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });
    const dashboardLink = screen.getByRole('link', { name: 'Dashboard' });
    const sectionHeader = Array.from(container.querySelectorAll('div')).find(
      (node) => node.textContent?.trim() === 'Operación',
    );

    expect(dashboardLink.className).toContain('text-[length:var(--text-ui-body)]');
    expect(dashboardLink.className).toContain('min-h-11');
    expect(sectionHeader?.className).toContain('text-[length:var(--text-ui-caption)]');
  });

  it('muestra las etiquetas de seccion como encabezados agrupadores', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    const { container } = render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const headers = Array.from(container.querySelectorAll('div'))
      .map((node) => node.textContent?.trim())
      .filter(
        (text): text is string => Boolean(text) && /^[A-ZÁÉÍÓÚÑ][a-záéíóúñ ]+$/.test(text ?? ''),
      );

    [
      'Inicio',
      'Operación',
      'Ventas',
      'Inventario y compras',
      'Finanzas',
      'Reportes y datos',
      'Administración',
    ].forEach((section) => {
      expect(headers).toContain(section);
    });
  });

  it('muestra Acceso con permisos alternativos y oculta Organizaciones sin grupos propios', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, {
      wrapper: makeWrapper([PERMISSIONS.ROLES_VIEW, PERMISSIONS.TENANTS_VIEW]),
    });

    expect(screen.getByRole('link', { name: 'Acceso' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Organizaciones' })).toBeNull();
  });

  it('apunta Configuración a una ruta existente', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    expect(screen.getByRole('link', { name: 'Configuración' })).toHaveAttribute(
      'href',
      '/settings/company',
    );
  });

  it('se expande como overlay al pasar el mouse (hover) y se colapsa al salir o hacer click', async () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    const { container } = render(<Sidebar />, {
      wrapper: makeWrapper(Object.values(PERMISSIONS)),
    });

    const wrapperDiv = container.firstChild as HTMLElement;
    const aside = container.querySelector('aside') as HTMLElement;

    // Estado inicial: colapsado en w-16
    expect(aside.className).toContain('w-16');

    // Hover (mouseEnter): se expande a w-64 con shadow-2xl flotante
    fireEvent.mouseEnter(wrapperDiv);
    expect(aside.className).toContain('w-64');
    expect(aside.className).toContain('shadow-2xl');

    // Mouse leave: vuelve a w-16
    fireEvent.mouseLeave(wrapperDiv);
    expect(aside.className).toContain('w-16');

    // Hover de nuevo y click en una opcion: se colapsa
    fireEvent.mouseEnter(wrapperDiv);
    expect(aside.className).toContain('w-64');

    const dashboardLink = screen.getByRole('link', { name: 'Dashboard' });
    fireEvent.click(dashboardLink);
    expect(aside.className).toContain('w-16');
  });
});
