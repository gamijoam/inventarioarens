import { createFileRoute } from '@tanstack/react-router';

import { ReportsWorkspace } from '@/features/reports/ReportsWorkspace';
import type { ReportsSearch } from '@/features/reports/ReportsManager';

const MODULES = ['daily', 'sales', 'cash', 'payments', 'stock', 'movements', 'finance'] as const;
const LEGACY_REPORTS: Record<string, string> = {
  daily: 'legacy_daily',
  sales: 'legacy_sales',
  cash: 'legacy_cash',
  payments: 'legacy_payments',
  stock: 'legacy_stock',
  movements: 'legacy_movements',
  finance: 'legacy_finance',
};

export const Route = createFileRoute('/_authed/reports')({
  validateSearch: (search: Record<string, unknown>): ReportsSearch => {
    const today = localDate();
    const reportModule = typeof search.module === 'string' && MODULES.includes(search.module as (typeof MODULES)[number])
      ? search.module
      : undefined;
    return {
      module: reportModule as ReportsSearch['module'],
      report: typeof search.report === 'string'
        ? search.report
        : reportModule
          ? LEGACY_REPORTS[reportModule]
          : undefined,
      date: typeof search.date === 'string' ? search.date : undefined,
      date_from: typeof search.date_from === 'string'
        ? search.date_from
        : !search.report && reportModule !== 'daily' && reportModule !== 'stock' && !search.date_to
          ? today.slice(0, 8) + '01'
          : undefined,
      date_to: typeof search.date_to === 'string'
        ? search.date_to
        : !search.report && reportModule !== 'daily' && reportModule !== 'stock' && !search.date_from
          ? today
          : undefined,
      branch_id: toNumber(search.branch_id),
      warehouse_id: toNumber(search.warehouse_id),
      cash_register_id: toNumber(search.cash_register_id),
      cashier_id: toNumber(search.cashier_id),
      customer_id: toNumber(search.customer_id),
      status: typeof search.status === 'string' ? search.status : undefined,
      type: typeof search.type === 'string' ? search.type : undefined,
      threshold: toNumber(search.threshold),
      limit: toNumber(search.limit),
      page: toNumber(search.page),
      per_page: toNumber(search.per_page),
      dimension: typeof search.dimension === 'string' ? search.dimension : undefined,
      scope: search.scope === 'organization' ? 'organization' : 'tenant',
      low_stock_only: toBoolean(search.low_stock_only),
      company_id: toNumber(search.company_id),
      chart: search.chart === 'table' || search.chart === 'line' || search.chart === 'bar'
        ? search.chart
        : undefined,
      review_status: typeof search.review_status === 'string' ? search.review_status : undefined,
      payment_method: typeof search.payment_method === 'string' ? search.payment_method : undefined,
      search: typeof search.search === 'string' ? search.search : undefined,
    };
  },
  component: ReportsPage,
});

function ReportsPage() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();

  return (
    <div className="flex flex-col gap-3">
      <header className="space-y-1">
        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Reportes</h1>
        <p className="text-sm text-text-muted">
          Información de ventas, caja, inventario y finanzas en un solo espacio.
        </p>
      </header>
      <ReportsWorkspace
        search={search}
        onSearchChange={(patch) => {
          void navigate({
            search: (previous) => cleanSearch({ ...previous, ...patch }),
          });
        }}
      />
    </div>
  );
}

function toNumber(value: unknown): number | undefined {
  if (value === undefined || value === null || value === '') return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function toBoolean(value: unknown): boolean | undefined {
  if (value === true || value === 'true' || value === '1' || value === 1) return true;
  if (value === false || value === 'false' || value === '0' || value === 0) return false;
  return undefined;
}

function cleanSearch(search: ReportsSearch): ReportsSearch {
  const next: ReportsSearch = {};
  for (const [key, value] of Object.entries(search)) {
    if (value !== undefined && value !== '') {
      (next as Record<string, unknown>)[key] = value;
    }
  }
  return next;
}

function localDate(): string {
  const date = new Date();
  const offsetDate = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return offsetDate.toISOString().slice(0, 10);
}
