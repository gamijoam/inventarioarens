import { expect, test, type Page } from '@playwright/test';

import { loginAsDemo } from './support/auth';
import {
  addQuantityStock,
  authenticatedApi,
  availableSerial,
  loadProducts,
  productSerials as allProductSerials,
  readIntercompanyConfig,
  type IntercompanyParty,
  type IntercompanyProduct,
} from './support/intercompany';

async function selectTransferProduct(page: Page, testId: string, search: string): Promise<void> {
  const input = page.getByTestId(testId);
  if ((await input.count()) === 0) return;

  await input.fill(search);
  const option = page.getByRole('option').filter({ hasText: search }).first();
  await expect(option).toBeVisible();
  await option.click();
}

async function addCreateItems(page: Page, products: IntercompanyProduct[]): Promise<void> {
  for (let index = 1; index < products.length; index += 1) {
    await page.getByTestId('add-item').click();
  }

  for (const [index, product] of products.entries()) {
    await selectTransferProduct(page, `item-product-${index}`, product.search);
    await page.getByTestId(`item-qty-${index}`).fill('1');
  }
}

async function selectSerial(page: Page, scannerTestId: string, serial: string): Promise<void> {
  const scanner = page.getByTestId(scannerTestId);
  await expect(scanner).toBeVisible();
  const unit = scanner.getByRole('button').filter({ hasText: serial }).first();
  await expect(unit).toBeVisible();
  await unit.click();
}

async function mapAcceptedItems(
  page: Page,
  itemIds: number[],
  products: IntercompanyProduct[],
  serials: Array<string | null>,
  selectSerials: boolean,
): Promise<void> {
  for (const [index, itemId] of itemIds.entries()) {
    await selectTransferProduct(page, `item-product-${itemId}`, products[index].search);
    if (selectSerials && serials[index]) {
      await selectSerial(page, `accept-imei-${itemId}`, serials[index]!);
    }
  }
}

async function fillGuideQuantities(page: Page, itemIds: number[]): Promise<void> {
  for (const itemId of itemIds) {
    await page.locator(`#guide-qty-${itemId}`).fill('1');
  }
}

async function prepareSerials(
  page: Page,
  itemIds: number[],
  products: IntercompanyProduct[],
  serials: Array<string | null>,
): Promise<void> {
  for (const [index, itemId] of itemIds.entries()) {
    if (products[index].trackingType === 'serialized' && serials[index]) {
      await selectSerial(page, `guide-imei-${itemId}`, serials[index]!);
    }
  }
}

async function fillReceivedSerials(
  page: Page,
  products: IntercompanyProduct[],
  serials: Array<string | null>,
): Promise<void> {
  const inputs = page.getByPlaceholder(
    'Escanea o escribe los IMEIs/seriales recibidos, separados por coma',
  );
  let serialIndex = 0;
  for (const [index, product] of products.entries()) {
    if (product.trackingType !== 'serialized') continue;
    await inputs.nth(serialIndex).fill(serials[index] ?? '');
    serialIndex += 1;
  }
}

async function expectCompleted(page: Page): Promise<void> {
  await expect(page.getByText('Completada', { exact: true }).first()).toBeVisible();
}

async function productSerials(
  api: Awaited<ReturnType<typeof authenticatedApi>>,
  party: IntercompanyParty,
  products: IntercompanyProduct[],
  excludedSerials: string[][] = [],
): Promise<Array<string | null>> {
  return Promise.all(
    products.map(async (product, index) =>
      product.trackingType === 'serialized'
        ? availableSerial(api, product.id, party.warehouseId, excludedSerials[index] ?? [])
        : null,
    ),
  );
}

