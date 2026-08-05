import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('pone una venta en espera, la recupera y completa el cobro', async ({ page }) => {
  test.skip(
    !credentials,
    'Configura las variables PLAYWRIGHT_E2E_* para probar tickets pendientes.',
  );

  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();

  await page.getByTestId('pos-search').fill('ADAPTADOR 3 EN 1');
  const product = page.getByRole('button', { name: /ADAPTADOR 3 EN 1/i });
  await expect(product).toBeVisible();
  await product.click();
  await expect(page.locator('section').first().getByText('$3.00', { exact: true })).toBeVisible();

  const holdResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/pos/checkouts') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: /F6 Espera/ }).click();
  const heldOrder = (await (await holdResponse).json()).data as { id: number; status: string };
  expect(heldOrder.status).toBe('open');
  await expect(page.getByText('Venta puesta en espera.')).toBeVisible();

  await page.getByRole('button', { name: /F7 Pendientes/ }).click();
  const pendingTicket = page.getByRole('button', { name: new RegExp(`Ticket #${heldOrder.id}`) });
  await expect(pendingTicket).toBeVisible();
  await pendingTicket.click();
  await page.getByRole('button', { name: 'Cobrar seleccionado' }).click();

  await expect(page.getByText(new RegExp(`Ticket #${heldOrder.id} recuperado`))).toBeVisible();
  await expect(page.locator('section').first().getByText('ADAPTADOR 3 EN 1')).toBeVisible();
  await page.getByRole('button', { name: 'Agregar pago con F2' }).click();
  await page.getByTestId('pos-add-payment-27').click();
  await expect(page.getByTestId(/^pos-payment-amount-/).first()).toHaveValue('3');

  const paymentResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/api/pos/orders/${heldOrder.id}/payments`) &&
      response.request().method() === 'POST',
  );
  await page
    .getByRole('button', { name: /Cobrar/ })
    .last()
    .click();
  const paymentPayload = (await (await paymentResponse).json()).data as {
    status: string;
    paid_base_amount: string;
  };
  expect(paymentPayload.status).toBe('paid');
  expect(Number(paymentPayload.paid_base_amount)).toBe(3);
  await expect(page.getByText('Venta completada', { exact: true })).toBeVisible();
  await expect(page.getByText(`Orden POS #${heldOrder.id}`, { exact: true })).toBeVisible();
});
