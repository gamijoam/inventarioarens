import { expect, request, test } from '@playwright/test';

import { getDemoCredentials } from './support/auth';

const credentials = getDemoCredentials();

test('checkout mixto USD/VES conserva doble cuenta y snapshot de tasa', async () => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar pagos.');

  const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';
  const api = await request.newContext({
    baseURL,
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

    const authenticated = await request.newContext({
      baseURL,
      extraHTTPHeaders: {
        Accept: 'application/json',
        'X-Tenant': credentials!.tenant,
        Authorization: `Bearer ${(await login.json()).data.token}`,
      },
    });

    try {
      const bootstrapResponse = await authenticated.get('/api/pos/bootstrap');
      expect(bootstrapResponse.ok()).toBe(true);
      const bootstrap = await bootstrapResponse.json();
      const session = bootstrap.open_session as { id: number } | null;
      const warehouse = bootstrap.warehouses[0] as { id: number } | undefined;
      const usdMethod = bootstrap.payment_methods.find(
        (method: { currency_mode: string; method: string }) =>
          method.currency_mode === 'USD' && method.method === 'cash',
      ) as { id: number; method: string } | undefined;
      const vesMethod = bootstrap.payment_methods.find(
        (method: { currency_mode: string; method: string; requires_reference: boolean }) =>
          method.currency_mode === 'VES' && method.method === 'mobile_payment',
      ) as { id: number; method: string; requires_reference: boolean } | undefined;
      const exchangeRate = bootstrap.exchange_rates.find(
        (rate: { exchange_rate_type_id: number; rate: number }) => Number(rate.rate) > 0,
      ) as { exchange_rate_type_id: number; rate: number } | undefined;

      expect(session).toBeTruthy();
      expect(warehouse).toBeTruthy();
      expect(usdMethod).toBeTruthy();
      expect(vesMethod).toBeTruthy();
      expect(exchangeRate).toBeTruthy();

      const productsResponse = await authenticated.get(
        `/api/products?warehouse_id=${warehouse!.id}&limit=100`,
      );
      expect(productsResponse.ok()).toBe(true);
      const products = (await productsResponse.json()).data as Array<{
        id: number;
        base_price: number | string | null;
        available_stock: number | string;
      }>;
      const product = products.find(
        (candidate) => Number(candidate.base_price) > 0 && Number(candidate.available_stock) > 0,
      );
      expect(product).toBeTruthy();

      const totalBase = Number(product!.base_price);
      const usdBase = Number((totalBase / 2).toFixed(2));
      const vesBase = Number((totalBase - usdBase).toFixed(2));
      const vesAmount = Number((vesBase * Number(exchangeRate!.rate)).toFixed(2));
      const checkout = await authenticated.post('/api/pos/checkouts', {
        data: {
          cash_register_session_id: session!.id,
          items: [{ warehouse_id: warehouse!.id, product_id: product!.id, quantity: 1 }],
          payments: [
            {
              payment_method_id: usdMethod!.id,
              method: usdMethod!.method,
              currency: 'USD',
              amount: usdBase,
            },
            {
              payment_method_id: vesMethod!.id,
              method: vesMethod!.method,
              currency: 'VES',
              amount: vesAmount,
              exchange_rate_type_id: exchangeRate!.exchange_rate_type_id,
              reference: `E2E-MIX-${Date.now()}`,
            },
          ],
        },
      });

      expect(checkout.status()).toBe(201);
      const order = (await checkout.json()).data as {
        status: string;
        total_base_amount: string;
        paid_base_amount: string;
        payments: Array<{
          currency: string;
          amount_base: string;
          amount_local: string;
          exchange_rate_type_id: number | null;
          exchange_rate: string | null;
        }>;
      };
      expect(order.status).toBe('paid');
      expect(Number(order.total_base_amount)).toBeCloseTo(totalBase, 2);
      expect(Number(order.paid_base_amount)).toBeCloseTo(totalBase, 2);
      expect(order.payments).toHaveLength(2);
      expect(order.payments[0].currency).toBe('USD');
      expect(Number(order.payments[0].amount_base)).toBeCloseTo(usdBase, 2);
      expect(order.payments[1].currency).toBe('VES');
      expect(Number(order.payments[1].amount_base)).toBeCloseTo(vesBase, 2);
      expect(Number(order.payments[1].amount_local)).toBeCloseTo(vesAmount, 2);
      expect(order.payments[1].exchange_rate_type_id).toBe(exchangeRate!.exchange_rate_type_id);
      expect(Number(order.payments[1].exchange_rate)).toBeCloseTo(Number(exchangeRate!.rate), 6);
    } finally {
      await authenticated.dispose();
    }
  } finally {
    await api.dispose();
  }
});
