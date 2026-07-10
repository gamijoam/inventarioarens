import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config para E2E del portal web de MiInventarioFacil.
 *
 * Setup esperado:
 *   1. El portal levantado (local Laragon o server QA)
 *   2. .env tiene FRONTEND_DEV_BYPASS_LOGIN=true
 *   3. La base de datos de QA tiene un usuario gerente con
 *      `inventory_transfers.admin` (gerente.valencia@demo.test en QA)
 *
 * Para correr:
 *   pnpm install
 *   pnpm e2e:install   # descarga chromium (~150 MB)
 *   pnpm e2e
 *
 * Variables de entorno utiles:
 *   BASE_URL   URL del portal (default http://127.0.0.1:8000)
 *   E2E_USER   email del usuario dev (default gerente.valencia@demo.test)
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30000,
    expect: { timeout: 5000 },
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: process.env.BASE_URL || 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
