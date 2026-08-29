import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetMany = vi.fn();

vi.mock('@/api/client', () => ({
  getMany: (path: string): Promise<unknown> => mockGetMany(path) as Promise<unknown>,
}));

import { FiscalTaxRateSchema, getFiscalTaxRates } from './taxRates';

describe('fiscal tax rates api', () => {
  beforeEach(() => mockGetMany.mockReset());

  it('parsea la respuesta real con timestamps nullable', async () => {
    const response = [
      {
        id: 1,
        tenant_id: 2,
        code: 'IVA16',
        name: 'IVA general',
        rate: 16,
        category: 'taxable',
        is_active: true,
        created_at: null,
        updated_at: null,
      },
    ];
    mockGetMany.mockResolvedValue(response);

    await expect(getFiscalTaxRates()).resolves.toEqual(response);
    expect(FiscalTaxRateSchema.parse(response[0]).updated_at).toBeNull();
    expect(mockGetMany).toHaveBeenCalledWith('/fiscal/tax-rates');
  });

  it('rechaza categorias fiscales desconocidas', async () => {
    mockGetMany.mockResolvedValue([
      {
        id: 1,
        tenant_id: 2,
        code: 'IVA16',
        name: 'IVA general',
        rate: 16,
        category: 'unknown',
        is_active: true,
      },
    ]);

    await expect(getFiscalTaxRates()).rejects.toThrow('alícuotas fiscales inválida');
  });
});
