import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { Promotion } from '@/features/promotions/schemas';

import { PromotionsPanel } from '../PromotionsPanel';

const promotion: Promotion = {
  id: 15,
  name: 'Telefono + cargador',
  code: 'COMBO-50',
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
};

const invoiceDiscount: Promotion = {
  ...promotion,
  id: 16,
  name: 'Descuento de factura',
  code: 'INVOICE-25',
  benefit_type: 'percent_discount',
  discount_percent: 25,
  price_usd: 0,
  items: [],
};

describe('PromotionsPanel', () => {
  it('muestra los componentes y permite aplicar una promocion', () => {
    const onSelect = vi.fn();

    render(<PromotionsPanel promotions={[promotion]} selectedId={null} onSelect={onSelect} />);

    expect(screen.getByText('Telefono + cargador')).toBeInTheDocument();
    expect(screen.getByText('Telefono + Cargador')).toBeInTheDocument();
    expect(screen.getByText('$50.00')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar COMBO-50' }));

    expect(onSelect).toHaveBeenCalledWith(promotion, 1);
  });

  it('muestra estado vacio cuando no hay promociones validas', () => {
    render(<PromotionsPanel promotions={[]} selectedId={null} onSelect={vi.fn()} />);

    expect(screen.getByText('No hay promociones disponibles')).toBeInTheDocument();
  });

  it('permite cargar cinco conjuntos de la misma promocion', () => {
    const onSelect = vi.fn();

    render(<PromotionsPanel promotions={[promotion]} selectedId={null} onSelect={onSelect} />);
    fireEvent.change(screen.getByLabelText('Cantidad de conjuntos'), { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar COMBO-50' }));

    expect(onSelect).toHaveBeenCalledWith(promotion, 5);
  });

  it('identifica visualmente una promocion 2x1', () => {
    render(
      <PromotionsPanel
        promotions={[
          {
            ...promotion,
            benefit_type: 'buy_x_get_y',
            code: 'PHONE-2X1',
          },
        ]}
        selectedId={null}
        onSelect={vi.fn()}
      />,
    );

    expect(screen.getByText('2x1')).toBeInTheDocument();
  });

  it('separa descuentos de factura de los combos y no pide conjuntos para descuentos', () => {
    const onSelectDiscount = vi.fn();

    render(
      <PromotionsPanel
        promotions={[promotion, invoiceDiscount]}
        selectedId={null}
        onSelect={vi.fn()}
        onSelectDiscount={onSelectDiscount}
      />,
    );

    expect(screen.getByText('Descuentos de factura')).toBeInTheDocument();
    expect(screen.getByText('Combos y promociones de productos')).toBeInTheDocument();
    expect(screen.getByText('Se aplica al total de la factura.')).toBeInTheDocument();
    expect(screen.getByLabelText('Cantidad de conjuntos')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Aplicar INVOICE-25' }));

    expect(onSelectDiscount).toHaveBeenCalledWith(invoiceDiscount);
  });

  it('muestra ofertas de producto separadas y no les aplica cantidad de conjuntos', () => {
    const offer = {
      ...promotion,
      id: 17,
      scope: 'product_offer' as const,
      benefit_type: 'free_item' as const,
      name: 'Cargador gratis',
      code: 'FREE-CHARGER',
    };
    const onSelectOffer = vi.fn();

    render(
      <PromotionsPanel
        invoicePromotions={[invoiceDiscount]}
        combos={[promotion]}
        productOffers={[offer]}
        selectedInvoiceId={null}
        selectedComboIds={[]}
        onSelectCombo={vi.fn()}
        onSelectProductOffer={onSelectOffer}
        onSelect={vi.fn()}
        selectedId={null}
      />,
    );

    expect(screen.getByText('Ofertas por producto')).toBeInTheDocument();
    expect(screen.getByText('Cargador gratis')).toBeInTheDocument();
    expect(screen.getAllByLabelText('Cantidad de conjuntos')).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar FREE-CHARGER' }));
    expect(onSelectOffer).toHaveBeenCalledWith(offer);
  });
});
