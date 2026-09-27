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

function makeSale(
  paidAt: string,
  payments: NonNullable<Sale['pos_order']>['payments'] = [],
): Sale {
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
      payments,
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
          refund_reference: null,
        },
      });
    });
  });

  it('exige referencia de reembolso cuando hay pagos externos y la envia', async () => {
    render(
      <ReverseSaleDialog
        sale={makeSale(new Date().toISOString(), [
          {
            id: 1,
            method: 'mobile_payment',
            currency: 'USD',
            amount: 100,
            amount_base: 100,
            amount_local: 0,
            exchange_rate: 0,
            status: 'captured',
          },
        ])}
        activeSession={{ id: 31 } as unknown as CashRegisterSession}
        open
        onOpenChange={vi.fn()}
      />,
    );

    fireEvent.change(screen.getByLabelText('Motivo'), { target: { value: 'Reembolso movil' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar anulación' }));

    expect(mutateAsync).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Referencia de reembolso'), {
      target: { value: 'REF-PM-1' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar anulación' }));

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        posOrderId: 22,
        payload: {
          type: 'void',
          reason: 'Reembolso movil',
          cash_register_session_id: 31,
          refund_reference: 'REF-PM-1',
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
