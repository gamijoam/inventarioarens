import { defineConfig } from '@playwright/test';
import path from 'node:path';

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
    navigationTimeout: 30_000,
  },
  webServer:
    process.env.PLAYWRIGHT_MANAGED_SERVERS === '1'
      ? [
          {
            command: 'CACHE_STORE=array php artisan serve --host=127.0.0.1 --port=8000',
            cwd: path.resolve(process.cwd(), '..'),
            url: 'http://127.0.0.1:8000/up',
            reuseExistingServer: true,
            timeout: 120_000,
          },
          {
            command:
              'VITE_API_BASE_URL=http://127.0.0.1:8000/api pnpm dev --host 127.0.0.1 --port 5173',
            cwd: process.cwd(),
            url: 'http://127.0.0.1:5173/login',
            reuseExistingServer: true,
            timeout: 120_000,
          },
        ]
      : undefined,
  projects: [
    {
      name: 'api',
      // Sin browser: usa `request` para tests HTTP puros.
      testMatch: '**/*.api.spec.ts',
      use: {},
    },
    {
      name: 'ui',
      testMatch: '**/*.ui.spec.ts',
      use: {
        baseURL: process.env.PLAYWRIGHT_FRONTEND_URL ?? 'http://127.0.0.1:5173',
        browserName: 'chromium',
      },
    },
  ],
});
