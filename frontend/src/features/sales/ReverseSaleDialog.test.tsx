import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import type { CashRegisterSession } from '@/features/pos/api';
import type { Sale } from './schemas';

const mutateAsync = vi.fn();

vi.mock('./api', () => ({
  useReversePosSale: () => ({ mutateAsync, isPending: false }),
}));
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { ReverseSaleDialog } from './ReverseSaleDialog';

function makeSale(paidAt: string): Sale {
  return {
    id: 15,
    status: 'confirmed',
    total_base_amount: 100,
    total_local_amount: 6000,
    pos_order: {
      id: 22,
      status: 'paid',
      cash_register_session_id: 3,
      total_base_amount: 100,
      total_local_amount: 6000,
      paid_base_amount: 100,
      paid_local_amount: 6000,
      paid_at: paidAt,
    },
    receivable: null,
  };
}

describe('ReverseSaleDialog', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
    mutateAsync.mockResolvedValue({ id: 9, type: 'void' });
  });

  it('envía void para una venta de hoy con motivo y sesión abierta', async () => {
    render(
      <ReverseSaleDialog
        sale={makeSale(new Date().toISOString())}
        activeSession={{ id: 31 } as unknown as CashRegisterSession}
        open
        onOpenChange={vi.fn()}
      />,
    );

    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'Error de cobro' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar anulación' }));

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        posOrderId: 22,
        payload: {
          type: 'void',
          reason: 'Error de cobro',
          cash_register_session_id: 31,
        },
      });
    });
  });

  it('fuerza reversal para una venta anterior y exige motivo mínimo', () => {
    render(
      <ReverseSaleDialog
        sale={makeSale('2020-01-01T12:00:00Z')}
        activeSession={{ id: 31 } as unknown as CashRegisterSession}
        open
        onOpenChange={vi.fn()}
      />,
    );

    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'bad' } });
    expect(screen.getByLabelText('Tipo de operación')).toHaveValue('reversal');
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar reversión' }));

    expect(mutateAsync).not.toHaveBeenCalled();
    expect(screen.getByText('El motivo debe tener al menos 5 caracteres.')).toBeInTheDocument();
  });

  it('bloquea el envío cuando no existe una caja abierta', () => {
    render(
      <ReverseSaleDialog
        sale={makeSale(new Date().toISOString())}
        activeSession={null}
        open
        onOpenChange={vi.fn()}
      />,
    );

    expect(
      screen.getByText('Debes abrir una caja para procesar esta operación.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Confirmar anulación' })).toBeDisabled();
  });
});
