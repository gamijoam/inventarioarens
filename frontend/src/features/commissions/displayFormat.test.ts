import { describe, expect, it } from 'vitest';

import { formatControlNumber, formatControlPayment } from './displayFormat';

describe('commission control display formatting', () => {
  it('rounds quantities and amounts to two decimals', () => {
    expect(formatControlNumber('1.0000')).toBe('1,00');
    expect(formatControlNumber('21990.0000')).toBe('21.990,00');
    expect(formatControlNumber('7.3300')).toBe('7,33');
  });

  it('formats payment values with their currency', () => {
    expect(formatControlPayment('100.0000', 'USD')).toBe('100,00 USD');
    expect(formatControlPayment('21990.0000', 'VES')).toBe('21.990,00 VES');
  });
});
