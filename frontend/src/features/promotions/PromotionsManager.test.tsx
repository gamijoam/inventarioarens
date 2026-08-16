import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockCreateInvoice = { mutateAsync: vi.fn(), isPending: false };
const mockUpdateInvoice = { mutateAsync: vi.fn(), isPending: false };
const mockDeleteInvoice = { mutateAsync: vi.fn(), isPending: false };
const mockCreateCombo = { mutateAsync: vi.fn(), isPending: false };
const mockUpdateCombo = { mutateAsync: vi.fn(), isPending: false };
const mockDeleteCombo = { mutateAsync: vi.fn(), isPending: false };
const mockCreateProductOffer = { mutateAsync: vi.fn(), isPending: false };
const mockUpdateProductOffer = { mutateAsync: vi.fn(), isPending: false };
const mockDeleteProductOffer = { mutateAsync: vi.fn(), isPending: false };

vi.mock('./api', () => ({
  useInvoicePromotions: () => ({
    data: [
      {
        id: 14,
        name: 'Descuento de factura',
        code: 'INVOICE-10',
        scope: 'invoice',
        allows_combos: true,
        benefit_type: 'percent_discount',
        price_currency: 'USD',
        payment_currency: 'ANY',
        price_usd: 0,
        discount_percent: 10,
        discount_amount_usd: null,
        priority: 20,
        is_active: true,
        items: [],
      },
    ],
    isLoading: false,
  }),
  useCombos: () => ({
    data: [
      {
        id: 15,
        name: 'Telefono + cargador',
        code: 'COMBO-50',
        scope: 'combo',
        allows_combos: false,
        benefit_type: 'fixed_bundle_price',
        price_currency: 'USD',
        payment_currency: 'ANY',
        price_usd: 50,
        discount_percent: null,
        discount_amount_usd: null,
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
  useProductOffers: () => ({
    data: [
      {
        id: 16,
        name: 'Telefono especial',
        code: 'PHONE-30',
        scope: 'product_offer',
        allows_combos: false,
        benefit_type: 'fixed_item_price',
        price_currency: 'USD',
        payment_currency: 'ANY',
        price_usd: 30,
        discount_percent: null,
        discount_amount_usd: null,
        priority: 5,
        is_active: true,
        items: [{ product_id: 10, product_name: 'Telefono', quantity: 1 }],
      },
    ],
    isLoading: false,
  }),
  useCreateInvoicePromotion: () => mockCreateInvoice,
  useUpdateInvoicePromotion: () => mockUpdateInvoice,
  useDeleteInvoicePromotion: () => mockDeleteInvoice,
  useCreateCombo: () => mockCreateCombo,
  useUpdateCombo: () => mockUpdateCombo,
  useDeleteCombo: () => mockDeleteCombo,
  useCreateProductOffer: () => mockCreateProductOffer,
  useUpdateProductOffer: () => mockUpdateProductOffer,
  useDeleteProductOffer: () => mockDeleteProductOffer,
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
    [
      mockCreateInvoice,
      mockUpdateInvoice,
      mockDeleteInvoice,
      mockCreateCombo,
      mockUpdateCombo,
      mockDeleteCombo,
      mockCreateProductOffer,
      mockUpdateProductOffer,
      mockDeleteProductOffer,
    ].forEach((mutation) => mutation.mutateAsync.mockReset());
  });

  it('muestra los tres dominios con acciones de creación independientes', () => {
    render(<PromotionsManager />);

    expect(screen.getByRole('heading', { name: 'Descuentos de factura' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Combos' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Ofertas de productos' })).toBeInTheDocument();
    expect(screen.getByText('Telefono + cargador')).toBeInTheDocument();
    expect(screen.getByText('Telefono especial')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nuevo descuento de factura' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nuevo combo' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nueva oferta de producto' })).toBeInTheDocument();
  });

  it('limita el formulario de factura a descuento porcentual o fijo e incluye allows_combos', async () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nuevo descuento de factura' }));
    const type = screen.getByLabelText('Tipo de promoción');

    expect(within(type).getByRole('option', { name: 'Descuento porcentual' })).toBeInTheDocument();
    expect(within(type).getByRole('option', { name: 'Descuento fijo USD' })).toBeInTheDocument();
    expect(within(type).queryByRole('option', { name: 'Combo con precio fijo' })).toBeNull();
    expect(screen.queryByLabelText('Buscar producto')).toBeNull();

    fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Factura flexible' } });
    fireEvent.change(screen.getByLabelText('Descuento porcentual'), { target: { value: '25' } });
    fireEvent.click(screen.getByLabelText('Permitir combinar con combos'));
    fireEvent.click(screen.getByRole('button', { name: 'Crear descuento de factura' }));

    await waitFor(() =>
      expect(mockCreateInvoice.mutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          benefit_type: 'percent_discount',
          discount_percent: 25,
          allows_combos: true,
          items: [],
        }),
      ),
    );
    expect(mockCreateCombo.mutateAsync).not.toHaveBeenCalled();
    expect(mockCreateProductOffer.mutateAsync).not.toHaveBeenCalled();
  });

  it('limita el formulario de combo a precio fijo y compra X recibe Y', () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nuevo combo' }));
    const type = screen.getByLabelText('Tipo de promoción');

    expect(within(type).getByRole('option', { name: 'Combo con precio fijo' })).toBeInTheDocument();
    expect(
      within(type).getByRole('option', { name: '2x1 / Compra X y recibe Y' }),
    ).toBeInTheDocument();
    expect(within(type).queryByRole('option', { name: 'Precio fijo por artículo' })).toBeNull();
    expect(screen.getByText('Componentes del combo')).toBeInTheDocument();
  });

  it('limita el formulario de oferta a precio fijo o artículo gratis y usa su mutación', async () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Nueva oferta de producto' }));
    const type = screen.getByLabelText('Tipo de promoción');

    expect(
      within(type).getByRole('option', { name: 'Precio fijo por artículo' }),
    ).toBeInTheDocument();
    expect(within(type).getByRole('option', { name: 'Artículo gratis' })).toBeInTheDocument();
    expect(within(type).queryByRole('option', { name: 'Descuento porcentual' })).toBeNull();

    fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Oferta telefono' } });
    fireEvent.change(screen.getByLabelText('Precio por artículo USD'), { target: { value: '30' } });
    fireEvent.click(screen.getByRole('button', { name: /Producto 2x1/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Crear oferta de producto' }));

    await waitFor(() =>
      expect(mockCreateProductOffer.mutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          benefit_type: 'fixed_item_price',
          price_usd: 30,
          items: [{ product_id: 10, quantity: 1, item_role: 'eligible' }],
        }),
      ),
    );
    expect(mockCreateInvoice.mutateAsync).not.toHaveBeenCalled();
    expect(mockCreateCombo.mutateAsync).not.toHaveBeenCalled();
  });

  it('actualiza un combo por la mutación del dominio mostrado', async () => {
    render(<PromotionsManager />);

    fireEvent.click(screen.getByRole('button', { name: 'Editar Telefono + cargador' }));
    fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Combo actualizado' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar cambios' }));

    await waitFor(() =>
      expect(mockUpdateCombo.mutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({ id: 15, name: 'Combo actualizado' }),
      ),
    );
    expect(mockUpdateInvoice.mutateAsync).not.toHaveBeenCalled();
    expect(mockUpdateProductOffer.mutateAsync).not.toHaveBeenCalled();
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
