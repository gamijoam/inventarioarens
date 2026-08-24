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
  await expect
    .poll(
      async () => {
        const picker = page.getByTestId('login-tenant');
        if ((await picker.count()) > 0 && (await picker.isVisible())) return 'picker';

        const matches = page.getByText(credentials.tenant, { exact: false });
        for (let index = 0; index < (await matches.count()); index += 1) {
          if (await matches.nth(index).isVisible()) return 'single';
        }

        return 'pending';
      },
      { timeout: 10_000 },
    )
    .not.toBe('pending');
  const tenantPicker = page.getByTestId('login-tenant');
  if (await tenantPicker.count()) {
    await expect(tenantPicker).toBeVisible();
    await tenantPicker.selectOption(credentials.tenant);
  } else {
    await expect(page.getByText(credentials.tenant, { exact: false })).toBeVisible();
  }
  await page.getByTestId('login-password').fill(credentials.password);
  await page.getByTestId('login-submit').click();
  const expectedHome = process.env.PLAYWRIGHT_APP_MODE === 'pos' ? /\/pos$/ : /\/dashboard$/;
  await expect(page).toHaveURL(expectedHome);
}
