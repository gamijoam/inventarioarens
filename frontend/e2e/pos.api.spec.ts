import { test, expect, request, type APIRequestContext } from '@playwright/test';

/**
 * Test E2E del flujo POS sin browser (usa `request` de Playwright).
 *
 * Cubre los sprints 1-4 sin necesidad de instalar chromium:
 * - Sprint 1: /api/pos/bootstrap reduce 8 requests a 1
 * - Sprint 2: paginacion server-side + eager load (categories.parent)
 * - Sprint 3: Idempotency-Key en /api/pos/checkouts
 * - Sprint 4: /api/inventory-center/products/{id}/stock-context
 *            + /api/pos/orders?cash_register_session_id=X
 *
 * Prerequisitos:
 * - Backend Laravel corriendo en http://127.0.0.1:8000
 * - Migrations aplicadas (php artisan migrate)
 * - Seeder demo cargado (php artisan db:seed --class=MultiCompanyLoginDemoSeeder)
 * - Sesion de caja abierta para gerente.valencia en demo-valencia-centro
 *   (la crea el seeder automaticamente)
 *
 * Ejecucion:
 *   cd frontend && pnpm e2e -- --project=api
 */

const DEMO_EMAIL = 'gerente.valencia@demo.test';
const DEMO_PASSWORD = 'gabo1234';
const DEMO_TENANT = 'demo-valencia-centro';
const IDEM_PREFIX = 'e2e-pos-';

// Variables a nivel de modulo para que se compartan entre tests
// (el closure de describe no siempre persiste entre tests con workers>0).
let api: APIRequestContext;
let token: string;
let sessionId: number;
let productId: number;
let productPrice: number;
let warehouseId: number;
const idemKeys: string[] = [];

