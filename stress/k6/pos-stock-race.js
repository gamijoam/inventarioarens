import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000/api').replace(/\/$/, '');
const tenantPrefix = __ENV.STRESS_PREFIX || 'loadtest';
const password = __ENV.STRESS_PASSWORD;
const tenantNumber = Number(__ENV.STRESS_RACE_TENANT || 1);
const target = __ENV.STRESS_RACE_TARGET || 'quantity';
const vus = Number(__ENV.STRESS_RACE_VUS || 8);
const p95Limit = Number(__ENV.STRESS_RACE_P95_MS || 5000);

const raceSuccess = new Counter('inventoryarens_pos_race_success');
const raceValidResponse = new Rate('inventoryarens_pos_race_valid_response');
const raceLatency = new Trend('inventoryarens_pos_race_latency', true);

if (!password) {
  throw new Error('Define STRESS_PASSWORD antes de ejecutar la prueba de colision POS.');
}

if (!['quantity', 'serialized'].includes(target)) {
  throw new Error('STRESS_RACE_TARGET debe ser quantity o serialized.');
}

if (/app\.miinventariofacil\.com/i.test(baseUrl) && __ENV.STRESS_ALLOW_PRODUCTION !== 'yes') {
  throw new Error('El destino es produccion. Define STRESS_ALLOW_PRODUCTION=yes solo durante una ventana aprobada.');
}

export const options = {
  scenarios: {
    simultaneous_last_unit_sale: {
      executor: 'per-vu-iterations',
      vus,
      iterations: 1,
      maxDuration: '1m',
      gracefulStop: '15s',
    },
  },
  thresholds: {
    inventoryarens_pos_race_success: ['count==1'],
    inventoryarens_pos_race_valid_response: ['rate==1'],
    inventoryarens_pos_race_latency: [`p(95)<${p95Limit}`],
  },
};

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

function productBySku(tenant, token, sku) {
  const response = http.get(`${baseUrl}/products?search=${encodeURIComponent(sku)}&per_page=10`, {
    headers: headers(tenant, token),
    tags: { name: 'GET /products race', tenant },
  });
  const products = response.status === 200 ? response.json('data') : [];

  return Array.isArray(products) ? products.find((product) => product.sku === sku) : null;
}

export function setup() {
  const suffix = String(tenantNumber).padStart(2, '0');
  const tenant = `${tenantPrefix}-${suffix}`;
  const login = http.post(`${baseUrl}/auth/login`, JSON.stringify({
    email: `${tenant}@loadtest.local`,
    password,
  }), { headers: headers(tenant, ''), tags: { name: 'POST /auth/login race', tenant } });

  if (!check(login, { 'login de colision responde 201': (response) => response.status === 201 })) {
    throw new Error(`No fue posible iniciar sesion para ${tenant}.`);
  }

  const token = login.json('data.token');
  const bootstrap = http.get(`${baseUrl}/pos/bootstrap`, {
    headers: headers(tenant, token),
    tags: { name: 'GET /pos/bootstrap race', tenant },
  });
  if (!check(bootstrap, { 'POS de colision tiene turno abierto': (response) => response.status === 200 && Boolean(response.json('open_session.id')) })) {
    throw new Error(`El laboratorio POS de ${tenant} no tiene una caja abierta valida.`);
  }

  const data = bootstrap.json();
  const priceList = data.price_lists.find((list) => list.is_default) || data.price_lists[0];
  const paymentMethod = data.payment_methods.find((method) => (
    method.method === 'cash' && method.currency_mode === 'USD' && priceList.payment_method_ids.includes(method.id)
  ));
  const product = productBySku(tenant, token, `${tenantPrefix.toUpperCase()}-${suffix}-RACE-${target === 'serialized' ? 'IMEI' : 'QTY'}`);

  if (!paymentMethod || !product) {
    throw new Error(`Faltan datos de colision para ${tenant}; ejecuta stress:seed otra vez.`);
  }

  let productUnitId;
  if (target === 'serialized') {
    const units = http.get(`${baseUrl}/inventory-centers/products/${product.id}/units?warehouse_id=${data.warehouses[0].id}&status=available&limit=10`, {
      headers: headers(tenant, token),
      tags: { name: 'GET /inventory-centers/products/{product}/units race', tenant },
    });
    productUnitId = units.status === 200 && Array.isArray(units.json('data')) ? units.json('data.0.id') : null;
    if (!productUnitId) {
      throw new Error(`No hay IMEI disponible de colision para ${tenant}; ejecuta stress:seed otra vez.`);
    }
  }

  return { tenant, token, product, productUnitId, paymentMethod, priceListId: priceList.id, warehouseId: data.warehouses[0].id, cashSessionId: data.open_session.id };
}

export default function (context) {
  const payload = {
    cash_register_session_id: context.cashSessionId,
    items: [{
      warehouse_id: context.warehouseId,
      product_id: context.product.id,
      price_list_id: context.priceListId,
      quantity: 1,
      ...(context.productUnitId ? { product_unit_ids: [context.productUnitId] } : {}),
    }],
    payments: [{
      payment_method_id: context.paymentMethod.id,
      method: context.paymentMethod.method,
      currency: 'USD',
      amount: Number(context.product.base_price),
    }],
  };
  const response = http.post(`${baseUrl}/pos/checkouts`, JSON.stringify(payload), {
    headers: headers(context.tenant, context.token, { 'Idempotency-Key': `race-${target}-${Date.now()}-${exec.vu.idInTest}` }),
    tags: { name: 'POST /pos/checkouts race', tenant: context.tenant, target },
  });
  raceLatency.add(response.timings.duration, { target });

  const sold = response.status === 201 && response.json('data.status') === 'paid';
  const rejectedForStock = response.status === 422;
  const valid = sold || rejectedForStock;
  raceValidResponse.add(valid, { target });
  if (sold) {
    raceSuccess.add(1, { target });
  }

  check(response, {
    'colision POS solo vende o rechaza por inventario': () => valid,
  });
}
