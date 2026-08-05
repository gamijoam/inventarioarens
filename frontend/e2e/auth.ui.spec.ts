import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();
const missingCredentialsMessage =
  'Configura PLAYWRIGHT_E2E_EMAIL, PLAYWRIGHT_E2E_PASSWORD y PLAYWRIGHT_E2E_TENANT.';

test.describe('Autenticación UI', () => {
  test.skip(!credentials, missingCredentialsMessage);

  test('inicia sesión con credenciales válidas', async ({ page }) => {
    await loginAsDemo(page, credentials!);
    await expect(page.getByText('Centro ejecutivo', { exact: false })).toBeVisible();
  });

  test('muestra un error con contraseña inválida', async ({ page }) => {
    test.skip(
      process.env.PLAYWRIGHT_RUN_NEGATIVE_AUTH !== '1',
      'Activa PLAYWRIGHT_RUN_NEGATIVE_AUTH=1 para ejecutar el caso negativo sin contaminar el smoke suite.',
    );
    await page.goto('/login');
    await page.getByTestId('login-email').fill(credentials!.email);
    await expect(page.getByText(credentials!.tenant, { exact: false })).toBeVisible();
    await page.getByTestId('login-password').fill(`${credentials!.password}-incorrecta`);
    await page.getByTestId('login-submit').click();

    await expect(page.getByText('No pudimos iniciar sesión')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
  });
});
