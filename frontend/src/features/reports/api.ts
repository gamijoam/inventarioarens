import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { getMany, getOne } from '@/api/client';
import {
  FinancePayableRowSchema,
  FinanceReceivableRowSchema,
  FinanceSummarySchema,
  CashSessionsSchema,
  DailyOperationsSchema,
  MovementReportRowSchema,
  PaymentMethodsReportSchema,
  ReportCatalogItemSchema,
  SalesDetailSchema,
  StockReportRowSchema,
  type CashSessions,
  type DailyOperations,
  type FinancePayableRow,
  type FinanceReceivableRow,
  type FinanceSummary,
  type MovementReportRow,
  type PaymentMethodsReport,
  type ReportFilters,
  type ReportCatalogItem,
  type SalesDetail,
  type StockReportRow,
} from './schemas';
import { reportKeys } from './queries';

function toQueryString(filters: ReportFilters = {}): string {
  const params = new URLSearchParams();
  if (filters.date) params.set('date', filters.date);
  if (filters.branch_id) params.set('branch_id', String(filters.branch_id));
  if (filters.warehouse_id) params.set('warehouse_id', String(filters.warehouse_id));
  if (filters.product_id) params.set('product_id', String(filters.product_id));
  if (filters.cash_register_id) params.set('cash_register_id', String(filters.cash_register_id));
  if (filters.cashier_id) params.set('cashier_id', String(filters.cashier_id));
  if (filters.customer_id) params.set('customer_id', String(filters.customer_id));
  if (filters.type && filters.type !== 'all') params.set('type', filters.type);
  if (filters.status && filters.status !== 'all') params.set('status', filters.status);
  if (filters.payment_method) params.set('payment_method', filters.payment_method);
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  if (filters.limit) params.set('limit', String(filters.limit));
  if (filters.threshold !== undefined) params.set('threshold', String(filters.threshold));
  const q = params.toString();
  return q ? `?${q}` : '';
}

export function buildStockReportQuery(filters: ReportFilters = {}): string {
  return `/reports/stock${toQueryString(filters)}`;
}

export function buildLowStockReportQuery(filters: ReportFilters = {}): string {
  return `/reports/stock/low${toQueryString(filters)}`;
}

export function buildMovementReportQuery(filters: ReportFilters = {}): string {
  return `/reports/movements${toQueryString(filters)}`;
}

export function buildReportCatalogQuery(): string {
  return '/reports/catalog';
}

export function buildDailyOperationsQuery(filters: ReportFilters = {}): string {
  return `/reports/daily-operations${toQueryString(filters)}`;
}

export function buildSalesDetailQuery(filters: ReportFilters = {}): string {
  return `/reports/sales-detail${toQueryString(filters)}`;
}

export function buildCashSessionsQuery(filters: ReportFilters = {}): string {
  return `/reports/cash-sessions${toQueryString(filters)}`;
}

export function buildPaymentMethodsReportQuery(filters: ReportFilters = {}): string {
  return `/reports/payment-methods${toQueryString(filters)}`;
}

export function buildFinanceSummaryQuery(filters: ReportFilters = {}): string {
  return `/finance-reports/summary${toQueryString(filters)}`;
}

export function buildFinanceReceivablesQuery(filters: ReportFilters = {}): string {
  return `/finance-reports/receivables${toQueryString(filters)}`;
}

export function buildFinancePayablesQuery(filters: ReportFilters = {}): string {
  return `/finance-reports/payables${toQueryString(filters)}`;
}

export function useStockReport(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.stock(filters),
    queryFn: async () =>
      z.array(StockReportRowSchema).parse(await getMany<unknown>(buildStockReportQuery(filters))),
    enabled,
  });
}

export function useLowStockReport(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.lowStock(filters),
    queryFn: async () =>
      z
        .array(StockReportRowSchema)
        .parse(await getMany<unknown>(buildLowStockReportQuery(filters))),
    enabled,
  });
}

export function useMovementReport(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.movements(filters),
    queryFn: async () =>
      z
        .array(MovementReportRowSchema)
        .parse(await getMany<unknown>(buildMovementReportQuery(filters))),
    enabled,
  });
}

export function useReportCatalog(enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.catalog(),
    queryFn: async () =>
      z.array(ReportCatalogItemSchema).parse(await getMany<unknown>(buildReportCatalogQuery())),
    enabled,
  });
}

export function useDailyOperations(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.dailyOperations(filters),
    queryFn: async () =>
      DailyOperationsSchema.parse(await getOne<unknown>(buildDailyOperationsQuery(filters))),
    enabled,
  });
}

export function useSalesDetail(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.salesDetail(filters),
    queryFn: async () => SalesDetailSchema.parse(await getOne<unknown>(buildSalesDetailQuery(filters))),
    enabled,
  });
}

export function useCashSessions(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.cashSessions(filters),
    queryFn: async () => CashSessionsSchema.parse(await getOne<unknown>(buildCashSessionsQuery(filters))),
    enabled,
  });
}

export function usePaymentMethodsReport(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.paymentMethods(filters),
    queryFn: async () =>
      PaymentMethodsReportSchema.parse(await getMany<unknown>(buildPaymentMethodsReportQuery(filters))),
    enabled,
  });
}

export function useFinanceSummary(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.financeSummary(filters),
    queryFn: async () =>
      FinanceSummarySchema.parse(await getOne<unknown>(buildFinanceSummaryQuery(filters))),
    enabled,
  });
}

export function useFinanceReceivables(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.financeReceivables(filters),
    queryFn: async () =>
      z
        .array(FinanceReceivableRowSchema)
        .parse(await getMany<unknown>(buildFinanceReceivablesQuery(filters))),
    enabled,
  });
}

export function useFinancePayables(filters: ReportFilters, enabled: boolean) {
  return useQuery({
    queryKey: reportKeys.financePayables(filters),
    queryFn: async () =>
      z
        .array(FinancePayableRowSchema)
        .parse(await getMany<unknown>(buildFinancePayablesQuery(filters))),
    enabled,
  });
}

export function downloadCsv(filename: string, rows: Array<Record<string, unknown>>): void {
  const headers = Object.keys(rows[0] ?? {});
  const body = [
    headers.join(','),
    ...rows.map((row) => headers.map((header) => csvCell(row[header])).join(',')),
  ].join('\n');
  const blob = new Blob([body], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

function csvCell(value: unknown): string {
  const text = value === null || value === undefined ? '' : String(value);
  return `"${text.replaceAll('"', '""')}"`;
}

export type {
  CashSessions,
  DailyOperations,
  FinancePayableRow,
  FinanceReceivableRow,
  FinanceSummary,
  MovementReportRow,
  PaymentMethodsReport,
  ReportCatalogItem,
  ReportFilters,
  SalesDetail,
  StockReportRow,
};
