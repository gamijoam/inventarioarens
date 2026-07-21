import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { api, deleteOne, getMany, getOne, getPaginated, patchOne, postOne } from '@/api/client';
import { useProducts } from '@/features/inventory-center/api';
import { BranchSchema, ExchangeRateTypeSchema, PriceListSchema, ProductSchema, WarehouseSchema } from '@/features/inventory-center/schemas';
import type { InventoryFilters } from '@/features/inventory-center/schemas';

export type PosPaymentMethod = 'cash' | 'card' | 'mobile_payment' | 'transfer' | 'zelle' | 'external_financing' | 'other';

const nullableNumber = z.union([z.number(), z.string()]).nullable().optional().transform((v) => {
  if (v == null || v === '') return null;
  return Number(v);
});

export const PaymentMethodSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  code: z.string().optional().nullable(),
  method: z.string().optional().nullable(),
  currency_mode: z.enum(['USD', 'VES', 'flexible']).optional(),
  is_active: z.boolean().optional(),
  requires_reference: z.boolean().optional(),
  sort_order: z.number().int().optional(),
}).passthrough();
export type PaymentMethod = z.infer<typeof PaymentMethodSchema>;

export const CashRegisterSchema = z.object({
  id: z.number().int(),
  branch_id: z.number().int().nullable().optional(),
  code: z.string().optional().nullable(),
  name: z.string(),
  status: z.string().optional().nullable(),
  is_active: z.boolean().optional(),
  open_session: z.unknown().nullable().optional(),
}).passthrough();
export type CashRegister = z.infer<typeof CashRegisterSchema>;

export const CashRegisterSessionSchema = z.object({
  id: z.number().int(),
  branch_id: z.number().int(),
  cash_register_id: z.number().int().nullable().optional(),
  cashier_id: z.number().int().nullable().optional(),
  status: z.string(),
  opening_base_amount: nullableNumber,
  opening_local_amount: nullableNumber,
  expected_base_amount: nullableNumber,
  expected_local_amount: nullableNumber,
  counted_base_amount: nullableNumber,
  counted_local_amount: nullableNumber,
  difference_base_amount: nullableNumber,
  difference_local_amount: nullableNumber,
  opened_at: z.string().nullable().optional(),
  closed_at: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  closing_notes: z.string().nullable().optional(),
  cash_register: CashRegisterSchema.nullable().optional(),
  branch: BranchSchema.nullable().optional(),
  cashier: z.object({ id: z.number().int(), name: z.string().nullable().optional(), email: z.string().nullable().optional() }).nullable().optional(),
  closer: z.object({ id: z.number().int(), name: z.string().nullable().optional(), email: z.string().nullable().optional() }).nullable().optional(),
  movements: z.array(z.object({
    id: z.number().int(),
    type: z.string(),
    method: z.string().nullable().optional(),
    currency: z.string(),
    amount: nullableNumber,
    amount_base: nullableNumber,
    amount_local: nullableNumber,
    notes: z.string().nullable().optional(),
    created_at: z.string().nullable().optional(),
  }).passthrough()).optional(),
}).passthrough();
export type CashRegisterSession = z.infer<typeof CashRegisterSessionSchema>;

export const PosPaymentSchema = z.object({
  id: z.number().int().optional(),
  method: z.string(),
  currency: z.enum(['USD', 'VES']),
  amount: nullableNumber,
  status: z.string().optional().nullable(),
  reference: z.string().nullable().optional(),
}).passthrough();
export type PosPayment = z.infer<typeof PosPaymentSchema>;

export const ProductSerialSchema = z.object({
  id: z.number().int(),
  serial_type: z.string().optional().nullable(),
  serial_number: z.string(),
  status: z.string(),
  warehouse_id: z.number().int().nullable().optional(),
  warehouse_name: z.string().nullable().optional(),
}).passthrough();
export type ProductSerial = z.infer<typeof ProductSerialSchema>;

