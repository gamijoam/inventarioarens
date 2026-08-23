import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { ReportsManager, type ReportsSearch } from '@/features/reports/ReportsManager';
import { ReportsV2Manager } from '@/features/reports-v2/ReportsV2Manager';

export const Route = createFileRoute('/_authed/reports')({
  validateSearch: (search: Record<string, unknown>): ReportsSearch => ({
    module:
      typeof search.module === 'string' ? (search.module as ReportsSearch['module']) : undefined,
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
    page: toNumber(search.page),
    per_page: toNumber(search.per_page),
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
      <Tabs defaultValue="v2" className="space-y-4">
        <TabsList>
          <TabsTrigger value="v2">Reportes V2</TabsTrigger>
          <TabsTrigger value="clasicos">Clásicos</TabsTrigger>
        </TabsList>
        <TabsContent value="v2">
          <ReportsV2Manager />
        </TabsContent>
        <TabsContent value="clasicos">
          <ReportsManager
            search={search}
            onSearchChange={(next) => {
              void navigate({ search: cleanSearch(next) });
            }}
          />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}

function toNumber(value: unknown): number | undefined {
  if (value === undefined || value === null || value === '') return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
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