test('UI: solicitante mueve 10 productos mixtos en una solicitud directa', async ({
  browser,
  baseURL,
}) => {
  const config = readIntercompanyConfig();
  test.skip(
    !config,
    'Configura las variables PLAYWRIGHT_INTERCOMPANY_* para probar los dos tenants.',
  );
  if (!config) return;

  const apiBaseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';
  const requesterApi = await authenticatedApi(apiBaseURL, config.requester);
  const supplierApi = await authenticatedApi(apiBaseURL, config.supplier);
  const requesterContext = await browser.newContext({ baseURL });
  const supplierContext = await browser.newContext({ baseURL });
  const requesterPage = await requesterContext.newPage();
  const supplierPage = await supplierContext.newPage();

  try {
    const requesterProducts = await loadProducts(requesterApi, config.requester);
    const supplierProducts = await loadProducts(supplierApi, config.supplier);
    const requesterSerials = await Promise.all(
      requesterProducts.map((product) => allProductSerials(requesterApi, product.id)),
    );
    await addQuantityStock(
      supplierApi,
      config.supplier,
      supplierProducts,
      `E2E UI solicitud mixta ${Date.now()}`,
    );
    const supplierSerials = await productSerials(
      supplierApi,
      config.supplier,
      supplierProducts,
      requesterSerials,
    );

    await loginAsDemo(requesterPage, config.requester);
    await requesterPage.goto('/inventory-transfer-requests');
    await expect(
      requesterPage.getByRole('heading', { name: 'Traslados interempresa' }),
    ).toBeVisible();
    await requesterPage.getByRole('button', { name: 'Solicitar stock', exact: true }).click();
    await requesterPage.locator('#dest-company').selectOption(config.supplier.tenant);
    await requesterPage.locator('#from-wh').selectOption(String(config.requester.warehouseId));
    await addCreateItems(requesterPage, requesterProducts);

    const createResponse = requesterPage.waitForResponse(
      (response) =>
        response.url().includes('/api/inventory-transfer-requests') &&
        response.request().method() === 'POST',
    );
    await requesterPage.getByTestId('submit-create').click();
    const created = await createResponse;
    expect(created.status(), await created.text()).toBe(201);
    const createdPayload = (await created.json()) as {
      data: { id: number; items: Array<{ id: number }> };
    };
    expect(createdPayload.data.items).toHaveLength(10);
    const requestId = createdPayload.data.id;
    const itemIds = createdPayload.data.items.map((item) => item.id);

    await loginAsDemo(supplierPage, config.supplier);
    await supplierPage.goto(`/inventory-transfer-requests/${requestId}`);
    await expect(supplierPage.getByTestId('detail-accept-btn')).toBeVisible();
    await supplierPage.getByTestId('detail-accept-btn').click();
    await supplierPage
      .locator('#accept-warehouse')
      .selectOption(String(config.supplier.warehouseId));
    await mapAcceptedItems(supplierPage, itemIds, supplierProducts, supplierSerials, true);

    const acceptResponse = supplierPage.waitForResponse((response) =>
      response.url().endsWith(`/api/inventory-transfer-requests/${requestId}/accept`),
    );
    await supplierPage.getByTestId('submit-accept').click();
    const accepted = await acceptResponse;
    expect(accepted.status(), await accepted.text()).toBe(200);
    expect((await accepted.json()).data.status).toBe('completed');

    await supplierPage.goto(`/inventory-transfer-requests/${requestId}`);
    await expectCompleted(supplierPage);
  } finally {
    await requesterPage.close();
    await supplierPage.close();
    await requesterContext.close();
    await supplierContext.close();
    await requesterApi.dispose();
    await supplierApi.dispose();
  }
});

