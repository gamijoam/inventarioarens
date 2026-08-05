import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('envia una venta a CxC, cobra parcial en VES y liquida el saldo', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar crédito y CxC.');

  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();

  await page.getByTestId('pos-search').fill('ADAPTADOR 3 EN 1');
  const product = page.getByRole('button', { name: /ADAPTADOR 3 EN 1/i }).first();
  await expect(product).toBeVisible();
  await product.click();

  await page.getByRole('button', { name: 'Cliente', exact: true }).first().click();
  const customerSearch = page.getByPlaceholder('Nombre, documento o telefono');
  await customerSearch.fill('GABRIEL');
  const customer = page.getByRole('button', { name: /GABRIEL/i }).first();
  await expect(customer).toBeVisible();
  await customer.click();

  await page.getByRole('button', { name: 'Enviar a CxC', exact: true }).click();
  await expect(page.getByText('Saldo a CxC', { exact: true })).toBeVisible();
  await page.locator('#credit-due-date').fill('2026-12-31');

  const checkoutResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/pos/checkouts') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Confirmar venta a credito', exact: true }).click();
  expect((await checkoutResponse).status()).toBe(201);
  await expect(page.getByText('Venta enviada a cuentas por cobrar.')).toBeVisible();

  await page.goto('/receivables');
  await expect(page.getByRole('heading', { name: 'Cuentas por cobrar' })).toBeVisible();
  await page.getByPlaceholder('Cliente, documento, venta o CxC').fill('GABRIEL');

  const receivableRow = page.getByRole('row').filter({ hasText: 'GABRIEL' });
  await expect(receivableRow).toBeVisible();
  await receivableRow.getByRole('button', { name: 'Cobrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Registrar cobro' })).toBeVisible();

  const amount = page.getByPlaceholder('Monto');
  const currency = page.locator('select').nth(1);
  await currency.selectOption('VES');
  const fullLocalAmount = Number(await amount.inputValue());
  expect(fullLocalAmount).toBeGreaterThan(0);
  await amount.fill(String(fullLocalAmount / 2));
  const collectResponse = page.waitForResponse(
    (response) =>
      /\/api\/accounts-receivable\/\d+\/payments$/.test(response.url()) &&
      response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Registrar cobro', exact: true }).click();
  expect((await collectResponse).status()).toBe(201);
  await expect(page.getByText('Cobro registrado.').last()).toBeVisible();

  const partialRow = page.getByRole('row').filter({ hasText: 'GABRIEL' });
  await expect(partialRow.getByText('Parcial', { exact: true })).toBeVisible();
  await partialRow.getByRole('button', { name: 'Cobrar', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Registrar cobro' })).toBeVisible();

  await page.getByPlaceholder('Monto').fill('999999');
  await expect(
    page.getByText('El monto supera el saldo pendiente.', { exact: false }),
  ).toBeVisible();
  await expect(page.getByRole('button', { name: 'Registrar cobro', exact: true })).toBeDisabled();

  await currency.selectOption('USD');
  await expect(page.getByPlaceholder('Monto')).not.toHaveValue('0');
  const finalCollectResponse = page.waitForResponse(
    (response) =>
      /\/api\/accounts-receivable\/\d+\/payments$/.test(response.url()) &&
      response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Registrar cobro', exact: true }).click();
  expect((await finalCollectResponse).status()).toBe(201);
  await expect(page.getByText('Cobro registrado.').last()).toBeVisible();
  await expect(page.getByText('Sin cuentas por cobrar abiertas')).toBeVisible();
});
