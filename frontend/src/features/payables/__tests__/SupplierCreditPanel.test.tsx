import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ValidationError } from '@/types/api';
import { SupplierCreditPanel } from '../SupplierCreditPanel';

const mutateAsync = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();
let credit: { available_base_amount: number; available_local_amount: number; transactions: [] } | undefined;

vi.mock('@/lib/toast', () => ({
  toast: { error: (message: string) => toastError(message), success: (message: string) => toastSuccess(message) },
}));

vi.mock('../api', () => ({
  useSupplierCredit: () => ({ data: credit }),
  useApplySupplierCredit: () => ({ mutateAsync, isPending: false }),
}));

describe('SupplierCreditPanel', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
    credit = { available_base_amount: 20, available_local_amount: 2000, transactions: [] };
  });

  it('no se muestra cuando el proveedor no tiene credito', () => {
    credit = { available_base_amount: 0, available_local_amount: 0, transactions: [] };
    const { container } = render(<SupplierCreditPanel payableId={3} supplierId={7} balance={50} canPay />);
    expect(container).toBeEmptyDOMElement();
  });

  it('aplica por defecto el menor entre el credito y el saldo', async () => {
    mutateAsync.mockResolvedValue({});
    render(<SupplierCreditPanel payableId={3} supplierId={7} balance={15} canPay />);

    expect(screen.getByText(/Credito con este proveedor/i)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /Aplicar credito/i }));

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ id: 3, supplierId: 7, amount: 15 }));
    expect(toastSuccess).toHaveBeenCalledWith('Credito aplicado a la cuenta por pagar.');
  });

  it('muestra el mensaje del backend cuando el credito es rechazado', async () => {
    const message = 'El credito disponible con este proveedor es $20.00; no cubre $25.00.';
    mutateAsync.mockRejectedValue(new ValidationError(message, { amount: [message] }));
    render(<SupplierCreditPanel payableId={3} supplierId={7} balance={50} canPay />);

    const input = screen.getByLabelText(/Monto a aplicar/i);
    await userEvent.clear(input);
    await userEvent.type(input, '25');
    await userEvent.click(screen.getByRole('button', { name: /Aplicar credito/i }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith(message));
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  it('oculta la accion a quien no puede pagar', () => {
    render(<SupplierCreditPanel payableId={3} supplierId={7} balance={50} canPay={false} />);
    expect(screen.getByText(/Credito con este proveedor/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Aplicar credito/i })).not.toBeInTheDocument();
  });
});
