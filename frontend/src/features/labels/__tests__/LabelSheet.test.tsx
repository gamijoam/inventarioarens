import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { LabelSheet } from '../LabelSheet';
import type { ProductLabel } from '../schemas';

const malta: ProductLabel = {
  product_id: 1,
  name: 'Malta 355ml',
  sku: 'MALTA',
  barcode: '7590001',
  unit_of_measure: 'unit',
  price_usd: 1.2,
  price_ves: 120,
  sale_currency: 'USD',
  price_list_name: null,
  exchange_rate: 100,
  exchange_rate_type_code: 'BCV',
  packagings: [{ name: 'Caja x24', factor: 24, barcode: '7590024', price_usd: 24, price_ves: 2400 }],
};

describe('LabelSheet', () => {
  it('muestra nombre, precio en USD y Bs y el codigo de barras', () => {
    render(<LabelSheet labels={[malta]} copies={1} format="shelf" showVes showPackagings={false} />);
    expect(screen.getByText('Malta 355ml')).toBeInTheDocument();
    expect(screen.getByTestId('label-price-usd-0')).toHaveTextContent('1,20');
    expect(screen.getByTestId('label-price-ves-0')).toHaveTextContent('120,00');
    expect(screen.getByLabelText('Codigo de barras 7590001')).toBeInTheDocument();
    expect(screen.queryByText(/Caja x24/)).not.toBeInTheDocument();
  });

  it('repite cada etiqueta segun las copias y puede ocultar los Bs', () => {
    render(<LabelSheet labels={[malta]} copies={3} format="a4" showVes={false} showPackagings={false} />);
    expect(screen.getAllByText('Malta 355ml')).toHaveLength(3);
    expect(screen.queryByTestId('label-price-ves-0')).not.toBeInTheDocument();
  });

  it('agrega una etiqueta por cada presentacion con precio propio', () => {
    render(<LabelSheet labels={[malta]} copies={1} format="shelf" showVes showPackagings />);
    expect(screen.getByText('Malta 355ml - Caja x24')).toBeInTheDocument();
    expect(screen.getByLabelText('Codigo de barras 7590024')).toBeInTheDocument();
  });

  it('usa el SKU cuando el producto no tiene codigo de barras', () => {
    render(<LabelSheet labels={[{ ...malta, barcode: null }]} copies={1} format="shelf" showVes showPackagings={false} />);
    expect(screen.getByLabelText('Codigo de barras MALTA')).toBeInTheDocument();
  });
});
