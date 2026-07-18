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

export const ReportFiltersSchema = z.object({
  warehouse_id: z.number().optional(),
  product_id: z.number().optional(),
  type: z.string().optional(),
  status: z.string().optional(),
  date_from: z.string().optional(),
  date_to: z.string().optional(),
  threshold: z.number().optional(),
});

export type StockReportRow = z.infer<typeof StockReportRowSchema>;
export type MovementReportRow = z.infer<typeof MovementReportRowSchema>;
export type FinanceSummary = z.infer<typeof FinanceSummarySchema>;
export type FinanceReceivableRow = z.infer<typeof FinanceReceivableRowSchema>;
export type FinancePayableRow = z.infer<typeof FinancePayableRowSchema>;
export type ReportFilters = z.infer<typeof ReportFiltersSchema>;