export const CurrentExchangeRateSchema = z.object({
  id: z.number().int(),
  exchange_rate_type_id: z.number().int(),
  exchange_rate_type_code: z.string().optional().nullable(),
  exchange_rate_type_name: z.string().optional().nullable(),
  base_currency: z.string(),
  quote_currency: z.string(),
  rate: z.union([z.number(), z.string()]).transform(Number),
  is_active: z.boolean().optional(),
}).passthrough();
export type CurrentExchangeRate = z.infer<typeof CurrentExchangeRateSchema>;

export const PosProductQuoteSchema = z.object({
  product_id: z.number().int(),
  price_list_id: z.number().int().nullable().optional(),
  price_list_name: z.string().nullable().optional(),
  price_source: z.string().optional(),
  base_price_usd: z.union([z.number(), z.string()]).transform(Number),
  sale_currency: z.enum(['USD', 'VES']),
  sale_price: z.union([z.number(), z.string()]).transform(Number),
  price_usd: z.union([z.number(), z.string()]).transform(Number),
  price_ves: z.union([z.number(), z.string()]).nullable().optional().transform((value) => (value == null ? null : Number(value))),
  exchange_rate_type_id: z.number().int().nullable().optional(),
  exchange_rate_type_code: z.string().nullable().optional(),
  exchange_rate: z.union([z.number(), z.string()]).nullable().optional().transform((value) => (value == null ? null : Number(value))),
}).passthrough();
export type PosProductQuote = z.infer<typeof PosProductQuoteSchema>;

export const PosOrderSchema = z.object({
  id: z.number().int(),
  sale_id: z.number().int().nullable().optional(),
  cash_register_session_id: z.number().int(),
  customer_id: z.number().int().nullable().optional(),
  status: z.string(),
  customer_name: z.string().nullable().optional(),
  total_base_amount: nullableNumber,
  paid_base_amount: nullableNumber,
  opened_at: z.string().nullable().optional(),
  paid_at: z.string().nullable().optional(),
  payments: z.array(PosPaymentSchema).optional(),
  sale: z.unknown().optional(),
}).passthrough();
export type PosOrder = z.infer<typeof PosOrderSchema>;

export const CustomerSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  tax_id: z.string().nullable().optional(),
  document_type: z.string().nullable().optional(),
  document_number: z.string().nullable().optional(),
  fiscal_address: z.string().nullable().optional(),
}).passthrough();
export type Customer = z.infer<typeof CustomerSchema>;

export interface CheckoutPayload {
  cash_register_session_id: number;
  customer_id?: number | null;
  customer_name?: string | null;
  credit?: boolean;
  credit_due_date?: string | null;
  items: Array<{
    warehouse_id: number;
    product_id: number;
    price_list_id?: number | null;
    quantity: number;
    discount_type?: 'percent' | 'fixed' | null;
    discount_value?: number | null;
    discount_reason?: string | null;
    product_unit_ids?: number[];
  }>;
  payments: Array<{
    payment_method_id?: number | null;
    method: PosPaymentMethod;
    currency: 'USD' | 'VES';
    amount: number;
    exchange_rate_type_id?: number | null;
    status?: 'captured' | 'pending' | 'failed';
    reference?: string | null;
  }>;
}

export interface OpenCashSessionPayload {
  branch_id: number;
  cash_register_id?: number | null;
  opening_currency?: 'USD' | 'VES';
  opening_amount?: number;
  opening_base_amount?: number;
  opening_local_amount?: number;
  exchange_rate_type_id?: number | null;
  notes?: string | null;
}

export interface CashMovementPayload {
  type: 'inflow' | 'outflow' | 'adjustment';
  method: PosPaymentMethod;
  currency: 'USD' | 'VES';
  amount: number;
  reference?: string | null;
  notes?: string | null;
}

export interface CloseCashSessionPayload {
  counted_currency?: 'USD' | 'VES';
  counted_amount?: number;
  counted_base_amount?: number;
  counted_local_amount?: number;
  exchange_rate_type_id?: number | null;
  closing_notes?: string | null;
}

export interface CreateBranchPayload {
  name: string;
  code: string;
  status?: 'active' | 'inactive';
}

export interface CreateCashRegisterPayload {
  branch_id: number;
  name: string;
  code: string;
  status?: 'active' | 'inactive';
  notes?: string | null;
}

