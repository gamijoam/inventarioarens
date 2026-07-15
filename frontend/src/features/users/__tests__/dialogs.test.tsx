/**
 * Tests de los dialogs de Fase B del modulo de usuarios:
 * - CreateUserDialog
 * - EditUserDialog
 * - ChangeRolesDialog
 * - ConfirmDestructiveDialog (compartido)
 *
 * Mockeamos @/features/users/api y @/features/access/api para evitar
 * requests reales y controlar el state.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

// Mocks compartidos. Cada test los resetea.
const mockMutateAsync = vi.fn();
const mockUseRoles = vi.fn();
const mockUseCreateUser = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseUpdateUser = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseUpdateUserRoles = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));
const mockUseUpdateUserStatus = vi.fn(() => ({ mutateAsync: mockMutateAsync, isPending: false }));

vi.mock('@/features/users/api', () => ({
  useUsers: vi.fn(),
  useCreateUser: () => mockUseCreateUser() as unknown as { mutateAsync: () => Promise<unknown> },
  useUpdateUser: () => mockUseUpdateUser() as unknown as { mutateAsync: () => Promise<unknown> },
  useUpdateUserRoles: () => mockUseUpdateUserRoles() as unknown as { mutateAsync: () => Promise<unknown> },
  useUpdateUserStatus: () => mockUseUpdateUserStatus() as unknown as { mutateAsync: () => Promise<unknown> },
  userKeys: { all: ['users'], lists: () => ['users','list'], list: () => ['users','list',{}] },
}));

vi.mock('@/features/access/api', () => ({
  useRoles: () => mockUseRoles() as unknown as { data: unknown; isLoading: boolean },
  roleKeys: { all: ['roles'], lists: () => ['roles','list'], list: () => ['roles','list',{}] },
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { CreateUserDialog } from '../dialogs/CreateUserDialog';
import { EditUserDialog } from '../dialogs/EditUserDialog';
import { ChangeRolesDialog } from '../dialogs/ChangeRolesDialog';
import { ConfirmDestructiveDialog } from '@/components/ConfirmDestructiveDialog';
import type { User } from '../schemas';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeRoles = {
  data: [
    { id: 1, name: 'Owner', is_protected: true, permissions: [] },
    { id: 2, name: 'Gerente', is_protected: true, permissions: [] },
    { id: 3, name: 'Vendedor', is_protected: true, permissions: [] },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 3 },
};

const fakeUser: User = {
  id: 5,
  name: 'Lucia Perez',
  email: 'lucia@test.test',
  status: 'active',
  roles: [{ id: 2, name: 'Gerente' }],
  created_at: '2026-07-15T10:00:00.000000Z',
};

beforeEach(() => {
  mockMutateAsync.mockReset();
  mockMutateAsync.mockResolvedValue(fakeUser);
  mockUseRoles.mockReset();
  mockUseRoles.mockReturnValue({ data: fakeRoles, isLoading: false });
});

describe('CreateUserDialog', () => {
  it('abre con el form vacio', () => {
    render(<CreateUserDialog open onOpenChange={vi.fn()} />, { wrapper: makeWrapper() });
    expect(screen.getByTestId('create-user-name')).toHaveValue('');
    expect(screen.getByTestId('create-user-email')).toHaveValue('');
    expect(screen.getByTestId('create-user-password')).toHaveValue('');
  });

  it('muestra error de validacion si el form esta vacio', async () => {
    render(<CreateUserDialog open onOpenChange={vi.fn()} />, { wrapper: makeWrapper() });
    await userEvent.click(screen.getByTestId('create-user-submit'));
    expect(mockMutateAsync).not.toHaveBeenCalled();
    expect(screen.getByText('Requerido.')).toBeTruthy();
    expect(screen.getByText('Email invalido.')).toBeTruthy();
  });

  it('llama a useCreateUser con los valores correctos', async () => {
    const onCreated = vi.fn();
    render(<CreateUserDialog open onOpenChange={vi.fn()} onCreated={onCreated} />, { wrapper: makeWrapper() });
    await userEvent.type(screen.getByTestId('create-user-name'), 'Test User');
    await userEvent.type(screen.getByTestId('create-user-email'), 'test@test.com');
    await userEvent.type(screen.getByTestId('create-user-password'), 'password123');
    // Marcar el primer rol.
    await userEvent.click(screen.getByTestId('create-user-role-1'));
    await userEvent.click(screen.getByTestId('create-user-submit'));

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
    const payload = mockMutateAsync.mock.calls[0]?.[0];
    expect(payload.name).toBe('Test User');
    expect(payload.email).toBe('test@test.com');
    expect(payload.password).toBe('password123');
    expect(payload.roles).toEqual(['Owner']);
    expect(onCreated).toHaveBeenCalledWith(fakeUser.id);
  });
});

describe('EditUserDialog', () => {
  it('abre con el nombre del user pre-cargado', () => {
    render(<EditUserDialog open onOpenChange={vi.fn()} user={fakeUser} />, { wrapper: makeWrapper() });
    expect(screen.getByTestId('edit-user-name')).toHaveValue('Lucia Perez');
  });

  it('muestra error si se borra el nombre', async () => {
    render(<EditUserDialog open onOpenChange={vi.fn()} user={fakeUser} />, { wrapper: makeWrapper() });
    await userEvent.clear(screen.getByTestId('edit-user-name'));
    await userEvent.click(screen.getByTestId('edit-user-submit'));
    expect(mockMutateAsync).not.toHaveBeenCalled();
    expect(screen.getByText('Requerido.')).toBeTruthy();
  });

  it('llama a useUpdateUser al guardar', async () => {
    const onUpdated = vi.fn();
    render(<EditUserDialog open onOpenChange={vi.fn()} user={fakeUser} onUpdated={onUpdated} />, { wrapper: makeWrapper() });
    await userEvent.clear(screen.getByTestId('edit-user-name'));
    await userEvent.type(screen.getByTestId('edit-user-name'), 'Lucia Modificada');
    await userEvent.click(screen.getByTestId('edit-user-submit'));
    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
    const payload = mockMutateAsync.mock.calls[0]?.[0];
    expect(payload.id).toBe(5);
    expect(payload.values.name).toBe('Lucia Modificada');
  });
});

describe('ChangeRolesDialog', () => {
  it('abre con los roles del user pre-seleccionados', () => {
    render(<ChangeRolesDialog open onOpenChange={vi.fn()} user={fakeUser} />, { wrapper: makeWrapper() });
    // El rol Gerente (id=2) debe estar checked.
    const gerenteCheckbox = screen.getByTestId('change-role-2');
    expect(gerenteCheckbox).toBeChecked();
    // El rol Owner (id=1) no.
    const ownerCheckbox = screen.getByTestId('change-role-1');
    expect(ownerCheckbox).not.toBeChecked();
  });

  it('filtra los roles por busqueda', async () => {
    render(<ChangeRolesDialog open onOpenChange={vi.fn()} user={fakeUser} />, { wrapper: makeWrapper() });
    await userEvent.type(screen.getByTestId('change-roles-search'), 'Vend');
    // Solo Vendedor debe quedar visible.
    expect(screen.queryByTestId('change-role-1')).toBeNull();
    expect(screen.queryByTestId('change-role-2')).toBeNull();
    expect(screen.getByTestId('change-role-3')).toBeTruthy();
  });

  it('envia el nuevo set de roles al guardar', async () => {
    const onUpdated = vi.fn();
    render(<ChangeRolesDialog open onOpenChange={vi.fn()} user={fakeUser} onUpdated={onUpdated} />, { wrapper: makeWrapper() });
    // Agregar Owner.
    await userEvent.click(screen.getByTestId('change-role-1'));
    // Quitar Gerente.
    await userEvent.click(screen.getByTestId('change-role-2'));
    await userEvent.click(screen.getByTestId('change-roles-submit'));
    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalled();
    });
    const payload = mockMutateAsync.mock.calls[0]?.[0];
    expect(payload.id).toBe(5);
    expect(payload.values.roles).toEqual(['Owner']);
  });
});

describe('ConfirmDestructiveDialog', () => {
  it('el boton confirmar esta deshabilitado hasta tipear el texto correcto', () => {
    render(
      <ConfirmDestructiveDialog
        open
        onOpenChange={vi.fn()}
        title="Eliminar"
        description="Borrar"
        confirmText="BORRAR"
        onConfirm={vi.fn()}
      />,
      { wrapper: makeWrapper() }
    );
    const submit = screen.getByTestId('confirm-destructive-submit');
    expect(submit).toBeDisabled();

    // Tipear mal no habilita.
    const input = screen.getByTestId('confirm-destructive-input');
    return userEvent.type(input, 'borrar').then(() => {
      expect(submit.disabled).toBe(true);
    });
  });

  it('tipear el texto correcto habilita confirmar y dispara onConfirm', async () => {
    const onConfirm = vi.fn();
    render(
      <ConfirmDestructiveDialog
        open
        onOpenChange={vi.fn()}
        title="Eliminar"
        description="Borrar"
        confirmText="BORRAR"
        onConfirm={onConfirm}
      />,
      { wrapper: makeWrapper() }
    );
    const input = screen.getByTestId('confirm-destructive-input');
    await userEvent.type(input, 'BORRAR');
    const submit = screen.getByTestId('confirm-destructive-submit');
    expect(submit).not.toBeDisabled();
    await userEvent.click(submit);
    expect(onConfirm).toHaveBeenCalled();
  });
});