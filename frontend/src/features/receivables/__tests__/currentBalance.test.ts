import { describe, expect, it } from 'vitest';

import { activeUsdVesRate, currentLocalBalance, rateLabel } from '../currentBalance';

describe('current receivable balance', () => {
  it('recalcula el saldo VES con la tasa activa actual', () => {
    const rate = activeUsdVesRate([
      {
        id: 1,
        exchange_rate_type_id: 1,
        exchange_rate_type_code: 'BCV',
        base_currency: 'USD',
        quote_currency: 'VES',
        rate: 1000,
        is_active: true,
      },
    ]);

    expect(currentLocalBalance({ balance_base_amount: 1 }, rate)).toBe(1000);
    expect(rateLabel(rate)).toBe('BCV @ 1.000,00');
  });

  it('no usa el saldo historico VES cuando cambia la tasa', () => {
    const rate = activeUsdVesRate([
      {
        id: 1,
        exchange_rate_type_id: 1,
        exchange_rate_type_code: 'BCV',
        base_currency: 'USD',
        quote_currency: 'VES',
        rate: 1000,
        is_active: true,
      },
    ]);
    const historical = { balance_base_amount: 1, balance_local_amount: 900 };

    expect(currentLocalBalance(historical, rate)).toBe(1000);
  });

  it('no inventa equivalente VES si no hay tasa activa USD/VES', () => {
    const rate = activeUsdVesRate([]);

    expect(currentLocalBalance({ balance_base_amount: 1 }, rate)).toBeNull();
    expect(rateLabel(rate)).toBe('Sin tasa activa USD/VES');
  });
});
