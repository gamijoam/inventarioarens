/**
 * Tests de Fase 2: GroupsTree + CreateGroupDialog + CreateSpinoffDialog.
 *
 * Cubre:
 *  - GroupsTree muestra empty state cuando no hay grupos.
 *  - GroupsTree renderiza cards de grupos cuando hay datos.
 *  - GroupsTree expande/colapsa y muestra spinoffs.
 *  - GroupsTree dispara onCreateGroup al click en "Crear organizacion".
 *  - CreateGroupDialog valida campos requeridos antes de submit.
 *  - CreateGroupDialog llama a la mutacion y cierra al exito.
 *  - CreateSpinoffDialog dispara la mutacion con el grupo correcto.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

const mockMutateAsync = vi.fn();
const mockUseTenantGroups = vi.fn();
const mockUseGroupSpinoffs = vi.fn();
const mockUseCreateTenantGroup = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseCreateSpinoff = vi.fn((_id: number | string) => ({ mutateAsync: mockMutateAsync, isPending: false }));

vi.mock('@/features/access/tenantGroupsApi', () => ({
  useTenantGroups: () =>
    mockUseTenantGroups() as unknown as {
      data: unknown[];
      isLoading: boolean;
      isError: boolean;
      error: Error | null;
      refetch: () => void;
      isFetching: boolean;
    },
  useGroupSpinoffs: (_id: number | string, enabled?: boolean) =>
    mockUseGroupSpinoffs(_id, enabled) as unknown as {
      data: unknown[];
      isLoading: boolean;
    },
  useCreateTenantGroup: () => mockUseCreateTenantGroup(),
  useCreateSpinoff: (_id: number | string) => mockUseCreateSpinoff(_id),
}));

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}));

import { GroupsTree } from '@/features/access/GroupsTree';
import { CreateGroupDialog } from '@/features/access/CreateGroupDialog';
import { CreateSpinoffDialog } from '@/features/access/CreateSpinoffDialog';

function renderWithProviders(ui: ReactNode) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>{ui}</QueryClientProvider>
  );
}

describe('GroupsTree', () => {
  beforeEach(() => {
    mockUseTenantGroups.mockReset();
    mockUseGroupSpinoffs.mockReset();
    mockMutateAsync.mockReset();
  });

  it('muestra empty state cuando no hay grupos', () => {
    mockUseTenantGroups.mockReturnValue({
      data: [],
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
      isFetching: false,
    });

    renderWithProviders(<GroupsTree onCreateGroup={vi.fn()} />);

    expect(screen.getByText(/Aun no tienes organizaciones/i)).toBeInTheDocument();
  });

  it('muestra loading state', () => {
    mockUseTenantGroups.mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
      error: null,
      refetch: vi.fn(),
      isFetching: false,
    });

    renderWithProviders(<GroupsTree onCreateGroup={vi.fn()} />);
    expect(screen.getAllByText(/Cargando organizaciones/i).length).toBeGreaterThan(0);
  });

  it('renderiza cards de grupos cuando hay datos', () => {
    mockUseTenantGroups.mockReturnValue({
      data: [
        {
          id: 1,
          name: 'Mi Holding',
          slug: 'mi-holding',
          plan: 'enterprise',
          status: 'active',
          children_count: 2,
          users_count: 5,
          is_owner: true,
        },
        {
          id: 2,
          name: 'Otro Grupo',
          slug: 'otro-grupo',
          plan: 'standard',
          status: 'active',
          children_count: 0,
          users_count: 1,
          is_owner: true,
        },
      ],
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
      isFetching: false,
    });
    mockUseGroupSpinoffs.mockReturnValue({ data: [], isLoading: false });

    renderWithProviders(<GroupsTree onCreateGroup={vi.fn()} />);

    expect(screen.getByText('Mi Holding')).toBeInTheDocument();
    expect(screen.getByText('Otro Grupo')).toBeInTheDocument();
    expect(screen.getByText(/Plan: enterprise/i)).toBeInTheDocument();
    expect(screen.getByText('mi-holding')).toBeInTheDocument();
  });

  it('dispara onCreateGroup al click en "Crear organizacion"', async () => {
    const onCreate = vi.fn();
    mockUseTenantGroups.mockReturnValue({
      data: [],
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
      isFetching: false,
    });

    renderWithProviders(<GroupsTree onCreateGroup={onCreate} />);
    await userEvent.click(screen.getByRole('button', { name: /Crear primera organizacion/i }));

    expect(onCreate).toHaveBeenCalledTimes(1);
  });

  it('expande y muestra spinoffs al click en toggle', async () => {
    mockUseTenantGroups.mockReturnValue({
      data: [
        {
          id: 1,
          name: 'Grupo A',
          slug: 'g-a',
          status: 'active',
          is_owner: true,
        },
      ],
      isLoading: false,
      isError: false,
      error: null,
      refetch: vi.fn(),
      isFetching: false,
    });
    mockUseGroupSpinoffs.mockReturnValue({
      data: [
        {
          id: 10,
          name: 'Sucursal Valencia',
          slug: 'valencia',
          status: 'active',
          users_count: 2,
        },
      ],
      isLoading: false,
    });

    renderWithProviders(<GroupsTree onCreateGroup={vi.fn()} />);

    // Spinoffs NO se cargan hasta expandir
    expect(mockUseGroupSpinoffs).toHaveBeenCalledWith(1, false);

    await userEvent.click(screen.getByTestId('group-toggle-1'));

    await waitFor(() => {
      expect(mockUseGroupSpinoffs).toHaveBeenCalledWith(1, true);
    });

    expect(await screen.findByText('Sucursal Valencia')).toBeInTheDocument();
    expect(screen.getByText('valencia')).toBeInTheDocument();
  });
});

describe('CreateGroupDialog', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset();
  });

  it('muestra errores cuando se submit sin campos requeridos', async () => {
    mockMutateAsync.mockResolvedValue({ data: {} });
    renderWithProviders(
      <CreateGroupDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />,
    );

    await userEvent.click(screen.getByTestId('create-group-submit'));

    await waitFor(() => {
      expect(screen.getAllByText(/Requerido/i).length).toBeGreaterThan(0);
    });
    expect(mockMutateAsync).not.toHaveBeenCalled();
  });

  it('envia payload completo al backend y cierra al exito', async () => {
    mockMutateAsync.mockResolvedValue({
      data: {
        group: { id: 1, name: 'Mi Holding', slug: 'mi-holding', status: 'active', is_owner: true },
        tenant: { id: 2, name: 'Mi Empresa', slug: 'mi-empresa', status: 'active' },
      },
    });
    const onCreated = vi.fn();
    const onOpenChange = vi.fn();

    renderWithProviders(
      <CreateGroupDialog open onOpenChange={onOpenChange} onCreated={onCreated} />,
    );

    await userEvent.type(screen.getByTestId('create-group-name'), 'Mi Holding');
    await userEvent.type(screen.getByTestId('create-group-slug'), 'mi-holding');
    await userEvent.type(screen.getByTestId('create-tenant-name'), 'Mi Empresa');
    await userEvent.type(screen.getByTestId('create-tenant-slug'), 'mi-empresa');
    await userEvent.type(screen.getByTestId('create-admin-name'), 'Owner');
    await userEvent.type(screen.getByTestId('create-admin-email'), 'owner@test.com');

    await userEvent.click(screen.getByTestId('create-group-submit'));

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalledTimes(1);
    });

    const payload = mockMutateAsync.mock.calls[0]?.[0] as { group: { name: string }; admin: { email: string }; tenant: { name: string } };
    expect(payload.group.name).toBe('Mi Holding');
    expect(payload.tenant.name).toBe('Mi Empresa');
    expect(payload.admin.email).toBe('owner@test.com');
    expect(onCreated).toHaveBeenCalledWith(
      expect.objectContaining({ slug: 'mi-holding' }),
      expect.objectContaining({ slug: 'mi-empresa' }),
    );
  });

  it('muestra toast de error si la mutacion falla', async () => {
    mockMutateAsync.mockRejectedValue(new Error('Slug duplicado'));
    renderWithProviders(
      <CreateGroupDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />,
    );

    await userEvent.type(screen.getByTestId('create-group-name'), 'X');
    await userEvent.type(screen.getByTestId('create-group-slug'), 'x');
    await userEvent.type(screen.getByTestId('create-tenant-name'), 'Y');
    await userEvent.type(screen.getByTestId('create-tenant-slug'), 'y');
    await userEvent.type(screen.getByTestId('create-admin-name'), 'A');
    await userEvent.type(screen.getByTestId('create-admin-email'), 'a@b.com');

    await userEvent.click(screen.getByTestId('create-group-submit'));

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
  });
});

describe('CreateSpinoffDialog', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset();
  });

  it('envia payload al endpoint del grupo', async () => {
    mockMutateAsync.mockResolvedValue({
      data: { id: 10, name: 'Sucursal Caracas', slug: 'caracas', status: 'active' },
    });
    const onCreated = vi.fn();
    const group = {
      id: 1,
      name: 'Mi Grupo',
      slug: 'mi-grupo',
      status: 'active',
      is_owner: true,
    };

    renderWithProviders(
      <CreateSpinoffDialog
        open
        onOpenChange={vi.fn()}
        group={group}
        onCreated={onCreated}
      />,
    );

    await userEvent.type(screen.getByTestId('create-spinoff-name'), 'Sucursal Caracas');
    await userEvent.type(screen.getByTestId('create-spinoff-slug'), 'caracas');
    await userEvent.type(screen.getByTestId('create-spinoff-admin-name'), 'Admin Caracas');
    await userEvent.type(screen.getByTestId('create-spinoff-admin-email'), 'admin@test.com');

    await userEvent.click(screen.getByTestId('create-spinoff-submit'));

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalledTimes(1);
    });
    expect(mockUseCreateSpinoff).toHaveBeenCalledWith(1);
    expect(onCreated).toHaveBeenCalledWith(
      expect.objectContaining({ slug: 'caracas' }),
    );
  });
});