export interface PaymentMethodPayload {
  name: string;
  code: string;
  method: PosPaymentMethod;
  currency_mode: 'USD' | 'VES' | 'flexible';
  requires_reference?: boolean;
  is_active?: boolean;
  sort_order?: number;
}

export interface CreateCustomerPayload {
  name: string;
  document_type: 'V' | 'E' | 'J' | 'G' | 'P';
  document_number: string;
  phone?: string | null;
  email?: string | null;
  fiscal_address?: string | null;
  is_active?: boolean;
  is_generic?: boolean;
}

export const posKeys = {
  all: ['pos'] as const,
  products: (filters: Partial<InventoryFilters>) => [...posKeys.all, 'products', filters] as const,
  orders: (status?: string) => [...posKeys.all, 'orders', status ?? 'all'] as const,
  cashSessions: (filters?: string) => [...posKeys.all, 'cash-sessions', filters ?? 'me-open'] as const,
  cashRegisters: () => [...posKeys.all, 'cash-registers'] as const,
  paymentMethods: () => [...posKeys.all, 'payment-methods'] as const,
  priceLists: () => [...posKeys.all, 'price-lists'] as const,
  productQuote: (productId: number, priceListId?: number | null) => [...posKeys.all, 'product-quote', productId, priceListId ?? 'default'] as const,
  exchangeRateTypes: () => [...posKeys.all, 'exchange-rate-types'] as const,
  currentRates: () => [...posKeys.all, 'current-rates'] as const,
  customers: (search: string) => [...posKeys.all, 'customers', search] as const,
  productSerials: (productId: number, warehouseId?: number | null) => [...posKeys.all, 'product-serials', productId, warehouseId ?? 'all'] as const,
};

export function usePosProducts(search: string, warehouseId?: number | null, options: { enabled?: boolean } = {}) {
  const filters: InventoryFilters = {
    search,
    tracking_type: 'all',
    stock_status: 'all',
    active_status: 'active',
    page: 1,
    per_page: 12,
    warehouse_id: warehouseId || undefined,
  };

  return useProducts(filters, options);
}

/**
 * Busqueda POS con debounce de 200ms y AbortController:
 * - Cancela la query anterior cuando el usuario sigue escribiendo
 *   para evitar respuestas obsoletas que pisen resultados mas recientes.
 * - Mantiene el ultimo snapshot (`placeholderData`) mientras llega la
 *   nueva respuesta para que la UI no parpadee con esqueletos.
 *
 * Retorna ademas el `debouncedSearch` para que el consumidor sepa que
 * lo que ve corresponde al query ya estabilizado.
 */
export function usePosProductsDebounced(
  search: string,
  warehouseId?: number | null,
  options: { enabled?: boolean; debounceMs?: number } = {},
) {
  const debounceMs = options.debounceMs ?? 200;
  const [debouncedSearch, setDebouncedSearch] = useState(search);

  const enabled = options.enabled ?? true;
  useEffect(() => {
    if (!enabled) return;
    const handle = window.setTimeout(() => setDebouncedSearch(search), debounceMs);
    return () => window.clearTimeout(handle);
  }, [search, debounceMs, enabled]);

  const query = usePosProducts(debouncedSearch, warehouseId, {
    enabled,
  });

  // Referencia estable al AbortController para que `queryFn` pueda cancelar
  // el fetch en curso cuando React Query lo descarte por una query mas
  // reciente (gestionado automaticamente por TanStack Query via signal).
  const abortRef = useRef<AbortController | null>(null);

  return { ...query, debouncedSearch, abortRef };
}

export function useOpenPosOrders() {
  return useQuery({
    queryKey: posKeys.orders('open'),
    queryFn: async () => {
      const response = await getPaginated<unknown>('/pos/orders?status=open&per_page=50');
      return z.array(PosOrderSchema).parse(response.data);
    },
  });
}

export function useCheckout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CheckoutPayload) => postOne<CheckoutPayload, PosOrder>('/pos/checkouts', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.orders('open') });
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'cash-sessions'] });
    },
  });
}

