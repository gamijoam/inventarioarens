import { act, renderHook } from '@testing-library/react';
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
 * - usePosCartPersistence hidrata el store desde sessionStorage al montar
 *   via useEffect (no en el render, asi React 18 strict mode no rompe).
 * - usePosCartPersistence suscribe cambios del store a sessionStorage y
 *   usa la clave correcta por (tenantId, cashierId).
 * - usePosCartPersistence NO suscribe ni hidrata cuando tenantId/cashierId
 *   son null (caja cerrada o sesion aun no cargo).
 */
describe('pos cartStore persistence', () => {
  const TENANT_A = 1;
  const CASHIER_A = 42;
  const TENANT_B = 2;
  const CASHIER_B = 99;

  beforeEach(() => {
    sessionStorage.clear();
    usePosCartStore.setState({
      lines: [],
      payments: [],
      warehouseId: null,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Consumidor Final',
      panel: null,
      query: '',
      productSearch: '',
      customerSearch: '',
      selectedPendingId: null,
      serialLineId: null,
    });
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

    const { unmount } = renderHook(
      ({ tenantId, cashierId }: { tenantId: number | null; cashierId: number | null }) =>
        usePosCartPersistence(tenantId, cashierId),
      { initialProps: { tenantId: TENANT_A, cashierId: CASHIER_A } },
    );

    // Esperar al siguiente tick para que useEffect se ejecute.
    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    const state = usePosCartStore.getState();
    expect(state.lines).toHaveLength(1);
    expect(state.lines[0]?.id).toBe('l-hidratado');
    expect(state.warehouseId).toBe(1);
    expect(state.customerName).toBe('Juan');

    unmount();
  });

  it('usePosCartPersistence: rehidrata cuando cambia (tenantId, cashierId)', async () => {
    // Pre-poblamos sessionStorage para la sesion B.
    savePersistedCart(TENANT_B, CASHIER_B, {
      lines: [
        {
          id: 'b-line',
          product_id: 11,
          name: 'Producto B',
          sku: 'B-1',
          barcode: null,
          warehouse_id: 2,
          quantity: 4,
          available_stock: 8,
          unit_price: 200,
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
      warehouseId: 2,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Sesion B',
    });

    const { rerender } = renderHook(
      ({ tenantId, cashierId }: { tenantId: number | null; cashierId: number | null }) =>
        usePosCartPersistence(tenantId, cashierId),
      { initialProps: { tenantId: TENANT_A, cashierId: CASHIER_A } },
    );

    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    expect(usePosCartStore.getState().customerName).toBe('Consumidor Final');

    // Cambiamos a la sesion B. Debe re-hidratar con el carrito de B.
    rerender({ tenantId: TENANT_B, cashierId: CASHIER_B });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    const state = usePosCartStore.getState();
    expect(state.lines).toHaveLength(1);
    expect(state.lines[0]?.id).toBe('b-line');
    expect(state.warehouseId).toBe(2);
    expect(state.customerName).toBe('Sesion B');
  });

  it('usePosCartPersistence: NO suscribe ni hidrata cuando tenantId/cashierId son null', async () => {
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [],
      payments: [],
      warehouseId: 1,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Pre-poblado',
    });

    renderHook(() => usePosCartPersistence(null, null));

    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    // Sin sesion activa: no debe leer sessionStorage ni pisar el store.
    const state = usePosCartStore.getState();
    expect(state.warehouseId).toBeNull();
    expect(state.customerName).toBe('Consumidor Final');
  });

  it('el subscriber persiste cambios del store a sessionStorage con la clave correcta', async () => {
    const { unmount } = renderHook(
      ({ tenantId, cashierId }: { tenantId: number | null; cashierId: number | null }) =>
        usePosCartPersistence(tenantId, cashierId),
      { initialProps: { tenantId: TENANT_A, cashierId: CASHIER_A } },
    );

    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    // El subscriber esta suscrito. Modificamos el store con contenido
    // (lineas no vacias) para que el subscriber persista.
    act(() => {
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
    });

    // Zustand dispara subscribers sincrónicamente, asi que la persistencia
    // ya ocurrio al volver de setState.
    const loaded = loadPersistedCart(TENANT_A, CASHIER_A);
    expect(loaded?.warehouseId).toBe(7);
    expect(loaded?.customerName).toBe('Subscriber');
    expect(loaded?.lines).toHaveLength(1);

    unmount();
  });

  it('el subscriber NO persiste sessionStorage cuando el carrito esta vacio', async () => {
    // Pre-poblamos sessionStorage.
    savePersistedCart(TENANT_A, CASHIER_A, {
      lines: [],
      payments: [],
      warehouseId: 5,
      selectedPriceListId: null,
      selectedCustomer: null,
      customerName: 'Consumidor Final',
    });

    const { unmount } = renderHook(
      ({ tenantId, cashierId }: { tenantId: number | null; cashierId: number | null }) =>
        usePosCartPersistence(tenantId, cashierId),
      { initialProps: { tenantId: TENANT_A, cashierId: CASHIER_A } },
    );

    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    // El store esta vacio. Cambiamos warehouseId a algo que haria
    // pensar que hay un carrito, pero sin lineas/payments.
    act(() => {
      usePosCartStore.setState({ warehouseId: 9, lines: [], payments: [] });
    });
    // El subscriber deberia limpiar sessionStorage porque lines+payments
    // estan vacios.
    const loaded = loadPersistedCart(TENANT_A, CASHIER_A);
    expect(loaded).toBeNull();

    unmount();
  });

  it('usePosCartPersistence: cleanup desuscribe al desmontar (no leak entre mounts)', async () => {
    // Primer mount con sesion A.
    const first = renderHook(
      ({ tenantId, cashierId }: { tenantId: number | null; cashierId: number | null }) =>
        usePosCartPersistence(tenantId, cashierId),
      { initialProps: { tenantId: TENANT_A, cashierId: CASHIER_A } },
    );

    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });

    first.unmount();

    // Despues del unmount, los cambios al store NO deben persistir en la
    // clave de la sesion A (porque el subscriber fue limpiado).
    act(() => {
      usePosCartStore.setState({
        warehouseId: 123,
        lines: [
          {
            id: 'after-unmount',
            product_id: 99,
            name: 'After unmount',
            sku: null,
            barcode: null,
            warehouse_id: 123,
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
      });
    });

    // No debe haberse guardado nada en la clave de A (el subscriber fue limpiado).
    const loaded = loadPersistedCart(TENANT_A, CASHIER_A);
    expect(loaded).toBeNull();
  });
});