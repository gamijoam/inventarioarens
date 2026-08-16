import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  useProducts: () => ({
    data: {
      data: [
        { id: 10, name: 'Producto 2x1', sku: 'P-2X1' },
        { id: 11, name: 'Producto regalo', sku: 'P-REGALO' },
      ],
    },
    isLoading: false,
  }),
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
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), {
      target: { value: 'percent_discount' },
    });

    expect(screen.getByLabelText('Descuento porcentual')).toBeInTheDocument();
    expect(screen.getByText('Productos elegibles')).toBeInTheDocument();
  });

  it('permite cambiar el formulario a precio fijo por articulo', () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), {
      target: { value: 'fixed_item_price' },
    });

    expect(screen.getByLabelText('Precio por artículo USD')).toBeInTheDocument();
    expect(screen.getByText('Productos elegibles')).toBeInTheDocument();
  });

  it('permite cambiar el formulario a articulo gratis', () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), {
      target: { value: 'free_item' },
    });

    expect(screen.getByText('$0.00 por unidad')).toBeInTheDocument();
    expect(screen.getByText('Productos elegibles')).toBeInTheDocument();
  });

  it('permite configurar cantidades y repetir el mismo producto en un 2x1', async () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva promoción' }));
    fireEvent.change(screen.getByLabelText('Tipo de promoción'), {
      target: { value: 'buy_x_get_y' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Producto 2x1/ }));
    fireEvent.click(screen.getByRole('button', { name: /Producto 2x1/ }));
    await waitFor(() => expect(screen.getAllByText('Producto 2x1')).toHaveLength(2));

    const quantities = screen.getAllByDisplayValue('1');
    expect(quantities).toHaveLength(2);
    const [triggerQuantity, rewardQuantity] = quantities;
    if (!triggerQuantity || !rewardQuantity) throw new Error('Faltan cantidades del 2x1.');
    fireEvent.change(triggerQuantity, { target: { value: '2' } });
    fireEvent.change(rewardQuantity, { target: { value: '1' } });
    const roles = screen.getAllByLabelText(/Rol Producto 2x1/);
    const [triggerRole, rewardRole] = roles;
    if (!triggerRole || !rewardRole) throw new Error('Faltan roles del 2x1.');
    fireEvent.change(rewardRole, { target: { value: 'reward' } });

    expect(screen.getAllByText('Producto 2x1')).toHaveLength(2);
    expect(triggerRole).toHaveValue('trigger');
    expect(rewardRole).toHaveValue('reward');

    fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: '2x1 Producto' } });
    fireEvent.click(screen.getByRole('button', { name: 'Crear promoción' }));

    await waitFor(() =>
      expect(mockCreate.mutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          benefit_type: 'buy_x_get_y',
          price_currency: 'USD',
          items: [
            { product_id: 10, quantity: 2, item_role: 'trigger' },
            { product_id: 10, quantity: 1, item_role: 'reward' },
          ],
        }),
      ),
    );
  });
});
