import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import { getExchangeRateStatusLabel, formatRateSource, ExchangeRatesManager } from './ExchangeRatesManager';

vi.mock('@/stores/session', () => ({
  useSessionStore: vi.fn((selector) => selector({ tenant: { id: 1, parent_id: null } })),
}));

vi.mock('@/components/permissions/Can', () => ({
  Can: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/features/inventory-center/api', () => ({
  useExchangeRateTypes: () => ({
    data: [
      { id: 1, code: 'BCV', name: 'Banco Central de Venezuela', is_default: true, is_active: true },
    ],
  }),
  useExchangeRates: () => ({
    data: [
      {
        id: 54,
        exchange_rate_type_id: 1,
        exchange_rate_type_code: 'BCV',
        exchange_rate_type_name: 'Banco Central de Venezuela',
        base_currency: 'USD',
        quote_currency: 'VES',
        rate: 881.876,
        effective_at: '2026-10-20T00:00:00.000000Z',
        is_active: true,
        source: 'manual',
      },
      {
        id: 53,
        exchange_rate_type_id: 1,
        exchange_rate_type_code: 'BCV',
        exchange_rate_type_name: 'Banco Central de Venezuela',
        base_currency: 'USD',
        quote_currency: 'VES',
        rate: 800.0,
        effective_at: '2026-07-28T00:00:00.000000Z',
        is_active: false,
        source: 'bcv_official',
      },
    ],
    isLoading: false,
    isError: false,
  }),
  useCreateExchangeRate: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useActivateExchangeRate: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeactivateExchangeRate: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

describe('ExchangeRatesManager', () => {
  it('distingue tasas propias de tasas heredadas', () => {
    expect(getExchangeRateStatusLabel(true, false)).toBe('Activa');
    expect(getExchangeRateStatusLabel(false, false)).toBe('Inactiva');
    expect(getExchangeRateStatusLabel(true, true)).toBe('Activa heredada');
    expect(getExchangeRateStatusLabel(false, true)).toBe('Histórica heredada');
  });

  it('formatea las fuentes de tasa correctamente', () => {
    expect(formatRateSource('manual')).toBe('Registro manual');
    expect(formatRateSource('bcv_official')).toBe('Publicación oficial BCV');
    expect(formatRateSource('market_reference')).toBe('Referencia de mercado');
    expect(formatRateSource('import')).toBe('Importación');
    expect(formatRateSource(null)).toBe('Sin indicar');
  });

  it('renderiza la fecha activa formateada correctamente sin desfase ni texto crudo ISO', () => {
    render(<ExchangeRatesManager />);

    // Verifica que la tarjeta de tasa activa muestre "Fecha activa: 20/10/2026"
    expect(screen.getByTestId('active-rate-card-54')).toHaveTextContent('Fecha activa: 20/10/2026');

    // Verifica que la tabla muestre la fecha formateada "20/10/2026" y "28/07/2026"
    const dates = screen.getAllByText('20/10/2026');
    expect(dates.length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('28/07/2026')).toBeDefined();

    // No debe aparecer la cadena ISO cruda
    expect(screen.queryByText('2026-10-20T00:00:00.000000Z')).toBeNull();
  });
});
