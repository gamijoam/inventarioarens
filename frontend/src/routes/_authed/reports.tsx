import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { ReportsManager, type ReportsSearch } from '@/features/reports/ReportsManager';

export const Route = createFileRoute('/_authed/reports')({
  validateSearch: (search: Record<string, unknown>): ReportsSearch => ({
    module: typeof search.module === 'string' ? (search.module as ReportsSearch['module']) : undefined,
    date: typeof search.date === 'string' ? search.date : undefined,
    date_from: typeof search.date_from === 'string' ? search.date_from : undefined,
    date_to: typeof search.date_to === 'string' ? search.date_to : undefined,
    branch_id: toNumber(search.branch_id),
    warehouse_id: toNumber(search.warehouse_id),
    cash_register_id: toNumber(search.cash_register_id),
    cashier_id: toNumber(search.cashier_id),
    customer_id: toNumber(search.customer_id),
    status: typeof search.status === 'string' ? search.status : undefined,
    type: typeof search.type === 'string' ? search.type : undefined,
    threshold: toNumber(search.threshold),
    limit: toNumber(search.limit),
  }),
  component: ReportsPage,
});

function ReportsPage() {
  const search = Route.useSearch();
  const navigate = Route.useNavigate();

  return (
    <PageLayout
      title="Reportes"
      description="Centro ejecutivo para inventario, movimientos, finanzas, caja y POS."
    >
      <ReportsManager
        search={search}
        onSearchChange={(next) => {
          void navigate({ search: cleanSearch(next) as never });
        }}
      />
    </PageLayout>
  );
}

function toNumber(value: unknown): number | undefined {
  if (value === undefined || value === null || value === '') return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function cleanSearch(search: ReportsSearch): ReportsSearch {
  return Object.fromEntries(
    Object.entries(search).filter(([, value]) => value !== undefined && value !== ''),
  ) as ReportsSearch;
}
