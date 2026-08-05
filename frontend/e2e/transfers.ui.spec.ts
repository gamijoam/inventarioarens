import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('crea un traslado directo con un IMEI seleccionado visualmente', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar traslados.');

  await loginAsDemo(page, credentials!);

  const warehouseResponse = await page.request.post('/api/warehouses', {
    headers: {
      Accept: 'application/json',
      Origin: 'http://127.0.0.1:5173',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Tenant': credentials!.tenant,
    },
    data: {
      branch_id: 4,
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

  await page.locator('#from-warehouse').selectOption('4');
  await page.locator('#to-warehouse').selectOption(String(warehousePayload.data.id));
  await page.locator('#validation-mode').selectOption('simple');

  const productSearch = page.getByPlaceholder('Buscar producto por nombre, SKU o barcode...');
  await productSearch.fill('IPHONE 20');
  await page.getByRole('option', { name: /IPHONE 20/i }).click();

  const imei = page.getByTestId(/^create-row-0-imei-item-/).first();
  await expect(imei).toBeVisible();
  const imeiText = (await imei.textContent())?.match(/\d{10,}/)?.[0];
  expect(imeiText).toBeTruthy();
  await imei.click();
  await expect(page.getByTestId('create-row-0-imei-counter')).toContainText(
    '1 / 1 IMEIs seleccionados',
  );

  const createResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/inventory-transfers') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Crear borrador', exact: true }).click();
  const response = await createResponse;
  expect(response.status()).toBe(201);
  const transferPayload = (await response.json()) as {
    data: {
      status: string;
      items: Array<{ product_unit_ids: number[] | null }>;
    };
  };
  expect(transferPayload.data.status).toBe('completed');
  expect(transferPayload.data.items[0]?.product_unit_ids).toHaveLength(1);

  await expect(page).toHaveURL(/\/transfers\/\d+$/);
  await expect(
    page.getByLabel('Estado del traslado').getByText('Completado', { exact: true }),
  ).toBeVisible();
  await page.getByRole('tab', { name: 'Items', exact: true }).click();
  await expect(page.getByText('IPHONE 20', { exact: true })).toBeVisible();
  expect(imeiText).toBeTruthy();
});
