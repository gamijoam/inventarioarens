import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test.describe('POS UI smoke flow', () => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para el smoke test UI.');

  test('inicia sesión y carga el terminal POS', async ({ page }) => {
    await loginAsDemo(page, credentials!);
    await page.goto('/pos');
    await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();
    await expect(page.getByTestId('pos-search')).toBeVisible();
    await expect(page.getByRole('button', { name: /Cobrar/ }).last()).toBeVisible();
  });
});
