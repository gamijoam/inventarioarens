import { expect, test } from '@playwright/test';

import { getDemoCredentials, loginAsDemo } from './support/auth';

const credentials = getDemoCredentials();

test.describe('POS Embedded Shell UI', () => {
  test.skip(!credentials, 'Configura las variables PLAYWRIGHT_E2E_* para el test.');

  test('conserva el menú lateral izquierdo al navegar a /pos y permite navegación administrativa', async ({
    page,
  }) => {
    await loginAsDemo(page, credentials!);

    // Navegar a POS
    await page.goto('/pos');
    await expect(page).toHaveURL(/\/pos$/);

    // El menú lateral administrativo (Sidebar) DEBE permanecer visible
    const sidebar = page.getByTestId('admin-sidebar');
    await expect(sidebar).toBeVisible();

    // La barra superior administrativa (Topbar) DEBE permanecer visible
    const topbar = page.getByTestId('admin-topbar');
    await expect(topbar).toBeVisible();

    // El POS está embebido dentro del layout
    const posHeader = page.getByRole('header', { name: 'POS' });
    await expect(posHeader).toBeVisible();

    // No debe existir el botón flotante de escape que cierra sesión
    await expect(page.getByRole('button', { name: 'Salir del POS' })).toHaveCount(0);

    // Navegación hacia otra sección usando el menú lateral sin perder sesión
    const inventoryLink = sidebar.getByRole('link', { name: /Inventario|Catálogo/i }).first();
    if (await inventoryLink.isVisible()) {
      await inventoryLink.click();
      await expect(page).not.toHaveURL(/\/login/);
      await expect(sidebar).toBeVisible();
    }
  });
});
