import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockCreate = { mutateAsync: vi.fn(), isPending: false };
const mockUpdate = { mutateAsync: vi.fn(), isPending: false };
const mockDelete = { mutateAsync: vi.fn(), isPending: false };

vi.mock('./api', () => ({
  usePromotions: () => ({
    data: [
      {
        id: 15,
        name: 'Telefono + cargador',
        code: 'COMBO-50',
        benefit_type: 'fixed_bundle_price',
        price_currency: 'USD',
        price_usd: 50,
        priority: 10,
        is_active: true,
        items: [
          { product_id: 10, product_name: 'Telefono', quantity: 1 },
          { product_id: 11, product_name: 'Cargador', quantity: 1 },
        ],
      },
    ],
    isLoading: false,
  }),
  useCreatePromotion: () => mockCreate,
  useUpdatePromotion: () => mockUpdate,
  useDeletePromotion: () => mockDelete,
}));

vi.mock('@/features/inventory-center/api', () => ({
  useProducts: () => ({ data: { data: [] }, isLoading: false }),
}));

import { PromotionsManager } from './PromotionsManager';

describe('PromotionsManager', () => {
  beforeEach(() => {
    mockCreate.mutateAsync.mockReset();
    mockUpdate.mutateAsync.mockReset();
    mockDelete.mutateAsync.mockReset();
  });

  it('muestra promociones existentes y abre el formulario de combo', () => {
    render(<PromotionsManager />);

    expect(screen.getByText('Telefono + cargador')).toBeInTheDocument();
    expect(screen.getByText('COMBO-50')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));

    expect(screen.getByRole('heading', { name: 'Nueva promoción' })).toBeInTheDocument();
    expect(screen.getByLabelText('Precio del combo USD')).toBeInTheDocument();
  });

  it('permite cambiar el formulario a descuento porcentual', () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), { target: { value: 'percent_discount' } });

    expect(screen.getByLabelText('Descuento porcentual')).toBeInTheDocument();
    expect(screen.getByText('Productos elegibles')).toBeInTheDocument();
  });

  it('permite cambiar el formulario a precio fijo por articulo', () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), { target: { value: 'fixed_item_price' } });

    expect(screen.getByLabelText('Precio por artículo USD')).toBeInTheDocument();
    expect(screen.getByText('Productos elegibles')).toBeInTheDocument();
  });

  it('permite cambiar el formulario a articulo gratis', () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), { target: { value: 'free_item' } });

    expect(screen.getByText('$0.00 por unidad')).toBeInTheDocument();
    expect(screen.getByText('Productos elegibles')).toBeInTheDocument();
  });
});
