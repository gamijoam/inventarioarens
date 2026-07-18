import { z } from 'zod';

const moneyValue = z.union([z.number(), z.string()]).nullable().optional().transform((value) => {
  if (value === null || value === undefined) return 0;
  const n = typeof value === 'string' ? Number(value) : value;
  return Number.isFinite(n) ? n : 0;
});

export const SaleStatusSchema = z.enum(['draft', 'confirmed', 'cancelled']);
export type SaleStatus = z.infer<typeof SaleStatusSchema>;

export const SALE_STATUS_LABELS: Record<SaleStatus, string> = {
  draft: 'Borrador',
  confirmed: 'Confirmada',
  cancelled: 'Cancelada',
};

export interface SaleListFilters {
  search?: string;
  status?: 'all' | SaleStatus;
  customer_id?: number;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

const SaleCustomerSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  document_type: z.string().nullable().optional(),
  document_number: z.string().nullable().optional(),
  email: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
}).passthrough();

const SalePosOrderSchema = z.object({
  id: z.number().int().positive(),
  status: z.string(),
  cashier_id: z.number().int().positive().nullable().optional(),
  cashier_name: z.string().nullable().optional(),
  cash_register_session_id: z.number().int().positive().nullable().optional(),
  total_base_amount: moneyValue,
  total_local_amount: moneyValue,
  paid_base_amount: moneyValue,
  paid_local_amount: moneyValue,
  paid_at: z.string().nullable().optional(),
  cash_register_session: z.object({
    id: z.number().int().positive(),
    status: z.string(),
    branch_id: z.number().int().positive().nullable().optional(),
    branch_name: z.string().nullable().optional(),
    cash_register_id: z.number().int().positive().nullable().optional(),
    cash_register_name: z.string().nullable().optional(),
    opened_at: z.string().nullable().optional(),
    closed_at: z.string().nullable().optional(),
  }).nullable().optional(),
  payments: z.array(z.object({
    id: z.number().int().positive(),
    payment_method_id: z.number().int().positive().nullable().optional(),
    payment_method_name: z.string().nullable().optional(),
    method: z.string(),
    currency: z.string().nullable().optional(),
    amount: moneyValue,
    amount_base: moneyValue,
    amount_local: moneyValue,
    exchange_rate_type_code: z.string().nullable().optional(),
    exchange_rate: moneyValue,
    status: z.string(),
    reference: z.string().nullable().optional(),
    external_provider: z.string().nullable().optional(),
    created_at: z.string().nullable().optional(),
  }).passthrough()).optional(),
}).nullable().optional();

const SaleReceivableSchema = z.object({
  id: z.number().int().positive(),
  status: z.string(),
  document_number: z.string().nullable().optional(),
  currency: z.string().nullable().optional(),
  original_base_amount: moneyValue,
  original_local_amount: moneyValue,
  returned_base_amount: moneyValue,
  returned_local_amount: moneyValue,
  collected_base_amount: moneyValue,
  collected_local_amount: moneyValue,
  adjusted_base_amount: moneyValue,
  adjusted_local_amount: moneyValue,
  balance_base_amount: moneyValue,
  balance_local_amount: moneyValue,
  due_date: z.string().nullable().optional(),
  opened_at: z.string().nullable().optional(),
  paid_at: z.string().nullable().optional(),
}).nullable().optional();

export const SaleItemSchema = z.object({
  id: z.number().int().positive(),
  sale_id: z.number().int().positive().optional(),
  warehouse_id: z.number().int().positive(),
  warehouse_name: z.string().nullable().optional(),
  product_id: z.number().int().positive(),
  product_name: z.string().nullable().optional(),
  product_sku: z.string().nullable().optional(),
  product_unit_ids: z.array(z.number()).nullable().optional(),
  serial_units: z.array(z.object({
    id: z.number().optional(),
    serial_number: z.string().nullable().optional(),
    status: z.string().nullable().optional(),
  }).passthrough()).nullable().optional(),
  quantity: moneyValue,
  sale_currency: z.string().nullable().optional(),
  unit_price: moneyValue,
  base_unit_price: moneyValue,
  total_price: moneyValue,
  total_base_amount: moneyValue,
  discount_type: z.string().nullable().optional(),
  discount_value: moneyValue,
  discount_amount: moneyValue,
  exchange_rate_type_id: z.number().int().positive().nullable().optional(),
  exchange_rate_type_code: z.string().nullable().optional(),
  exchange_rate: moneyValue,
  warranty_starts_at: z.string().nullable().optional(),
  warranty_ends_at: z.string().nullable().optional(),
  warranty_days: z.number().nullable().optional(),
}).passthrough();

export const SaleSchema = z.object({
  id: z.number().int().positive(),
  tenant_id: z.number().int().positive().optional(),
  status: SaleStatusSchema,
  customer_id: z.number().int().positive().nullable().optional(),
  total_base_amount: moneyValue,
  total_local_amount: moneyValue,
  created_by: z.number().int().positive().nullable().optional(),
  created_by_name: z.string().nullable().optional(),
  items_count: z.number().nullable().optional(),
  confirmed_at: z.string().nullable().optional(),
  cancelled_at: z.string().nullable().optional(),
  customer: SaleCustomerSchema.nullable().optional(),
  items: z.array(SaleItemSchema).optional(),
  pos_order: SalePosOrderSchema,
  receivable: SaleReceivableSchema,
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
}).passthrough();

export type Sale = z.infer<typeof SaleSchema>;
export type SaleItem = z.infer<typeof SaleItemSchema>;
