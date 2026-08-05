import { expect, request, test } from '@playwright/test';

const securityEmail = process.env.PLAYWRIGHT_SECURITY_EMAIL;
const securityPassword = process.env.PLAYWRIGHT_SECURITY_PASSWORD;
const securityTenant = process.env.PLAYWRIGHT_SECURITY_TENANT ?? 'mi-empresa';
const otherTenant = process.env.PLAYWRIGHT_SECURITY_OTHER_TENANT ?? 'oscarcell-yaracall';

test('rechaza usar un token de un tenant contra otro tenant', async ({ baseURL }) => {
  test.skip(
    !securityEmail || !securityPassword,
    'Configura PLAYWRIGHT_SECURITY_EMAIL y PLAYWRIGHT_SECURITY_PASSWORD para probar aislamiento.',
  );

  const unauthenticated = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Tenant': securityTenant,
    },
  });
  const login = await unauthenticated.post('/api/auth/login', {
    data: { email: securityEmail, password: securityPassword },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(login.status()).toBe(201);
  const token = (await login.json()).data?.token as string | undefined;
  expect(token).toBeTruthy();
  await unauthenticated.dispose();

  const authenticated = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      'X-Tenant': securityTenant,
    },
  });
  await expect((await authenticated.get('/api/pos/bootstrap')).status()).toBe(200);

  const crossTenant = await authenticated.get('/api/pos/bootstrap', {
    headers: { 'X-Tenant': otherTenant },
  });
  expect(crossTenant.status()).toBe(403);
  await authenticated.dispose();
});
