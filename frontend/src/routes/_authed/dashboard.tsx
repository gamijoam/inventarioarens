import { createFileRoute } from '@tanstack/react-router'
/**
 * Dashboard ejecutivo.
 * Consume GET /api/dashboard/summary.
 */
import { useQuery } from '@tanstack/react-query';
import { getOne } from '@/api/client';
import { Boxes, ShoppingCart, Wallet, AlertTriangle, Receipt, Wallet2 } from 'lucide-react';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Skeleton } from '@/components/ui/Skeleton';
import { PageLayout } from '@/components/layout/PageLayout';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatMoney } from '@/lib/money';

export const Route = createFileRoute('/_authed/dashboard')({
  component: DashboardPage,
});

interface DashboardSummary {
  // La forma exacta se ajustara cuando el backend estabilice este endpoint.
  // Por ahora tipamos solo lo que la UI muestra.
  tenant_name?: string;
  period?: string;
  sales_total_base?: string | null;
  pos_cobrado_base?: string | null;
  pos_pendiente_base?: string | null;
  open_cash_registers?: number;
  low_stock_count?: number;
  alerts?: { id: number | string; severity: 'info' | 'warning' | 'danger'; message: string }[];
}

function DashboardPage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard', 'summary'],
    queryFn: () => getOne<DashboardSummary>('/dashboard/summary'),
    refetchInterval: 30_000,
  });

  return (
    <PageLayout
      title="Dashboard"
      description={data?.tenant_name ? `Resumen operativo · ${data.tenant_name}` : 'Resumen operativo'}
    >
      {isLoading && <DashboardSkeleton />}

      {isError && (
        <EmptyState
          title="No se pudo cargar el dashboard"
          description="Verifica tu conexión o intenta refrescar."
        />
      )}

      {data && (
        <>
          <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <MetricCard
              title="Ventas confirmadas"
              icon={ShoppingCart}
              value={data.sales_total_base}
              tone="primary"
            />
            <MetricCard
              title="POS cobrado"
              icon={Wallet}
              value={data.pos_cobrado_base}
              tone="success"
            />
            <MetricCard
              title="POS pendiente"
              icon={Wallet2}
              value={data.pos_pendiente_base}
              tone="warning"
            />
            <MetricCard
              title="Cajas abiertas"
              icon={Receipt}
              value={data.open_cash_registers?.toString() ?? '—'}
              tone="info"
              isCount
            />
            <MetricCard
              title="Productos bajo stock"
              icon={Boxes}
              value={data.low_stock_count?.toString() ?? '—'}
              tone={data.low_stock_count && data.low_stock_count > 0 ? 'danger' : 'default'}
              isCount
            />
          </section>

          <section className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Alertas operativas</CardTitle>
                <CardDescription>Items que requieren atención.</CardDescription>
              </CardHeader>
              <CardContent>
                {!data.alerts || data.alerts.length === 0 ? (
                  <EmptyState
                    icon={<AlertTriangle className="size-8" />}
                    title="Sin alertas"
                    description="Todo en orden."
                  />
                ) : (
                  <ul className="space-y-2">
                    {data.alerts.map((alert) => (
                      <li
                        key={alert.id}
                        className={`rounded border p-2 text-sm ${
                          alert.severity === 'danger'
                            ? 'border-danger/30 bg-danger/5'
                            : alert.severity === 'warning'
                              ? 'border-warning/30 bg-warning/5'
                              : 'border-info/30 bg-info/5'
                        }`}
                      >
                        {alert.message}
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Resumen del día</CardTitle>
                <CardDescription>
                  {data.period ? `Período: ${data.period}` : 'Hoy'}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-text-muted">
                  El backend consolidará aqui los KPIs principales del día en una fase
                  posterior. Por ahora consulta Ventas, Caja e Inventario desde el menú
                  lateral.
                </p>
              </CardContent>
            </Card>
          </section>
        </>
      )}
    </PageLayout>
  );
}

interface MetricCardProps {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  value: string | null | undefined;
  tone: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'default';
  isCount?: boolean;
}

function MetricCard({ title, icon: Icon, value, tone, isCount }: MetricCardProps) {
  const toneClasses = {
    primary: 'text-primary',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    info: 'text-info',
    default: 'text-text-primary',
  } as const;

  const display = value == null || value === '' ? '—' : isCount ? String(value) : formatMoney(value);

  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-3 p-4">
        <div className="min-w-0 space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-text-muted">
            {title}
          </p>
          <p className={`text-2xl font-semibold tabular-nums ${toneClasses[tone]}`}>{display}</p>
        </div>
        <div className={`shrink-0 rounded-md bg-bg p-2 ${toneClasses[tone]}`}>
          <Icon className="size-5" aria-hidden="true" />
        </div>
      </CardContent>
    </Card>
  );
}

function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        {Array.from({ length: 5 }).map((_, i) => (
          <Skeleton key={i} className="h-24" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Skeleton className="h-40" />
        <Skeleton className="h-40" />
      </div>
    </div>
  );
}

// Re-exportar Spinner para que no se marque como unused
export { Spinner };