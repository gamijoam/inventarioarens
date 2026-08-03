import { describe, expect, it } from 'vitest';

import { getExchangeRateStatusLabel } from './ExchangeRatesManager';

describe('ExchangeRatesManager', () => {
  it('distingue tasas propias de tasas heredadas', () => {
    expect(getExchangeRateStatusLabel(true, false)).toBe('Activa');
    expect(getExchangeRateStatusLabel(false, false)).toBe('Inactiva');
    expect(getExchangeRateStatusLabel(true, true)).toBe('Activa heredada');
    expect(getExchangeRateStatusLabel(false, true)).toBe('Histórica heredada');
  });
});
