import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ProductPriceHistoryTab } from '../ProductPriceHistoryTab';

vi.mock('../../priceHistoryApi', async (importOriginal) => ({
  ...(await importOriginal<typeof PriceHistoryApiModule>()),
  useProductPriceHistory: () => ({
    isLoading: false,
    data: [
      {
        id: 2,
        action: 'updated',
        kind: 'base_price',
        price_list_id: null,
        price_list_name: 'Precio base',
        price_list_code: null,
        before: { price: 1.2 },
        after: { price: 1.44 },
        created_by_name: 'Ana',
        created_by_email: null,
        created_at: '2026-11-13T10:00:00.000000Z',
      },
    ],
  }),
}));

import type * as PriceHistoryApiModule from '../../priceHistoryApi';

describe('ProductPriceHistoryTab', () => {
  it('muestra cada cambio con precio anterior, nuevo, variacion y usuario', () => {
    render(<ProductPriceHistoryTab productId={1} />);
    expect(screen.getByText('Precio base')).toBeInTheDocument();
    expect(screen.getByText('$1,20')).toBeInTheDocument();
    expect(screen.getByText('$1,44')).toBeInTheDocument();
    expect(screen.getByText('+20,0%')).toBeInTheDocument();
    expect(screen.getByText('Ana')).toBeInTheDocument();
  });
});
