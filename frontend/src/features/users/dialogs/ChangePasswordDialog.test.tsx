/**
 * Tests del ChangePasswordDialog: cambia la contrasena de un usuario
 * con validacion de coincidencia.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const changePasswordMutation = vi.fn();

vi.mock('../api', () => ({
  useChangePassword: () => ({ mutateAsync: changePasswordMutation }),
}));
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { ChangePasswordDialog } from './ChangePasswordDialog';

const user = { id: 7, email: 'target@empresa.com', name: 'Target' };

describe('ChangePasswordDialog', () => {
  beforeEach(() => {
    changePasswordMutation.mockReset();
    changePasswordMutation.mockResolvedValue({ id: 7 });
  });

  it('envia nueva contrasena y confirmacion', async () => {
    const onOpenChange = vi.fn();
    render(<ChangePasswordDialog open onOpenChange={onOpenChange} user={user as never} />);

    await userEvent.type(screen.getByTestId('user-new-password'), 'NuevaClave123');
    await userEvent.type(screen.getByTestId('user-confirm-password'), 'NuevaClave123');
    fireEvent.click(screen.getByTestId('user-change-password-submit'));

    await waitFor(() => {
      expect(changePasswordMutation).toHaveBeenCalledWith({
        id: 7,
        values: { new_password: 'NuevaClave123', confirm_password: 'NuevaClave123' },
      });
    });
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('bloquea cuando las contrasenas no coinciden', async () => {
    render(<ChangePasswordDialog open onOpenChange={vi.fn()} user={user as never} />);

    await userEvent.type(screen.getByTestId('user-new-password'), 'NuevaClave123');
    await userEvent.type(screen.getByTestId('user-confirm-password'), 'OtraClave456');
    fireEvent.click(screen.getByTestId('user-change-password-submit'));

    await waitFor(() => {
      expect(screen.getByText(/no coinciden/i)).toBeInTheDocument();
    });
    expect(changePasswordMutation).not.toHaveBeenCalled();
  });
});
