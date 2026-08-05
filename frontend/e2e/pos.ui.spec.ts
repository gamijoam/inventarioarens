import { expect, test } from '@playwright/test';

const email = process.env.PLAYWRIGHT_E2E_EMAIL;
const password = process.env.PLAYWRIGHT_E2E_PASSWORD;
const tenant = process.env.PLAYWRIGHT_E2E_TENANT;

test.describe('POS UI smoke flow', () => {
  test.skip(
    !email || !password || !tenant,
    'Configura PLAYWRIGHT_E2E_EMAIL, PLAYWRIGHT_E2E_PASSWORD y PLAYWRIGHT_E2E_TENANT.',
  );

  test('inicia sesión y carga el terminal POS', async ({ page }) => {
    await page.goto('/login');
    await page.getByTestId('login-email').fill(email!);
    await expect(page.getByText(tenant!, { exact: false })).toBeVisible();
    await page.getByTestId('login-password').fill(password!);
    await page.getByTestId('login-submit').click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/pos');
    await expect(page.getByRole('heading', { name: 'POS' })).toBeVisible();
    await expect(page.getByTestId('pos-search')).toBeVisible();
    await expect(page.getByRole('button', { name: /Cobrar/ }).last()).toBeVisible();
  });
});
