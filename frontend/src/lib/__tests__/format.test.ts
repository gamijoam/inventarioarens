import { describe, expect, it } from 'vitest';
import { formatEffectiveDate } from '../format';

describe('formatEffectiveDate', () => {
  it('formatea correctamente fechas ISO con medianoche UTC sin desfase de dia', () => {
    expect(formatEffectiveDate('2026-10-20T00:00:00.000000Z')).toBe('20/10/2026');
    expect(formatEffectiveDate('2026-10-21T00:00:00Z')).toBe('21/10/2026');
    expect(formatEffectiveDate('2026-01-01T00:00:00.000Z')).toBe('01/01/2026');
  });

  it('formatea fechas YYYY-MM-DD sin hora', () => {
    expect(formatEffectiveDate('2026-10-20')).toBe('20/10/2026');
    expect(formatEffectiveDate('2026-05-09')).toBe('09/05/2026');
  });

  it('formatea fechas con hora cuando no es medianoche', () => {
    expect(formatEffectiveDate('2026-10-20 16:40:48')).toBe('20/10/2026 4:40 PM');
    expect(formatEffectiveDate('2026-07-30T09:15:00Z')).toBe('30/07/2026 9:15 AM');
  });

  it('retorna guion si es nulo o indefinido', () => {
    expect(formatEffectiveDate(null)).toBe('—');
    expect(formatEffectiveDate(undefined)).toBe('—');
    expect(formatEffectiveDate('')).toBe('—');
  });
});
