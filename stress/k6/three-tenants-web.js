import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000/api').replace(/\/$/, '');
const tenantPrefix = __ENV.STRESS_PREFIX || 'loadtest';
const tenantCount = Number(__ENV.STRESS_TENANTS || 3);
const password = __ENV.STRESS_PASSWORD;
const vus = Number(__ENV.STRESS_VUS || 9);
const duration = __ENV.STRESS_DURATION || '1m';

const apiLatency = new Trend('inventoryarens_api_latency', true);
const tenantIsolation = new Rate('tenant_isolation_ok');
const isolationViolations = new Counter('tenant_isolation_violations');

if (!password) {
  throw new Error('Define STRESS_PASSWORD antes de ejecutar la prueba.');
}

if (/app\.miinventariofacil\.com/i.test(baseUrl) && __ENV.STRESS_ALLOW_PRODUCTION !== 'yes') {
  throw new Error('El destino es produccion. Define STRESS_ALLOW_PRODUCTION=yes solo durante una ventana aprobada.');
}

export const options = {
  scenarios: {
    three_web_tenants: {
      executor: 'constant-vus',
      vus,
      duration,
      gracefulStop: '15s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800', 'p(99)<1500'],
    inventoryarens_api_latency: ['p(95)<800'],
    tenant_isolation_ok: ['rate==1'],
  },
};

function tenantAt(index) {
  return `${tenantPrefix}-${String(index).padStart(2, '0')}`;
}

function headers(tenant, token = null) {
  const result = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Tenant': tenant,
    'X-Requested-With': 'XMLHttpRequest',
  };

  if (token) {
    result.Authorization = `Bearer ${token}`;
  }

  return result;
}

function request(method, path, tenant, token = null, body = null, name = path) {
  const response = http.request(method, `${baseUrl}${path}`, body, {
    headers: headers(tenant, token),
    tags: { name, tenant },
  });

  apiLatency.add(response.timings.duration, { endpoint: name, tenant });
  return response;
}

export function setup() {
  const sessions = {};

  for (let number = 1; number <= tenantCount; number += 1) {
    const tenant = tenantAt(number);
    const login = request(
      'POST',
      '/auth/login',
      tenant,
      null,
      JSON.stringify({ email: `${tenant}@loadtest.local`, password }),
      'POST /auth/login',
    );
    const loginOk = check(login, {
      'login inicial responde 201': (response) => response.status === 201,
      'login inicial entrega token': (response) => Boolean(response.json('data.token')),
    });

    if (!loginOk) {
      throw new Error(`No fue posible preparar la sesion de ${tenant}.`);
    }

    sessions[tenant] = login.json('data.token');
  }

  return sessions;
}

export default function (sessions) {
  const tenantNumber = ((exec.vu.idInTest - 1) % tenantCount) + 1;
  const tenant = tenantAt(tenantNumber);
  const token = sessions[tenant];
  const dashboard = request('GET', '/dashboard/summary?period=today', tenant, token, null, 'GET /dashboard/summary');
  const products = request('GET', '/products?per_page=25&page=1', tenant, token, null, 'GET /products');
  const me = request('GET', '/auth/me', tenant, token, null, 'GET /auth/me');

  check(dashboard, { 'dashboard responde 200': (response) => response.status === 200 });
  check(products, { 'catalogo responde 200': (response) => response.status === 200 });
  check(me, { 'sesion corresponde al tenant': (response) => response.status === 200 && response.json('data.tenant.slug') === tenant });

  const foreignTenant = tenantAt((tenantNumber % tenantCount) + 1);
  const foreignSku = `${tenantPrefix.toUpperCase()}-${foreignTenant.slice(-2)}-0001`;
  const isolation = request(
    'GET',
    `/products?search=${encodeURIComponent(foreignSku)}&per_page=25`,
    tenant,
    token,
    null,
    'GET /products cross-tenant',
  );
  const ownData = isolation.status === 200 ? isolation.json('data') : null;
  const leaked = Array.isArray(ownData) && ownData.some((product) => product.sku === foreignSku);
  tenantIsolation.add(!leaked);
  if (leaked) {
    isolationViolations.add(1);
  }
  check(isolation, { 'no expone producto de otra empresa': () => !leaked });

  sleep(Number(__ENV.STRESS_THINK_TIME || 0.5));
}
