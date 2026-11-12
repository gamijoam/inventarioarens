import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ValidationError } from '@/types/api';
import { SupplierCreditDialog } from '../SupplierCreditDialog';

const mutateAsync = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();
let canPay = true;

vi.mock('@/lib/toast', () => ({
  toast: { error: (message: string) => toastError(message), success: (message: string) => toastSuccess(message) },
}));

vi.mock('@/permissions/useCan', () => ({ useCan: () => canPay }));

vi.mock('@/features/payables/api', () => ({
  useSupplierCredit: () => ({
    data: {
      supplier_id: 7,
      available_base_amount: 20,
      available_local_amount: 2000,
      transactions: [
        { id: 1, type: 'return_credit', currency: 'USD', amount: 20, amount_base: 20, amount_local: 2000, notes: 'Devolucion de compra #4 posterior al pago', occurred_at: '2026-11-12T10:00:00.000000Z' },
      ],
    },
    isLoading: false,
  }),
  useRegisterSupplierCreditRefund: () => ({ mutateAsync, isPending: false }),
}));

const supplier = { id: 7, name: 'Distribuidora Los Andes' } as never;

describe('SupplierCreditDialog', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
    canPay = true;
  });

  it('muestra el credito disponible y su historial', () => {
    render(<SupplierCreditDialog supplier={supplier} onClose={vi.fn()} />);
    expect(screen.getByText(/Disponible/i)).toHaveTextContent('20,00');
    expect(screen.getByText(/Devolucion de compra #4/i)).toBeInTheDocument();
  });

  it('registra el reembolso recibido y cierra', async () => {
    mutateAsync.mockResolvedValue({});
    const onClose = vi.fn();
    render(<SupplierCreditDialog supplier={supplier} onClose={onClose} />);

    await userEvent.type(screen.getByLabelText(/Monto recibido/i), '1500');
    await userEvent.selectOptions(screen.getByLabelText(/Moneda/i), 'VES');
    await userEvent.type(screen.getByLabelText(/Referencia/i), 'TRF-1');
    await userEvent.click(screen.getByRole('button', { name: /Registrar reembolso/i }));

    await waitFor(() =>
      expect(mutateAsync).toHaveBeenCalledWith({
        supplierId: 7,
        values: { payment_currency: 'VES', amount: 1500, method: 'transfer', reference: 'TRF-1' },
      }),
    );
    expect(toastSuccess).toHaveBeenCalledWith('Reembolso registrado.');
    expect(onClose).toHaveBeenCalled();
  });

  it('muestra el mensaje del backend y no cierra si el reembolso es rechazado', async () => {
    const message = 'El credito disponible con este proveedor es $20.00; el reembolso equivale a $25.00.';
    mutateAsync.mockRejectedValue(new ValidationError(message, { amount: [message] }));
    const onClose = vi.fn();
    render(<SupplierCreditDialog supplier={supplier} onClose={onClose} />);

    await userEvent.type(screen.getByLabelText(/Monto recibido/i), '25');
    await userEvent.click(screen.getByRole('button', { name: /Registrar reembolso/i }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith(message));
    expect(onClose).not.toHaveBeenCalled();
  });

  it('sin permiso de pago solo muestra el saldo', () => {
    canPay = false;
    render(<SupplierCreditDialog supplier={supplier} onClose={vi.fn()} />);
    expect(screen.queryByRole('button', { name: /Registrar reembolso/i })).not.toBeInTheDocument();
  });
});
