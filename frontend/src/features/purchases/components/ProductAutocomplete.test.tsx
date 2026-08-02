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
});
