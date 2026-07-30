import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000/api').replace(/\/$/, '');
const tenantPrefix = __ENV.STRESS_PREFIX || 'loadtest';
const tenantCount = Number(__ENV.STRESS_TENANTS || 3);
const productCount = Number(__ENV.STRESS_PRODUCTS || 100);
const password = __ENV.STRESS_PASSWORD;
const vus = Number(__ENV.STRESS_POS_VUS || 6);
const iterations = Number(__ENV.STRESS_POS_ITERATIONS || 5);
const checkoutP95Limit = Number(__ENV.STRESS_POS_P95_MS || 3000);

const checkoutLatency = new Trend('inventoryarens_pos_checkout_latency', true);
const checkoutSuccess = new Rate('inventoryarens_pos_checkout_ok');
const serializedCheckoutSuccess = new Rate('inventoryarens_pos_serialized_checkout_ok');
const idempotencyViolations = new Counter('inventoryarens_pos_idempotency_violations');

if (!password) {
  throw new Error('Define STRESS_PASSWORD antes de ejecutar la prueba POS.');
}

if (/app\.miinventariofacil\.com/i.test(baseUrl) && __ENV.STRESS_ALLOW_PRODUCTION !== 'yes') {
  throw new Error('El destino es produccion. Define STRESS_ALLOW_PRODUCTION=yes solo durante una ventana aprobada.');
}

export const options = {
  scenarios: {
    pos_cash_and_serialized_inventory: {
      executor: 'per-vu-iterations',
      vus,
      iterations,
      maxDuration: '5m',
      gracefulStop: '20s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: [`p(95)<${checkoutP95Limit}`, 'p(99)<5000'],
    inventoryarens_pos_checkout_latency: [`p(95)<${checkoutP95Limit}`],
    inventoryarens_pos_checkout_ok: ['rate==1'],
    inventoryarens_pos_serialized_checkout_ok: ['rate==1'],
  },
};

function tenantAt(index) {
  return `${tenantPrefix}-${String(index).padStart(2, '0')}`;
}

function headers(tenant, token, extra = {}) {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Tenant': tenant,
    'X-Requested-With': 'XMLHttpRequest',
    Authorization: `Bearer ${token}`,
    ...extra,
  };
}

function get(path, tenant, token, name) {
  return http.get(`${baseUrl}${path}`, {
    headers: headers(tenant, token),
    tags: { name, tenant },
  });
}

function checkout(tenant, token, payload, idempotencyKey) {
  const response = http.post(`${baseUrl}/pos/checkouts`, JSON.stringify(payload), {
    headers: headers(tenant, token, { 'Idempotency-Key': idempotencyKey }),
    tags: { name: 'POST /pos/checkouts', tenant },
  });
  checkoutLatency.add(response.timings.duration, { tenant });
  return response;
}

function findProduct(tenant, token, sku) {
  const response = get(`/products?search=${encodeURIComponent(sku)}&per_page=10`, tenant, token, 'GET /products POS seed');
  const products = response.status === 200 ? response.json('data') : [];
  const product = Array.isArray(products) ? products.find((item) => item.sku === sku) : null;
  if (!product) {
    throw new Error(`No se encontro ${sku}; vuelve a ejecutar stress:seed con el mismo prefijo.`);
  }
  return product;
}

