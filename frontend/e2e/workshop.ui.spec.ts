import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

/**
 * Test E2E del Taller con browser (proyecto `ui`, chromium).
 *
 * Flujo visual: login -> /workshop -> crear una orden de reparacion ->
 * verla en la bandeja -> expandir -> diagnosticar -> completar.
 *
 * Prerequisitos:
 * - Backend Laravel en http://127.0.0.1:8000 y Vite en http://127.0.0.1:5173
 *   (pnpm e2e:ui gestiona ambos con PLAYWRIGHT_MANAGED_SERVERS=1).
 * - Roles re-sembrados para que el demo user tenga service_orders.*.
 *
 * Ejecucion: cd frontend && pnpm e2e -- --project=ui
 */

const credentials = getDemoCredentials();

test('crea, diagnostica y completa una orden de taller', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar el Taller.');

  await loginAsDemo(page, credentials!);

  await page.goto('/workshop');
  await expect(page.getByRole('heading', { name: 'Taller' })).toBeVisible();

  await page.getByRole('button', { name: 'Nueva orden' }).click();
  await expect(page.getByRole('heading', { name: 'Nueva orden de taller' })).toBeVisible();

  const prefix = `E2E-UI-${Date.now()}`;
  await page.getByTestId('ws-create-customer').fill(`Cliente ${prefix}`);
  await page.getByTestId('ws-create-device').fill(`Equipo ${prefix}`);
  await page.getByTestId('ws-create-issue').fill(`Falla ${prefix}`);
  await page.getByTestId('ws-create-warehouse').selectOption({ index: 1 });

  const createResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/service-orders') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Crear orden', exact: true }).click();
  const create = await createResponse;
  expect(create.status()).toBe(201);
  const created = (await create.json()) as { data: { id: number; order_number: string; status: string } };
  expect(created.data.status).toBe('received');

  // La orden aparece en la bandeja.
  await expect(page.getByText(created.data.order_number)).toBeVisible();

  // Expandir y diagnosticar.
  await page.getByText(created.data.order_number).click();
  await page.getByTestId('ws-diagnose-text').fill('Cambio de pieza E2E UI');
  await page.getByTestId('ws-diagnose-labor').fill('30');
  const diagnoseResponse = page.waitForResponse(
    (response) => response.url().includes('/diagnose') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Guardar', exact: true }).click();
  await (await diagnoseResponse).json();

  // El badge de la fila pasa a Diagnosticado.
  await expect(
    page.getByTestId(`workshop-row-${created.data.id}`).getByText('Diagnosticado'),
  ).toBeVisible();

  // Completar.
  await page.getByRole('button', { name: 'Completar y entregar', exact: true }).click();
  await expect(
    page.getByTestId(`workshop-row-${created.data.id}`).getByText('Entregado'),
  ).toBeVisible();
});

test('garantia exige tratamiento en la UI (boton deshabilitado)', async ({ page }) => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para probar el Taller.');

  await loginAsDemo(page, credentials!);
  await page.goto('/workshop');
  await expect(page.getByRole('heading', { name: 'Taller' })).toBeVisible();

  await page.getByRole('button', { name: 'Nueva orden' }).click();
  await page.getByTestId('ws-create-type').selectOption('warranty');
  await page.getByTestId('ws-create-warehouse').selectOption({ index: 1 });

  const crear = page.getByRole('button', { name: 'Crear orden', exact: true });
  await expect(crear).toBeDisabled();

  // Elegir tratamiento workshop -> se habilita.
  await page.getByTestId('ws-create-resolution').selectOption('workshop');
  await expect(crear).toBeEnabled();
});