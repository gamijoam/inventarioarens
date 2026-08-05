import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test('registra movimiento, cierra y reabre el turno de caja', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar caja.');

  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();

  const bootstrapResponse = await page.request.get('/api/pos/bootstrap', {
    headers: { 'X-Tenant': credentials!.tenant },
  });
  expect(bootstrapResponse.status()).toBe(200);
  const initialBootstrap = await bootstrapResponse.json();
  const initialSession = initialBootstrap.open_session as {
    id: number;
    branch_id: number;
    cash_register_id: number;
  };
  expect(initialSession).toBeTruthy();

  await page.getByRole('button', { name: 'Caja', exact: true }).click();
  await expect(page.getByText('Movimiento extra', { exact: true })).toBeVisible();
  await page.getByRole('combobox').first().selectOption('inflow');
  await page.getByTestId('pos-cash-movement-amount').fill('1');
  await page.getByTestId('pos-cash-movement-notes').fill('E2E movimiento de caja');

  const movementResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/cash-register/sessions/${initialSession.id}/movements`) &&
      response.request().method() === 'POST',
  );
  await page.getByTestId('pos-cash-movement-submit').click();
  expect((await movementResponse).status()).toBe(200);

  const updatedBootstrapResponse = await page.request.get('/api/pos/bootstrap', {
    headers: { 'X-Tenant': credentials!.tenant },
  });
  expect(updatedBootstrapResponse.status()).toBe(200);
  const updatedBootstrap = await updatedBootstrapResponse.json();
  const updatedSession = updatedBootstrap.open_session as {
    expected_cash_usd: number | string | null;
  };
  const expectedCashUsd = Number(updatedSession.expected_cash_usd ?? 0);

  await page.getByTestId('pos-cash-closing-amount').fill(String(expectedCashUsd));
  const closeResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/cash-register/sessions/${initialSession.id}/close`) &&
      response.request().method() === 'PATCH',
  );
  await page.getByTestId('pos-cash-close-submit').click();
  expect((await closeResponse).status()).toBe(200);
  await expect(page.getByRole('heading', { name: 'Abrir turno POS' })).toBeVisible();

  const openBranch = page.getByTestId('pos-cash-open-branch');
  const openRegister = page.getByTestId('pos-cash-open-register');
  await expect(openBranch.locator(`option[value="${initialSession.branch_id}"]`)).toBeAttached();
  await expect(
    openRegister.locator(`option[value="${initialSession.cash_register_id}"]`),
  ).toBeAttached();
  await openBranch.selectOption(String(initialSession.branch_id));
  await openRegister.selectOption(String(initialSession.cash_register_id));
  await page.getByTestId('pos-cash-open-base').fill('0');
  await page.getByTestId('pos-cash-open-local').fill('0');

  const openResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/cash-register/sessions') &&
      response.request().method() === 'POST',
  );
  await page.getByTestId('pos-cash-open-submit').click();
  expect((await openResponse).status()).toBe(201);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();
  await expect(page.getByText('CAJA1', { exact: false })).toBeVisible();
});