export function setup() {
  const sessions = { runId: String(Date.now()) };
  const serializedSuffix = String(productCount).padStart(4, '0');

  for (let number = 1; number <= tenantCount; number += 1) {
    const tenant = tenantAt(number);
    const login = http.post(`${baseUrl}/auth/login`, JSON.stringify({
      email: `${tenant}@loadtest.local`,
      password,
    }), {
      headers: headers(tenant, ''),
      tags: { name: 'POST /auth/login', tenant },
    });
    if (!check(login, { 'login POS inicial responde 201': (response) => response.status === 201 })) {
      throw new Error(`No fue posible iniciar sesion para ${tenant}.`);
    }

    const token = login.json('data.token');
    const bootstrap = get('/pos/bootstrap', tenant, token, 'GET /pos/bootstrap');
    if (!check(bootstrap, {
      'POS tiene turno abierto': (response) => response.status === 200 && Boolean(response.json('open_session.id')),
      'POS tiene lista predeterminada': (response) => response.status === 200 && Array.isArray(response.json('price_lists')),
    })) {
      throw new Error(`El laboratorio POS de ${tenant} no tiene una caja abierta valida.`);
    }

    const bootstrapData = bootstrap.json();
    const priceLists = bootstrapData.price_lists;
    const priceList = priceLists.find((list) => list.is_default) || priceLists[0];
    const paymentMethods = bootstrapData.payment_methods;
    const paymentMethod = paymentMethods.find((method) => (
      method.method === 'cash'
      && method.currency_mode === 'USD'
      && priceList.payment_method_ids.includes(method.id)
    ));
    if (!paymentMethod) {
      throw new Error(`El laboratorio POS de ${tenant} no tiene efectivo USD permitido.`);
    }

    const suffix = tenant.slice(-2);
    sessions[tenant] = {
      token,
      warehouseId: bootstrapData.warehouses[0].id,
      cashSessionId: bootstrapData.open_session.id,
      priceListId: priceList.id,
      paymentMethod,
      quantityProduct: findProduct(tenant, token, `${tenantPrefix.toUpperCase()}-${suffix}-0001`),
      serializedProduct: findProduct(tenant, token, `${tenantPrefix.toUpperCase()}-${suffix}-${serializedSuffix}`),
    };
  }

  return sessions;
}

export default function (sessions) {
  const tenantNumber = ((exec.vu.idInTest - 1) % tenantCount) + 1;
  const tenant = tenantAt(tenantNumber);
  const context = sessions[tenant];
  const serialSale = exec.vu.iterationInScenario % 2 === 1;
  let product = context.quantityProduct;
  let productUnitIds;

  if (serialSale) {
    const units = get(
      `/inventory-centers/products/${context.serializedProduct.id}/units?warehouse_id=${context.warehouseId}&status=available&limit=100`,
      tenant,
      context.token,
      'GET /inventory-centers/products/{product}/units',
    );
    const availableUnits = units.status === 200 && Array.isArray(units.json('data')) ? units.json('data') : [];
    const unitIndex = ((exec.vu.idInTest - 1) * iterations + exec.vu.iterationInScenario) % availableUnits.length;
    const unit = availableUnits[unitIndex];
    if (!unit) {
      throw new Error(`No quedan IMEIs de laboratorio disponibles para ${tenant}.`);
    }
    product = context.serializedProduct;
    productUnitIds = [unit.id];
  }

  const amount = Number(product.base_price);
  const payload = {
    cash_register_session_id: context.cashSessionId,
    items: [{
      warehouse_id: context.warehouseId,
      product_id: product.id,
      price_list_id: context.priceListId,
      quantity: 1,
      ...(productUnitIds ? { product_unit_ids: productUnitIds } : {}),
    }],
    payments: [{
      payment_method_id: context.paymentMethod.id,
      method: context.paymentMethod.method,
      currency: 'USD',
      amount,
    }],
  };
  const key = `load-${sessions.runId}-${tenant}-${exec.vu.idInTest}-${exec.vu.iterationInScenario}`;
  const first = checkout(tenant, context.token, payload, key);
  const succeeded = first.status === 201 && first.json('data.status') === 'paid';
  if (!succeeded) {
    console.error(`Checkout POS fallido para ${tenant}: HTTP ${first.status} ${first.body}`);
  }
  checkoutSuccess.add(succeeded, { tenant, serial_sale: String(serialSale) });
  if (serialSale) {
    serializedCheckoutSuccess.add(succeeded, { tenant });
  }
  check(first, { 'checkout POS crea una venta pagada': () => succeeded });

  if (exec.vu.iterationInScenario === 0) {
    const retry = checkout(tenant, context.token, payload, key);
    const sameOrder = retry.status === 201 && retry.json('data.id') === first.json('data.id');
    if (!sameOrder) {
      idempotencyViolations.add(1, { tenant });
    }
    check(retry, { 'reintento POS no duplica la venta': () => sameOrder });
  }

  sleep(Number(__ENV.STRESS_THINK_TIME || 0.25));
}
