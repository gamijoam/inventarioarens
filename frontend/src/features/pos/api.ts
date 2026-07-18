import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { deleteOne, getMany, getPaginated, patchOne, postOne } from '@/api/client';
import { useProducts } from '@/features/inventory-center/api';
import { BranchSchema, ExchangeRateTypeSchema, ProductSchema, WarehouseSchema } from '@/features/inventory-center/schemas';
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
  expected_base_amount: nullableNumber,
  counted_base_amount: nullableNumber,
  difference_base_amount: nullableNumber,
  opened_at: z.string().nullable().optional(),
  closed_at: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  cash_register: CashRegisterSchema.nullable().optional(),
  branch: BranchSchema.nullable().optional(),
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
}).passthrough();
export type Customer = z.infer<typeof CustomerSchema>;

export interface CheckoutPayload {
  cash_register_session_id: number;
  customer_id?: number | null;
  customer_name?: string | null;
  items: Array<{
    warehouse_id: number;
    product_id: number;
    price_list_id?: number | null;
    quantity: number;
    discount_type?: 'percent' | 'fixed' | null;
    discount_value?: number | null;
    discount_reason?: string | null;
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
  opening_currency: 'USD' | 'VES';
  opening_amount: number;
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
  counted_currency: 'USD' | 'VES';
  counted_amount: number;
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

export const posKeys = {
  all: ['pos'] as const,
  products: (filters: Partial<InventoryFilters>) => [...posKeys.all, 'products', filters] as const,
  orders: (status?: string) => [...posKeys.all, 'orders', status ?? 'all'] as const,
  cashSessions: () => [...posKeys.all, 'cash-sessions'] as const,
  cashRegisters: () => [...posKeys.all, 'cash-registers'] as const,
  paymentMethods: () => [...posKeys.all, 'payment-methods'] as const,
  exchangeRateTypes: () => [...posKeys.all, 'exchange-rate-types'] as const,
  currentRates: () => [...posKeys.all, 'current-rates'] as const,
  customers: (search: string) => [...posKeys.all, 'customers', search] as const,
};

export function usePosProducts(search: string, warehouseId?: number | null) {
  const filters: InventoryFilters = {
    search,
    tracking_type: 'all',
    stock_status: 'all',
    active_status: 'active',
    page: 1,
    per_page: 12,
    warehouse_id: warehouseId || undefined,
  };

  return useProducts(filters);
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
      void qc.invalidateQueries({ queryKey: posKeys.cashSessions() });
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
      void qc.invalidateQueries({ queryKey: posKeys.cashSessions() });
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
      const response = await getPaginated<unknown>('/cash-register/sessions?status=open&per_page=25');
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

export function useOpenCashSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: OpenCashSessionPayload) =>
      postOne<OpenCashSessionPayload, CashRegisterSession>('/cash-register/sessions', payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.cashSessions() });
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
      void qc.invalidateQueries({ queryKey: posKeys.cashSessions() });
    },
  });
}

export function useCloseCashSession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ sessionId, payload }: { sessionId: number; payload: CloseCashSessionPayload }) =>
      patchOne<CloseCashSessionPayload, CashRegisterSession>(`/cash-register/sessions/${sessionId}/close`, payload),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: posKeys.cashSessions() });
    },
  });
}

export { ProductSchema };
