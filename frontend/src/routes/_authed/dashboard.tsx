import { createFileRoute } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  Boxes,
  CalendarDays,
  Landmark,
  Receipt,
  ShoppingCart,
  Wallet,
} from 'lucide-react';
import { useState } from 'react';

import { getOne } from '@/api/client';
import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { formatMoney } from '@/lib/money';

export const Route = createFileRoute('/_authed/dashboard')({
  component: DashboardPage,
});

interface DashboardSummary {
  currency: 'USD';
  period: {
    from: string;
    to: string;
  };
  sales: {
    confirmed_count: number;
    total_base_amount: number;
  };
  pos: {
    paid_orders_count: number;
    paid_base_amount: number;
  };
  cash_register: {
    open_sessions_count: number;
  };
  inventory: {
    low_stock_count: number;
    low_stock_threshold: number;
    low_stock_items: Array<{
      product_id: number;
      product_name: string | null;
      sku: string | null;
      warehouse_id: number;
      warehouse_name: string | null;
      quantity_available: number;
    }>;
  };
  finance: {
    accounts_receivable_balance_base_amount: number;
    accounts_payable_balance_base_amount: number;
    accounts_receivable_count: number;
    accounts_payable_count: number;
  };
}

type Period = 'today' | 'week' | 'month' | 'custom';

