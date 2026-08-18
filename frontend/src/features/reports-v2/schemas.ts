import { z } from 'zod';

export const ReportV2CatalogItemSchema = z.object({
  code: z.string(),
  name: z.string(),
  domain: z.string(),
  default_dimension: z.string(),
  default_measure: z.string(),
  dimensions: z.array(z.string()),
  measures: z.array(z.string()),
  org_supported: z.boolean(),
  has_warehouse_filter: z.boolean(),
  has_low_stock_filter: z.boolean(),
  date_range_required: z.boolean(),
});
export type ReportV2CatalogItem = z.infer<typeof ReportV2CatalogItemSchema>;

export const ReportV2RowSchema = z
  .object({
    label: z.string(),
    group_key: z.union([z.number(), z.string()]),
  })
  .catchall(z.unknown());
export type ReportV2Row = z.infer<typeof ReportV2RowSchema>;

export const ReportV2Schema = z.object({
  report: z.object({
    code: z.string(),
    name: z.string(),
    domain: z.string(),
    dimension: z.string(),
  }),
  scope: z.string(),
  period: z
    .object({
      from: z.string(),
      to: z.string(),
    })
    .nullable(),
  rows: z.array(ReportV2RowSchema),
  totals: z.record(z.unknown()),
});
export type ReportV2 = z.infer<typeof ReportV2Schema>;

export const REPORT_DOMAIN_LABELS: Record<string, string> = {
  ventas: 'Ventas',
  inventario: 'Inventario',
  finanzas: 'Finanzas',
};

export const REPORT_DIMENSION_LABELS: Record<string, string> = {
  day: 'Día',
  week: 'Semana',
  month: 'Mes',
  product: 'Producto',
  cashier: 'Cajero / Vendedor',
  method: 'Método de pago',
  company: 'Empresa',
  warehouse: 'Almacén',
  customer: 'Cliente',
  supplier: 'Proveedor',
};

export const REPORT_MEASURE_LABELS: Record<string, string> = {
  sales_total: 'Ventas totales',
  sales_count: 'Nº de ventas',
  ticket_avg: 'Ticket promedio',
  units: 'Unidades',
  amount: 'Monto',
  amount_base: 'Monto (base)',
  orders_count: 'Nº de órdenes',
  stock_qty: 'Unidades disponibles',
  stock_value: 'Valor de inventario',
  balance: 'Saldo',
  count: 'Nº de cuentas',
};
