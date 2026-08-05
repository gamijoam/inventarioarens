import { expect, test, type APIRequestContext } from '@playwright/test';

import {
  addQuantityStock,
  authenticatedApi,
  availableSerial,
  availableStock,
  loadProducts,
  productSerials,
  readIntercompanyConfig,
  type IntercompanyParty,
  type IntercompanyProduct,
} from './support/intercompany';

async function stockSnapshot(
  api: APIRequestContext,
  party: IntercompanyParty,
  products: IntercompanyProduct[],
): Promise<number[]> {
  return Promise.all(products.map((product) => availableStock(api, product.id, party.warehouseId)));
}

async function serialSnapshot(
  api: Awaited<ReturnType<typeof authenticatedApi>>,
  party: IntercompanyParty,
  products: IntercompanyProduct[],
  excludedSerials: string[][] = [],
): Promise<Array<string | null>> {
  return Promise.all(
    products.map(async (product, index) =>
      product.trackingType === 'serialized'
        ? availableSerial(api, product.id, party.warehouseId, excludedSerials[index] ?? [])
        : null,
    ),
  );
}

test('solicitante recibe 10 productos mixtos en transferencia interempresa directa', async ({
  baseURL,
}) => {
  const config = readIntercompanyConfig();
  test.skip(
    !config,
    'Configura las variables PLAYWRIGHT_INTERCOMPANY_* para probar los dos tenants.',
  );
  if (!config) return;

  const requester = await authenticatedApi(baseURL, config.requester);
  const supplier = await authenticatedApi(baseURL, config.supplier);

  try {
    const requesterProducts = await loadProducts(requester, config.requester);
    const supplierProducts = await loadProducts(supplier, config.supplier);
    const requesterSerials = await Promise.all(
      requesterProducts.map((product) => productSerials(requester, product.id)),
    );
    await addQuantityStock(
      supplier,
      config.supplier,
      supplierProducts,
      `E2E solicitud mixta ${Date.now()}`,
    );
    const supplierStock = await stockSnapshot(supplier, config.supplier, supplierProducts);
    const supplierSerials = await serialSnapshot(
      supplier,
      config.supplier,
      supplierProducts,
      requesterSerials,
    );

    const created = await requester.post('/api/inventory-transfer-requests', {
      data: {
        flow_type: 'stock_request',
        destination_tenant_slug: config.supplier.tenant,
        from_warehouse_id: config.requester.warehouseId,
        reason: `E2E solicitud mixta ${Date.now()}`,
        items: requesterProducts.map((product) => ({ product_id: product.id, quantity: 1 })),
      },
    });
    expect(created.status(), await created.text()).toBe(201);
    const createdPayload = (await created.json()) as {
      data: { id: number; items: Array<{ id: number }> };
    };
    expect(createdPayload.data.items).toHaveLength(10);

    const accepted = await supplier.post(
      `/api/inventory-transfer-requests/${createdPayload.data.id}/accept`,
      {
        data: {
          destination_warehouse_id: config.supplier.warehouseId,
          items: await Promise.all(
            supplierProducts.map(async (product, index) => ({
              request_item_id: createdPayload.data.items[index].id,
              destination_product_id: product.id,
              ...(supplierSerials[index]
                ? {
                    serial_units: [{ serial_type: 'imei', serial_number: supplierSerials[index] }],
                  }
                : {}),
            })),
          ),
        },
      },
    );
    expect(accepted.status(), await accepted.text()).toBe(200);
    expect((await accepted.json()).data.status).toBe('completed');

    const supplierAfter = await stockSnapshot(supplier, config.supplier, supplierProducts);
    supplierAfter.forEach((available, index) => {
      expect(available).toBe(supplierStock[index] - 1);
    });
  } finally {
    await requester.dispose();
    await supplier.dispose();
  }
});

