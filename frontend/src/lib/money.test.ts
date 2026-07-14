import { describe, it, expect } from 'vitest';
import { formatMoney, formatMoneyWithRate, formatCost } from './money';

describe('formatMoney', () => {
  it('formatea numero como USD', () => {
    expect(formatMoney(1234.56)).toBe('$1.234,56');
    expect(formatMoney('1234.5')).toBe('$1.234,50');
  });

  it('formatea monto con currency explicita', () => {
    expect(formatMoney({ amount: '1234.56', currency: 'VES' })).toBe('Bs 1.234,56');
  });

  it('oculta currency cuando se pide', () => {
    expect(formatMoney(1234.56, { showCurrency: false })).toBe('1.234,56');
  });

  it('devuelve guion para null/undefined (campo enmascarado o no aplica)', () => {
    expect(formatMoney(null)).toBe('—');
    expect(formatMoney(undefined)).toBe('—');
  });

  it('devuelve guion para valores invalidos', () => {
    expect(formatMoney('not-a-number')).toBe('—');
    expect(formatMoney({ amount: 'abc', currency: 'USD' })).toBe('—');
  });
});

describe('formatMoneyWithRate', () => {
  it('muestra monto + base + rate cuando hay snapshot', () => {
    const result = formatMoneyWithRate({
      amount: '120000.00',
      currency: 'VES',
      base_amount: '3.20',
      exchange_rate: '37500',
    });
    expect(result).toContain('Bs');
    expect(result).toContain('3,20');
    expect(result).toContain('USD');
    expect(result).toContain('37.500');
  });

  it('cae a formatMoney si no hay base_amount o rate', () => {
    expect(formatMoneyWithRate({ amount: '100.00', currency: 'USD' })).toBe('$100,00');
  });

  it('maneja null', () => {
    expect(formatMoneyWithRate(null)).toBe('—');
  });
});

describe('formatCost', () => {
  it('alias de formatMoney para field masking de unit_cost', () => {
    expect(formatCost('120.50')).toBe('$120,50');
    expect(formatCost(null)).toBe('—'); // <- caso principal: backend enmascara con null
  });
});