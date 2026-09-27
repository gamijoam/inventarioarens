import { describe, it, expect, vi, beforeEach } from 'vitest';
import { searchCatalogInMemory } from '../catalogSearch';

describe('searchCatalogInMemory', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('queries local catalog-search engine and returns results', async () => {
    const mockResults = [
      { id: 1, barcode: '7591234567890', sku: 'HAR-01', name: 'Harina Pan 1kg', price_usd: 1.20, stock: 45 },
    ];

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ok: true, count: 1, results: mockResults }),
    } as any);

    const res = await searchCatalogInMemory('1', 'harina', 10);
    expect(res).toEqual(mockResults);
    expect(global.fetch).toHaveBeenCalledWith(
      expect.stringContaining('18888/search?tenant_id=1&q=harina&limit=10'),
      expect.any(Object)
    );
  });

  it('returns empty array gracefully on network error or timeout', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('Network error'));

    const res = await searchCatalogInMemory('1', 'arroz');
    expect(res).toEqual([]);
  });
});
