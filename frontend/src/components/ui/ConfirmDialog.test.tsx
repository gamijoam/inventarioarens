import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ConfirmDialog } from './ConfirmDialog';

// eslint-disable-next-line @typescript-eslint/no-empty-function
const noop = () => {};

describe('<ConfirmDialog>', () => {
  it('renderiza titulo y descripcion', () => {
    render(
      <ConfirmDialog
        open
        onOpenChange={noop}
        title="Eliminar producto"
        description="Esta accion no se puede deshacer."
        confirmLabel="Eliminar"
        onConfirm={noop}
      />,
    );
    expect(screen.getByText('Eliminar producto')).toBeInTheDocument();
    expect(screen.getByText('Esta accion no se puede deshacer.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Eliminar' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeInTheDocument();
  });

  it('llama onConfirm al click en Confirmar', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog
        open
        onOpenChange={noop}
        title="Confirmar"
        confirmLabel="Si"
        onConfirm={onConfirm}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Si' }));
    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(1));
  });

  it('llama onOpenChange(false) al click en Cancelar', async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    render(
      <ConfirmDialog
        open
        onOpenChange={onOpenChange}
        title="Confirmar"
        onConfirm={noop}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});