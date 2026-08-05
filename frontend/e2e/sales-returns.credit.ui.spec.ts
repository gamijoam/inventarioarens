import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('procesa una devolución como saldo a favor y lo aplica en una nueva venta', async ({
  page,
}) => {
  test.skip(
    !credentials,
    'Configura las variables PLAYWRIGHT_E2E_* para probar devoluciones y crédito.',
  );

  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();

  await page.getByTestId('pos-search').fill('ADAPTADOR 3 EN 1');
  await page
    .getByRole('button', { name: /ADAPTADOR 3 EN 1/i })
    .first()
    .click();
  await page.getByRole('button', { name: 'Cliente', exact: true }).first().click();
  await page.getByPlaceholder('Nombre, documento o telefono').fill('GABRIEL');
  await page
    .getByRole('button', { name: /GABRIEL/i })
    .first()
    .click();
  await page.getByRole('button', { name: 'Agregar pago con F2', exact: true }).click();
  await page.getByTestId('pos-add-payment-27').click();

  const initialCheckout = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/pos/checkouts') && response.request().method() === 'POST',
  );
  await page
    .getByRole('button', { name: /Cobrar/ })
    .last()
    .click();
  expect((await initialCheckout).status()).toBe(201);
  await expect(page.getByText('Venta confirmada.')).toBeVisible();

  await page.goto('/sales');
  await expect(page.getByRole('heading', { name: 'Ventas' })).toBeVisible();
  await page.getByPlaceholder('Cliente, documento, producto, SKU o venta #').fill('GABRIEL');
  const saleRow = page.getByRole('row').filter({ hasText: 'GABRIEL' }).first();
  await expect(saleRow).toBeVisible();
  await saleRow.click();
  await page.getByRole('button', { name: 'Devolver', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Registrar devolución' })).toBeVisible();

  await page
    .getByPlaceholder('Ej. cliente devuelve por cambio')
    .fill('Cliente solicita devolución');
  await page.locator('input[type="number"]').last().fill('1');

  const createReturn = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/sales-returns') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Registrar devolución', exact: true }).click();
  expect((await createReturn).status()).toBe(201);
  await expect(page.getByText('Devolución registrada.')).toBeVisible();

  await page.goto('/sales-returns');
  await expect(page.getByRole('heading', { name: 'Devoluciones' })).toBeVisible();
  const returnRow = page.getByRole('row').filter({ hasText: 'GABRIEL' }).first();
  await expect(returnRow).toBeVisible();
  await returnRow.click();
  await returnRow
    .locator('xpath=following-sibling::tr')
    .getByRole('button', { name: 'Aprobar', exact: true })
    .click();
  await expect(page.getByText('Devolución aprobada.')).toBeVisible();

  const expandedReturn = page
    .getByRole('row')
    .filter({ hasText: 'GABRIEL' })
    .locator('xpath=following-sibling::tr');
  const financeSelect = page
    .getByText('Finanzas', { exact: true })
    .locator('..')
    .getByRole('combobox');
  await financeSelect.selectOption('customer_credit');
  await expect(
    page.getByText('El importe quedará como saldo a favor', { exact: false }),
  ).toBeVisible();

  const processReturn = page.waitForResponse(
    (response) =>
      /\/api\/sales-returns\/\d+\/process$/.test(response.url()) &&
      response.request().method() === 'POST',
  );
  await expandedReturn.getByRole('button', { name: 'Procesar devolución', exact: true }).click();
  expect((await processReturn).status()).toBe(200);
  await expect(page.getByText('Devolución procesada.')).toBeVisible();

  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();
  await page.getByTestId('pos-search').fill('ADAPTADOR 3 EN 1');
  await page
    .getByRole('button', { name: /ADAPTADOR 3 EN 1/i })
    .first()
    .click();
  await page.getByRole('button', { name: 'Cliente', exact: true }).first().click();
  await page.getByPlaceholder('Nombre, documento o telefono').fill('GABRIEL');
  await page
    .getByRole('button', { name: /GABRIEL/i })
    .first()
    .click();

  const creditButton = page.getByRole('button', { name: /Aplicar saldo a favor/ });
  await expect(creditButton).toBeVisible();
  await creditButton.click();
  await expect(page.getByText('Pagos aplicados', { exact: true })).toBeVisible();

  const finalCheckout = page.waitForRequest(
    (request) => request.url().endsWith('/api/pos/checkouts') && request.method() === 'POST',
  );
  await page
    .getByRole('button', { name: /Cobrar/ })
    .last()
    .click();
  const finalPayload = (await finalCheckout).postDataJSON() as {
    payments: Array<{ method: string; amount: number }>;
  };
  expect(finalPayload.payments).toEqual([
    expect.objectContaining({ method: 'customer_credit', amount: 3 }),
  ]);
  await expect(page.getByText('Venta confirmada.')).toBeVisible();
});
