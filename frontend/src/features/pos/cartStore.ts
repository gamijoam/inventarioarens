/**
 * Store de carrito/pagos/UI state del POS, implementado con Zustand v5.
 *
 * El POS renderiza el carrito, pagos, IMEIs y panel de busqueda
 * constantemente. Con useState el componente entero re-renderiza ante
 * cualquier cambio. Con Zustand usamos selectores granulares para que
 * solo los componentes que leen la parte afectada re-renderizen.
 *
 * El store se divide en 4 slices (UI, seleccion, carrito, pagos) y
 * expone acciones estables (referencialmente) para que TanStack Query
 * no invalide consumidores por identidad de funcion.
 */
import { create } from 'zustand';
import { subscribeWithSelector } from 'zustand/middleware';

import type { Customer } from './api';
import type { PosCartLine, PosPaymentLine } from './posLogic';
import type { CurrencyCode, DiscountType } from './posLogic';

export type Panel =
  | 'pay'
  | 'hold'
  | 'customer'
  | 'cash'
  | 'receipt'
  | 'product-search'
  | 'credit'
  | 'serials'
  | null;

export interface AddLineInput {
  product: {
    id: number;
    name: string;
    sku?: string | null;
    barcode?: string | null;
    tracking_type?: 'quantity' | 'serialized' | string | null;
    track_stock?: boolean;
    base_price?: number | string | null;
    sale_currency?: CurrencyCode | string | null;
  };
  warehouse: { id: number; name?: string; code?: string };
  quote: {
    base_price_usd: number;
    sale_currency: CurrencyCode;
    price_list_id?: number | null;
    price_list_name?: string | null;
  } | null;
  availableStock: number;
  defaultUnitPrice?: number;
}

