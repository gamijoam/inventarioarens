import { expect, request, type APIRequestContext } from '@playwright/test';

import type { DemoCredentials } from './auth';

export type IntercompanyProduct = {
  id: number;
  search: string;
  trackingType: 'quantity' | 'serialized';
};

export type IntercompanyParty = DemoCredentials & {
  warehouseId: number;
  productIds: number[];
};

export type IntercompanyConfig = {
  requester: IntercompanyParty;
  supplier: IntercompanyParty;
};

function readProductIds(prefix: 'REQUESTER' | 'SUPPLIER'): number[] {
  const plural = process.env[`PLAYWRIGHT_INTERCOMPANY_${prefix}_PRODUCT_IDS`];
  const singular = process.env[`PLAYWRIGHT_INTERCOMPANY_${prefix}_PRODUCT_ID`];
  const raw = plural || singular || '';

  return raw
    .split(',')
    .map((value) => Number(value.trim()))
    .filter((value) => Number.isInteger(value) && value > 0);
}

function readParty(prefix: 'REQUESTER' | 'SUPPLIER'): IntercompanyParty | null {
  const email = process.env[`PLAYWRIGHT_INTERCOMPANY_${prefix}_EMAIL`];
  const password = process.env[`PLAYWRIGHT_INTERCOMPANY_${prefix}_PASSWORD`];
  const tenant = process.env[`PLAYWRIGHT_INTERCOMPANY_${prefix}_TENANT`];
  const warehouseId = Number(process.env[`PLAYWRIGHT_INTERCOMPANY_${prefix}_WAREHOUSE_ID`]);
  const productIds = readProductIds(prefix);

  if (!email || !password || !tenant || !Number.isInteger(warehouseId) || productIds.length === 0) {
    return null;
  }

  return { email, password, tenant, warehouseId, productIds };
}

export function readIntercompanyConfig(): IntercompanyConfig | null {
  const requester = readParty('REQUESTER');
  const supplier = readParty('SUPPLIER');

  if (!requester || !supplier || requester.productIds.length !== supplier.productIds.length) {
    return null;
  }

  return { requester, supplier };
}

export async function authenticatedApi(
  baseURL: string | undefined,
  party: IntercompanyParty,
): Promise<APIRequestContext> {
  const loginContext = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Tenant': party.tenant,
    },
  });
  const login = await loginContext.post('/api/auth/login', {
    data: { email: party.email, password: party.password },
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(login.status(), await login.text()).toBe(201);
  const payload = (await login.json()) as { data?: { token?: string } };
  expect(payload.data?.token).toBeTruthy();
  await loginContext.dispose();

  return request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      Authorization: `Bearer ${payload.data?.token}`,
      'X-Tenant': party.tenant,
    },
  });
}

export async function loadProducts(
  api: APIRequestContext,
  party: IntercompanyParty,
): Promise<IntercompanyProduct[]> {
  const products = await Promise.all(
    party.productIds.map(async (productId) => {
      const response = await api.get(`/api/products/${productId}`);
      expect(response.status(), await response.text()).toBe(200);
      const payload = (await response.json()) as {
        data?: { sku?: string | null; name?: string; tracking_type?: string };
      };
      const product = payload.data;
      const trackingType = product?.tracking_type;
      expect(['quantity', 'serialized']).toContain(trackingType);

      return {
        id: productId,
        search: product?.sku ?? product?.name ?? String(productId),
        trackingType: trackingType as IntercompanyProduct['trackingType'],
      };
    }),
  );

  expect(products).toHaveLength(10);
  expect(products.filter((product) => product.trackingType === 'quantity')).toHaveLength(5);
  expect(products.filter((product) => product.trackingType === 'serialized')).toHaveLength(5);

  return products;
}

export async function addQuantityStock(
  api: APIRequestContext,
  party: IntercompanyParty,
  products: IntercompanyProduct[],
  reason: string,
): Promise<void> {
  for (const product of products.filter((item) => item.trackingType === 'quantity')) {
    const response = await api.post('/api/inventory/purchases', {
      data: {
        warehouse_id: party.warehouseId,
        product_id: product.id,
        quantity: 2,
        unit_cost: 50,
        reason,
      },
    });
    expect(response.status(), await response.text()).toBe(201);
  }
}

export async function availableStock(
  api: APIRequestContext,
  productId: number,
  warehouseId: number,
): Promise<number> {
  const response = await api.get(`/api/inventory-center/products/${productId}/stock-by-warehouse`);
  expect(response.status(), await response.text()).toBe(200);
  const payload = (await response.json()) as {
    data?: { data?: Array<{ warehouse_id: number; available: number }> };
  };
  const row = payload.data?.data?.find((item) => item.warehouse_id === warehouseId);
  return Number(row?.available ?? 0);
}

export async function availableSerial(
  api: APIRequestContext,
  productId: number,
  warehouseId: number,
  excludedSerials: string[] = [],
): Promise<string> {
  const response = await api.get(
    `/api/inventory-centers/products/${productId}/units?warehouse_id=${warehouseId}&status=available`,
  );
  expect(response.status(), await response.text()).toBe(200);
  const payload = (await response.json()) as {
    data?: Array<{ serial_type: string; serial_number: string }>;
  };
  const excluded = new Set(excludedSerials);
  const unit = payload.data?.find(
    (item) => item.serial_type === 'imei' && !excluded.has(item.serial_number),
  );
  expect(unit?.serial_number).toBeTruthy();
  return unit!.serial_number;
}

export async function productSerials(api: APIRequestContext, productId: number): Promise<string[]> {
  const response = await api.get(
    `/api/inventory-center/products/${productId}/serials?per_page=500`,
  );
  expect(response.status(), await response.text()).toBe(200);
  const payload = (await response.json()) as {
    data?: { data?: Array<{ serial_number: string }> };
  };
  return payload.data?.data?.map((item) => item.serial_number) ?? [];
}
