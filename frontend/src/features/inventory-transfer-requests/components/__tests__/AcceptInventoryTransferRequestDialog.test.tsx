/**
 * Tests para AcceptInventoryTransferRequestDialog con scoring + badges.
 *
 * Cubre:
 *   - El dropdown de producto destino se ordena por scoreMatch:
 *     SKU exacto primero, barcode segundo, nombre tercero, resto al final.
 *   - Las opciones con match muestran prefijo "[SKU]", "[Barcode]", "[Similar]".
 *   - Debajo del select aparece la sugerencia con el nombre del mejor match.
 *   - Sin match, no aparece sugerencia y el orden es por nombre.
 *   - El tracking_type incompatible se filtra.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// vi.hoisted garantiza que el mock este disponible cuando vi.mock se ejecute
// (vi.mock se hoistea al top del archivo, antes de cualquier const).
const { mockAcceptMutateAsync, mockUseWarehouses, mockUseProductsForTransfer } = vi.hoisted(() => ({
  mockAcceptMutateAsync: vi.fn(),
  mockUseWarehouses: vi.fn(),
  mockUseProductsForTransfer: vi.fn(),
}));

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useAcceptTransferRequest: () => ({
    mutateAsync: async (values: unknown) => {
      await mockAcceptMutateAsync(values);
      return { id: 999 };
    },
  }),
}));

vi.mock('@/features/inventory-center/api', () => ({
  useWarehouses: () => mockUseWarehouses(),
}));

vi.mock('@/features/transfers/api', () => ({
  useProductsForTransfer: () => mockUseProductsForTransfer(),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { AcceptInventoryTransferRequestDialog } from '../AcceptInventoryTransferRequestDialog';
import type { TransferRequest } from '../../schemas';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function makeRequest(items: NonNullable<TransferRequest['items']>): TransferRequest {
  return {
    id: 1,
    origin_tenant_id: 2,
    destination_tenant_id: 1,
    from_warehouse_id: 10,
    destination_warehouse_id: null,
    status: 'requested',
    items,
  } as TransferRequest;
}

describe('AcceptInventoryTransferRequestDialog - scoring y badges', () => {
  beforeEach(() => {
    mockAcceptMutateAsync.mockReset();
    mockAcceptMutateAsync.mockResolvedValue({});
    mockUseWarehouses.mockReturnValue({
      data: [
        { id: 10, code: 'W1' },
        { id: 11, code: 'W2' },
      ],
    });
  });

  it('ordena opciones por score: SKU exacto primero, barcode segundo, nombre tercero', () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Coca-Cola 1.5L',
          sku: 'CC-1500',
          barcode: '1234567890',
          tracking_type: 'quantity',
        },
        quantity: 5,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [
        // Match por nombre (no SKU, no barcode).
        { id: 1, name: 'Coca-Cola 1.5L Zero', sku: 'CC-1500-Z', tracking_type: 'quantity' },
        // Sin match.
        { id: 2, name: 'Pepsi 1.5L', sku: 'PP-1500', tracking_type: 'quantity' },
        // Match por barcode (no SKU).
        { id: 3, name: 'Otra marca importada', sku: 'IMP-001', barcode: '1234567890', tracking_type: 'quantity' },
        // Match exacto SKU.
        { id: 4, name: 'Coca-Cola 1.5L', sku: 'CC-1500', tracking_type: 'quantity' },
      ],
    });
    render(<AcceptInventoryTransferRequestDialog request={request} open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });

    const select = screen.getByTestId('accept-product-100') as HTMLSelectElement;
    const labels = Array.from(select.options).map((o) => o.text);
    // El primer option es el placeholder vacio.
    expect(labels[0]).toBe('Selecciona...');
    // Segundo: match SKU exacto.
    expect(labels[1]).toContain('[SKU]');
    expect(labels[1]).toContain('Coca-Cola 1.5L (CC-1500)');
    // Tercero: match barcode.
    expect(labels[2]).toContain('[Barcode]');
    // Cuarto: match nombre (Coca-Cola 1.5L Zero).
    expect(labels[3]).toContain('[Similar]');
    // Ultimo: sin match (Pepsi).
    expect(labels[4]).not.toContain('[');
    expect(labels[4]).toContain('Pepsi 1.5L');
  });

  it('muestra sugerencia con el nombre del mejor match debajo del select', () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Coca-Cola 1.5L',
          sku: 'CC-1500',
          barcode: null,
          tracking_type: 'quantity',
        },
        quantity: 5,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [
        { id: 99, name: 'Coca-Cola 1.5L', sku: 'CC-1500', tracking_type: 'quantity' },
      ],
    });
    render(<AcceptInventoryTransferRequestDialog request={request} open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    const hint = screen.getByTestId('accept-hint-100');
    expect(hint).toHaveTextContent('Coca-Cola 1.5L');
    expect(hint).toHaveTextContent('Sugerencia');
  });

  it('sin matches no muestra sugerencia', () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Coca-Cola 1.5L',
          sku: 'CC-1500',
          barcode: null,
          tracking_type: 'quantity',
        },
        quantity: 5,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [
        { id: 1, name: 'Pepsi 1.5L', sku: 'PP', tracking_type: 'quantity' },
        { id: 2, name: 'Fanta 1.5L', sku: 'FT', tracking_type: 'quantity' },
      ],
    });
    render(<AcceptInventoryTransferRequestDialog request={request} open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    expect(screen.queryByTestId('accept-hint-100')).not.toBeInTheDocument();
  });

  it('filtra productos con tracking_type incompatible', () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Celular Pro',
          sku: 'CP-001',
          barcode: null,
          tracking_type: 'serialized',
        },
        quantity: 1,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [
        // Mismo SKU pero tracking_type incompatible (quantity vs serialized).
        // Se filtra por tracking_type y no debe aparecer.
        { id: 99, name: 'Celular Pro', sku: 'CP-001', tracking_type: 'quantity' },
        // Sin match (y compatible: tambien serialized).
        { id: 1, name: 'Otro Celular', sku: 'X', tracking_type: 'serialized' },
      ],
    });
    render(<AcceptInventoryTransferRequestDialog request={request} open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    const select = screen.getByTestId('accept-product-100') as HTMLSelectElement;
    const texts = Array.from(select.options).map((o) => o.text);
    // Placeholder + 1 opcion compatible (Otro Celular, sin match).
    expect(texts).toHaveLength(2);
    expect(texts[1]).toContain('Otro Celular');
    expect(texts[1]).not.toContain('[SKU]');
    expect(texts[1]).not.toContain('[Barcode]');
    expect(texts[1]).not.toContain('[Similar]');
  });

  it('permite seleccionar una opcion del dropdown y submitea con destination_product_id correcto', async () => {
    const request = makeRequest([
      {
        id: 100,
        origin_product_id: 50,
        origin_product: {
          id: 50,
          name: 'Coca-Cola 1.5L',
          sku: 'CC-1500',
          barcode: null,
          tracking_type: 'quantity',
        },
        quantity: 5,
      },
    ]);
    mockUseProductsForTransfer.mockReturnValue({
      data: [
        { id: 77, name: 'Coca-Cola 1.5L', sku: 'CC-1500', tracking_type: 'quantity' },
      ],
    });
    const user = userEvent.setup();
    render(<AcceptInventoryTransferRequestDialog request={request} open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    await user.selectOptions(screen.getByLabelText(/almacen destino/i), '10');
    await user.selectOptions(screen.getByTestId('accept-product-100'), '77');
    await user.click(screen.getByTestId('submit-accept'));

    // Esperamos que mutateAsync se haya llamado. La inspeccion del payload
    // se hace DENTRO del waitFor para que se evalue repetidamente hasta
    // que mock.calls este materializado (problema de timing con vitest).
    await waitFor(() => {
      const call = mockAcceptMutateAsync.mock.calls[0]?.[0] as
        | { id: number; values: { destination_warehouse_id: number; items: Array<{ request_item_id: number; destination_product_id: number }> } }
        | undefined;
      expect(call).toBeDefined();
      expect(call?.id).toBe(request.id);
      expect(call?.values.destination_warehouse_id).toBe(10);
      expect(call?.values.items[0]).toEqual({
        request_item_id: 100,
        destination_product_id: 77,
      });
    });
  });
});
