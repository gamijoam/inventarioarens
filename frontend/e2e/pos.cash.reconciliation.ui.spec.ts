import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

/**
 * E2E de conciliacion de caja:
 * - Abre un turno en 0.
 * - Cuenta exactamente el efectivo fisico esperado (USD y VES) y cierra.
 * - Exige que la diferencia fisica quede en 0 aunque existan ventas
 *   electronicas (que solo afectan `difference_base_amount`).
 * - Reabre el turno para dejar el POS operable.
 */
test('cierra la caja contando el efectivo fisico y deja diferencia fisica en cero', async ({
  page,
}) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar caja.');

  const tenantHeader = { 'X-Tenant': credentials!.tenant };

  await loginAsDemo(page, credentials!);
  await page.goto('/pos');
  await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();

  const bootstrapResponse = await page.request.get('/api/pos/bootstrap', {
    headers: tenantHeader,
  });
  expect(bootstrapResponse.status()).toBe(200);
  let bootstrap = await bootstrapResponse.json();
  let session = bootstrap.open_session as
    | { id: number; branch_id: number; cash_register_id: number }
    | null;

  if (!session) {
    const branches = bootstrap.branches as Array<{ id: number }>;
    const registers = bootstrap.cash_registers as Array<{ id: number; branch_id: number }>;
    const branch = branches[0];
    const register = registers.find((item) => item.branch_id === branch.id) ?? registers[0];

    await page.getByTestId('pos-cash-open-branch').selectOption(String(branch.id));
    await page.getByTestId('pos-cash-open-register').selectOption(String(register.id));
    await page.getByTestId('pos-cash-open-base').fill('0');
    await page.getByTestId('pos-cash-open-local').fill('0');
    const openResponse = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/cash-register/sessions') &&
        response.request().method() === 'POST',
    );
    await page.getByTestId('pos-cash-open-submit').click();
    expect((await openResponse).status()).toBe(201);

    bootstrap = await (
      await page.request.get('/api/pos/bootstrap', { headers: tenantHeader })
    ).json();
    session = bootstrap.open_session as { id: number; branch_id: number; cash_register_id: number };
  }

  expect(session).toBeTruthy();
  const sessionId = session!.id;

  const current = bootstrap.open_session as {
    expected_cash_usd: number | string | null;
    expected_cash_ves: number | string | null;
    expected_base_amount: number | string | null;
  };
  const expectedCashUsd = Number(current.expected_cash_usd ?? 0);
  const expectedCashVes = Number(current.expected_cash_ves ?? 0);

  await page.getByRole('button', { name: 'Caja', exact: true }).click();
  await expect(page.getByText('Movimiento extra', { exact: true })).toBeVisible();

  // Modo standard para ver Esperado / Diferencia fisica.
  const blindToggle = page.getByTestId('pos-cash-blind-toggle');
  if (await blindToggle.isVisible()) {
    await blindToggle.click();
  }

  await page.getByTestId('pos-cash-closing-amount').fill(String(expectedCashUsd));
  await page.getByTestId('pos-cash-closing-ves').fill(String(expectedCashVes));

  const closeResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/cash-register/sessions/${sessionId}/close`) &&
      response.request().method() === 'PATCH',
  );
  await page.getByTestId('pos-cash-close-submit').click();
  const closed = await closeResponse;
  expect(closed.status()).toBe(200);

  const payload = (await closed.json()) as {
    data: {
      status: string;
      difference_cash_usd: string | number | null;
      difference_cash_ves: string | number | null;
      counted_cash_usd: string | number | null;
      counted_cash_ves: string | number | null;
      expected_cash_usd: string | number | null;
      expected_cash_ves: string | number | null;
    };
  };

  expect(payload.data.status).toBe('closed');
  // Al contar exactamente el efectivo esperado, no hay faltante fisico.
  expect(Number(payload.data.difference_cash_usd ?? 1)).toBeCloseTo(0, 4);
  expect(Number(payload.data.difference_cash_ves ?? 1)).toBeCloseTo(0, 4);
  expect(Number(payload.data.expected_cash_usd ?? 0)).toBeCloseTo(expectedCashUsd, 4);
  expect(Number(payload.data.expected_cash_ves ?? 0)).toBeCloseTo(expectedCashVes, 4);

  await expect(page.getByRole('heading', { name: 'Abrir turno POS' })).toBeVisible();

  // Reabrir el turno para no dejar el POS cerrado.
  await page.getByTestId('pos-cash-open-branch').selectOption(String(session!.branch_id));
  await page.getByTestId('pos-cash-open-register').selectOption(String(session!.cash_register_id));
  await page.getByTestId('pos-cash-open-base').fill('0');
  await page.getByTestId('pos-cash-open-local').fill('0');
  const reopenResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/cash-register/sessions') &&
      response.request().method() === 'POST',
  );
  await page.getByTestId('pos-cash-open-submit').click();
  expect((await reopenResponse).status()).toBe(201);
});
