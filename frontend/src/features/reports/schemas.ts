import { z } from 'zod';

export const StockReportRowSchema = z.object({
  warehouse_id: z.number(),
  warehouse_name: z.string().nullable().optional(),
  product_id: z.number(),
  product_name: z.string().nullable().optional(),
  sku: z.string().nullable().optional(),
  quantity_available: z.number(),
  quantity_reserved: z.number(),
  quantity_damaged: z.number(),
});

export const MovementReportRowSchema = z.object({
  id: z.number(),
  warehouse_id: z.number(),
  warehouse_name: z.string().nullable().optional(),
  product_id: z.number(),
  product_name: z.string().nullable().optional(),
  sku: z.string().nullable().optional(),
  type: z.string(),
  quantity: z.number(),
  unit_cost: z.number().nullable().optional(),
  reason: z.string().nullable().optional(),
  created_by: z.number().nullable().optional(),
  created_at: z.string().nullable().optional(),
});

export const FinanceSummarySchema = z.object({
  currency: z.string(),
  accounts_receivable: z.object({
    total_balance_base_amount: z.number(),
    pending_count: z.number(),
    partial_count: z.number(),
    paid_count: z.number(),
    overdue_count: z.number(),
  }),
  accounts_payable: z.object({
    total_balance_base_amount: z.number(),
    pending_count: z.number(),
    partial_count: z.number(),
    paid_count: z.number(),
    overdue_count: z.number(),
  }),
  cash_flow: z.object({
    collections_base_amount: z.number(),
    supplier_payments_base_amount: z.number(),
  }),
  net_balance_base_amount: z.number(),
});

export const FinanceReceivableRowSchema = z.object({
  id: z.number(),
  customer_id: z.number().nullable().optional(),
  customer_name: z.string().nullable().optional(),
  sale_id: z.number().nullable().optional(),
  document_number: z.string().nullable().optional(),
  status: z.string(),
  original_base_amount: z.string(),
  returned_base_amount: z.string(),
  collected_base_amount: z.string(),
  balance_base_amount: z.string(),
  due_date: z.string().nullable().optional(),
  opened_at: z.string().nullable().optional(),
});

export const FinancePayableRowSchema = z.object({
  id: z.number(),
  supplier_id: z.number().nullable().optional(),
  supplier_name: z.string().nullable().optional(),
  purchase_order_id: z.number().nullable().optional(),
  document_number: z.string().nullable().optional(),
  status: z.string(),
  original_base_amount: z.string(),
  returned_base_amount: z.string(),
  paid_base_amount: z.string(),
  balance_base_amount: z.string(),
  due_date: z.string().nullable().optional(),
  opened_at: z.string().nullable().optional(),
});

export const ReportCatalogItemSchema = z.object({
  key: z.string(),
  label: z.string(),
  permission: z.string(),
  available: z.boolean(),
});

export const DailyOperationsSchema = z.object({
  period: z.object({
    from: z.string(),
    to: z.string(),
    from_datetime: z.string(),
    to_datetime: z.string(),
  }),
  currency: z.string(),
  sales: z.object({
    confirmed_count: z.number(),
    confirmed_base_amount: z.number(),
    pos_paid_count: z.number(),
    pos_paid_base_amount: z.number(),
    pos_open_count: z.number(),
    pos_open_base_amount: z.number(),
    credit_count: z.number(),
    credit_balance_base_amount: z.number(),
  }),
  returns: z.object({
    requested_count: z.number(),
    processed_count: z.number(),
  }),
  cash: z.object({
    open_count: z.number(),
    closed_count: z.number(),
    expected_base_amount: z.number(),
    expected_local_amount: z.number(),
    difference_base_amount: z.number(),
  }),
  payment_methods: z.array(
    z.object({
      method: z.string().nullable().optional(),
      currency: z.string().nullable().optional(),
      name: z.string(),
      requires_reference: z.boolean(),
      payments_count: z.number(),
      amount_base: z.number(),
      amount_local: z.number(),
      missing_reference_count: z.number(),
    }),
  ),
  alerts: z.object({
    stale_open_sessions: z.number(),
    closed_sessions_with_difference: z.number(),
    payments_missing_reference: z.number(),
    paid_pos_without_cash_session: z.number(),
  }),
  generated_at: z.string(),
});

const SerialUnitSchema = z.object({
  id: z.number(),
  serial_type: z.string().nullable().optional(),
  serial_number: z.string().nullable().optional(),
  status: z.string().nullable().optional(),
});

