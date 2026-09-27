/**
 * Cliente de consulta ultrarrápida al motor en memoria catalog-search (Go).
 * Latencia típica: < 0.1 ms.
 */

export interface CatalogSearchProduct {
  id: number;
  barcode?: string;
  sku?: string;
  name: string;
  price_usd?: number;
  price_ves?: number;
  stock?: number;
}

export async function searchCatalogInMemory(
  tenantId: number | string,
  query: string,
  limit = 20,
  timeoutMs = 250
): Promise<CatalogSearchProduct[]> {
  if (!query || !query.trim()) {
    return [];
  }

  const host = typeof window !== 'undefined' ? window.location.hostname : '127.0.0.1';
  const url = `http://${host}:18888/search?tenant_id=${encodeURIComponent(tenantId)}&q=${encodeURIComponent(query.trim())}&limit=${limit}`;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const resp = await fetch(url, {
      method: 'GET',
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
      },
    });

    clearTimeout(timeoutId);

    if (!resp.ok) {
      return [];
    }

    const data = await resp.json();
    if (data && Array.isArray(data.results)) {
      return data.results;
    }

    return [];
  } catch {
    clearTimeout(timeoutId);
    return [];
  }
}
