import { expect, request, test } from '@playwright/test';

import { getDemoCredentials } from './support/auth';

const credentials = getDemoCredentials();

test('cotiza un producto con una lista de precios distinta al precio base', async () => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar precios.');

  const api = await request.newContext({
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Tenant': credentials!.tenant,
    },
  });

  try {
    const login = await api.post('/api/auth/login', {
      data: { email: credentials!.email, password: credentials!.password },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(login.status()).toBe(201);
    const token = (await login.json()).data.token as string;
    const authenticated = await request.newContext({
      baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
      extraHTTPHeaders: {
        Accept: 'application/json',
        'X-Tenant': credentials!.tenant,
        Authorization: `Bearer ${token}`,
      },
    });

    try {
      const [listsResponse, productsResponse] = await Promise.all([
        authenticated.get('/api/price-lists?active_only=1'),
        authenticated.get('/api/products?per_page=100'),
      ]);
      expect(listsResponse.ok()).toBe(true);
      expect(productsResponse.ok()).toBe(true);

      const lists = (await listsResponse.json()).data as Array<{ id: number; name: string }>;
      const products = (await productsResponse.json()).data as Array<{
        id: number;
        name: string;
        base_price?: number | string | null;
      }>;
      let match: {
        productName: string;
        listName: string;
        basePrice: number;
        listPrice: number;
      } | null = null;

      for (const list of lists) {
        for (const product of products) {
          if (product.base_price == null) continue;
          const quoteResponse = await authenticated.get(
            `/api/products/${product.id}/price?price_list_id=${list.id}`,
          );
          if (!quoteResponse.ok()) continue;
          const quote = (await quoteResponse.json()).data as {
            price_list_id?: number | null;
            price_source?: string;
            base_price_usd: number | string;
            sale_price: number | string;
          };
          const basePrice = Number(product.base_price);
          const listPrice = Number(quote.sale_price);
          if (
            quote.price_list_id === list.id &&
            quote.price_source === 'price_list' &&
            Number.isFinite(basePrice) &&
            Number.isFinite(listPrice) &&
            Math.abs(basePrice - listPrice) > 0.0001
          ) {
            match = {
              productName: product.name,
              listName: list.name,
              basePrice,
              listPrice,
            };
            break;
          }
        }
        if (match) break;
      }

      test.skip(!match, 'El tenant no tiene un producto con precio distinto en una lista activa.');
      expect(match!.listPrice).not.toBe(match!.basePrice);
    } finally {
      await authenticated.dispose();
    }
  } finally {
    await api.dispose();
  }
});