export const SalesDetailSchema = z.object({
  period: z.object({ from: z.string(), to: z.string(), from_datetime: z.string(), to_datetime: z.string() }),
  rows: z.array(
    z.object({
      id: z.number(),
      status: z.string(),
      origin: z.string(),
      customer_name: z.string(),
      created_by_name: z.string().nullable().optional(),
      cashier_name: z.string().nullable().optional(),
      confirmed_at: z.string().nullable().optional(),
      created_at: z.string().nullable().optional(),
      total_base_amount: z.number(),
      total_local_amount: z.number(),
      items_count: z.number().nullable().optional(),
      collection: z.object({
        status: z.string(),
        balance_base_amount: z.number(),
        collected_base_amount: z.number(),
      }),
      pos_order: z
        .object({
          id: z.number(),
          status: z.string(),
          paid_base_amount: z.number(),
          paid_at: z.string().nullable().optional(),
          cash_register_session_id: z.number().nullable().optional(),
          cash_register_name: z.string().nullable().optional(),
          branch_name: z.string().nullable().optional(),
        })
        .nullable()
        .optional(),
      items: z.array(
        z.object({
          id: z.number(),
          product_id: z.number(),
          product_name: z.string().nullable().optional(),
          sku: z.string().nullable().optional(),
          warehouse_name: z.string().nullable().optional(),
          quantity: z.number(),
          unit_price: z.number(),
          base_total_amount: z.number(),
          discount_base_amount: z.number(),
          discount_reason: z.string().nullable().optional(),
          exchange_rate_type_code: z.string().nullable().optional(),
          exchange_rate: z.number().nullable().optional(),
          serial_units: z.array(SerialUnitSchema),
          warranty_policy_name: z.string().nullable().optional(),
          warranty_expires_at: z.string().nullable().optional(),
        }),
      ),
      payments: z.array(
        z.object({
          id: z.number(),
          method: z.string(),
          payment_method_name: z.string().nullable().optional(),
          currency: z.string(),
          amount: z.number(),
          amount_base: z.number(),
          amount_local: z.number(),
          exchange_rate_type_code: z.string().nullable().optional(),
          exchange_rate: z.number().nullable().optional(),
          reference: z.string().nullable().optional(),
        }),
      ),
      returns: z.array(
        z.object({
          id: z.number(),
          status: z.string(),
          reason: z.string().nullable().optional(),
          items_count: z.number(),
          processed_at: z.string().nullable().optional(),
        }),
      ),
    }),
  ),
});

export const CashSessionsSchema = z.object({
  period: z.object({ from: z.string(), to: z.string(), from_datetime: z.string(), to_datetime: z.string() }),
  summary: z.object({
    open_count: z.number(),
    closed_count: z.number(),
    expected_base_amount: z.number(),
    expected_local_amount: z.number(),
    difference_base_amount: z.number(),
  }),
  rows: z.array(
    z.object({
      id: z.number(),
      status: z.string(),
      branch_name: z.string().nullable().optional(),
      cash_register_name: z.string().nullable().optional(),
      cashier_name: z.string().nullable().optional(),
      opening_base_amount: z.number(),
      opening_local_amount: z.number(),
      expected_base_amount: z.number(),
      expected_local_amount: z.number(),
      counted_base_amount: z.number().nullable().optional(),
      counted_local_amount: z.number().nullable().optional(),
      difference_base_amount: z.number().nullable().optional(),
      difference_local_amount: z.number().nullable().optional(),
      opened_at: z.string().nullable().optional(),
      closed_at: z.string().nullable().optional(),
      movements: z.array(
        z.object({
          id: z.number(),
          type: z.string(),
          method: z.string().nullable().optional(),
          currency: z.string(),
          amount_base: z.number(),
          amount_local: z.number(),
          reference: z.string().nullable().optional(),
          created_at: z.string().nullable().optional(),
        }),
      ),
    }),
  ),
  movement_breakdown: z.array(
    z.object({
      type: z.string(),
      method: z.string().nullable().optional(),
      currency: z.string(),
      movements_count: z.number(),
      amount_base: z.number(),
      amount_local: z.number(),
    }),
  ),
});

export const PaymentMethodsReportSchema = z.array(
  z.object({
    method: z.string().nullable().optional(),
    currency: z.string().nullable().optional(),
    name: z.string(),
    requires_reference: z.boolean(),
    payments_count: z.number(),
    amount_base: z.number(),
    amount_local: z.number(),
    missing_reference_count: z.number(),
  }),
);

export const ReportFiltersSchema = z.object({
  warehouse_id: z.number().optional(),
  product_id: z.number().optional(),
  branch_id: z.number().optional(),
  cash_register_id: z.number().optional(),
  cashier_id: z.number().optional(),
  customer_id: z.number().optional(),
  type: z.string().optional(),
  status: z.string().optional(),
  date: z.string().optional(),
  date_from: z.string().optional(),
  date_to: z.string().optional(),
  payment_method: z.string().optional(),
  limit: z.number().optional(),
  threshold: z.number().optional(),
});

export type StockReportRow = z.infer<typeof StockReportRowSchema>;
export type MovementReportRow = z.infer<typeof MovementReportRowSchema>;
export type FinanceSummary = z.infer<typeof FinanceSummarySchema>;
export type FinanceReceivableRow = z.infer<typeof FinanceReceivableRowSchema>;
export type FinancePayableRow = z.infer<typeof FinancePayableRowSchema>;
export type ReportCatalogItem = z.infer<typeof ReportCatalogItemSchema>;
export type DailyOperations = z.infer<typeof DailyOperationsSchema>;
export type SalesDetail = z.infer<typeof SalesDetailSchema>;
export type CashSessions = z.infer<typeof CashSessionsSchema>;
export type PaymentMethodsReport = z.infer<typeof PaymentMethodsReportSchema>;
export type ReportFilters = z.infer<typeof ReportFiltersSchema>;
