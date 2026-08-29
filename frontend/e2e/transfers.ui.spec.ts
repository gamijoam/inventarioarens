import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('crea un traslado directo con un IMEI seleccionado visualmente', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar traslados.');

  await loginAsDemo(page, credentials!);

  const branchesResponse = await page.request.get('/api/branches', {
    headers: {
      Accept: 'application/json',
      'X-Tenant': credentials!.tenant,
    },
  });
  expect(branchesResponse.status()).toBe(200);
  const branchesPayload = (await branchesResponse.json()) as { data: { id: number }[] };
  const branchId = branchesPayload.data[0]?.id;
  expect(branchId).toBeTruthy();

  const sourceWarehousesResponse = await page.request.get('/api/warehouses', {
    headers: {
      Accept: 'application/json',
      'X-Tenant': credentials!.tenant,
    },
  });
  expect(sourceWarehousesResponse.status()).toBe(200);
  const sourceWarehousesPayload = (await sourceWarehousesResponse.json()) as {
    data: { id: number }[];
  };
  const productsResponse = await page.request.get(
    '/api/products?tracking_type=serialized&per_page=100',
    {
      headers: {
        Accept: 'application/json',
        'X-Tenant': credentials!.tenant,
      },
    },
  );
  expect(productsResponse.status()).toBe(200);
  const productsPayload = (await productsResponse.json()) as {
    data: { id: number; name: string }[];
  };
  let sourceWarehouseId: number | null = null;
  let selectedProduct: { id: number; name: string; serialNumber: string } | null = null;
  search: for (const warehouse of sourceWarehousesPayload.data) {
    for (const product of productsPayload.data) {
      const unitsResponse = await page.request.get(
        `/api/inventory-centers/products/${product.id}/units?warehouse_id=${warehouse.id}&status=available`,
        { headers: { Accept: 'application/json', 'X-Tenant': credentials!.tenant } },
      );
      if (unitsResponse.status() !== 200) continue;
      const unitsPayload = (await unitsResponse.json()) as {
        data: { serial_number: string }[];
      };
      if (unitsPayload.data[0]) {
        sourceWarehouseId = warehouse.id;
        selectedProduct = { ...product, serialNumber: unitsPayload.data[0].serial_number };
        break search;
      }
    }
  }
  expect(sourceWarehouseId).toBeTruthy();
  expect(selectedProduct).not.toBeNull();

  const warehouseResponse = await page.request.post('/api/warehouses', {
    headers: {
      Accept: 'application/json',
      Origin: new URL(page.url()).origin,
      'X-Requested-With': 'XMLHttpRequest',
      'X-Tenant': credentials!.tenant,
    },
    data: {
      branch_id: branchId,
      code: `E2E-${Date.now()}`,
      name: 'Almacen E2E Traslados',
      status: 'active',
    },
  });
  expect(warehouseResponse.status()).toBe(201);
  const warehousePayload = (await warehouseResponse.json()) as { data: { id: number } };

  await page.goto('/transfers');
  await expect(page.getByRole('heading', { name: 'Traslados' })).toBeVisible();
  await page.getByRole('button', { name: 'Nuevo traslado', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nuevo traslado' })).toBeVisible();

  await page.locator('#from-warehouse').selectOption(String(sourceWarehouseId));
  await page.locator('#to-warehouse').selectOption(String(warehousePayload.data.id));
  await page.locator('#validation-mode').selectOption('simple');

  const productSearch = page.getByPlaceholder('Buscar producto por nombre, SKU o barcode...');
  await productSearch.fill(selectedProduct!.name);
  await page.getByRole('option', { name: new RegExp(selectedProduct!.name, 'i') }).click();

  const imei = page.getByTestId(/^create-row-0-imei-item-/).first();
  await expect(imei).toBeVisible();
  const imeiText = selectedProduct!.serialNumber;
  await expect(imei).toContainText(imeiText);
  await imei.click();
  await expect(page.getByTestId('create-row-0-imei-counter')).toContainText(
    '1 / 1 IMEIs seleccionados',
  );

  const createResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/inventory-transfers') && response.request().method() === 'POST',
  );
  const createRequest = page.waitForRequest(
    (request) => request.url().endsWith('/api/inventory-transfers') && request.method() === 'POST',
  );
  await page.getByRole('button', { name: 'Crear borrador', exact: true }).click();
  const request = await createRequest;
  const response = await createResponse;
  expect(response.status()).toBe(201);
  const idempotencyKey = request.headers()['idempotency-key'];
  expect(idempotencyKey).toBeTruthy();
  const transferPayload = (await response.json()) as {
    data: {
      status: string;
      items: { product_unit_ids: number[] | null }[];
      id: number;
    };
  };
  expect(transferPayload.data.status).toBe('completed');
  expect(transferPayload.data.items[0]?.product_unit_ids).toHaveLength(1);

  const replayResponse = await page.request.post('/api/inventory-transfers', {
    headers: {
      Accept: 'application/json',
      Origin: new URL(page.url()).origin,
      'X-Requested-With': 'XMLHttpRequest',
      'X-Tenant': credentials!.tenant,
      'Idempotency-Key': idempotencyKey!,
    },
    data: request.postDataJSON(),
  });
  expect(replayResponse.status()).toBe(201);
  const replayPayload = (await replayResponse.json()) as { data: { id: number } };
  expect(replayPayload.data.id).toBe(transferPayload.data.id);

  await expect(page).toHaveURL(/\/transfers\/\d+$/);
  await expect(
    page.getByLabel('Estado del traslado').getByText('Completado', { exact: true }),
  ).toBeVisible();
  await page.getByRole('tab', { name: 'Items', exact: true }).click();
  await expect(page.getByText(selectedProduct!.name, { exact: true })).toBeVisible();
  expect(imeiText).toBeTruthy();
});
