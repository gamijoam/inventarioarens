import { expect, request, test, type APIRequestContext } from '@playwright/test';

import { getDemoCredentials } from './support/auth';

const credentials = getDemoCredentials();

interface FiscalRate {
  id: number;
  code: string;
  category: string;
  rate: number | string;
}

interface Product {
  id: number;
  name: string;
  base_price: number | string | null;
  sale_currency: string;
  tracking_type: string;
  available_stock?: number | string;
}

interface Warehouse {
  id: number;
  branch_id: number;
}

interface Bootstrap {
  warehouses: Warehouse[];
  open_session: { id: number } | null;
}

function money(value: number): number {
  return Math.round(value * 10000) / 10000;
}

test.describe('Fiscal POS E2E flow (API)', () => {
  let api: APIRequestContext | undefined;

  test.beforeAll(async ({ baseURL }) => {
    if (!credentials) return;

    const unauthenticated = await request.newContext({
      baseURL,
      extraHTTPHeaders: {
        Accept: 'application/json',
        'X-Tenant': credentials.tenant,
      },
    });
    const login = await unauthenticated.post('/api/auth/login', {
      data: { email: credentials.email, password: credentials.password },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(login.status(), 'fiscal E2E login status').toBe(201);
    const token = (await login.json()).data?.token as string | undefined;
    expect(token, 'fiscal E2E login token').toBeTruthy();
    await unauthenticated.dispose();

    api = await request.newContext({
      baseURL,
      extraHTTPHeaders: {
        Accept: 'application/json',
        'X-Tenant': credentials.tenant,
        Authorization: `Bearer ${token}`,
      },
    });
  });

  test.afterAll(async () => {
    await api?.dispose();
  });

  test('aplica override fiscal en checkout y conserva el snapshot en devolución', async () => {
    test.skip(
      !credentials,
      'Configura PLAYWRIGHT_E2E_EMAIL, PLAYWRIGHT_E2E_PASSWORD y PLAYWRIGHT_E2E_TENANT para probar fiscalización.',
    );
    if (!api) return;

    const bootstrapResponse = await api.get('/api/pos/bootstrap');
    expect(bootstrapResponse.status(), 'bootstrap status').toBe(200);
    const bootstrap = (await bootstrapResponse.json()) as Bootstrap;
    const openSession = bootstrap.open_session;
    if (!openSession) {
      test.skip(true, 'El tenant E2E no tiene una sesión de caja abierta.');
      return;
    }
    expect(bootstrap.warehouses.length, 'available warehouses').toBeGreaterThan(0);

    const ratesResponse = await api.get('/api/fiscal/tax-rates');
    expect(ratesResponse.status(), 'tax rates status').toBe(200);
    const ratesBody = (await ratesResponse.json()) as { data: FiscalRate[] };
    let exemptRate = ratesBody.data.find((rate) => rate.category === 'exempt');

    if (!exemptRate) {
      const createRate = await api.post('/api/fiscal/tax-rates', {
        data: {
          code: `E2E-EXENTO-${Date.now()}`,
          name: 'Exento E2E',
          rate: 0,
          category: 'exempt',
          is_active: true,
        },
      });
      if (createRate.status() === 403) {
        test.skip(
          true,
          'El tenant no tiene una alícuota exenta y las credenciales no tienen settings.manage.',
        );
        return;
      }
      expect(createRate.status(), 'create exempt rate status').toBe(201);
      exemptRate = (await createRate.json()).data as FiscalRate;
    }
    if (!exemptRate) {
      test.skip(true, 'No se pudo resolver una alícuota exenta para el escenario fiscal.');
      return;
    }

    const productsResponse = await api.get('/api/products?per_page=100');
    expect(productsResponse.status(), 'products status').toBe(200);
    const products = ((await productsResponse.json()) as { data: Product[] }).data.filter(
      (product) =>
        product.sale_currency === 'USD' &&
        product.tracking_type === 'quantity' &&
        product.base_price !== null &&
        Number(product.base_price) > 0,
    );
    const candidates: { product: Product; warehouseId: number }[] = [];

    for (const product of products.slice(0, 40)) {
      for (const warehouse of bootstrap.warehouses) {
        const stockResponse = await api.get(
          `/api/inventory-center/products/${product.id}/stock-context?warehouse_id=${warehouse.id}`,
        );
        if (!stockResponse.ok()) continue;
        const stock = (await stockResponse.json()) as { data: { available: number | string } };
        if (Number(stock.data.available) >= 1) {
          candidates.push({ product, warehouseId: warehouse.id });
          break;
        }
      }
      if (candidates.length >= 2) break;
    }

    if (candidates.length < 2) {
      test.skip(
        true,
        'Se requieren dos productos USD de cantidad con stock disponible en el tenant E2E.',
      );
      return;
    }

    const first = candidates[0]!;
    const second = candidates[1]!;
    const bundlePrice = money(
      (Number(first.product.base_price) + Number(second.product.base_price)) * 0.75,
    );
    const promotionResponse = await api.post('/api/combos', {
      data: {
        name: `Combo fiscal E2E ${Date.now()}`,
        code: `E2E-FISCAL-${Date.now()}`,
        benefit_type: 'fixed_bundle_price',
        price_usd: bundlePrice,
        fiscal_tax_mode: 'override',
        fiscal_tax_rate_id: exemptRate.id,
        items: [
          { product_id: first.product.id, quantity: 1 },
          { product_id: second.product.id, quantity: 1 },
        ],
      },
    });
    expect(promotionResponse.status(), 'combo creation status').toBe(201);
    const promotion = (await promotionResponse.json()).data as {
      id: number;
      fiscal_tax_mode: string;
      fiscal_tax_rate_id: number;
    };
    expect(promotion.fiscal_tax_mode).toBe('override');
    expect(promotion.fiscal_tax_rate_id).toBe(exemptRate.id);

    try {
      const checkoutResponse = await api.post('/api/pos/checkouts', {
        data: {
          cash_register_session_id: openSession.id,
          promotion_id: promotion.id,
          items: [
            { warehouse_id: first.warehouseId, product_id: first.product.id, quantity: 1 },
            { warehouse_id: second.warehouseId, product_id: second.product.id, quantity: 1 },
          ],
          payments: [{ method: 'cash', currency: 'USD', amount: bundlePrice }],
        },
        headers: { 'Idempotency-Key': `e2e-fiscal-${Date.now()}` },
      });
      expect(checkoutResponse.status(), 'fiscal combo checkout status').toBe(201);
      const checkout = (await checkoutResponse.json()).data as {
        sale: {
          id: number;
          total_base_amount: number | string;
          fiscal_tax_base_amount: number | string;
          fiscal_snapshot_at: string | null;
          items: {
            id: number;
            fiscal_tax_code: string | null;
            fiscal_tax_category: string | null;
            fiscal_total_base_amount: number | string;
          }[];
        };
      };
      expect(Number(checkout.sale.total_base_amount)).toBeCloseTo(bundlePrice, 4);
      expect(Number(checkout.sale.fiscal_tax_base_amount)).toBe(0);
      expect(checkout.sale.fiscal_snapshot_at).not.toBeNull();
      expect(checkout.sale.items).toHaveLength(2);
      const checkoutItem = checkout.sale.items[0];
      if (!checkoutItem) throw new Error('El checkout fiscal no devolvió líneas de venta.');
      for (const item of checkout.sale.items) {
        expect(item.fiscal_tax_code).toBe(exemptRate.code);
        expect(item.fiscal_tax_category).toBe('exempt');
      }

      const previewResponse = await api.post('/api/fiscal/documents/previews', {
        data: { sale_id: checkout.sale.id },
      });
      expect(previewResponse.status(), 'internal preview creation status').toBe(201);
      const preview = (await previewResponse.json()).data as {
        id: number;
        sale_id: number;
        document_type: string;
        document_mode: string;
        status: string;
        officially_issued: boolean;
        items: { sale_item_id: number }[];
      };
      expect(preview.sale_id).toBe(checkout.sale.id);
      expect(preview.document_type).toBe('internal_preview');
      expect(preview.document_mode).toBe('internal_preview');
      expect(preview.status).toBe('preview');
      expect(preview.officially_issued).toBe(false);
      expect(preview.items).toHaveLength(2);

      const repeatedPreviewResponse = await api.post('/api/fiscal/documents/previews', {
        data: { sale_id: checkout.sale.id },
      });
      expect(repeatedPreviewResponse.status(), 'internal preview idempotency status').toBe(200);
      expect((await repeatedPreviewResponse.json()).data.id).toBe(preview.id);

      const listResponse = await api.get(
        `/api/fiscal/documents?sale_id=${checkout.sale.id}&status=preview&per_page=1`,
      );
      expect(listResponse.status(), 'internal preview list status').toBe(200);
      const list = (await listResponse.json()) as {
        data: { id: number; sale_id: number; officially_issued: boolean; items: unknown[] }[];
        meta: { total: number };
      };
      expect(list.meta.total).toBe(1);
      expect(list.data).toHaveLength(1);
      expect(list.data[0]?.id).toBe(preview.id);
      expect(list.data[0]?.sale_id).toBe(checkout.sale.id);
      expect(list.data[0]?.officially_issued).toBe(false);
      expect(list.data[0]?.items).toHaveLength(2);

      const reopenedResponse = await api.get(`/api/fiscal/documents/${preview.id}`);
      expect(reopenedResponse.status(), 'internal preview reopen status').toBe(200);
      expect((await reopenedResponse.json()).data.id).toBe(preview.id);

      const returnResponse = await api.post('/api/sales-returns', {
        data: {
          sale_id: checkout.sale.id,
          reason: 'E2E snapshot fiscal',
          items: [{ sale_item_id: checkoutItem.id, quantity: 1 }],
        },
      });
      expect(returnResponse.status(), 'sales return creation status').toBe(201);
      const salesReturn = (await returnResponse.json()).data as {
        items: {
          fiscal_tax_code: string | null;
          fiscal_tax_category: string | null;
          fiscal_total_base_amount: number | string;
          fiscal_snapshot_at: string | null;
        }[];
      };
      const returnItem = salesReturn.items[0];
      if (!returnItem) throw new Error('La devolución fiscal no devolvió líneas.');
      expect(returnItem.fiscal_tax_code).toBe(exemptRate.code);
      expect(returnItem.fiscal_tax_category).toBe('exempt');
      expect(Number(returnItem.fiscal_total_base_amount)).toBe(
        Number(checkoutItem.fiscal_total_base_amount),
      );
      expect(returnItem.fiscal_snapshot_at).not.toBeNull();
    } finally {
      await api.delete(`/api/promotions/${promotion.id}`);
    }
  });
});
