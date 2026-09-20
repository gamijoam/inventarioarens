import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/features/transfers/api', () => ({
  useProductsForTransfer: () => ({
    data: [
      {
        id: 1,
        name: 'PRODUCTO ACTIVO',
        sku: 'ACT-1',
        barcode: null,
        is_active: true,
        available_stock: 12,
      },
      {
        id: 2,
        name: 'PRODUCTO INACTIVO',
        sku: 'INA-1',
        barcode: null,
        is_active: false,
        available_stock: 5,
      },
    ],
  }),
}));

vi.mock('@/features/inventory-center/variantApi', () => ({
  useProductVariants: () => ({ data: [] }),
}));

vi.mock('./api', () => ({
  useCreateManualMovement: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useRejectManualMovement: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

import { CreateManualMovementDialog } from './ManualMovementDialogs';

describe('CreateManualMovementDialog', () => {
  it('muestra el stock y marca los productos inactivos', async () => {
    render(
      <CreateManualMovementDialog
        open
        onOpenChange={vi.fn()}
        warehouses={[{ id: 1, name: 'Principal' }]}
        onCreated={vi.fn()}
      />,
    );

    const input = screen.getByPlaceholderText('Buscar producto por nombre, SKU o código…');
    fireEvent.focus(input);

    expect(await screen.findByText(/Stock: 12/)).toBeInTheDocument();
    expect(screen.getByText(/Stock: 5/)).toBeInTheDocument();
    expect(screen.getByText('Inactivo')).toBeInTheDocument();
  });
});
