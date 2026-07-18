/**
 * Tests del UsersManager (Fase A: listado).
 *
 * Mockeamos useUsers para inyectar datos controlados y verificamos:
 *   - render basico (tabla con usuarios mock)
 *   - estado vacio
 *   - estado de error
 *   - cambio de filtro status (re-render con queryKey distinto)
 *   - render de badges de roles y status
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

// Mockear useUsers ANTES de importar el manager para que el componente
// tome el mock al inicializar.
const mockUseUsers = vi.fn();
vi.mock('@/features/users/api', () => ({
  useUsers: (filters: unknown) => mockUseUsers(filters) as { data: unknown; isLoading: boolean; isError: boolean },
  userKeys: {
    all: ['users'],
    lists: () => ['users', 'list'],
    list: (f: unknown) => ['users', 'list', f],
    details: () => ['users', 'detail'],
    detail: (id: number) => ['users', 'detail', id],
  },
}));

import { UsersManager } from '../UsersManager';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeUsers = {
  data: [
    {
      id: 1,
      name: 'Lucia Perez',
      email: 'lucia@test.test',
      status: 'active' as const,
      roles: [
        { id: 10, name: 'Gerente' },
        { id: 11, name: 'Vendedor' },
      ],
      created_at: '2026-07-01T10:00:00.000000Z',
    },
    {
      id: 2,
      name: 'Juan Garcia',
      email: 'juan@test.test',
      status: 'inactive' as const,
      roles: [{ id: 12, name: 'Almacen' }],
      created_at: '2026-06-15T12:30:00.000000Z',
    },
  ],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 2,
    from: 1,
    to: 2,
  },
};

describe('UsersManager', () => {
  beforeEach(() => {
    mockUseUsers.mockReset();
  });

  it('renderiza el listado con usuarios mock', async () => {
    mockUseUsers.mockReturnValue({
      data: fakeUsers,
      isLoading: false,
      isError: false,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Lucia Perez')).toBeTruthy();
      expect(screen.getByText('lucia@test.test')).toBeTruthy();
      expect(screen.getByText('Juan Garcia')).toBeTruthy();
    });
    expect(screen.getByText('Gerente')).toBeTruthy();
    expect(screen.getByText('Vendedor')).toBeTruthy();
    expect(screen.getByText('Almacen')).toBeTruthy();
    expect(screen.getByText('Activo')).toBeTruthy();
    expect(screen.getByText('Inactivo')).toBeTruthy();
  });

  it('muestra estado vacio cuando no hay usuarios', async () => {
    mockUseUsers.mockReturnValue({
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } },
      isLoading: false,
      isError: false,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Sin usuarios')).toBeTruthy();
    });
    expect(screen.getByText(/Aun no hay usuarios en esta empresa/)).toBeTruthy();
  });

  it('muestra estado de error cuando falla la query', async () => {
    mockUseUsers.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    await waitFor(() => {
      expect(screen.getByText('No se pudo cargar el listado')).toBeTruthy();
    });
    expect(screen.getByText(/Verifica tu conexion o tus permisos/)).toBeTruthy();
  });

  it('renderiza skeleton mientras esta cargando', () => {
    mockUseUsers.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
    });
    const { container } = render(<UsersManager />, { wrapper: makeWrapper() });
    // 6 skeletons en la card de la tabla
    const skeletons = container.querySelectorAll('[class*="animate-pulse"], [class*="skeleton"]');
    expect(skeletons.length).toBeGreaterThanOrEqual(6);
  });

  it('cambia el filtro status y re-renderiza', async () => {
    mockUseUsers.mockReturnValue({
      data: fakeUsers,
      isLoading: false,
      isError: false,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    const statusSelect = screen.getByTestId('users-status-filter');
    await userEvent.selectOptions(statusSelect, 'active');

    await waitFor(() => {
      // La ultima llamada a mockUseUsers debe haber sido con status='active'.
      const lastCall = mockUseUsers.mock.calls[mockUseUsers.mock.calls.length - 1]?.[0] as { status?: string };
      expect(lastCall?.status).toBe('active');
    });
  });

  it('cambia el alcance a organizacion', async () => {
    mockUseUsers.mockReturnValue({
      data: fakeUsers,
      isLoading: false,
      isError: false,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    const scopeSelect = screen.getByTestId('users-scope-filter');
    await userEvent.selectOptions(scopeSelect, 'organization');

    await waitFor(() => {
      const lastCall = mockUseUsers.mock.calls[mockUseUsers.mock.calls.length - 1]?.[0] as { scope?: string };
      expect(lastCall?.scope).toBe('organization');
    });
  });

  it('el input de busqueda actualiza el filtro', async () => {
    mockUseUsers.mockReturnValue({
      data: fakeUsers,
      isLoading: false,
      isError: false,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    const searchInput = screen.getByTestId('users-search');
    await userEvent.type(searchInput, 'lucia');

    await waitFor(() => {
      const lastCall = mockUseUsers.mock.calls[mockUseUsers.mock.calls.length - 1]?.[0] as { search?: string };
      expect(lastCall?.search).toBe('lucia');
    });
  });

  it('muestra paginacion cuando hay mas de una pagina', async () => {
    mockUseUsers.mockReturnValue({
      data: { data: fakeUsers.data, meta: { ...fakeUsers.meta, last_page: 3, total: 75 } },
      isLoading: false,
      isError: false,
    });
    render(<UsersManager />, { wrapper: makeWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Mostrando 2 de 75 usuarios/)).toBeTruthy();
    });
    expect(screen.getByText(/Pagina 1 \/ 3/)).toBeTruthy();
  });
});
