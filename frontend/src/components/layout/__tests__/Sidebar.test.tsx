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

let mockPathname = '/dashboard';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ to, search, className, title, children, ...props }: any) => (
    <a href={typeof to === 'string' ? to : '#'} className={className} title={title} {...props}>
      {children}
      {search ? null : null}
    </a>
  ),
  useRouterState: () => ({ location: { pathname: mockPathname } }),
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

import { useUiModeStore } from '@/stores/uiMode';
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
  mockPathname = '/dashboard';
  useUiModeStore.setState({ isSimpleMode: false });
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
      'Cuentas por cobrar',
      'Cuentas por pagar',
      'Metodos de pago',
      'Proveedores',
      'Inventario',
      'Compras',
      'Traslados',
      'Solicitudes inter-empresa',
      'Garantías',
      'Taller',
      'Reportes',
      'Importar datos',
      'Impresion',
      'Acceso',
      'Configuración',
    ]);
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

  it('muestra las etiquetas de seccion como encabezados agrupadores', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    const { container } = render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const headers = Array.from(container.querySelectorAll('div'))
      .map((node) => node.textContent?.trim())
      .filter(
        (text): text is string => Boolean(text) && /^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+$/.test(text ?? ''),
      );

    ['Operación', 'Ventas', 'Finanzas', 'Inventario', 'Analítica', 'Configuración'].forEach(
      (section) => {
        expect(headers).toContain(section);
      },
    );
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

  it('oculta traslados e interempresa cuando las capacidades de traslado estan desactivadas', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });
    // Configuración típica de Avilacar sin traslados
    mockSessionState.capabilities = new Set([
      'dashboard',
      'catalog',
      'inventory',
      'customers',
      'suppliers',
      'sales',
      'pos',
      'reports',
      'printing',
    ]);

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    expect(screen.queryByRole('link', { name: 'Traslados' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Solicitudes inter-empresa' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Taller' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Garantías' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Comisiones' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Dashboard' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'POS' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Inventario' })).toBeTruthy();
  });

  it('muestra solo los módulos configurados cuando el Modo Fácil está activado', () => {
    useUiModeStore.setState({ isSimpleMode: true });
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const labels = screen
      .getAllByRole('link')
      .map((link) => link.textContent?.trim())
      .filter(Boolean);

    expect(labels).toEqual([
      'Dashboard',
      'POS',
      'Clientes',
      'Inventario',
      'Reportes',
      'Acceso',
      'Configuración',
    ]);
  });

  it('permite personalizar qué módulos se visualizan en el Modo Fácil', () => {
    // Activamos solo Inventario y Configuración
    useUiModeStore.setState({
      isSimpleMode: true,
      visibleRoutes: ['/inventory', '/settings/company'],
    });
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const labels = screen
      .getAllByRole('link')
      .map((link) => link.textContent?.trim())
      .filter(Boolean);

    expect(labels).toEqual(['Inventario', 'Configuración']);
  });

  it('en Modo Fácil muestra los submódulos configurados (ej: Tasas y Catálogos) dentro de Inventario', () => {
    useUiModeStore.setState({
      isSimpleMode: true,
      visibleRoutes: ['/inventory', '/inventory/currency', '/inventory/catalogs'],
    });
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    // Abrimos el submenú de Inventario
    const openButtons = screen.getAllByRole('button', { name: /abrir submenú/i });
    expect(openButtons[0]).toBeDefined();
    fireEvent.click(openButtons[0]!);

    expect(screen.getByRole('link', { name: 'Productos' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Catálogos' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Tipos de tasa' })).toBeTruthy();

    // Los no configurados permanecen ocultos
    expect(screen.queryByRole('link', { name: 'Movimientos manuales' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Administración' })).toBeNull();
  });

  it('renderiza la identidad retro de Repuestos Avilacar con badge RA e indicador', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    expect(screen.getByText('RA')).toBeDefined();
    expect(screen.getByText('Repuestos Avilacar')).toBeDefined();
  });

  it('renderiza la imagen del logo cuando tenant.logo_url está configurado', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });
    mockSessionState.tenant = {
      id: 1,
      name: 'Repuestos Avilacar',
      slug: 'repuestos-avilacar',
      logo_url: '/storage/tenants/1/logo.png',
    } as any;

    render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const logo = screen.getByTestId('sidebar-tenant-logo');
    expect(logo).toHaveAttribute('src', '/storage/tenants/1/logo.png');

    // Reset
    mockSessionState.tenant = { id: 1 } as any;
  });

  it('colapsa el menu automaticamente cuando se ingresa al POS', () => {
    mockUseTenantGroups.mockReturnValue({ data: [], isLoading: false, isError: false });
    mockPathname = '/pos';

    const { container } = render(<Sidebar />, { wrapper: makeWrapper(Object.values(PERMISSIONS)) });

    const aside = container.querySelector('aside');
    expect(aside?.className).toContain('w-16');
    expect(screen.getByRole('button', { name: 'Expandir menú' })).toBeDefined();
  });
});
