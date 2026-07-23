/**
 * Tests del TransferCreateDialog: validaciones client-side.
 *
 * Caso principal: si el usuario elige el mismo almacen como origen y
 * destino, el dialog debe bloquear el submit con error inline (regla
 * backend: `different:from_warehouse_id` en StoreInventoryTransferRequest).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mutateAsync = vi.fn();
const invalidateQueries = vi.fn();

vi.mock('@/features/transfers/api', () => ({
  useCreateTransfer: () => ({ mutateAsync }),
  useProductsForTransfer: () => ({
    data: [
      { id: 100, name: 'PRODUCTO A', sku: 'SKU-A', tracking_type: 'quantity' },
      { id: 200, name: 'PRODUCTO B', sku: 'SKU-B', tracking_type: 'serialized' },
    ],
  }),
  useWarehouses: () => ({
    data: [
      { id: 1, code: 'WH-1', name: 'Almacen 1' },
      { id: 2, code: 'WH-2', name: 'Almacen 2' },
    ],
  }),
}));

vi.mock('@tanstack/react-query', () => ({
  useQueryClient: () => ({ invalidateQueries }),
  useQuery: () => ({ data: [] }),
  useMutation: () => ({ mutateAsync }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { TransferCreateDialog } from './TransferCreateDialog';

function getWarehouseSelect(which: 'from' | 'to'): HTMLSelectElement {
  // 0: from warehouse, 1: to warehouse, 2: validation_mode, 3: product (cuando hay items)
  const selects = screen.getAllByRole('combobox') as HTMLSelectElement[];
  const idx = which === 'from' ? 0 : 1;
  const el = selects[idx];
  if (!el) throw new Error(`Warehouse ${which} select not found`);
  return el;
}

function getProductInput(): HTMLInputElement {
  const input = screen.getByLabelText(/Buscar producto de la linea 1/i);
  if (!(input instanceof HTMLInputElement)) throw new Error('Product input no encontrado');
  return input;
}

describe('TransferCreateDialog', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
    mutateAsync.mockResolvedValue({ id: 999 });
    invalidateQueries.mockReset();
  });

  it('bloquea submit cuando from_warehouse_id === to_warehouse_id', async () => {
    render(<TransferCreateDialog open onOpenChange={vi.fn()} />);

    await userEvent.selectOptions(getWarehouseSelect('from'), '1');
    await userEvent.selectOptions(getWarehouseSelect('to'), '1');
    await userEvent.type(getProductInput(), 'PRODUCTO A');
    await userEvent.click(await screen.findByRole('option', { name: /PRODUCTO A/i }));

    fireEvent.click(screen.getByRole('button', { name: /Crear borrador/ }));

    await waitFor(() => {
      expect(screen.getByText(/El almacen de destino debe ser distinto del origen/)).toBeTruthy();
    });
    expect(mutateAsync).not.toHaveBeenCalled();
  });

  it('bloquea submit si falta almacen origen', async () => {
    render(<TransferCreateDialog open onOpenChange={vi.fn()} />);
    await userEvent.selectOptions(getWarehouseSelect('to'), '2');
    await userEvent.type(getProductInput(), 'PRODUCTO A');
    await userEvent.click(await screen.findByRole('option', { name: /PRODUCTO A/i }));

    fireEvent.click(screen.getByRole('button', { name: /Crear borrador/ }));

    await waitFor(() => {
      expect(screen.getByText(/Selecciona el almacen de origen/)).toBeTruthy();
    });
    expect(mutateAsync).not.toHaveBeenCalled();
  });

  it('bloquea submit si falta almacen destino', async () => {
    render(<TransferCreateDialog open onOpenChange={vi.fn()} />);
    await userEvent.selectOptions(getWarehouseSelect('from'), '1');
    await userEvent.type(getProductInput(), 'PRODUCTO A');
    await userEvent.click(await screen.findByRole('option', { name: /PRODUCTO A/i }));

    fireEvent.click(screen.getByRole('button', { name: /Crear borrador/ }));

    await waitFor(() => {
      expect(screen.getByText(/Selecciona el almacen de destino/)).toBeTruthy();
    });
    expect(mutateAsync).not.toHaveBeenCalled();
  });

  it('happy path: warehouses distintos + item con cantidad llama a la mutation y redirige', async () => {
    const onCreated = vi.fn();
    const onOpenChange = vi.fn();
    render(<TransferCreateDialog open onOpenChange={onOpenChange} onCreated={onCreated} />);

    await userEvent.selectOptions(getWarehouseSelect('from'), '1');
    await userEvent.selectOptions(getWarehouseSelect('to'), '2');
    await userEvent.type(getProductInput(), 'PRODUCTO A');
    await userEvent.click(await screen.findByRole('option', { name: /PRODUCTO A/i }));

    fireEvent.click(screen.getByRole('button', { name: /Crear borrador/ }));

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledTimes(1);
    });
    const payload = mutateAsync.mock.calls[0]?.[0] as { from_warehouse_id: number; to_warehouse_id: number; items: { product_id: number }[] };
    expect(payload.from_warehouse_id).toBe(1);
    expect(payload.to_warehouse_id).toBe(2);
    expect(payload.items).toHaveLength(1);
    expect(payload.items[0]?.product_id).toBe(100);
    expect(onCreated).toHaveBeenCalledWith(999);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('happy path serializado: exige N IMEIs = quantity', async () => {
    render(<TransferCreateDialog open onOpenChange={vi.fn()} />);

    await userEvent.selectOptions(getWarehouseSelect('from'), '1');
    await userEvent.selectOptions(getWarehouseSelect('to'), '2');
    await userEvent.type(getProductInput(), 'PRODUCTO B');
    await userEvent.click(await screen.findByRole('option', { name: /PRODUCTO B/i }));
    // product 200 es serialized, quantity arranca en 1, falta IMEI.

    fireEvent.click(screen.getByRole('button', { name: /Crear borrador/ }));

    await waitFor(() => {
      expect(screen.getByText(/Debe ingresar un IMEI\/serial por cada unidad/)).toBeTruthy();
    });
    expect(mutateAsync).not.toHaveBeenCalled();
  });
});