function DashboardPage() {
  const [period, setPeriod] = useState<Period>('today');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const query = new URLSearchParams();
  if (period !== 'custom') query.set('period', period);
  if (period === 'custom' && dateFrom && dateTo) {
    query.set('date_from', dateFrom);
    query.set('date_to', dateTo);
  }

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard', 'summary', period, dateFrom, dateTo],
    queryFn: () => getOne<DashboardSummary>(`/dashboard/summary?${query.toString()}`),
    refetchInterval: 30_000,
  });

  return (
    <PageLayout
      title="Dashboard"
      description="Centro ejecutivo de ventas, POS, caja, inventario y finanzas."
    >
      <Card>
        <CardContent className="flex flex-col gap-3 p-4 md:flex-row md:items-end">
          <Field label="Periodo">
            <Select value={period} onChange={(event) => setPeriod(event.target.value as Period)}>
              <option value="today">Hoy</option>
              <option value="week">Semana</option>
              <option value="month">Mes</option>
              <option value="custom">Rango</option>
            </Select>
          </Field>
          {period === 'custom' && (
            <>
              <Field label="Desde">
                <Input
                  type="date"
                  value={dateFrom}
                  onChange={(event) => setDateFrom(event.target.value)}
                />
              </Field>
              <Field label="Hasta">
                <Input
                  type="date"
                  value={dateTo}
                  onChange={(event) => setDateTo(event.target.value)}
                />
              </Field>
            </>
          )}
          {data && (
            <div className="text-text-muted flex items-center gap-2 text-sm md:ml-auto">
              <CalendarDays className="size-4" />
              {data.period.from} al {data.period.to}
            </div>
          )}
        </CardContent>
      </Card>

      {isLoading && <DashboardSkeleton />}

      {isError && (
        <EmptyState
          title="No se pudo cargar el dashboard"
          description="Verifica tu conexión o intenta refrescar."
        />
      )}

      {data && (
        <>
          <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
            <MetricCard
              title="Ventas"
              icon={ShoppingCart}
              value={formatMoney(data.sales.total_base_amount)}
              helper={`${data.sales.confirmed_count} confirmadas`}
              tone="primary"
            />
            <MetricCard
              title="POS cobrado"
              icon={Wallet}
              value={formatMoney(data.pos.paid_base_amount)}
              helper={`${data.pos.paid_orders_count} tickets pagados`}
              tone="success"
            />
            <MetricCard
              title="Cajas abiertas"
              icon={Receipt}
              value={String(data.cash_register.open_sessions_count)}
              helper="Turnos activos"
              tone="info"
            />
            <MetricCard
              title="Bajo stock"
              icon={Boxes}
              value={String(data.inventory.low_stock_count)}
              helper={`Umbral ${data.inventory.low_stock_threshold}`}
              tone={data.inventory.low_stock_count > 0 ? 'danger' : 'default'}
            />
            <MetricCard
              title="CxC abierta"
              icon={Wallet}
              value={formatMoney(data.finance.accounts_receivable_balance_base_amount)}
              helper={`${data.finance.accounts_receivable_count} cuentas`}
              tone="warning"
            />
            <MetricCard
              title="CxP abierta"
              icon={Landmark}
              value={formatMoney(data.finance.accounts_payable_balance_base_amount)}
              helper={`${data.finance.accounts_payable_count} cuentas`}
              tone="danger"
            />
          </section>

          <section className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Alertas de inventario</CardTitle>
              </CardHeader>
              <CardContent>
                {data.inventory.low_stock_items.length === 0 ? (
                  <EmptyState
                    icon={<AlertTriangle className="size-8" />}
                    title="Sin alertas de stock"
                    description="No hay productos por debajo del umbral configurado."
                  />
                ) : (
                  <div className="border-border overflow-auto rounded-md border">
                    <table className="w-full min-w-[560px] text-sm">
                      <thead className="bg-bg text-text-muted text-left text-xs uppercase">
                        <tr>
                          <th className="px-3 py-2">Producto</th>
                          <th className="px-3 py-2">Almacén</th>
                          <th className="px-3 py-2 text-right">Disponible</th>
                        </tr>
                      </thead>
                      <tbody className="divide-border divide-y">
                        {data.inventory.low_stock_items.map((item) => (
                          <tr key={`${item.product_id}-${item.warehouse_id}`}>
                            <td className="px-3 py-2">
                              <div className="font-medium">
                                {item.product_name ?? `Producto #${item.product_id}`}
                              </div>
                              <div className="text-text-muted text-xs">{item.sku ?? '-'}</div>
                            </td>
                            <td className="px-3 py-2">
                              {item.warehouse_name ?? `Almacén #${item.warehouse_id}`}
                            </td>
                            <td className="px-3 py-2 text-right font-semibold tabular-nums">
                              {item.quantity_available}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Lectura ejecutiva</CardTitle>
              </CardHeader>
              <CardContent className="text-text-secondary space-y-3 text-sm">
                <p>
                  El periodo seleccionado concentra ventas confirmadas, tickets POS pagados, cajas
                  abiertas y saldos financieros abiertos. Para auditoría detallada usa el módulo
                  Reportes.
                </p>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <Info
                    label="Balance operativo"
                    value={formatMoney(
                      data.finance.accounts_receivable_balance_base_amount -
                        data.finance.accounts_payable_balance_base_amount,
                    )}
                  />
                  <Info
                    label="Ventas promedio"
                    value={formatMoney(
                      data.sales.confirmed_count > 0
                        ? data.sales.total_base_amount / data.sales.confirmed_count
                        : 0,
                    )}
                  />
                </div>
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
  value: string;
  helper: string;
  tone: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'default';
}

function MetricCard({ title, icon: Icon, value, helper, tone }: MetricCardProps) {
  const toneClasses = {
    primary: 'text-primary',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    info: 'text-info',
    default: 'text-text-primary',
  } as const;

  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-3 p-4">
        <div className="min-w-0">
          <p className="text-text-muted text-xs font-medium uppercase">{title}</p>
          <p className={`mt-1 text-2xl font-semibold tabular-nums ${toneClasses[tone]}`}>{value}</p>
          <p className="text-text-muted mt-1 text-xs">{helper}</p>
        </div>
        <div className={`bg-bg shrink-0 rounded-md p-2 ${toneClasses[tone]}`}>
          <Icon className="size-5" aria-hidden="true" />
        </div>
      </CardContent>
    </Card>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="w-full md:w-52">
      <Label>{label}</Label>
      <div className="mt-1">{children}</div>
    </div>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="text-text-muted text-xs uppercase">{label}</div>
      <div className="text-text-primary mt-1 font-semibold tabular-nums">{value}</div>
    </div>
  );
}

function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
        {Array.from({ length: 6 }).map((_, i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Skeleton className="h-72" />
        <Skeleton className="h-72" />
      </div>
    </div>
  );
}
