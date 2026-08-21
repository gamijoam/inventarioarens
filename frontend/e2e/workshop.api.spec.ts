import { expect, request, test, type APIRequestContext } from '@playwright/test';

/**
 * Test E2E del Taller sin browser (proyecto `api`, usa `request` de Playwright).
 *
 * Cubre el ciclo completo de una orden de servicio:
 * - Login + crear orden (repair) -> received + numero SO-XXXXXX.
 * - Diagnosticar con mano de obra -> diagnosed.
 * - Asignar tecnico.
 * - Agregar pieza del inventario (con stock).
 * - Completar -> delivered, stock descontado.
 *
 * Prerequisitos:
 * - Backend Laravel corriendo en http://127.0.0.1:8000
 * - Migrations + DemoDataSeeder + RolesAndPermissionsSeeder aplicados
 *   (el rol Gerente debe tener service_orders.*).
 *
 * Ejecucion: cd frontend && pnpm e2e -- --project=api
 */

const DEMO_EMAIL = process.env.PLAYWRIGHT_E2E_EMAIL ?? 'gerente.valencia@demo.test';
const DEMO_PASSWORD = process.env.PLAYWRIGHT_E2E_PASSWORD ?? 'gabo1234';
const DEMO_TENANT = process.env.PLAYWRIGHT_E2E_TENANT ?? 'demo-valencia-centro';
const E2E_PREFIX = `E2E-${Date.now()}`;

let api: APIRequestContext;
let token: string;
let warehouseId: number;
let productId: number;
let productSku: string;
let orderId: number;

async function authedContext(baseURL: string): Promise<APIRequestContext> {
  const ctx = await request.newContext({
    baseURL,
    extraHTTPHeaders: { Accept: 'application/json', 'X-Tenant': DEMO_TENANT },
  });
  const loginRes = await ctx.post('/api/auth/login', {
    data: { email: DEMO_EMAIL, password: DEMO_PASSWORD },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(loginRes.status(), 'login status').toBe(201);
  const body = await loginRes.json();
  token = body.data?.token as string;
  expect(token, 'login returns token').toBeTruthy();

  return request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Tenant': DEMO_TENANT,
      Authorization: `Bearer ${token}`,
    },
  });
}

