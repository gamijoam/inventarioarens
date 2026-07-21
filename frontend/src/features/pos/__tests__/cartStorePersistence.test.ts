import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  clearPersistedCart,
  loadPersistedCart,
  savePersistedCart,
  usePosCartStore,
  usePosCartPersistence,
} from '../cartStore';

/**
 * Tests de la persistencia del carrito en sessionStorage.
 *
 * Cubren:
 * - savePersistedCart serializa el state correctamente
 * - loadPersistedCart deserializa el state correctamente
 * - clearPersistedCart elimina la key
 * - La clave incluye tenant_id y cashier_id (sin colisiones)
 * - usePosCartPersistence suscribe cambios del store a sessionStorage
 *
 * El ciclo de hidratacion via window.setTimeout se valida en el test
 * "hidrata el store desde sessionStorage".
 */
describe('pos cartStore persistence', () => {
  const TENANT_A = 1;
  const CASHIER_A = 42;
  const TENANT_B = 2;
  const CASHIER_B = 99;

  beforeEach(() => {
    // Limpiamos sessionStorage entre tests para que el hidrato empiece
    // limpio. NO reseteamos el store a vacio: varios tests verifican
    // que el state del hidrato anterior se mantiene (la flag de
    // hidratacion persiste en window, asi que usePosCartPersistence
    // no re-hidrata entre tests).
    sessionStorage.clear();
  });

  afterEach(() => {
    sessionStorage.clear();
  });

  it('savePersistedCart + loadPersistedCart: round-trip basico', () => {
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [
        {
          id: 'l1',
          product_id: 5,
          name: 'Laptop',
          sku: 'LAP-001',
          barcode: null,
          warehouse_id: 3,
          quantity: 2,
          available_stock: 5,
          unit_price: 650,
          base_unit_price: null,
          currency: 'USD',
          base_currency: null,
          discount_type: null,
          discount_value: null,
          discount_reason: null,
          price_list_id: null,
          price_list_name: null,
          price_issue: null,
          tracking_type: 'quantity',
          track_stock: true,
          selected_serials: [],
        },
      ],
      payments: [],
      warehouseId: 3,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Cliente demo',
    });
    const loaded = loadPersistedCart(TENANT_A, CASHIER_A);
    expect(loaded).not.toBeNull();
    expect(loaded!.lines).toHaveLength(1);
    expect(loaded!.lines?.[0]?.name).toBe('Laptop');
    expect(loaded!.warehouseId).toBe(3);
    expect(loaded!.customerName).toBe('Cliente demo');
  });

  it('la clave del sessionStorage incluye tenantId y cashierId (sin colisiones)', () => {
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [],
      payments: [],
      warehouseId: 1,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Consumidor Final',
    });
    savePersistedCart(TENANT_B, CASHIER_B, {
      lines: [],
      payments: [],
      warehouseId: 2,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Consumidor Final',
    });

    const aLoaded = loadPersistedCart(TENANT_A, CASHIER_A);
    const bLoaded = loadPersistedCart(TENANT_B, CASHIER_B);
    expect(aLoaded?.warehouseId).toBe(1);
    expect(bLoaded?.warehouseId).toBe(2);
  });

  it('clearPersistedCart elimina el state', () => {
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [],
      payments: [],
      warehouseId: 1,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'X',
    });
    expect(loadPersistedCart(TENANT_A, CASHIER_A)).not.toBeNull();
    clearPersistedCart(TENANT_A, CASHIER_A);
    expect(loadPersistedCart(TENANT_A, CASHIER_A)).toBeNull();
  });

  it('usePosCartPersistence: hidrata el store desde sessionStorage al montar', async () => {
    // Pre-poblamos sessionStorage con un carrito guardado.
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [
        {
          id: 'l-hidratado',
          product_id: 7,
          name: 'Producto hidratado',
          sku: 'PH-1',
          barcode: null,
          warehouse_id: 1,
          quantity: 3,
          available_stock: 5,
          unit_price: 100,
          base_unit_price: null,
          currency: 'USD',
          base_currency: null,
          discount_type: null,
          discount_value: null,
          discount_reason: null,
          price_list_id: null,
          price_list_name: null,
          price_issue: null,
          tracking_type: 'quantity',
          track_stock: true,
          selected_serials: [],
        },
      ],
      payments: [],
      warehouseId: 1,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Juan',
    });

    // Forzamos la hidratacion. La primera llamada al hook verifica
    // la flag __posCartHydrated en window y salta si ya se hizo. Como
    // cada test crea un window nuevo, la primera llamada SI hidrata.
    usePosCartPersistence(TENANT_A, CASHIER_A);

    // El setTimeout(0) del hook se ejecuta en el siguiente tick.
    await new Promise((r) => setTimeout(r, 0));

    const state = usePosCartStore.getState();
    expect(state.lines).toHaveLength(1);
    expect(state.lines[0]?.id).toBe('l-hidratado');
    expect(state.warehouseId).toBe(1);
    expect(state.customerName).toBe('Juan');
  });

  it('usePosCartPersistence: no hidrata si ya esta hidratado (mismo tenant+cashier)', async () => {
    // Hidratamos manualmente con el state A.
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [
        {
          id: 'a',
          product_id: 1,
          name: 'A',
          sku: 'A',
          barcode: null,
          warehouse_id: 1,
          quantity: 1,
          available_stock: 1,
          unit_price: 1,
          base_unit_price: null,
          currency: 'USD',
          base_currency: null,
          discount_type: null,
          discount_value: null,
          discount_reason: null,
          price_list_id: null,
          price_list_name: null,
          price_issue: null,
          tracking_type: 'quantity',
          track_stock: true,
          selected_serials: [],
        },
      ],
      payments: [],
      warehouseId: 1,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'A',
    });
    usePosCartPersistence(TENANT_A, CASHIER_A);
    await new Promise((r) => setTimeout(r, 0));
    expect(usePosCartStore.getState().warehouseId).toBe(1);

    // Cambiamos sessionStorage a un valor distinto (B). Volvemos a llamar
    // al hook con el mismo contexto: NO debe re-hidratar (la flag persiste).
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [],
      payments: [],
      warehouseId: 999,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'B-in-sessionStorage',
    });
    usePosCartPersistence(TENANT_A, CASHIER_A);
    await new Promise((r) => setTimeout(r, 0));

    // El state sigue siendo el de la primera hidratacion (warehouseId=1).
    expect(usePosCartStore.getState().warehouseId).toBe(1);
  });

  it('el subscriber persiste cambios del store a sessionStorage', () => {
    usePosCartPersistence(TENANT_A, CASHIER_A);

    // El subscriber esta suscrito. Modificamos el store con contenido
    // (lineas no vacias) para que el subscriber persista.
    usePosCartStore.setState({
      warehouseId: 7,
      lines: [
        {
          id: 'l1',
          product_id: 5,
          name: 'X',
          sku: null,
          barcode: null,
          warehouse_id: 7,
          quantity: 1,
          available_stock: 1,
          unit_price: 100,
          base_unit_price: null,
          currency: 'USD',
          base_currency: null,
          discount_type: null,
          discount_value: null,
          discount_reason: null,
          price_list_id: null,
          price_list_name: null,
          price_issue: null,
          tracking_type: 'quantity',
          track_stock: true,
          selected_serials: [],
        },
      ],
      payments: [],
    });
    usePosCartStore.setState({ customerName: 'Subscriber' });

    // Zustand dispara subscribers sincrónicamente, asi que la persistencia
    // ya ocurrio al volver de setState.
    const loaded = loadPersistedCart(TENANT_A, CASHIER_A);
    expect(loaded?.warehouseId).toBe(7);
    expect(loaded?.customerName).toBe('Subscriber');
    expect(loaded?.lines).toHaveLength(1);
  });

  it('el subscriber NO persiste sessionStorage cuando el carrito esta vacio', () => {
    // Pre-poblamos sessionStorage.
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [],
      payments: [],
      warehouseId: 5,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Consumidor Final',
    });
    usePosCartPersistence(TENANT_A, CASHIER_A);

    // El store esta vacio. Cambiamos warehouseId a algo que haria
    // pensar que hay un carrito, pero sin lineas/payments.
    usePosCartStore.setState({ warehouseId: 9, lines: [], payments: [] });
    // El subscriber deberia limpiar sessionStorage porque lines+payments
    // estan vacios.
    const loaded = loadPersistedCart(TENANT_A, CASHIER_A);
    expect(loaded).toBeNull();
  });
});
