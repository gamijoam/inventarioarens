import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('cambia lista, selecciona IMEI y completa checkout UI USD/VES', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar el POS UI.');

  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();

  const search = page.getByTestId('pos-search');
  await search.fill('IPHONE12');
  const product = page.getByRole('button', { name: /^IPHONE 12 DE 64GB/i });
  await expect(product).toBeVisible();
  await search.press('Enter');
  const serial = page.getByRole('button', { name: /IMEI - ALMACEN PRINCIPAL/i }).first();
  await expect(serial).toBeVisible();
  await serial.press('Enter');
  await expect(page.getByText('1/1 seleccionados', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Cerrar' }).click();

  const priceList = page.getByTestId('pos-price-list');
  const ticket = page.locator('section').first();
  await expect(priceList).toBeVisible();
  await expect(priceList.locator('option:checked')).toHaveText('Precio base');
  await expect(ticket.getByText('$290.00', { exact: true })).toBeVisible();

  await priceList.selectOption({ label: 'DETAL - PRECIO DETAL' });
  await expect(priceList.locator('option:checked')).toHaveText(/DETAL - PRECIO DETAL/i);
  await expect(ticket.getByText('$435.00', { exact: true })).toBeVisible();
  await expect(page.getByText('PRECIO DETAL', { exact: true })).toBeVisible();

  await priceList.selectOption('base');
  await expect(priceList.locator('option:checked')).toHaveText('Precio base');
  await expect(ticket.getByText('$290.00', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Agregar pago con F2' }).click();
  await page.getByTestId('pos-add-payment-27').click();
  const firstPayment = page.getByTestId(/^pos-payment-amount-/).first();
  await expect(firstPayment).toHaveValue('290');
  await firstPayment.fill('100');

  await page
    .getByRole('button', { name: /F2 Pago/ })
    .first()
    .click();
  await page.getByTestId('pos-add-payment-2').click();
  const secondPayment = page.getByTestId(/^pos-payment-amount-/).nth(1);
  await expect(secondPayment).toHaveValue('190000');
  await page
    .getByTestId(/^pos-payment-reference-/)
    .last()
    .fill('UI-MIX-001');
  await expect(page.getByText('Pagos aplicados')).toBeVisible();

  const checkoutResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/pos/checkouts') && response.request().method() === 'POST',
  );
  await page
    .getByRole('button', { name: /Cobrar/ })
    .last()
    .click();
  const response = await checkoutResponse;
  expect(response.status()).toBe(201);
  const payload = response.request().postDataJSON() as {
    items: Array<{ price_list_id: number; price_source: string }>;
    payments: Array<{
      payment_method_id: number;
      currency: string;
      amount: number;
      exchange_rate_type_id: number | null;
      reference: string | null;
    }>;
  };

  expect(payload.items[0].price_source).toBe('base');
  expect(payload.items[0].price_list_id).toBeNull();
  expect(payload.payments).toHaveLength(2);
  expect(payload.payments[0]).toMatchObject({
    payment_method_id: 27,
    currency: 'USD',
    amount: 100,
  });
  expect(payload.payments[1]).toMatchObject({
    payment_method_id: 2,
    currency: 'VES',
    amount: 190000,
    exchange_rate_type_id: 1,
    reference: 'UI-MIX-001',
  });
  await expect(page.getByText('Venta confirmada.')).toBeVisible();
});
