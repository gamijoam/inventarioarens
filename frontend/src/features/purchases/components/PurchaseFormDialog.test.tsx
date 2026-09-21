import { fireEvent, render, screen } from '@testing-library/react';
import type * as ReactQuery from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@tanstack/react-query', async () => {
  const actual = await vi.importActual<typeof ReactQuery>('@tanstack/react-query');
  return { ...actual, useQueryClient: () => ({ invalidateQueries: vi.fn() }) };
});

vi.mock('@/features/purchases/api', () => ({
  useCreatePurchase: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdatePurchase: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useProductsForPurchase: () => ({ data: [], isFetching: false }),
}));

vi.mock('@/features/inventory-center/api', () => ({
  useExchangeRateTypes: () => ({ data: [] }),
  useWarehouses: () => ({ data: [{ id: 1, code: 'P', name: 'Principal' }] }),
  useProduct: () => ({ data: undefined }),
  useUpdateProduct: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: () => ({ data: [], isLoading: false }),
}));

vi.mock('@/features/inventory-center/dialogs/CreateProductDialog', () => ({
  CreateProductDialog: () => null,
}));

vi.mock('@/features/inventory-center/dialogs/EditProductDialog', () => ({
  EditProductDialog: () => null,
}));

vi.mock('./SupplierAutocomplete', () => ({
  SupplierAutocomplete: () => <div data-testid="supplier-autocomplete" />,
}));

import { PurchaseFormDialog } from './PurchaseFormDialog';

describe('PurchaseFormDialog descartar', () => {
  it('pide confirmacion antes de descartar una compra con datos y permite seguir editando', () => {
    const onOpenChange = vi.fn();
    render(<PurchaseFormDialog open onOpenChange={onOpenChange} />);

    // Agregar una segunda linea genera datos sin guardar.
    fireEvent.click(screen.getByRole('button', { name: /Línea en blanco/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(onOpenChange).not.toHaveBeenCalled();
    expect(screen.getByText('¿Descartar la compra?')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('purchase-discard-cancel'));
    expect(onOpenChange).not.toHaveBeenCalled();
    expect(screen.queryByText('¿Descartar la compra?')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
    fireEvent.click(screen.getByTestId('purchase-discard-confirm'));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