test.describe('Taller E2E flow (API)', () => {
  test.beforeAll(async ({ baseURL }) => {
    api = await authedContext(baseURL);

    // Asegurar un almacen para la orden.
    const warehouses = await api.get('/api/warehouses?limit=100');
    expect(warehouses.status()).toBe(200);
    const whBody = (await warehouses.json()) as { data: Array<{ id: number }> };
    warehouseId = whBody.data[0]?.id;
    if (!warehouseId) {
      const branches = await api.get('/api/branches?limit=100');
      const branchBody = (await branches.json()) as { data: Array<{ id: number }> };
      const branchId = branchBody.data[0]?.id;
      expect(branchId, 'demo requiere al menos una sucursal').toBeTruthy();
      const created = await api.post('/api/warehouses', {
        data: {
          branch_id: branchId,
          code: `WH-${E2E_PREFIX}`,
          name: `Almacen ${E2E_PREFIX}`,
          status: 'active',
        },
      });
      expect(created.status()).toBe(201);
      warehouseId = ((await created.json()) as { data: { id: number } }).data.id;
    }

    // Asegurar un producto con stock para la pieza: si ninguno tiene, crear + entrada.
    const products = await api.get('/api/products?per_page=100&stock_status=all&active_status=active');
    expect(products.status()).toBe(200);
    const prodBody = (await products.json()) as {
      data: Array<{ id: number; sku: string; available_stock: number }>;
    };
    const withStock = prodBody.data.find((p) => Number(p.available_stock) > 0);
    if (withStock) {
      productId = withStock.id;
      productSku = withStock.sku;
    } else {
      const created = await api.post('/api/products', {
        data: {
          name: `Pieza ${E2E_PREFIX}`,
          sku: `PIEZA-${E2E_PREFIX}`,
          base_price: 15,
          sale_currency: 'USD',
          tracking_type: 'quantity',
        },
      });
      expect(created.status()).toBe(201);
      const createdBody = (await created.json()) as { data: { id: number; sku: string } };
      productId = createdBody.data.id;
      productSku = createdBody.data.sku;
      const entry = await api.post('/api/product-entries', {
        data: {
          reason: 'Entrada E2E taller',
          items: [
            { warehouse_id: warehouseId, product_id: productId, quantity: 10, unit_cost: 8 },
          ],
        },
      });
      expect(entry.status(), 'entrada de stock para la pieza').toBe(201);
    }
  });

  test('ciclo completo de una orden de servicio', async () => {
    // 1) Crear orden (repair).
    const createRes = await api.post('/api/service-orders', {
      data: {
        type: 'repair',
        customer_name: 'Cliente E2E',
        customer_phone: '0412',
        device_description: `Equipo ${E2E_PREFIX}`,
        issue_description: 'Falla E2E',
        warehouse_id: warehouseId,
      },
    });
    expect(createRes.status()).toBe(201);
    const order = (await createRes.json()) as { data: { id: number; order_number: string; status: string } };
    orderId = order.data.id;
    expect(order.data.status).toBe('received');
    expect(order.data.order_number).toMatch(/^SO-\d{6}$/);

    // 2) Diagnosticar.
    const diagnoseRes = await api.post(`/api/service-orders/${orderId}/diagnose`, {
      data: { diagnosis: 'Cambio de pieza E2E', labor_base_amount: 25 },
    });
    expect(diagnoseRes.status()).toBe(200);
    expect(((await diagnoseRes.json()) as { data: { status: string } }).data.status).toBe('diagnosed');

    // 3) Asignar tecnico (primer usuario del tenant).
    const users = await api.get('/api/users?per_page=1');
    expect(users.status()).toBe(200);
    const technicianId = ((await users.json()) as { data: Array<{ id: number }> }).data[0]?.id;
    expect(technicianId, 'existe al menos un usuario para ser tecnico').toBeTruthy();
    const assignRes = await api.post(`/api/service-orders/${orderId}/assign-technician`, {
      data: { technician_id: technicianId, warehouse_id: warehouseId },
    });
    expect(assignRes.status()).toBe(200);
    expect(((await assignRes.json()) as { data: { technician_id: number } }).data.technician_id).toBe(technicianId);

    // 4) Agregar pieza.
    const partRes = await api.post(`/api/service-orders/${orderId}/parts`, {
      data: { product_id: productId, quantity: 2 },
    });
    expect(partRes.status()).toBe(201);
    const part = (await partRes.json()) as { data: { id: number; status: string; unit_cost: number } };
    expect(part.data.status).toBe('pending');

    // 5) Completar -> delivered + stock descontado.
    const beforeStock = await api.get(
      `/api/products?search=${encodeURIComponent(productSku)}&warehouse_id=${warehouseId}&per_page=1`,
    );
    const beforeAvailable = Number(
      ((await beforeStock.json()) as { data: Array<{ available_stock: number }> }).data[0]
        ?.available_stock ?? 0,
    );

    const completeRes = await api.post(`/api/service-orders/${orderId}/complete`);
    expect(completeRes.status()).toBe(200);
    expect(((await completeRes.json()) as { data: { status: string } }).data.status).toBe('delivered');

    const afterStock = await api.get(
      `/api/products?search=${encodeURIComponent(productSku)}&warehouse_id=${warehouseId}&per_page=1`,
    );
    const afterAvailable = Number(
      ((await afterStock.json()) as { data: Array<{ available_stock: number }> }).data[0]
        ?.available_stock ?? 0,
    );
    expect(afterAvailable).toBe(beforeAvailable - 2);
  });
});