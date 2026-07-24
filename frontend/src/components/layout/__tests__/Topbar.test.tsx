import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const mockSignOut = vi.fn();
const mockRefreshSession = vi.fn();
const mockSwitchTo = vi.fn();

let currentTenant: { name: string; slug: string; is_group?: boolean; parent_id?: number | null } | null = null;

vi.mock('@/stores/session', () => ({
  useSessionStore: (selector: (state: { user: { name: string; email: string } | null; tenant: typeof currentTenant; roles: string[] }) => unknown) =>
    selector({
      user: { name: 'Admin', email: 'admin@test.test' },
      tenant: currentTenant,
      roles: ['Administrador'],
    }),
}));

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({ signOut: mockSignOut, refreshSession: mockRefreshSession, switchTo: mockSwitchTo }),
  useAvailableTenants: () => ({ data: [], isLoading: false }),
}));

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => vi.fn(),
}));

import { Topbar } from '../Topbar';

describe('<Topbar>', () => {
  it('muestra Grupo cuando el tenant es grupo', () => {
    currentTenant = { name: 'Holding Demo', slug: 'holding-demo', is_group: true, parent_id: null };
    render(<Topbar />);

    expect(screen.getByTestId('tenant-context-badge')).toHaveTextContent('Grupo');
  });

  it('muestra Sucursal cuando el tenant tiene parent_id', () => {
    currentTenant = { name: 'Empresa Demo', slug: 'empresa-demo', is_group: false, parent_id: 10 };
    render(<Topbar />);

    expect(screen.getByTestId('tenant-context-badge')).toHaveTextContent('Sucursal');
  });

  it('muestra Empresa cuando no pertenece a un grupo', () => {
    currentTenant = { name: 'Empresa Normal', slug: 'empresa-normal', is_group: false, parent_id: null };
    render(<Topbar />);

    expect(screen.getByTestId('tenant-context-badge')).toHaveTextContent('Empresa');
  });
});