export const usePosCartStore = create<{
  // ===== UI state =====
  panel: Panel;
  query: string;
  productSearch: string;
  selectedPendingId: number | null;
  serialLineId: string | null;

  // ===== Seleccion =====
  warehouseId: number | null;
  selectedPriceListId: number | null;
  selectedCustomer: Customer | null;
  customerName: string;
  customerSearch: string;

  // ===== Carrito =====
  lines: PosCartLine[];

  // ===== Pagos =====
  payments: PosPaymentLine[];

  // ===== Acciones UI =====
  setPanel: (panel: Panel) => void;
  setQuery: (query: string) => void;
  setProductSearch: (query: string) => void;
  setSelectedPendingId: (id: number | null) => void;
  setSerialLineId: (id: string | null) => void;

  // ===== Acciones de seleccion =====
  setWarehouseId: (id: number | null) => void;
  setSelectedPriceListId: (id: number | null) => void;
  setSelectedCustomer: (customer: Customer | null) => void;
  setCustomerName: (name: string) => void;
  setCustomerSearch: (search: string) => void;

  // ===== Acciones de carrito =====
  addLine: (input: AddLineInput) => string | null;
  removeLine: (id: string) => void;
  updateQuantity: (id: string, quantity: number) => void;
  incrementLine: (id: string) => void;
  decrementLine: (id: string) => void;
  setLineDiscount: (id: string, type: DiscountType | null, value: number | null, reason?: string | null) => void;
  setLineSerials: (id: string, serials: PosCartLine['selected_serials']) => void;
  clearLines: () => void;
  clearAll: () => void;

  // ===== Acciones de pagos =====
  addPayment: (payment: PosPaymentLine) => void;
  updatePayment: (id: string, patch: Partial<PosPaymentLine>) => void;
  removePayment: (id: string) => void;
  clearPayments: () => void;
}>()(
  subscribeWithSelector((set, get) => ({
    // ===== UI state =====
    panel: null,
    query: '',
    productSearch: '',
    selectedPendingId: null,
    serialLineId: null,

    // ===== Seleccion =====
    warehouseId: null,
    selectedPriceListId: null,
    selectedCustomer: null,
    customerName: 'Consumidor Final',
    customerSearch: '',

    // ===== Carrito =====
    lines: [],

    // ===== Pagos =====
    payments: [],

    // ===== Acciones UI =====
    setPanel: (panel) => set({ panel }),
    setQuery: (query) => set({ query }),
    setProductSearch: (productSearch) => set({ productSearch }),
    setSelectedPendingId: (selectedPendingId) => set({ selectedPendingId }),
    setSerialLineId: (serialLineId) => set({ serialLineId }),

    // ===== Acciones de seleccion =====
    setWarehouseId: (warehouseId) => set({ warehouseId }),
    setSelectedPriceListId: (selectedPriceListId) => set({ selectedPriceListId }),
    setSelectedCustomer: (selectedCustomer) => set({ selectedCustomer }),
    setCustomerName: (customerName) => set({ customerName }),
    setCustomerSearch: (customerSearch) => set({ customerSearch }),

    // ===== Acciones de carrito =====
    addLine: (input) => {
      const { product, warehouse, quote, availableStock, defaultUnitPrice } = input;
      const current = get().lines;
      const existing = current.find(
        (line) => line.product_id === product.id && line.warehouse_id === warehouse.id,
      );
      if (existing) {
        set({
          lines: current.map((line) =>
            line.id === existing.id
              ? { ...line, quantity: Math.min(line.quantity + 1, Math.max(0, availableStock)) }
              : line,
          ),
        });

        return existing.id;
      }

      const id = crypto.randomUUID();
      set({
        lines: [
          ...current,
          {
            id,
            product_id: product.id,
            name: product.name,
            sku: product.sku ?? null,
            barcode: product.barcode ?? null,
            warehouse_id: warehouse.id,
            quantity: 1,
            available_stock: availableStock,
            unit_price: quote?.base_price_usd ?? defaultUnitPrice ?? Number(product.base_price ?? 0),
            base_unit_price: Number(product.base_price ?? 0),
            currency: (quote?.sale_currency ?? product.sale_currency ?? 'USD') as CurrencyCode,
            base_currency: (product.sale_currency ?? 'USD') as CurrencyCode,
            price_list_id: quote?.price_list_id ?? null,
            price_list_name: quote?.price_list_name ?? null,
            price_issue: null,
            tracking_type: product.tracking_type ?? null,
            track_stock: product.track_stock !== false,
            selected_serials: [],
          },
        ],
      });

      return id;
    },
    removeLine: (id) => set({ lines: get().lines.filter((line) => line.id !== id) }),
    updateQuantity: (id, quantity) =>
      set({
        lines: get().lines.map((line) =>
          line.id === id
            ? {
                ...line,
                quantity: Math.max(0, Math.min(quantity, line.track_stock === false ? Infinity : line.available_stock)),
              }
            : line,
        ),
      }),
    incrementLine: (id) => {
      const line = get().lines.find((entry) => entry.id === id);
      if (!line) return;
      get().updateQuantity(id, line.quantity + 1);
    },
    decrementLine: (id) => {
      const line = get().lines.find((entry) => entry.id === id);
      if (!line) return;
      get().updateQuantity(id, Math.max(0, line.quantity - 1));
    },
    setLineDiscount: (id, type, value, reason = null) =>
      set({
        lines: get().lines.map((line) =>
          line.id === id
            ? {
                ...line,
                discount_type: type,
                discount_value: value ?? null,
                discount_reason: reason,
              }
            : line,
        ),
      }),
    setLineSerials: (id, serials) =>
      set({
        lines: get().lines.map((line) =>
          line.id === id ? { ...line, selected_serials: serials } : line,
        ),
      }),
    clearLines: () => set({ lines: [] }),
    clearAll: () =>
      set({
        lines: [],
        payments: [],
        selectedCustomer: null,
        customerName: 'Consumidor Final',
      }),

    // ===== Acciones de pagos =====
    addPayment: (payment) => set({ payments: [...get().payments, payment] }),
    updatePayment: (id, patch) =>
      set({
        payments: get().payments.map((payment) => (payment.id === id ? { ...payment, ...patch } : payment)),
      }),
    removePayment: (id) => set({ payments: get().payments.filter((payment) => payment.id !== id) }),
    clearPayments: () => set({ payments: [] }),
  })),
);

// Selectores atomicos para evitar re-renders innecesarios. Cada consumer
// suscribe solo a la slice que lee, no al store completo.
export const selectLines = (s: ReturnType<typeof usePosCartStore.getState>) => s.lines;
export const selectPayments = (s: ReturnType<typeof usePosCartStore.getState>) => s.payments;
export const selectQuery = (s: ReturnType<typeof usePosCartStore.getState>) => s.query;
export const selectPanel = (s: ReturnType<typeof usePosCartStore.getState>) => s.panel;
