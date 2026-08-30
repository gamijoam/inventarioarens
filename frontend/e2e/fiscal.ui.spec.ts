import { expect, request, test, type APIRequestContext, type Page } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

interface FiscalRate {
  id: number;
  code: string;
  category: string;
}

interface Product {
  id: number;
  name: string;
  base_price: number | string | null;
  sale_currency: string;
  tracking_type: string;
}

interface Warehouse {
  id: number;
}

interface Bootstrap {
  warehouses: Warehouse[];
  open_session: { id: number } | null;
}

interface StockContext {
  data: {
    available: number | string;
  };
}

function money(value: number): number {
  return Math.round(value * 10000) / 10000;
}

async function authenticatedApi(baseURL: string): Promise<APIRequestContext> {
  if (!credentials) throw new Error('Faltan credenciales E2E.');

  const loginContext = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Tenant': credentials.tenant,
    },
  });
  const login = await loginContext.post('/api/auth/login', {
    data: { email: credentials.email, password: credentials.password },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(login.status(), 'UI E2E login status').toBe(201);
  const token = (await login.json()).data?.token as string | undefined;
  expect(token, 'UI E2E login token').toBeTruthy();
  await loginContext.dispose();

  return request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Tenant': credentials.tenant,
      Authorization: `Bearer ${token}`,
    },
  });
}

