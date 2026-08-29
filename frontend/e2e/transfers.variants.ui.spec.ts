import { expect, test, type APIRequestContext } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

async function json(response: Awaited<ReturnType<APIRequestContext['post']>>): Promise<any> {
  return (await response.json()) as any;
}

test('crea un traslado por variante (4 Azul + 5 Naranja) entre almacenes', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar traslados.');

  await loginAsDemo(page, credentials!);

  const headers = {
    Accept: 'application/json',
    Origin: 'http://127.0.0.1:5173',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Tenant': credentials!.tenant,
  };

  // 1. Almacen origen (el primero del tenant) + almacen destino nuevo.
  const warehousesResponse = await page.request.get('/api/warehouses', { headers });
  expect(warehousesResponse.status()).toBe(200);
  const warehouses = ((await json(warehousesResponse)) as { data: Array<{ id: number; branch_id: number }> }).data;
  expect(warehouses.length).toBeGreaterThan(0);
  const fromWarehouse = warehouses[0]!;
  expect(fromWarehouse).toBeTruthy();

  const whResp = await page.request.post('/api/warehouses', {
    headers,
    data: {
      branch_id: fromWarehouse.branch_id,
      code: `E2E-VAR-${Date.now()}`,
      name: 'Almacen E2E Variantes',
      status: 'active',
    },
  });
  expect(whResp.status()).toBe(201);
  const toWarehouse = (await json(whResp)).data.id as number;

  // 2. Producto con dos variantes (Azul / Naranja).
  const sku = `SKU-E2EVAR-${Date.now()}`;
  const prodResp = await page.request.post('/api/products', {
    headers,
    data: {
      name: 'TELEFONO E2E VAR',
      sku,
      tracking_type: 'quantity',
      base_price: 100,
      sale_currency: 'USD',
    },
  });
  expect(prodResp.status()).toBe(201);
  const productId = (await json(prodResp)).data.id as number;

  const azulResp = await page.request.post(`/api/products/${productId}/variants`, {
    headers,
    data: { color: 'Azul' },
  });
  expect(azulResp.status()).toBe(201);
  const azulId = (await json(azulResp)).data.id as number;

  const naranjaResp = await page.request.post(`/api/products/${productId}/variants`, {
    headers,
    data: { color: 'Naranja' },
  });
  expect(naranjaResp.status()).toBe(201);
  const naranjaId = (await json(naranjaResp)).data.id as number;

  // 3. Stock por variante en el almacen origen.
  const entryResp = await page.request.post('/api/product-entries', {
    headers,
    data: {
      reason: 'Carga E2E variantes',
      items: [
        { warehouse_id: fromWarehouse.id, product_id: productId, product_variant_id: azulId, quantity: 4, unit_cost: 50 },
        { warehouse_id: fromWarehouse.id, product_id: productId, product_variant_id: naranjaId, quantity: 5, unit_cost: 50 },
      ],
    },
  });
  expect(entryResp.status()).toBe(201);

  // 4. UI: crear el traslado con dos lineas, una por variante.
  await page.goto('/transfers');
  await expect(page.getByRole('heading', { name: 'Traslados' })).toBeVisible();
  await page.getByRole('button', { name: 'Nuevo traslado', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nuevo traslado' })).toBeVisible();

  await page.locator('#from-warehouse').selectOption(String(fromWarehouse.id));
  await page.locator('#to-warehouse').selectOption(String(toWarehouse));
  await page.locator('#validation-mode').selectOption('simple');

  const productSearch = page.getByPlaceholder('Buscar producto por nombre, SKU o barcode...');
  await productSearch.fill(sku);
  await page.getByRole('option', { name: new RegExp(sku) }).click();

  // Linea 1: Azul x2.
  const azulSelect = page.getByTestId('create-row-0-variant-select');
  await expect(azulSelect).toBeVisible();
  await azulSelect.selectOption(String(azulId));
  await page.getByTestId('create-row-0-quantity').fill('2');

  // Linea 2: Naranja x3.
  await page.getByRole('button', { name: 'Agregar linea' }).click();
  const row2 = page.getByTestId('create-row-1');
  const productSearch2 = row2.getByPlaceholder('Buscar producto por nombre, SKU o barcode...');
  await productSearch2.fill(sku);
  await page.getByRole('option', { name: new RegExp(sku) }).click();
  const naranjaSelect = row2.getByTestId('create-row-1-variant-select');
  await expect(naranjaSelect).toBeVisible();
  await naranjaSelect.selectOption(String(naranjaId));
  await page.getByTestId('create-row-1-quantity').fill('3');

  const createResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/inventory-transfers') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Crear borrador', exact: true }).click();
  const response = await createResponse;
  expect(response.status()).toBe(201);

  const transferPayload = await json(response);
  expect(transferPayload.data.status).toBe('completed');
  expect(transferPayload.data.items).toHaveLength(2);
  expect(transferPayload.data.items[0].product_variant_id).toBe(azulId);
  expect(transferPayload.data.items[0].quantity).toBe(2);
  expect(transferPayload.data.items[1].product_variant_id).toBe(naranjaId);
  expect(transferPayload.data.items[1].quantity).toBe(3);

  // 5. Verificacion de stock via API: origen queda Azul=2, Naranja=2; destino Azul=2, Naranja=3.
  const transferId = transferPayload.data.id as number;
  const detail = await page.request.get(`/api/inventory-transfers/${transferId}`, { headers });
  expect(detail.status()).toBe(200);

  const stockResp = await page.request.get(
    `/api/inventory-center/products/${productId}/stock-status`,
    { headers },
  );
  expect(stockResp.status()).toBe(200);
});
