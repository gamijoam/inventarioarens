/**
 * Tests para TransferResolveDifferencesDialog (Fase T2 - resolver diffs).
 * Cubre:
 *   - muestra el mensaje "sin diferencias" cuando el traslado no tiene items
 *     con difference_quantity > 0.
 *   - renderiza una fila por cada item con diferencia y permite elegir accion.
 *   - el input de cantidad solo aparece para adjusted_manually.
 *   - el submit llama a useResolveTransferDifferences con el payload correcto.
 *   - valida que quantity > 0 cuando la accion es adjusted_manually.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { TransferResolveDifferencesDialog } from '../TransferResolveDifferencesDialog';
import type { Transfer } from '../../schemas';

const mockMutateAsync = vi.fn();
let lastMutationValues: unknown = null;

vi.mock('@/features/transfers/api', () => ({
  useResolveTransferDifferences: () => ({
    mutateAsync: async (values: unknown) => {
      lastMutationValues = values;
      await mockMutateAsync(values);
      return {};
    },
  }),
}));

function makeTransfer(): Transfer {
  return {
    id: 99,
    sequence: 1,
    document_number: 'TRF-DIFF-99',
    guide_number: 'GUIA-DIFF-99',
    type: 'internal',
    validation_mode: 'logistics',
    status: 'completed_with_differences',
    from_warehouse_id: 1,
    to_warehouse_id: 2,
    reason: 'Test',
    reference: null,
    notes: null,
    total_base_amount: 100,
    total_local_amount: null,
    received_base_amount: 90,
    received_local_amount: null,
    resolution_status: 'unresolved',
    resolution_notes: null,
    driver: null,
    items_count: 2,
    items: [
      {
        id: 1001,
        product_id: 10,
        product: { id: 10, name: 'Producto A', sku: 'PA' },
        quantity: 10,
        requested_quantity: 10,
        prepared_quantity: 10,
        received_quantity: 8,
        difference_quantity: 2,
        serial_units: null,
        prepared_product_unit_ids: null,
        received_product_unit_ids: null,
      },
      {
        id: 1002,
        product_id: 20,
        product: { id: 20, name: 'Producto B', sku: 'PB' },
        quantity: 5,
        requested_quantity: 5,
        prepared_quantity: 5,
        received_quantity: 5,
        difference_quantity: 0,
        serial_units: null,
        prepared_product_unit_ids: null,
        received_product_unit_ids: null,
      },
    ],
  } as Transfer;
}

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('TransferResolveDifferencesDialog', () => {
  beforeEach(() => {
    mockMutateAsync.mockReset();
    mockMutateAsync.mockResolvedValue({});
    lastMutationValues = null;
  });

  it('muestra el mensaje de sin diferencias cuando no hay items con diff', () => {
    const t = makeTransfer();
    const sinDiff: Transfer = { ...t, items: [] } as Transfer;
    render(<TransferResolveDifferencesDialog transfer={sinDiff} onClose={() => {}} />, {
      wrapper: makeWrapper(),
    });
    expect(screen.getByText(/no tiene diferencias pendientes/i)).toBeInTheDocument();
  });

  it('renderiza una fila por item con diferencia y envia accepted_loss por default', async () => {
    const t = makeTransfer();
    const user = userEvent.setup();
    render(<TransferResolveDifferencesDialog transfer={t} onClose={() => {}} />, {
      wrapper: makeWrapper(),
    });
    expect(screen.getByText('Producto A')).toBeInTheDocument();
    // Producto B tiene difference_quantity = 0 -> no se renderiza en la tabla.
    expect(screen.queryByText('Producto B')).not.toBeInTheDocument();

    const submit = screen.getByRole('button', { name: /confirmar resolucion/i });
    await user.click(submit);

    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenCalledTimes(1);
    });
    expect(lastMutationValues).toEqual({
      id: 99,
      values: {
        items: [{ inventory_transfer_item_id: 1001, action: 'accepted_loss' }],
        notes: null,
      },
    });
  });

  it('muestra input de cantidad solo cuando la accion es adjusted_manually', async () => {
    const t = makeTransfer();
    const user = userEvent.setup();
    render(<TransferResolveDifferencesDialog transfer={t} onClose={() => {}} />, {
      wrapper: makeWrapper(),
    });

    // Antes de cambiar la accion, el input de cantidad NO deberia existir.
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();

    // Cambiar la accion del unico item con diff a adjusted_manually.
    const select = screen.getByRole('combobox');
    await user.selectOptions(select, 'adjusted_manually');

    // Ahora si aparece el input de cantidad (con la diferencia precargada).
    const input = await screen.findByRole('spinbutton');
    expect(input).toBeInTheDocument();
    expect((input as HTMLInputElement).value).toBe('2');
  });

  it('rechaza adjusted_manually con quantity = 0 y muestra toast', async () => {
    const t = makeTransfer();
    const user = userEvent.setup();
    render(<TransferResolveDifferencesDialog transfer={t} onClose={() => {}} />, {
      wrapper: makeWrapper(),
    });
    const select = screen.getByRole('combobox');
    await user.selectOptions(select, 'adjusted_manually');
    const input = await screen.findByRole('spinbutton');
    await user.clear(input);

    const submit = screen.getByRole('button', { name: /confirmar resolucion/i });
    await user.click(submit);

    await waitFor(() => {
      expect(mockMutateAsync).not.toHaveBeenCalled();
    });
  });
});
