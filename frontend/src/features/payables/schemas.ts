import { z } from 'zod';

const moneyValue = z
  .union([z.number(), z.string()])
  .nullable()
  .optional()
  .transform((value) => {
    if (value === null || value === undefined || value === '') return 0;
    const n = typeof value === 'string' ? Number(value) : value;
    return Number.isFinite(n) ? n : 0;
  });

export const PayableStatusSchema = z.enum(['pending', 'partial', 'paid', 'overdue']);
export type PayableStatus = z.infer<typeof PayableStatusSchema>;

export const PAYABLE_STATUS_LABELS: Record<PayableStatus, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  paid: 'Pagada',
  overdue: 'Vencida',
};

export interface PayableListFilters {
  search?: string;
  status?: 'all' | 'open' | PayableStatus;
  supplier_id?: number;
  due_from?: string;
  due_to?: string;
  page?: number;
  limit?: number;
}

const SupplierSchema = z
  .object({
    id: z.number().int().positive(),
    name: z.string(),
    document_type: z.string().nullable().optional(),
    document_number: z.string().nullable().optional(),
    email: z.string().nullable().optional(),
    phone: z.string().nullable().optional(),
  })
  .passthrough();

const PurchaseItemSchema = z
  .object({
    id: z.number().int().positive(),
    product_id: z.number().int().positive().nullable().optional(),
    quantity: moneyValue,
    received_quantity: moneyValue,
    unit_cost: moneyValue,
    total_cost: moneyValue,
    base_unit_cost: moneyValue,
    base_total_cost: moneyValue,
    product: z
      .object({
        name: z.string().nullable().optional(),
        sku: z.string().nullable().optional(),
      })
      .passthrough()
      .nullable()
      .optional(),
  })
  .passthrough();

const PurchaseOrderSchema = z
  .object({
    id: z.number().int().positive(),
    status: z.string(),
    document_number: z.string().nullable().optional(),
    issued_at: z.string().nullable().optional(),
    due_date: z.string().nullable().optional(),
    total_base_amount: moneyValue,
    total_local_amount: moneyValue,
    items: z.array(PurchaseItemSchema).optional(),
  })
  .passthrough();

export const PayablePaymentSchema = z
  .object({
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
  })
  .passthrough();

export const PayablePaymentRequestStatusSchema = z.enum([
  'prepared',
  'approved',
  'rejected',
  'cancelled',
  'executed',
]);
export type PayablePaymentRequestStatus = z.infer<typeof PayablePaymentRequestStatusSchema>;

export const PAYABLE_PAYMENT_REQUEST_STATUS_LABELS: Record<PayablePaymentRequestStatus, string> = {
  prepared: 'Pendiente de aprobacion',
  approved: 'Aprobada',
  rejected: 'Rechazada',
  cancelled: 'Cancelada',
  executed: 'Ejecutada',
};

export const PayablePaymentRequestSchema = z
  .object({
    id: z.number().int().positive(),
    accounts_payable_id: z.number().int().positive(),
    accounts_payable_payment_id: z.number().int().positive().nullable().optional(),
    status: PayablePaymentRequestStatusSchema,
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
    scheduled_for: z.string().nullable().optional(),
    cash_register_session_id: z.number().int().positive().nullable().optional(),
    prepared_by: z.number().int().positive().nullable().optional(),
    approved_by: z.number().int().positive().nullable().optional(),
    rejected_by: z.number().int().positive().nullable().optional(),
    cancelled_by: z.number().int().positive().nullable().optional(),
    executed_by: z.number().int().positive().nullable().optional(),
    prepared_at: z.string().nullable().optional(),
    approved_at: z.string().nullable().optional(),
    rejected_at: z.string().nullable().optional(),
    cancelled_at: z.string().nullable().optional(),
    executed_at: z.string().nullable().optional(),
    rejection_reason: z.string().nullable().optional(),
    cancellation_reason: z.string().nullable().optional(),
    payment: PayablePaymentSchema.nullable().optional(),
  })
  .passthrough();

export const PayableSchema = z
  .object({
    id: z.number().int().positive(),
    supplier_id: z.number().int().positive().nullable().optional(),
    supplier: SupplierSchema.nullable().optional(),
    purchase_order_id: z.number().int().positive().nullable().optional(),
    purchase_order: PurchaseOrderSchema.nullable().optional(),
    status: PayableStatusSchema,
    document_number: z.string().nullable().optional(),
    currency: z.string().nullable().optional(),
    original_base_amount: moneyValue,
    original_local_amount: moneyValue,
    returned_base_amount: moneyValue,
    returned_local_amount: moneyValue,
    paid_base_amount: moneyValue,
    paid_local_amount: moneyValue,
    adjusted_base_amount: moneyValue,
    adjusted_local_amount: moneyValue,
    balance_base_amount: moneyValue,
    balance_local_amount: moneyValue,
    due_date: z.string().nullable().optional(),
    opened_at: z.string().nullable().optional(),
    paid_at: z.string().nullable().optional(),
    payments: z.array(PayablePaymentSchema).optional(),
    payment_requests: z.array(PayablePaymentRequestSchema).optional(),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
  })
  .passthrough();

export const PayPayableSchema = z.object({
  payment_currency: z.enum(['USD', 'VES']),
  amount: z.number().positive(),
  cash_register_session_id: z.number().int().positive().nullable().optional(),
  exchange_rate_type_id: z.number().int().positive().nullable().optional(),
  exchange_rate: z.number().positive().nullable().optional(),
  method: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  paid_at: z.string().nullable().optional(),
});

export const PayablePaymentRequestPayloadSchema = PayPayableSchema.omit({ paid_at: true }).extend({
  scheduled_for: z.string().nullable().optional(),
});

export const ExecutePayablePaymentRequestSchema = z.object({
  cash_register_session_id: z.number().int().positive().nullable().optional(),
  reference: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
});

export type Payable = z.infer<typeof PayableSchema>;
export type PayablePayment = z.infer<typeof PayablePaymentSchema>;
export type PayablePaymentRequest = z.infer<typeof PayablePaymentRequestSchema>;
export type PayPayableValues = z.infer<typeof PayPayableSchema>;
export type PayablePaymentRequestPayload = z.infer<typeof PayablePaymentRequestPayloadSchema>;
export type ExecutePayablePaymentRequestValues = z.infer<typeof ExecutePayablePaymentRequestSchema>;
