import { expect, request, test, type APIRequestContext } from '@playwright/test';

import { getDemoCredentials, loginAsDemo, type DemoCredentials } from './support/auth';

const masterCredentials = getDemoCredentials();
const tenant = 'oscarcell-yaracall';
const restrictedPassword = 'E2eRestricted123!';

async function loginApi(baseURL: string, credentials: DemoCredentials): Promise<APIRequestContext> {
  const loginContext = await request.newContext({
    baseURL,
    extraHTTPHeaders: { Accept: 'application/json', 'X-Tenant': credentials.tenant },
  });
  const response = await loginContext.post('/api/auth/login', {
    data: { email: credentials.email, password: credentials.password },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(response.status()).toBe(201);
  const token = (await response.json()).data?.token as string | undefined;
  expect(token).toBeTruthy();
  await loginContext.dispose();

  return request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      'X-Tenant': credentials.tenant,
    },
  });
}

test('oculta acciones protegidas para un usuario con rol restringido', async ({ page }) => {
  test.skip(!masterCredentials, 'Configura PLAYWRIGHT_E2E_* para probar permisos visuales.');

  const apiBaseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000';
  const master = await loginApi(apiBaseURL, masterCredentials!);
  const roleName = `E2E Vendedor Restringido ${Date.now()}`;
  const roleResponse = await master.post('/api/roles', {
    data: {
      name: roleName,
      permissions: [
        'products.view',
        'branches.view',
        'warehouses.view',
        'customers.view',
        'sales.view',
        'sales_returns.view',
        'sales_returns.create',
        'inventory_transfers.view',
        'cash_register.view',
        'pos.view',
        'pos.checkout',
      ],
    },
  });
  expect(roleResponse.status()).toBe(201);

  const restrictedEmail = `e2e-restricted-${Date.now()}@test.local`;
  const userResponse = await master.post('/api/users', {
    data: {
      name: 'E2E Usuario Restringido',
      email: restrictedEmail,
      password: restrictedPassword,
      roles: [roleName],
    },
  });
  expect(userResponse.status()).toBe(201);
  await master.dispose();

  const restrictedCredentials: DemoCredentials = {
    email: restrictedEmail,
    password: restrictedPassword,
    tenant,
  };
  await loginAsDemo(page, restrictedCredentials);

  const restrictedApi = await loginApi(apiBaseURL, restrictedCredentials);
  const forbiddenRoleCreation = await restrictedApi.post('/api/roles', {
    data: { name: `E2E Forbidden ${Date.now()}`, permissions: [] },
  });
  expect(forbiddenRoleCreation.status()).toBe(403);
  await restrictedApi.dispose();

  await expect(page.getByRole('link', { name: /Acceso/ })).toHaveCount(0);

  await page.goto('/transfers');
  await expect(page.getByRole('heading', { name: 'Traslados' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Nuevo traslado', exact: true })).toHaveCount(0);

  await page.goto('/sales-returns');
  await expect(page.getByRole('heading', { name: 'Devoluciones' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Procesar devolución', exact: true })).toHaveCount(
    0,
  );
});
