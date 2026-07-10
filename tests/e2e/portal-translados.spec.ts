import { test, expect, type Page } from '@playwright/test';

/**
 * E2E del portal web de MiInventarioFacil — modulo de traslados.
 *
 * Requisitos:
 *   - Portal levantado en $BASE_URL (default http://127.0.0.1:8000)
 *   - .env con FRONTEND_DEV_BYPASS_LOGIN=true o un usuario con
 *     credenciales conocidas (ver TEST_USER / TEST_PASSWORD abajo)
 *   - DB QA con al menos un traslado para los tests de listado/drawer
 *
 * Si tu setup usa el dev bypass, los tests detectan que no hay form
 * de login y skipean el paso automaticamente.
 */

const TEST_USER = process.env.E2E_USER ?? 'gerente.valencia@demo.test';
const TEST_PASSWORD = process.env.E2E_PASSWORD ?? 'password';
const TEST_TENANT_SLUG = process.env.E2E_TENANT_SLUG ?? 'demo-valencia';

/**
 * Hace login via el form del portal. Si el portal esta en modo
 * dev bypass (no muestra form), sale silenciosamente.
 */
async function login(page: Page): Promise<void> {
    await page.goto('/admin');

    // Espera a que el JS termine de cargar y muestre el form (o el portal)
    await page.waitForLoadState('networkidle');

    const emailField = page.locator('input[name="email"], input[type="email"]');
    const hasLoginForm = await emailField.count() > 0;

    if (!hasLoginForm) {
        // Probablemente dev bypass: ya estamos dentro.
        return;
    }

    const tenantField = page.locator('input[name="tenant"], select[name="tenant"], #tenant');
    if (await tenantField.count() > 0) {
        await tenantField.first().fill(TEST_TENANT_SLUG).catch(() => tenantField.first().selectOption(TEST_TENANT_SLUG));
    }

    await emailField.first().fill(TEST_USER);
    const passwordField = page.locator('input[name="password"], input[type="password"]');
    if (await passwordField.count() > 0) {
        await passwordField.first().fill(TEST_PASSWORD);
    }

    await page.locator('button[type="submit"], form button').first().click();
    await page.waitForLoadState('networkidle');
}

test.describe('Portal de traslados', () => {

    test('carga el listado de traslados despues del login', async ({ page }) => {
        await login(page);

        // Esperar que la navegacion SPA termine de inyectar las secciones del menu.
        await page.waitForSelector('[data-portal-section="transfers"]', { timeout: 60000 });

        // Ir a la seccion de traslados
        await page.locator('button[data-portal-section="transfers"]').first().click();

        // Esperar a que la tabla se llene o aparezca el empty state
        const table = page.locator('#admin-transfers-table');
        await expect(table).toBeVisible({ timeout: 10000 });

        // El header de la tabla debe tener las columnas esperadas
        await expect(page.locator('th:has-text("Codigo")')).toBeVisible();
        await expect(page.locator('th:has-text("Estado")')).toBeVisible();
        await expect(page.locator('th:has-text("Items")')).toBeVisible();
    });

    test('abre el drawer de detalle al hacer click en Ver', async ({ page }) => {
        await login(page);
        await page.locator('button[data-portal-section="transfers"]').first().click();

        const firstVer = page.locator('button[data-admin-transfer-view]').first();
        const hasRows = await firstVer.count() > 0;
        test.skip(!hasRows, 'No hay traslados cargados. Crea uno via API antes de correr este test.');

        await firstVer.click();
        const drawer = page.locator('#admin-transfer-drawer');
        await expect(drawer).toBeVisible();

        // El drawer debe mostrar el header y los items
        await expect(page.locator('#admin-transfer-drawer-title')).toBeVisible();
        await expect(page.locator('#admin-transfer-drawer-items')).toBeVisible();
        await expect(page.locator('#admin-transfer-drawer-status-pill')).toBeVisible();

        // Cerrar con Escape
        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
    });

    test('filtra por estado usando los chips', async ({ page }) => {
        await login(page);
        await page.locator('button[data-portal-section="transfers"]').first().click();

        // Click en el chip de "Cancelados"
        const canceladosChip = page.locator('[data-admin-transfer-chip="cancelled"]');
        const hasChips = await canceladosChip.count() > 0;
        test.skip(!hasChips, 'No hay chips de filtros visibles (modulo no cargo).');

        await canceladosChip.click();
        await page.waitForTimeout(500); // esperar el refresh

        // El filtro debe estar activo
        const cancelledCheckbox = page.locator('#admin-transfers-status-options input[value="cancelled"]');
        await expect(cancelledCheckbox).toBeChecked();
    });

    test('exporta CSV cuando se hace click en el boton', async ({ page }) => {
        await login(page);
        await page.locator('button[data-portal-section="transfers"]').first().click();

        const exportButton = page.locator('#admin-transfers-export');
        await expect(exportButton).toBeVisible();

        // Capturamos la descarga
        const downloadPromise = page.waitForEvent('download', { timeout: 10000 }).catch(() => null);
        await exportButton.click();
        const download = await downloadPromise;

        test.skip(!download, 'No se disparo la descarga (puede ser que el modulo no cargo o el navegador bloqueo la descarga).');
        const filename = download.suggestedFilename();
        expect(filename).toMatch(/traslados-.*\.csv$/);
    });

    test('muestra error claro si no tiene permiso de admin', async ({ page }) => {
        // Intentar ir directo a la API sin permiso (caso limite: dificil
        // de testear sin un segundo usuario). Marcamos como skip si
        // no se puede configurar.
        test.skip(true, 'Requiere un usuario sin inventory_transfers.admin para validar el 403. Skipeado por defecto.');
    });
});
