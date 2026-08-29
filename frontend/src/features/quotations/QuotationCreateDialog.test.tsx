/**
 * Tests del QuotationCreateDialog: en modo carrito (POS /pos/armar) crea
 * la cotizacion enviando los items y el almacen.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const createMutation = vi.fn();

vi.mock('./api', () => ({
  useCreateQuotation: () => ({ mutateAsync: createMutation }),
}));
vi.mock('@/features/customers/api', () => ({
  useCustomers: () => ({ data: [] }),
}));
vi.mock('@/features/inventory-center/api', () => ({
  useWarehouses: () => ({ data: [{ id: 5, code: 'WH-5', name: 'Almacen 5' }] }),
}));
vi.mock('@/features/transfers/api', () => ({
  useProductsForTransfer: () => ({ data: [] }),
}));
vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: () => ({ data: [] }),
}));
vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { QuotationCreateDialog } from './QuotationCreateDialog';

describe('QuotationCreateDialog', () => {
  beforeEach(() => {
    createMutation.mockReset();
    createMutation.mockResolvedValue({ id: 10, document_number: 'COT-000001' });
  });

  it('crea una cotizacion desde los items del carrito', async () => {
    const onCreated = vi.fn();
    const onOpenChange = vi.fn();
    render(
      <QuotationCreateDialog
        open
        onOpenChange={onOpenChange}
        onCreated={onCreated}
        defaultWarehouseId={5}
        initialItems={[
          { product_id: 100, product_variant_id: null, quantity: 2, price_list_id: 3 },
          { product_id: 200, product_variant_id: 7, product_variant_name: 'Azul', quantity: 1, price_list_id: 3 },
        ]}
      />,
    );

    await waitFor(() => {
      expect(screen.getByText(/Producto #100/)).toBeInTheDocument();
    });
    expect(screen.getByText('Azul')).toBeInTheDocument();
    expect(screen.getByText(/x 2/)).toBeInTheDocument();

    await userEvent.type(
      screen.getByPlaceholderText('Nombre del cliente (o dejar vacio)'),
      'Cliente XYZ',
    );
    fireEvent.click(screen.getByTestId('quote-create-submit'));

    await waitFor(() => {
      expect(createMutation).toHaveBeenCalledTimes(1);
    });
    const payload = createMutation.mock.calls[0]?.[0] as {
      customer_name: string;
      warehouse_id: number;
      items: Array<{ product_id: number; product_variant_id?: number; quantity: number; price_list_id?: number }>;
    };
    expect(payload.customer_name).toBe('Cliente XYZ');
    expect(payload.warehouse_id).toBe(5);
    expect(payload.items).toHaveLength(2);
    expect(payload.items[0]).toEqual({ product_id: 100, quantity: 2, price_list_id: 3 });
    expect(payload.items[1]).toEqual({ product_id: 200, product_variant_id: 7, quantity: 1, price_list_id: 3 });
    expect(onCreated).toHaveBeenCalledWith(10);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