export function useAddPosPayments() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ orderId, payments }: { orderId: number; payments: CheckoutPayload['payments'] }) =>
      postOne<{ payments: CheckoutPayload['payments'] }, PosOrder>(`/pos/orders/${orderId}/payments`, { payments }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.orders('open') });
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'cash-sessions'] });
    },
  });
}

export function useCancelPosOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (orderId: number) => postOne<Record<string, never>, PosOrder>(`/pos/orders/${orderId}/cancel`, {}),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.orders('open') });
    },
  });
}

export function useCashSessions() {
  return useQuery({
    queryKey: posKeys.cashSessions(),
    queryFn: async () => {
      const response = await getPaginated<unknown>('/cash-register/sessions?status=open&cashier_id=me&per_page=25');
      return z.array(CashRegisterSessionSchema).parse(response.data);
    },
  });
}

export const BootstrapWarehouseSchema = z.object({
  id: z.number().int(),
  branch_id: z.number().int().nullish(),
  code: z.string().nullish(),
  name: z.string(),
  status: z.string().nullish(),
  branch_name: z.string().nullish(),
  branch_code: z.string().nullish(),
}).transform((value) => ({
  id: value.id,
  branch_id: value.branch_id ?? null,
  code: value.code ?? '',
  name: value.name,
  status: value.status ?? 'active',
  branch_name: value.branch_name ?? null,
  branch_code: value.branch_code ?? null,
}));
export type BootstrapWarehouse = z.infer<typeof BootstrapWarehouseSchema>;

export const BootstrapCashRegisterSchema = z.object({
  id: z.number().int(),
  branch_id: z.number().int().nullish(),
  code: z.string().nullish(),
  name: z.string(),
  branch_name: z.string().nullish(),
}).transform((value) => ({
  id: value.id,
  branch_id: value.branch_id ?? null,
  code: value.code ?? '',
  name: value.name,
  branch_name: value.branch_name ?? null,
}));
export type BootstrapCashRegister = z.infer<typeof BootstrapCashRegisterSchema>;

export const BootstrapBranchSchema = z.object({
  id: z.number().int(),
  code: z.string().nullish(),
  name: z.string(),
}).transform((value) => ({
  id: value.id,
  code: value.code ?? '',
  name: value.name,
}));
export type BootstrapBranch = z.infer<typeof BootstrapBranchSchema>;

export const BootstrapExchangeRateTypeSchema = z.object({
  id: z.number().int(),
  code: z.string().nullish(),
  name: z.string(),
  is_default: z.boolean().nullish(),
}).transform((value) => ({
  id: value.id,
  code: value.code ?? '',
  name: value.name,
  is_default: value.is_default ?? false,
}));
export type BootstrapExchangeRateType = z.infer<typeof BootstrapExchangeRateTypeSchema>;

export const BootstrapExchangeRateSchema = z.object({
  id: z.number().int(),
  exchange_rate_type_id: z.number().int(),
  base_currency: z.string(),
  quote_currency: z.string(),
  rate: z.number(),
  effective_at: z.string().nullable().optional(),
}).passthrough();
export type BootstrapExchangeRate = z.infer<typeof BootstrapExchangeRateSchema>;

export const PosBootstrapSchema = z.object({
  warehouses: z.array(BootstrapWarehouseSchema),
  branches: z.array(BootstrapBranchSchema),
  cash_registers: z.array(BootstrapCashRegisterSchema),
  payment_methods: z.array(PaymentMethodSchema),
  price_lists: z.array(PriceListSchema),
  exchange_rate_types: z.array(BootstrapExchangeRateTypeSchema),
  exchange_rates: z.array(BootstrapExchangeRateSchema),
  open_session: CashRegisterSessionSchema.nullable(),
});
export type PosBootstrap = z.infer<typeof PosBootstrapSchema>;

/**
 * Hook crudo: devuelve el payload completo del bootstrap sin normalizar.
 * Para datos derivados con tipos estrictos usar `useBootstrapRefsForPos`.
 *
 * El endpoint `/pos/bootstrap` no envuelve la respuesta en `{ data: ... }`
 * (es un objeto plano con varias claves), por eso usamos `api.get` directamente
 * y devolvemos `response.data` completo en vez de `response.data.data`.
 */
