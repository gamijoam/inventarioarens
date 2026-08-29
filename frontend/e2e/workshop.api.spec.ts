import { expect, request, test, type APIRequestContext } from '@playwright/test';

/**
 * Test E2E del Taller sin browser (proyecto `api`, usa `request` de Playwright).
 *
 * Replica los casos del backend (ServiceOrderApiTest):
 *  - Ciclo completo: crear -> diagnosticar -> asignar tecnico -> pieza -> completar
 *    (delivered + stock descontado).
 *  - Garantia: exige treatment (422 sin resolution) y acepta los 3 tratamientos.
 *  - Pieza sin stock -> 422.
 *  - Transicion invalida (completar desde received) -> 422.
 *  - Cancelar orden -> cancelled.
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

async function createOrder(payload: Record<string, unknown>): Promise<{ id: number; order_number: string; status: string }> {
  const res = await api.post('/api/service-orders', { data: payload });
  expect(res.status()).toBe(201);
  return (await res.json()).data;
}

async function availableStock(): Promise<number> {
  const res = await api.get(
    `/api/products?search=${encodeURIComponent(productSku)}&warehouse_id=${warehouseId}&per_page=1`,
  );
  return Number((await res.json()).data[0]?.available_stock ?? 0);
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

  test('ciclo completo: crear, diagnosticar, tecnico, pieza, completar (stock descontado)', async () => {
    const order = await createOrder({
      type: 'repair',
      customer_name: 'Cliente E2E',
      customer_phone: '0412',
      device_description: `Equipo ${E2E_PREFIX}`,
      issue_description: 'Falla E2E',
      warehouse_id: warehouseId,
    });
    expect(order.status).toBe('received');
    expect(order.order_number).toMatch(/^SO-\d{6}$/);

    const diagnoseRes = await api.post(`/api/service-orders/${order.id}/diagnose`, {
      data: { diagnosis: 'Cambio de pieza E2E', labor_base_amount: 25 },
    });
    expect(diagnoseRes.status()).toBe(200);
    expect(((await diagnoseRes.json()) as { data: { status: string } }).data.status).toBe('diagnosed');

    const users = await api.get('/api/users?per_page=1');
    expect(users.status()).toBe(200);
    const technicianId = ((await users.json()) as { data: Array<{ id: number }> }).data[0]?.id;
    expect(technicianId, 'existe al menos un usuario para ser tecnico').toBeTruthy();
    const assignRes = await api.post(`/api/service-orders/${order.id}/assign-technician`, {
      data: { technician_id: technicianId, warehouse_id: warehouseId },
    });
    expect(assignRes.status()).toBe(200);
    expect(((await assignRes.json()) as { data: { technician_id: number } }).data.technician_id).toBe(technicianId);

    const partRes = await api.post(`/api/service-orders/${order.id}/parts`, {
      data: { product_id: productId, quantity: 2 },
    });
    expect(partRes.status()).toBe(201);
    expect(((await partRes.json()) as { data: { status: string } }).data.status).toBe('pending');

    const before = await availableStock();
    const completeRes = await api.post(`/api/service-orders/${order.id}/complete`);
    expect(completeRes.status()).toBe(200);
    expect(((await completeRes.json()) as { data: { status: string } }).data.status).toBe('delivered');
    expect(await availableStock()).toBe(before - 2);
  });

  test('garantia sin treatment -> 422', async () => {
    const res = await api.post('/api/service-orders', {
      data: {
        type: 'warranty',
        customer_name: 'Cliente',
        device_description: 'Equipo en garantia',
        warehouse_id: warehouseId,
      },
    });
    expect(res.status()).toBe(422);
  });

  for (const resolution of ['workshop', 'exchange', 'return_supplier']) {
    test(`garantia con treatment ${resolution} -> 201`, async () => {
      const order = await createOrder({
        type: 'warranty',
        resolution,
        customer_name: 'Cliente',
        device_description: 'Equipo en garantia',
        warehouse_id: warehouseId,
      });
      expect(order.resolution).toBe(resolution);
    });
  }

  test('pieza sin stock -> 422', async () => {
    // Producto nuevo sin stock.
    const created = await api.post('/api/products', {
      data: {
        name: `SinStock ${E2E_PREFIX}`,
        sku: `SINSTOCK-${E2E_PREFIX}`,
        base_price: 10,
        sale_currency: 'USD',
        tracking_type: 'quantity',
      },
    });
    expect(created.status()).toBe(201);
    const noStockProduct = ((await created.json()) as { data: { id: number } }).data.id;

    const order = await createOrder({
      type: 'repair',
      customer_name: 'Cliente',
      warehouse_id: warehouseId,
    });

    const partRes = await api.post(`/api/service-orders/${order.id}/parts`, {
      data: { product_id: noStockProduct, quantity: 1 },
    });
    expect(partRes.status()).toBe(422);
  });

  test('transicion invalida: completar desde received -> 422', async () => {
    const order = await createOrder({
      type: 'repair',
      customer_name: 'Cliente',
      warehouse_id: warehouseId,
    });
    const completeRes = await api.post(`/api/service-orders/${order.id}/complete`);
    expect(completeRes.status()).toBe(422);
  });

  test('cancelar una orden recibida -> cancelled', async () => {
    const order = await createOrder({
      type: 'repair',
      customer_name: 'Cliente',
      warehouse_id: warehouseId,
    });
    const cancelRes = await api.post(`/api/service-orders/${order.id}/cancel`);
    expect(cancelRes.status()).toBe(200);
    expect(((await cancelRes.json()) as { data: { status: string } }).data.status).toBe('cancelled');
  });
});