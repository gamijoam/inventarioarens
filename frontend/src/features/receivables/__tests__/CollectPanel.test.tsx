import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ValidationError } from '@/types/api';
import { CollectPanel } from '../ReceivablesManager';
import type { Receivable } from '../schemas';

const mutateAsync = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();

vi.mock('@/lib/toast', () => ({
  toast: { error: (message: string) => toastError(message), success: (message: string) => toastSuccess(message) },
}));

vi.mock('../api', () => ({
  useCollectReceivable: () => ({ mutateAsync, isPending: false }),
  useReceivable: () => ({ data: undefined }),
  useReceivables: () => ({ data: undefined }),
  exportReceivables: vi.fn(),
}));

vi.mock('@/features/pos/api', () => ({
  useCashSessions: () => ({ data: [{ id: 7, status: 'open' }] }),
  usePaymentMethods: () => ({ data: [] }),
  useCurrentExchangeRatesForPos: () => ({ data: [] }),
}));

const receivable = {
  id: 3,
  sale_id: 9,
  status: 'pending',
  original_base_amount: 200,
  original_local_amount: 20000,
  returned_base_amount: 0,
  returned_local_amount: 0,
  collected_base_amount: 0,
  collected_local_amount: 0,
  adjusted_base_amount: 0,
  adjusted_local_amount: 0,
  balance_base_amount: 200,
  balance_local_amount: 20000,
} as unknown as Receivable;

describe('CollectPanel', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
  });

  it('muestra el mensaje del backend cuando el cobro es rechazado y no cierra el panel', async () => {
    const message = 'La tasa indicada (1) no coincide con la tasa oficial vigente BCV (100).';
    mutateAsync.mockRejectedValue(new ValidationError(message, { exchange_rate: [message] }));
    const onClose = vi.fn();

    render(<CollectPanel receivable={receivable} activeRate={null} onClose={onClose} />);
    await userEvent.click(screen.getByRole('button', { name: /Registrar cobro/i }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith(message));
    expect(onClose).not.toHaveBeenCalled();
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  it('confirma y cierra cuando el cobro se registra', async () => {
    mutateAsync.mockResolvedValue({});
    const onClose = vi.fn();

    render(<CollectPanel receivable={receivable} activeRate={null} onClose={onClose} />);
    await userEvent.click(screen.getByRole('button', { name: /Registrar cobro/i }));

    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(toastSuccess).toHaveBeenCalledWith('Cobro registrado.');
  });
});