export function usePosBootstrap() {
  return useQuery({
    queryKey: [...posKeys.all, 'bootstrap'] as const,
    queryFn: async () => {
      const response = await api.get<unknown>('/pos/bootstrap');
      return PosBootstrapSchema.parse(response.data);
    },
    staleTime: 60_000,
  });
}

export interface BootstrapRefs {
  warehouses: Array<{
    id: number;
    branch_id: number | null;
    code: string;
    name: string;
    status: 'active' | 'inactive';
    branch_name: string | null;
    branch_code: string | null;
  }>;
  cash_registers: Array<{
    id: number;
    branch_id: number | null;
    code: string;
    name: string;
  }>;
  branches: Array<{
    id: number;
    code: string;
    name: string;
  }>;
}

export interface BootstrapCombined {
  refs: BootstrapRefs | undefined;
}

/**
 * Combina el payload del bootstrap con las refs normalizadas (warehouses,
 * branches, cash_registers con `code` no-null). Reutiliza la cache de
 * `usePosBootstrap` para evitar un fetch duplicado.
 */
export function useBootstrapRefsForPos() {
  const query = usePosBootstrap();
  const refs = useMemo<BootstrapCombined['refs']>(() => {
    if (!query.data) return undefined;
    return {
      warehouses: query.data.warehouses.map((warehouse) => ({
        id: warehouse.id,
        branch_id: warehouse.branch_id,
        code: warehouse.code ?? '',
        name: warehouse.name,
        status: (warehouse.status ?? 'active') as 'active' | 'inactive',
        branch_name: warehouse.branch_name,
        branch_code: warehouse.branch_code,
      })),
      cash_registers: query.data.cash_registers.map((register) => ({
        id: register.id,
        branch_id: register.branch_id,
        code: register.code ?? '',
        name: register.name,
        branch_name: register.branch_name,
      })),
      branches: query.data.branches.map((branch) => ({
        id: branch.id,
        code: branch.code ?? '',
        name: branch.name,
      })),
    };
  }, [query.data]);
  return { ...query, refs };
}

export function useCashSessionsList(filters: { status?: 'open' | 'closed' | 'cancelled'; cashier?: 'me'; perPage?: number } = {}) {
  const params = new URLSearchParams({ per_page: String(filters.perPage ?? 25) });
  if (filters.status) params.set('status', filters.status);
  if (filters.cashier) params.set('cashier_id', filters.cashier);

  return useQuery({
    queryKey: posKeys.cashSessions(params.toString()),
    queryFn: async () => {
      const response = await getPaginated<unknown>(`/cash-register/sessions?${params.toString()}`);
      return z.array(CashRegisterSessionSchema).parse(response.data);
    },
  });
}

export function useCashRegisters() {
  return useQuery({
    queryKey: posKeys.cashRegisters(),
    queryFn: async () => {
      const data = await getMany<unknown>('/cash-register/registers');
      return z.array(CashRegisterSchema).parse(data);
    },
  });
}

export function useBranchesForPos() {
  return useQuery({
    queryKey: [...posKeys.all, 'branches'],
    queryFn: async () => {
      const response = await getPaginated<unknown>('/branches?per_page=100');
      return z.array(BranchSchema).parse(response.data);
    },
  });
}

export function useWarehousesForPos() {
  return useQuery({
    queryKey: [...posKeys.all, 'warehouses'],
    queryFn: async () => z.array(WarehouseSchema).parse(await getMany<unknown>('/warehouses')),
  });
}

export function usePaymentMethods() {
  return useQuery({
    queryKey: posKeys.paymentMethods(),
    queryFn: async () => z.array(PaymentMethodSchema).parse(await getMany<unknown>('/payment-methods')),
  });
}

export function usePriceListsForPos() {
  return useQuery({
    queryKey: posKeys.priceLists(),
    queryFn: async () => z.array(PriceListSchema).parse(await getMany<unknown>('/price-lists?active_only=1')),
  });
}

