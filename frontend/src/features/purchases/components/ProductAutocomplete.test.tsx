import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ProductAutocomplete } from './ProductAutocomplete';

const { mockUseProductsForPurchase } = vi.hoisted(() => ({
  mockUseProductsForPurchase: vi.fn(),
}));

vi.mock('@/features/purchases/api', () => ({
  useProductsForPurchase: mockUseProductsForPurchase,
}));

describe('ProductAutocomplete', () => {
  beforeEach(() => {
    mockUseProductsForPurchase.mockReturnValue({
      data: [
        {
          id: 20,
          name: 'IPHONE 20',
          sku: 'IPHONE-20',
          barcode: null,
          tracking_type: 'serialized',
        },
      ],
    });
  });

  it('muestra y permite seleccionar un producto serializado', async () => {
    const onChange = vi.fn();
    render(<ProductAutocomplete value={null} onChange={onChange} />);

    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    const option = await screen.findByRole('option', { name: /IPHONE 20/i });
    expect(option).toHaveTextContent('Serializado');

    fireEvent.click(option);
    expect(onChange).toHaveBeenCalledWith(
      20,
      expect.objectContaining({ name: 'IPHONE 20', tracking_type: 'serialized' }),
    );
  });

  it('conserva visualmente el producto seleccionado en el formulario', async () => {
    function ControlledAutocomplete() {
      const [value, setValue] = useState<number | null>(null);
      const [product, setProduct] = useState<{
        id: number;
        name: string;
        sku: string | null;
        barcode: string | null;
        tracking_type?: string;
      } | null>(null);

      return (
        <ProductAutocomplete
          value={value}
          selectedProduct={product}
          onChange={(nextValue, nextProduct) => {
            setValue(nextValue);
            setProduct(nextProduct ?? null);
          }}
        />
      );
    }

    render(<ControlledAutocomplete />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));
    fireEvent.click(await screen.findByRole('option', { name: /IPHONE 20/i }));

    expect(screen.getByText('IPHONE 20')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Quitar IPHONE 20' })).toBeInTheDocument();
    expect(
      screen.queryByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'),
    ).not.toBeInTheDocument();
  });

  it('mantiene los resultados dentro de un area desplazable', () => {
    mockUseProductsForPurchase.mockReturnValue({
      data: Array.from({ length: 40 }, (_, index) => ({
        id: index + 1,
        name: `PRODUCTO ${index + 1}`,
        sku: `SKU-${index + 1}`,
        barcode: null,
        tracking_type: 'quantity',
      })),
    });

    render(<ProductAutocomplete value={null} onChange={vi.fn()} />);
    fireEvent.focus(screen.getByPlaceholderText('Buscar por SKU, codigo de barras o nombre...'));

    const results = screen.getByTestId('purchase-product-results');
    expect(results.querySelector('.overflow-y-auto')).toBeInTheDocument();
  });
});