test.describe('POS E2E flow (API)', () => {
  test.beforeAll(async ({ baseURL }) => {
    api = await request.newContext({
      baseURL,
      extraHTTPHeaders: { Accept: 'application/json', 'X-Tenant': DEMO_TENANT },
    });

    // 1) Login: el backend emite cookie httpOnly si viene con
    //    X-Requested-With: XMLHttpRequest. Aqui lo dejamos con
    //    Bearer token porque el endpoint emite ambos.
    const loginRes = await api.post('/api/auth/login', {
      data: { email: DEMO_EMAIL, password: DEMO_PASSWORD },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    expect(loginRes.status(), 'login status').toBe(201);
    const loginBody = await loginRes.json();
    expect(loginBody.data?.token, 'login returns token').toBeTruthy();
    token = loginBody.data.token;
    api = await request.newContext({
      baseURL,
      extraHTTPHeaders: {
        Accept: 'application/json',
        'X-Tenant': DEMO_TENANT,
        Authorization: `Bearer ${token}`,
      },
    });
  });

  test.afterAll(async () => {
    await api?.dispose();
  });

  test('Sprint 1: GET /api/pos/bootstrap reemplaza multiples requests', async () => {
    const res = await api.get('/api/pos/bootstrap');
    expect(res.status(), 'bootstrap status').toBe(200);
    const body = await res.json();
    expect(body).toHaveProperty('warehouses');
    expect(body).toHaveProperty('branches');
    expect(body).toHaveProperty('cash_registers');
    expect(body).toHaveProperty('payment_methods');
    expect(body).toHaveProperty('price_lists');
    expect(body).toHaveProperty('exchange_rate_types');
    expect(body).toHaveProperty('exchange_rates');
    expect(body).toHaveProperty('open_session');

    // Asignamos la sesion y warehouse del primer item disponible
    // para los tests siguientes.
    expect(body.open_session).toBeTruthy();
    sessionId = body.open_session.id;
    expect(body.warehouses.length).toBeGreaterThan(0);
    warehouseId = body.warehouses[0].id;
  });

  test('Sprint 2: GET /api/products?per_page=25 pagina server-side y eager-loads categories.parent', async () => {
    const res = await api.get('/api/products?per_page=25');
    expect(res.status(), 'products status').toBe(200);
    const body = await res.json();
    expect(body.data).toBeInstanceOf(Array);
    expect(body.data.length).toBeGreaterThan(0);
    expect(body.data.length).toBeLessThanOrEqual(25);
    expect(body.meta).toHaveProperty('total');
    expect(body.meta).toHaveProperty('current_page');
    // El eager load categories.parent garantiza que NO se hace 1 query
    // por categoria padre (N+1 fix).
    const withCategories = body.data.find((p: any) => p.categories.length > 0);
    if (withCategories) {
      for (const c of withCategories.categories) {
        // Si tiene parent cargado, la propiedad existe aunque sea null.
        expect('parent' in c).toBe(true);
      }
    }
    // Guardamos el primer producto con stock disponible. Si los tests E2E
    // anteriores agotaron el stock, el primer producto puede tener 0.
    // Probamos hasta encontrar uno con stock > 0.
    const withStock = body.data.find((p: any) => Number(p.available_stock ?? 0) > 0);
    if (!withStock) {
      throw new Error(
        'No hay productos con stock disponible en el tenant demo. ' +
          'Ejecuta `php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force` para resetear.',
      );
    }
    productId = withStock.id;
    productPrice = Number(withStock.base_price ?? 0);
    // Si el warehouseId ya no tiene stock del nuevo producto, elegimos
    // uno que sí tenga.
    if (Number(withStock.available_stock ?? 0) <= 0) {
      throw new Error('Producto seleccionado sin stock: ' + productId);
    }
  });

  test('Sprint 4: GET /api/inventory-center/products/{id}/stock-context devuelve el contexto completo', async () => {
    const res = await api.get(
      `/api/inventory-center/products/${productId}/stock-context?warehouse_id=${warehouseId}`,
    );
    expect(res.status(), 'stock-context status').toBe(200);
    const body = await res.json();
    expect(body.data).toHaveProperty('product_id', productId);
    expect(body.data).toHaveProperty('warehouse_id', warehouseId);
    expect(body.data).toHaveProperty('available');
    expect(body.data).toHaveProperty('reserved');
    expect(body.data).toHaveProperty('other_warehouses');
    expect(body.data).toHaveProperty('total_other');
    expect(body.data).toHaveProperty('total_all_warehouses');
    expect(typeof body.data.available).toBe('number');
  });

  test('Sprint 3: POST /api/pos/checkouts con Idempotency-Key reutiliza respuesta en retry', async () => {
    const idemKey = `${IDEM_PREFIX}${Date.now()}-1`;
    idemKeys.push(idemKey);
    // Pagamos el precio exacto del producto (pago exacto, no excedente)
    // para evitar el error "El cobro supera el saldo pendiente".
    const body = {
      cash_register_session_id: sessionId,
      items: [{ warehouse_id: warehouseId, product_id: productId, quantity: 1 }],
      payments: [{ method: 'cash', currency: 'USD', amount: productPrice }],
    };

    // 1ra request: crea la orden.
    const r1 = await api.post('/api/pos/checkouts', {
      data: body,
      headers: { 'Idempotency-Key': idemKey },
    });
    expect(r1.status(), 'first checkout status').toBe(201);
    const order1 = (await r1.json()).data;
    expect(order1.id).toBeGreaterThan(0);

    // 2da request con mismo key+body: misma response sin duplicar venta.
    const r2 = await api.post('/api/pos/checkouts', {
      data: body,
      headers: { 'Idempotency-Key': idemKey },
    });
    expect(r2.status(), 'second checkout status').toBe(201);
    const order2 = (await r2.json()).data;
    expect(order2.id, 'mismo order_id en retry idempotente').toBe(order1.id);
  });

  test('Sprint 3: mismo Idempotency-Key con body distinto devuelve 409', async () => {
    const idemKey = `${IDEM_PREFIX}${Date.now()}-conflict`;
    idemKeys.push(idemKey);
    const body1 = {
      cash_register_session_id: sessionId,
      items: [{ warehouse_id: warehouseId, product_id: productId, quantity: 1 }],
      payments: [{ method: 'cash', currency: 'USD', amount: productPrice }],
    };
    const body2 = {
      ...body1,
      items: [{ warehouse_id: warehouseId, product_id: productId, quantity: 2 }],
      payments: [{ method: 'cash', currency: 'USD', amount: productPrice * 2 }],
    };

    // 1ra: crea la reserva de key.
    const r1 = await api.post('/api/pos/checkouts', {
      data: body1,
      headers: { 'Idempotency-Key': idemKey },
    });
    expect(r1.status(), 'first status').toBeLessThan(500);

    // 2da: distinto body con misma key -> 409.
    const r2 = await api.post('/api/pos/checkouts', {
      data: body2,
      headers: { 'Idempotency-Key': idemKey },
    });
    expect(r2.status(), 'conflict status').toBe(409);
  });

  test('Sprint 4: GET /api/pos/orders?cash_register_session_id=X devuelve los recibos de la sesion', async () => {
    const res = await api.get(`/api/pos/orders?cash_register_session_id=${sessionId}&per_page=10`);
    expect(res.status(), 'orders status').toBe(200);
    const body = await res.json();
    expect(body.data).toBeInstanceOf(Array);
    // Las ordenes de la sesion incluyen las que acabamos de crear
    // (pagadas) y posiblemente alguna abierta.
    for (const order of body.data) {
      expect(order.cash_register_session_id).toBe(sessionId);
    }
  });
});
