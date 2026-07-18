import { z } from 'zod';

const moneyValue = z.union([z.number(), z.string()]).nullable().optional().transform((value) => {
  if (value === null || value === undefined || value === '') return 0;
  const n = typeof value === 'string' ? Number(value) : value;
  return Number.isFinite(n) ? n : 0;
});

export const ReceivableStatusSchema = z.enum(['pending', 'partial', 'paid', 'overdue']);
export type ReceivableStatus = z.infer<typeof ReceivableStatusSchema>;

export const RECEIVABLE_STATUS_LABELS: Record<ReceivableStatus, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  paid: 'Pagada',
  overdue: 'Vencida',
};

export interface ReceivableListFilters {
  search?: string;
  status?: 'all' | 'open' | ReceivableStatus;
  customer_id?: number;
  due_from?: string;
  due_to?: string;
  page?: number;
  limit?: number;
}

const CustomerSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  document_type: z.string().nullable().optional(),
  document_number: z.string().nullable().optional(),
  email: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
}).passthrough();

const SaleItemSchema = z.object({
  id: z.number().int().positive(),
  product_name: z.string().nullable().optional(),
  product_sku: z.string().nullable().optional(),
  warehouse_name: z.string().nullable().optional(),
  quantity: moneyValue,
  total_base_amount: moneyValue,
}).passthrough();

const SaleSchema = z.object({
  id: z.number().int().positive(),
  status: z.string(),
  total_base_amount: moneyValue,
  total_local_amount: moneyValue,
  created_at: z.string().nullable().optional(),
  confirmed_at: z.string().nullable().optional(),
  items: z.array(SaleItemSchema).optional(),
}).passthrough();

export const ReceivablePaymentSchema = z.object({
  id: z.number().int().positive(),
  payment_currency: z.enum(['USD', 'VES']),
  amount: moneyValue,
  exchange_rate_type_id: z.number().int().positive().nullable().optional(),
  exchange_rate_type_code: z.string().nullable().optional(),
  exchange_rate: moneyValue,
  amount_base: moneyValue,
  amount_local: moneyValue,
  method: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  created_by: z.number().int().positive().nullable().optional(),
  paid_at: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
}).passthrough();

export const ReceivableSchema = z.object({
  id: z.number().int().positive(),
  customer_id: z.number().int().positive().nullable().optional(),
  customer: CustomerSchema.nullable().optional(),
  sale_id: z.number().int().positive(),
  sale: SaleSchema.nullable().optional(),
  status: ReceivableStatusSchema,
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
  payments: z.array(ReceivablePaymentSchema).optional(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
}).passthrough();

export const CollectReceivableSchema = z.object({
  payment_currency: z.enum(['USD', 'VES']),
  amount: z.number().positive(),
  cash_register_session_id: z.number().int().positive(),
  exchange_rate_type_id: z.number().int().positive().nullable().optional(),
  exchange_rate: z.number().positive().nullable().optional(),
  method: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  paid_at: z.string().nullable().optional(),
});

export type Receivable = z.infer<typeof ReceivableSchema>;
export type ReceivablePayment = z.infer<typeof ReceivablePaymentSchema>;
export type CollectReceivableValues = z.infer<typeof CollectReceivableSchema>;
