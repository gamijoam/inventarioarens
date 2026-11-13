import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ValidationError } from '@/types/api';
import { ExpirationsPanel } from '../ExpirationsPanel';

const mutateAsync = vi.fn();
const toastError = vi.fn<(message: string) => void>();
const toastSuccess = vi.fn<(message: string) => void>();
let canAdjust = true;
const lastFilters: unknown[] = [];

vi.mock('@/lib/toast', () => ({
  toast: {
    error: (m: string) => {
      toastError(m);
    },
    success: (m: string) => {
      toastSuccess(m);
    },
  },
}));
vi.mock('@/permissions/useCan', () => ({ useCan: () => canAdjust }));
vi.mock('../api', () => ({
  useStockLots: (filters: unknown) => {
    lastFilters.push(filters);
    return {
      isLoading: false,
      data: {
        data: [
          { id: 9, lot_code: 'L-1', expires_on: '2026-11-11', days_left: -2, quantity: 3, unit_cost: 2, value_base_amount: 6, product: { id: 1, name: 'Yogurt', sku: 'YOG' }, variant: null, warehouse: { id: 1, name: 'Almacen', code: 'ALM' } },
          { id: 10, lot_code: null, expires_on: '2026-11-16', days_left: 3, quantity: 5, unit_cost: 1, value_base_amount: 5, product: { id: 2, name: 'Queso', sku: 'QUE' }, variant: null, warehouse: { id: 1, name: 'Almacen', code: 'ALM' } },
        ],
        summary: { lots: 2, value_base_amount: 11, expired_value_base_amount: 6 },
      },
    };
  },
  useWriteOffStockLot: () => ({ mutateAsync, isPending: false }),
}));

describe('ExpirationsPanel', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
    canAdjust = true;
    lastFilters.length = 0;
  });

  it('muestra los lotes con dias restantes y el valor en riesgo', () => {
    render(<ExpirationsPanel />);
    expect(screen.getByText('Yogurt')).toBeInTheDocument();
    expect(screen.getByText(/Vencido hace 2 d/i)).toBeInTheDocument();
    expect(screen.getByText(/Vence en 3 d/i)).toBeInTheDocument();
    expect(screen.getByTestId('expirations-total')).toHaveTextContent('11');
  });

  it('cambia el filtro a vencidos', async () => {
    render(<ExpirationsPanel />);
    await userEvent.selectOptions(screen.getByLabelText(/Mostrar/i), 'expired');
    expect(lastFilters.at(-1)).toMatchObject({ status: 'expired' });
  });

  it('da de baja un lote y confirma', async () => {
    mutateAsync.mockResolvedValue({});
    render(<ExpirationsPanel />);
    await userEvent.click(screen.getAllByRole('button', { name: /Dar de baja/i })[0]!);
    await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ id: 9, reason: 'Vencido' }));
    expect(toastSuccess).toHaveBeenCalledWith('Lote dado de baja como merma.');
  });

  it('muestra el mensaje del backend si la baja es rechazada', async () => {
    const message = 'La cantidad a dar de baja debe ser mayor que cero y no superar lo que queda en el lote (3).';
    mutateAsync.mockRejectedValue(new ValidationError(message, { quantity: [message] }));
    render(<ExpirationsPanel />);
    await userEvent.click(screen.getAllByRole('button', { name: /Dar de baja/i })[0]!);
    await waitFor(() => expect(toastError).toHaveBeenCalledWith(message));
  });

  it('sin permiso de ajuste no ofrece dar de baja', () => {
    canAdjust = false;
    render(<ExpirationsPanel />);
    expect(screen.queryByRole('button', { name: /Dar de baja/i })).not.toBeInTheDocument();
  });
});