async function resolveComboFixture(api: APIRequestContext): Promise<{
  promotionId: number;
  promotionCode: string;
  firstProduct: Product;
  secondProduct: Product;
  warehouseId: number;
  sessionId: number;
}> {
  const bootstrapResponse = await api.get('/api/pos/bootstrap');
  expect(bootstrapResponse.status(), 'bootstrap status').toBe(200);
  const bootstrap = (await bootstrapResponse.json()) as Bootstrap;
  if (!bootstrap.open_session || bootstrap.warehouses.length === 0) {
    throw new Error('El tenant E2E necesita una sesión de caja y un almacén.');
  }

  const ratesResponse = await api.get('/api/fiscal/tax-rates');
  expect(ratesResponse.status(), 'tax rates status').toBe(200);
  const rates = ((await ratesResponse.json()) as { data: FiscalRate[] }).data;
  let exemptRate = rates.find((rate) => rate.category === 'exempt');
  if (!exemptRate) {
    const createRate = await api.post('/api/fiscal/tax-rates', {
      data: {
        code: `E2E-UI-EXENTO-${Date.now()}`,
        name: 'Exento UI E2E',
        rate: 0,
        category: 'exempt',
        is_active: true,
      },
    });
    expect(createRate.status(), 'create exempt rate status').toBe(201);
    exemptRate = (await createRate.json()).data as FiscalRate;
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

  let pair: { first: Product; second: Product; warehouseId: number } | null = null;
  for (const warehouse of bootstrap.warehouses) {
    const stocked: Product[] = [];
    for (const product of products.slice(0, 40)) {
      const stockResponse = await api.get(
        `/api/inventory-center/products/${product.id}/stock-context?warehouse_id=${warehouse.id}`,
      );
      if (!stockResponse.ok()) continue;
      const stock = (await stockResponse.json()) as StockContext;
      if (Number(stock.data.available) >= 1) stocked.push(product);
      if (stocked.length >= 2) break;
    }
    if (stocked.length >= 2) {
      pair = { first: stocked[0]!, second: stocked[1]!, warehouseId: warehouse.id };
      break;
    }
  }

  if (!pair) throw new Error('Se requieren dos productos USD de cantidad con stock en un almacén.');

  const promotionCode = `E2E-UI-FISCAL-${Date.now()}`;
  const promotionResponse = await api.post('/api/combos', {
    data: {
      name: `Combo fiscal UI E2E ${Date.now()}`,
      code: promotionCode,
      benefit_type: 'fixed_bundle_price',
      price_usd: money((Number(pair.first.base_price) + Number(pair.second.base_price)) * 0.75),
      fiscal_tax_mode: 'override',
      fiscal_tax_rate_id: exemptRate.id,
      items: [
        { product_id: pair.first.id, quantity: 1 },
        { product_id: pair.second.id, quantity: 1 },
      ],
    },
  });
  expect(promotionResponse.status(), 'combo creation status').toBe(201);
  const promotion = (await promotionResponse.json()).data as { id: number };

  return {
    promotionId: promotion.id,
    promotionCode,
    firstProduct: pair.first,
    secondProduct: pair.second,
    warehouseId: pair.warehouseId,
    sessionId: bootstrap.open_session.id,
  };
}

async function openPos(page: Page): Promise<void> {
  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();
}

test('muestra en navegador el combo fiscal y la devolución con snapshot', async ({ page }) => {
  test.skip(
    !credentials,
    'Configura PLAYWRIGHT_E2E_EMAIL, PLAYWRIGHT_E2E_PASSWORD y PLAYWRIGHT_E2E_TENANT para probar el POS UI fiscal.',
  );
  const api = await authenticatedApi(process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000');
  let fixture: Awaited<ReturnType<typeof resolveComboFixture>> | undefined;

  try {
    fixture = await resolveComboFixture(api);
    await openPos(page);

    await page.getByRole('button', { name: 'Combos', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Combos', exact: true })).toBeVisible();
    await page
      .getByRole('button', { name: `Aplicar ${fixture.promotionCode}`, exact: true })
      .click();
    await expect(page.getByText(/1 conjunto\(s\).*cargado\(s\)/)).toBeVisible();
    await expect(page.getByText(fixture.firstProduct.name, { exact: true })).toBeVisible();
    await expect(page.getByText(fixture.secondProduct.name, { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Agregar pago con F2', exact: true }).click();
    const usdPayment = page
      .getByTestId(/^pos-add-payment-\d+$/)
      .filter({ hasText: /USD/ })
      .first();
    await expect(usdPayment).toBeVisible();
    await usdPayment.click();
    await expect(page.getByText('Pagos aplicados', { exact: true })).toBeVisible();

    const checkoutResponse = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/pos/checkouts') && response.request().method() === 'POST',
    );
    await page
      .getByRole('button', { name: /Cobrar/ })
      .last()
      .click();
    const response = await checkoutResponse;
    expect(response.status(), 'checkout status').toBe(201);
    const checkout = (await response.json()).data as {
      sale: {
        id: number;
        fiscal_tax_base_amount: number | string;
        fiscal_snapshot_at: string | null;
      };
    };
    expect(Number(checkout.sale.fiscal_tax_base_amount)).toBe(0);
    expect(checkout.sale.fiscal_snapshot_at).not.toBeNull();
    await expect(page.getByText('Venta confirmada.')).toBeVisible();

    await page.goto('/sales');
    await expect(page.getByRole('heading', { name: 'Ventas' })).toBeVisible();
    const search = page.getByPlaceholder('Cliente, documento, producto, SKU o venta #');
    await search.fill(String(checkout.sale.id));
    const saleRow = page
      .getByRole('row')
      .filter({ hasText: `#${checkout.sale.id}` })
      .first();
    await expect(saleRow).toBeVisible();
    await saleRow.click();
    const saleDetail = saleRow.locator('xpath=following-sibling::tr');
    await saleDetail.getByRole('button', { name: 'Vista previa interna', exact: true }).click();
    await expect(
      page.getByRole('heading', { name: 'Vista previa interna', exact: true }),
    ).toBeVisible();
    await expect(
      page.getByText(
        'Documento interno para revisión comercial. No constituye emisión fiscal oficial.',
        { exact: true },
      ),
    ).toBeVisible();
    await expect(page.getByText('Interno · No emitido', { exact: true })).toBeVisible();
    await expect(page.getByText(/número de control/i)).toHaveCount(0);
    await page.getByRole('button', { name: 'Cerrar', exact: true }).last().click();

    await saleDetail.getByRole('button', { name: 'Devolver', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Registrar devolución' })).toBeVisible();
    await page.getByRole('button', { name: 'Cerrar', exact: true }).last().click();

    await page.addInitScript(() => {
      (window as Window & { __fiscalPreviewPrinted?: boolean }).print = () => {
        (window as Window & { __fiscalPreviewPrinted?: boolean }).__fiscalPreviewPrinted = true;
      };
    });
    await page.goto('/fiscal/documents');
    await expect(page.getByRole('heading', { name: 'Documentos internos' })).toBeVisible();
    await page.getByLabel('Venta').fill(String(checkout.sale.id));
    const previewRow = page
      .getByRole('row')
      .filter({ hasText: `#${checkout.sale.id}` })
      .first();
    await expect(previewRow).toBeVisible();
    await previewRow.getByRole('button', { name: 'Abrir', exact: true }).click();
    await expect(
      page.getByRole('heading', { name: 'Vista previa interna', exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Imprimir vista comercial', exact: true }).click();
    expect(
      await page.evaluate(
        () => (window as Window & { __fiscalPreviewPrinted?: boolean }).__fiscalPreviewPrinted,
      ),
    ).toBe(true);
    await page.getByRole('button', { name: 'Cerrar', exact: true }).last().click();
  } finally {
    if (fixture) await api.delete(`/api/promotions/${fixture.promotionId}`);
    await api.dispose();
  }
});
