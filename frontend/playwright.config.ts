import { defineConfig } from '@playwright/test';

/**
 * Playwright config para E2E del POS.
 *
 * Tenemos dos proyectos:
 *
 * - `api`: tests sin browser que usan `request` de Playwright. Sirven
 *   para validar el flujo completo del POS (login, bootstrap, checkout
 *   con idempotency) sin necesidad de instalar browsers. Rapidos y
 *   faciles de correr en CI.
 *
 * - `ui`: tests con browser (chromium) que validan el flujo visual del
 *   POS. Requieren `pnpm e2e:install` (descarga chromium ~150MB).
 *
 * El backend esperado corre en `BASE_URL` (default http://127.0.0.1:8000).
 * El frontend (vite) corre en `FRONTEND_URL` (default http://127.0.0.1:5173).
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
    extraHTTPHeaders: {
      Accept: 'application/json',
    },
    actionTimeout: 10_000,
    navigationTimeout: 10_000,
  },
  projects: [
    {
      name: 'api',
      // Sin browser: usa `request` para tests HTTP puros.
      use: {},
    },
    {
      name: 'ui',
      testIgnore: '**/*.api.spec.ts',
      use: {
        baseURL: process.env.PLAYWRIGHT_FRONTEND_URL ?? 'http://127.0.0.1:5173',
        browserName: 'chromium',
      },
    },
  ],
});
