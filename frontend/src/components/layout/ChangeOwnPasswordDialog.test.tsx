import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const mockChangeOwnPassword = vi.fn();

vi.mock('@/api/endpoints/auth', () => ({
  changeOwnPassword: (...args: unknown[]) => mockChangeOwnPassword(...args),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { ChangeOwnPasswordDialog } from './ChangeOwnPasswordDialog';

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('ChangeOwnPasswordDialog', () => {
  beforeEach(() => {
    mockChangeOwnPassword.mockReset();
    mockChangeOwnPassword.mockResolvedValue({ message: 'Contraseña actualizada' });
  });

  it('envía contraseña actual, nueva contraseña y confirmación', async () => {
    const onOpenChange = vi.fn();
    render(<ChangeOwnPasswordDialog open onOpenChange={onOpenChange} />, { wrapper: makeWrapper() });

    await userEvent.type(screen.getByTestId('own-current-password'), 'Password123');
    await userEvent.type(screen.getByTestId('own-new-password'), 'NuevaClave456');
    await userEvent.type(screen.getByTestId('own-confirm-password'), 'NuevaClave456');
    fireEvent.click(screen.getByTestId('own-change-password-submit'));

    await waitFor(() => {
      expect(mockChangeOwnPassword).toHaveBeenCalledWith({
        current_password: 'Password123',
        new_password: 'NuevaClave456',
        confirm_password: 'NuevaClave456',
      });
    });
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('bloquea cuando las contraseñas nuevas no coinciden', async () => {
    render(<ChangeOwnPasswordDialog open onOpenChange={vi.fn()} />, { wrapper: makeWrapper() });

    await userEvent.type(screen.getByTestId('own-current-password'), 'Password123');
    await userEvent.type(screen.getByTestId('own-new-password'), 'NuevaClave456');
    await userEvent.type(screen.getByTestId('own-confirm-password'), 'DistintaClave789');
    fireEvent.click(screen.getByTestId('own-change-password-submit'));

    await waitFor(() => {
      expect(screen.getByText(/no coinciden/i)).toBeInTheDocument();
    });
    expect(mockChangeOwnPassword).not.toHaveBeenCalled();
  });

  it('bloquea si falta la contraseña actual', async () => {
    render(<ChangeOwnPasswordDialog open onOpenChange={vi.fn()} />, { wrapper: makeWrapper() });

    await userEvent.type(screen.getByTestId('own-new-password'), 'NuevaClave456');
    await userEvent.type(screen.getByTestId('own-confirm-password'), 'NuevaClave456');
    fireEvent.click(screen.getByTestId('own-change-password-submit'));

    await waitFor(() => {
      expect(screen.getByText(/Ingresa tu contrasena actual/i)).toBeInTheDocument();
    });
    expect(mockChangeOwnPassword).not.toHaveBeenCalled();
  });
});
