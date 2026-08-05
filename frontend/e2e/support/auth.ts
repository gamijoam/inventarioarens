import { expect, type Page } from '@playwright/test';

export type DemoCredentials = {
  email: string;
  password: string;
  tenant: string;
};

export function getDemoCredentials(): DemoCredentials | null {
  const email = process.env.PLAYWRIGHT_E2E_EMAIL;
  const password = process.env.PLAYWRIGHT_E2E_PASSWORD;
  const tenant = process.env.PLAYWRIGHT_E2E_TENANT;

  return email && password && tenant ? { email, password, tenant } : null;
}

export async function loginAsDemo(page: Page, credentials: DemoCredentials): Promise<void> {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(credentials.email);
  await expect(page.getByText(credentials.tenant, { exact: false })).toBeVisible();
  await page.getByTestId('login-password').fill(credentials.password);
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/dashboard$/);
}