test('UI: proveedor mueve 10 productos mixtos mediante una guía logística', async ({
  browser,
  baseURL,
}) => {
  const config = readIntercompanyConfig();
  test.skip(
    !config,
    'Configura las variables PLAYWRIGHT_INTERCOMPANY_* para probar los dos tenants.',
  );
  if (!config) return;

  const apiBaseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';
  const senderApi = await authenticatedApi(apiBaseURL, config.requester);
  const receiverApi = await authenticatedApi(apiBaseURL, config.supplier);
  const senderContext = await browser.newContext({ baseURL });
  const receiverContext = await browser.newContext({ baseURL });
  const senderPage = await senderContext.newPage();
  const receiverPage = await receiverContext.newPage();

  try {
    const senderProducts = await loadProducts(senderApi, config.requester);
    const receiverProducts = await loadProducts(receiverApi, config.supplier);
    const receiverSerials = await Promise.all(
      receiverProducts.map((product) => allProductSerials(receiverApi, product.id)),
    );
    await addQuantityStock(
      senderApi,
      config.requester,
      senderProducts,
      `E2E UI propuesta mixta ${Date.now()}`,
    );
    const senderSerials = await productSerials(
      senderApi,
      config.requester,
      senderProducts,
      receiverSerials,
    );

    await loginAsDemo(senderPage, config.requester);
    await senderPage.goto('/inventory-transfer-requests');
    await senderPage.getByRole('button', { name: 'Proponer envío', exact: true }).click();
    await senderPage.locator('#dest-company').selectOption(config.supplier.tenant);
    await senderPage.locator('#from-wh').selectOption(String(config.requester.warehouseId));
    await addCreateItems(senderPage, senderProducts);

    const createResponse = senderPage.waitForResponse(
      (response) =>
        response.url().includes('/api/inventory-transfer-requests') &&
        response.request().method() === 'POST',
    );
    await senderPage.getByTestId('submit-create').click();
    const created = await createResponse;
    expect(created.status(), await created.text()).toBe(201);
    const createdPayload = (await created.json()) as {
      data: { id: number; items: Array<{ id: number }> };
    };
    expect(createdPayload.data.items).toHaveLength(10);
    const requestId = createdPayload.data.id;
    const itemIds = createdPayload.data.items.map((item) => item.id);

    await loginAsDemo(receiverPage, config.supplier);
    await receiverPage.goto(`/inventory-transfer-requests/${requestId}`);
    await receiverPage.getByTestId('detail-accept-btn').click();
    await receiverPage
      .locator('#accept-warehouse')
      .selectOption(String(config.supplier.warehouseId));
    await mapAcceptedItems(receiverPage, itemIds, receiverProducts, senderSerials, false);
    const acceptResponse = receiverPage.waitForResponse((response) =>
      response.url().endsWith(`/api/inventory-transfer-requests/${requestId}/accept`),
    );
    await receiverPage.getByTestId('submit-accept').click();
    expect((await (await acceptResponse).json()).data.status).toBe('accepted');

    await senderPage.goto(`/inventory-transfer-requests/${requestId}`);
    await senderPage.getByRole('button', { name: 'Preparar guía', exact: true }).click();
    await fillGuideQuantities(senderPage, itemIds);
    await prepareSerials(senderPage, itemIds, senderProducts, senderSerials);
    const [prepareResponse] = await Promise.all([
      senderPage.waitForResponse((response) =>
        response.url().includes(`/inventory-transfer-requests/${requestId}/guide/prepare`),
      ),
      senderPage.getByRole('button', { name: 'Confirmar preparación', exact: true }).click(),
    ]);
    expect((await prepareResponse.json()).data.guide.status).toBe('prepared');

    const [dispatchResponse] = await Promise.all([
      senderPage.waitForResponse((response) =>
        response.url().includes(`/inventory-transfer-requests/${requestId}/guide/dispatch`),
      ),
      senderPage.getByRole('button', { name: 'Despachar', exact: true }).click(),
    ]);
    expect((await dispatchResponse.json()).data.guide.status).toBe('dispatched');

    const [deliverResponse] = await Promise.all([
      senderPage.waitForResponse((response) =>
        response.url().includes(`/inventory-transfer-requests/${requestId}/guide/deliver`),
      ),
      senderPage.getByRole('button', { name: 'Marcar entregada', exact: true }).click(),
    ]);
    expect((await deliverResponse.json()).data.guide.status).toBe('delivered');

    await receiverPage.goto(`/inventory-transfer-requests/${requestId}`);
    await receiverPage.getByRole('button', { name: 'Recibir guía', exact: true }).click();
    await fillGuideQuantities(receiverPage, itemIds);
    await fillReceivedSerials(receiverPage, receiverProducts, senderSerials);
    const [receiveResponse] = await Promise.all([
      receiverPage.waitForResponse((response) =>
        response.url().includes(`/inventory-transfer-requests/${requestId}/guide/receive`),
      ),
      receiverPage.getByRole('button', { name: 'Confirmar recepción', exact: true }).click(),
    ]);
    expect((await receiveResponse.json()).data.status).toBe('completed');

    await receiverPage.goto(`/inventory-transfer-requests/${requestId}`);
    await expectCompleted(receiverPage);
  } finally {
    await senderPage.close();
    await receiverPage.close();
    await senderContext.close();
    await receiverContext.close();
    await senderApi.dispose();
    await receiverApi.dispose();
  }
});
