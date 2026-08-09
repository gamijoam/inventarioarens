import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const routerState = vi.hoisted(() => ({ pathname: '/pos' }));

vi.mock('@tanstack/react-router', () => ({
  useRouterState: ({
    select,
  }: {
    select: (state: { location: { pathname: string } }) => unknown;
  }) => select({ location: { pathname: routerState.pathname } }),
  Link: ({ to, children, ...props }: { to: string; children: string }) => (
    <a href={to} {...props}>
      {children}
    </a>
  ),
}));

vi.mock('../Sidebar', () => ({
  Sidebar: () => <aside data-testid="admin-sidebar">Admin sidebar</aside>,
}));

vi.mock('../Topbar', () => ({
  Topbar: () => <header data-testid="admin-topbar">Admin topbar</header>,
}));

vi.mock('@/permissions/PermissionContext', () => ({
  usePermissionContext: () => ({
    permissions: new Set<string>(),
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
  }),
}));

import { AppShell } from '../AppShell';

describe('<AppShell> POS integration', () => {
  beforeEach(() => {
    routerState.pathname = '/pos';
  });

  it('delega /pos al contenido POS sin envolverlo en otro shell', () => {
    render(<AppShell>Terminal POS</AppShell>);

    expect(screen.getByText('Terminal POS')).toBeInTheDocument();
    expect(screen.queryByTestId('pos-shell')).not.toBeInTheDocument();
    expect(screen.queryByTestId('admin-sidebar')).not.toBeInTheDocument();
    expect(screen.queryByTestId('admin-topbar')).not.toBeInTheDocument();
  });

  it('delega /pos/armar al contenido tactil sin sidebar ni topbar administrativos', () => {
    routerState.pathname = '/pos/armar';

    render(<AppShell>Pantalla para armar pedidos</AppShell>);

    expect(screen.getByText('Pantalla para armar pedidos')).toBeInTheDocument();
    expect(screen.queryByTestId('admin-sidebar')).not.toBeInTheDocument();
    expect(screen.queryByTestId('admin-topbar')).not.toBeInTheDocument();
  });

  it('conserva el shell administrativo fuera de /pos', () => {
    routerState.pathname = '/inventory';

    render(<AppShell>Inventario</AppShell>);

    expect(screen.getByTestId('admin-sidebar')).toBeInTheDocument();
    expect(screen.getByTestId('admin-topbar')).toBeInTheDocument();
    expect(screen.queryByTestId('pos-shell')).not.toBeInTheDocument();
  });
});