export async function quoteProductForPos(productId: number, priceListId: number | null): Promise<PosProductQuote> {
  const params = new URLSearchParams();
  if (priceListId) params.set('price_list_id', String(priceListId));

  return PosProductQuoteSchema.parse(await getOne<unknown>(`/products/${productId}/price${params.toString() ? `?${params.toString()}` : ''}`));
}

export function useExchangeRateTypesForPos() {
  return useQuery({
    queryKey: posKeys.exchangeRateTypes(),
    queryFn: async () => z.array(ExchangeRateTypeSchema).parse(await getMany<unknown>('/currency/rate-types')),
  });
}

export function useCurrentExchangeRatesForPos() {
  return useQuery({
    queryKey: posKeys.currentRates(),
    queryFn: async () => z.array(CurrentExchangeRateSchema).parse(await getMany<unknown>('/currency/rates/current')),
  });
}

export function useAvailableProductSerialsForPos(productId: number | null, warehouseId?: number | null) {
  return useQuery({
    queryKey: posKeys.productSerials(productId ?? 0, warehouseId),
    queryFn: async () => {
      const params = new URLSearchParams({ status: 'available', limit: '100' });
      if (warehouseId) params.set('warehouse_id', String(warehouseId));
      const response = await getOne<unknown>(`/inventory-center/products/${productId}/serials?${params.toString()}`);
      const items = typeof response === 'object' && response && 'data' in response && Array.isArray((response as { data?: unknown }).data)
        ? (response as { data: unknown[] }).data
        : [];
      return z.array(ProductSerialSchema).parse(items);
    },
    enabled: Number.isFinite(productId) && Number(productId) > 0,
  });
}

export function useCreatePaymentMethod() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: PaymentMethodPayload) => postOne<PaymentMethodPayload, PaymentMethod>('/payment-methods', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.paymentMethods() });
    },
  });
}

export function useUpdatePaymentMethod() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, ...payload }: { id: number } & Partial<PaymentMethodPayload>) =>
      patchOne<Partial<PaymentMethodPayload>, PaymentMethod>(`/payment-methods/${id}`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.paymentMethods() });
    },
  });
}

export function useDeletePaymentMethod() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => deleteOne(`/payment-methods/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.paymentMethods() });
    },
  });
}

export function useCustomers(search: string) {
  return useQuery({
    queryKey: posKeys.customers(search),
    queryFn: async () => {
      const response = await getPaginated<unknown>(`/customers?search=${encodeURIComponent(search)}&per_page=8`);
      return z.array(CustomerSchema).parse(response.data);
    },
    enabled: search.trim().length >= 2,
  });
}

export function useCreateCustomerForPos() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateCustomerPayload) => postOne<CreateCustomerPayload, Customer>('/customers', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'customers'] });
    },
  });
}

export function useOpenCashSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: OpenCashSessionPayload) =>
      postOne<OpenCashSessionPayload, CashRegisterSession>('/cash-register/sessions', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'cash-sessions'] });
      void qc.invalidateQueries({ queryKey: posKeys.cashRegisters() });
    },
  });
}

export function useCreatePosBranch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateBranchPayload) => postOne<CreateBranchPayload, unknown>('/branches', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'branches'] });
    },
  });
}

export function useCreateCashRegister() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateCashRegisterPayload) =>
      postOne<CreateCashRegisterPayload, CashRegister>('/cash-register/registers', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.cashRegisters() });
    },
  });
}

export function useAddCashMovement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ sessionId, payload }: { sessionId: number; payload: CashMovementPayload }) =>
      postOne<CashMovementPayload, CashRegisterSession>(`/cash-register/sessions/${sessionId}/movements`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'cash-sessions'] });
      void qc.invalidateQueries({ queryKey: posKeys.cashRegisters() });
    },
  });
}

export function useCloseCashSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ sessionId, payload }: { sessionId: number; payload: CloseCashSessionPayload }) =>
      patchOne<CloseCashSessionPayload, CashRegisterSession>(`/cash-register/sessions/${sessionId}/close`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: [...posKeys.all, 'cash-sessions'] });
      void qc.invalidateQueries({ queryKey: posKeys.cashRegisters() });
    },
  });
}

export { ProductSchema };