test('proveedor propone 10 productos mixtos y receptor completa la guía logística', async ({
  baseURL,
}) => {
  const config = readIntercompanyConfig();
  test.skip(
    !config,
    'Configura las variables PLAYWRIGHT_INTERCOMPANY_* para probar los dos tenants.',
  );
  if (!config) return;

  const sender = await authenticatedApi(baseURL, config.requester);
  const receiver = await authenticatedApi(baseURL, config.supplier);

  try {
    const senderProducts = await loadProducts(sender, config.requester);
    const receiverProducts = await loadProducts(receiver, config.supplier);
    const receiverSerials = await Promise.all(
      receiverProducts.map((product) => productSerials(receiver, product.id)),
    );
    await addQuantityStock(
      sender,
      config.requester,
      senderProducts,
      `E2E propuesta mixta ${Date.now()}`,
    );
    const senderStock = await stockSnapshot(sender, config.requester, senderProducts);
    const receiverStock = await stockSnapshot(receiver, config.supplier, receiverProducts);
    const senderSerials = await serialSnapshot(
      sender,
      config.requester,
      senderProducts,
      receiverSerials,
    );

    const created = await sender.post('/api/inventory-transfer-requests', {
      data: {
        flow_type: 'shipment_offer',
        destination_tenant_slug: config.supplier.tenant,
        from_warehouse_id: config.requester.warehouseId,
        reason: `E2E propuesta mixta ${Date.now()}`,
        items: senderProducts.map((product) => ({ product_id: product.id, quantity: 1 })),
      },
    });
    expect(created.status(), await created.text()).toBe(201);
    const createdPayload = (await created.json()) as {
      data: { id: number; items: Array<{ id: number }> };
    };
    expect(createdPayload.data.items).toHaveLength(10);
    const requestId = createdPayload.data.id;

    const accepted = await receiver.post(`/api/inventory-transfer-requests/${requestId}/accept`, {
      data: {
        logistics_mode: true,
        destination_warehouse_id: config.supplier.warehouseId,
        items: receiverProducts.map((product, index) => ({
          request_item_id: createdPayload.data.items[index].id,
          destination_product_id: product.id,
        })),
      },
    });
    expect(accepted.status(), await accepted.text()).toBe(200);
    expect((await accepted.json()).data.status).toBe('accepted');

    const prepared = await sender.post(
      `/api/inventory-transfer-requests/${requestId}/guide/prepare`,
      {
        data: {
          transport_mode: 'simple',
          items: senderProducts.map((product, index) => ({
            request_item_id: createdPayload.data.items[index].id,
            prepared_quantity: 1,
            ...(senderSerials[index]
              ? {
                  prepared_serial_units: [
                    { serial_type: 'imei', serial_number: senderSerials[index] },
                  ],
                }
              : {}),
          })),
        },
      },
    );
    expect(prepared.status(), await prepared.text()).toBe(200);
    expect((await prepared.json()).data.guide.status).toBe('prepared');

    const dispatched = await sender.post(
      `/api/inventory-transfer-requests/${requestId}/guide/dispatch`,
    );
    expect(dispatched.status(), await dispatched.text()).toBe(200);
    expect((await dispatched.json()).data.guide.status).toBe('dispatched');

    const senderAfter = await stockSnapshot(sender, config.requester, senderProducts);
    senderAfter.forEach((available, index) => {
      expect(available).toBe(senderStock[index] - 1);
    });

    const delivered = await sender.post(
      `/api/inventory-transfer-requests/${requestId}/guide/deliver`,
    );
    expect(delivered.status(), await delivered.text()).toBe(200);
    expect((await delivered.json()).data.guide.status).toBe('delivered');

    const received = await receiver.post(
      `/api/inventory-transfer-requests/${requestId}/guide/receive`,
      {
        data: {
          items: receiverProducts.map((product, index) => ({
            request_item_id: createdPayload.data.items[index].id,
            received_quantity: 1,
            ...(senderSerials[index]
              ? {
                  received_serial_units: [
                    { serial_type: 'imei', serial_number: senderSerials[index] },
                  ],
                }
              : {}),
          })),
        },
      },
    );
    expect(received.status(), await received.text()).toBe(200);
    expect((await received.json()).data.status).toBe('completed');

    const receiverAfter = await stockSnapshot(receiver, config.supplier, receiverProducts);
    receiverAfter.forEach((available, index) => {
      expect(available).toBe(receiverStock[index] + 1);
    });
  } finally {
    await sender.dispose();
    await receiver.dispose();
  }
});
