import { createFileRoute } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  Boxes,
  Building2,
  CalendarDays,
  Landmark,
  Receipt,
  ShoppingCart,
  Wallet,
} from 'lucide-react';
import { useState } from 'react';

import { getOne } from '@/api/client';
import { PageLayout } from '@/components/layout/PageLayout';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { formatMoney } from '@/lib/money';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useSessionStore } from '@/stores/session';
import { OrganizationDashboardView } from '@/features/dashboard/OrganizationDashboardView';
import { useOrganizationDashboard } from '@/features/dashboard/organizationApi';

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
    low_stock_items: {
      product_id: number;
      product_name: string | null;
      sku: string | null;
      warehouse_id: number;
      warehouse_name: string | null;
      quantity_available: number;
    }[];
  };
  finance: {
    accounts_receivable_balance_base_amount: number;
    accounts_payable_balance_base_amount: number;
    accounts_receivable_count: number;
    accounts_payable_count: number;
  };
}

type Period = 'today' | 'week' | 'month' | 'custom';
type DashboardScope = 'tenant' | 'organization';

function DashboardPage() {
  const [period, setPeriod] = useState<Period>('today');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const tenant = useSessionStore((s) => s.tenant);
  const roles = useSessionStore((s) => s.roles);
  const canViewOrganization = useCan(PERMISSIONS.REPORTS_ORGANIZATION_VIEW);
  const isGroupOwner = Boolean(tenant?.is_group) && roles.includes('Owner') && canViewOrganization;

  const [scope, setScope] = useState<DashboardScope>(isGroupOwner ? 'organization' : 'tenant');

  const query = new URLSearchParams();
  if (period !== 'custom') query.set('period', period);
  if (period === 'custom' && dateFrom && dateTo) {
    query.set('date_from', dateFrom);
    query.set('date_to', dateTo);
  }

  const tenantSummary = useQuery({
    queryKey: ['dashboard', 'summary', period, dateFrom, dateTo],
    queryFn: () => getOne<DashboardSummary>(`/dashboard/summary?${query.toString()}`),
    refetchInterval: 30_000,
    enabled: scope === 'tenant',
  });

  const organizationSummary = useOrganizationDashboard({
    period,
    dateFrom,
    dateTo,
    enabled: isGroupOwner && scope === 'organization',
  });

  const isOrganization = scope === 'organization';
  const tenantData = isOrganization ? null : tenantSummary.data;
  const orgData = isOrganization ? organizationSummary.data : null;
  const data = orgData ?? tenantData;
  const isLoading = isOrganization ? organizationSummary.isLoading : tenantSummary.isLoading;
  const isError = isOrganization ? organizationSummary.isError : tenantSummary.isError;

  return (
    <PageLayout
      title="Dashboard"
      description="Centro ejecutivo de ventas, POS, caja, inventario y finanzas."
    >
      <div className="bg-white/95 rounded-2xl border border-orange-100 shadow-sm backdrop-blur-sm p-4">
        <div className="flex flex-col gap-3 md:flex-row md:items-end">
          <Field label="Periodo">
            <Select value={period} onChange={(event) => setPeriod(event.target.value as Period)} className="rounded-xl">
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
                  className="rounded-xl"
                />
              </Field>
              <Field label="Hasta">
                <Input
                  type="date"
                  value={dateTo}
                  onChange={(event) => setDateTo(event.target.value)}
                  className="rounded-xl"
                />
              </Field>
            </>
          )}
          {isGroupOwner && (
            <Field label="Ámbito">
              <Select
                value={scope}
                onChange={(event) => setScope(event.target.value as DashboardScope)}
                className="rounded-xl"
              >
                {tenant?.is_group ? (
                  <option value="organization">Todo el grupo</option>
                ) : (
                  <option value="tenant">Esta empresa</option>
                )}
                {tenant?.is_group ? (
                  <option value="tenant">Esta empresa</option>
                ) : (
                  <option value="organization">Todo el grupo</option>
                )}
              </Select>
            </Field>
          )}
          {data && (
            <div className="text-slate-500 bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-200 flex items-center gap-2 text-xs font-semibold md:ml-auto">
              {isOrganization ? (
                <Building2 className="size-4 text-orange-600" />
              ) : (
                <CalendarDays className="size-4 text-orange-600" />
              )}
              <span>{data.period.from} al {data.period.to}</span>
            </div>
          )}
        </div>
      </div>

      {isLoading && <DashboardSkeleton />}

      {isError && (
        <EmptyState
          title="No se pudo cargar el dashboard"
          description="Verifica tu conexión o intenta refrescar."
        />
      )}

      {orgData && <OrganizationDashboardView data={orgData} />}

      {tenantData && <TenantDashboardSummary data={tenantData} />}
    </PageLayout>
  );
}

function TenantDashboardSummary({ data }: { data: DashboardSummary }) {
  return (
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
        <div className="bg-white rounded-2xl border border-slate-200/90 shadow-sm overflow-hidden">
          <div className="p-5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-amber-50/40 to-transparent">
            <div>
              <h4 className="font-bold text-slate-900 text-sm">Alertas de inventario</h4>
              <p className="text-xs text-slate-500">Productos con existencia por debajo del umbral mínimo.</p>
            </div>
            <span className="text-xs bg-slate-100 text-slate-700 font-semibold px-2.5 py-1 rounded-full border border-slate-200">
              {data.inventory.low_stock_items.length} alertas
            </span>
          </div>
          <div className="p-4">
            {data.inventory.low_stock_items.length === 0 ? (
              <EmptyState
                icon={<AlertTriangle className="size-8 text-amber-500" />}
                title="Sin alertas de stock"
                description="No hay productos por debajo del umbral configurado."
              />
            ) : (
              <div className="overflow-x-auto rounded-xl border border-slate-200">
                <table className="w-full min-w-[560px] text-sm text-left">
                  <thead className="bg-slate-50/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200/80">
                    <tr>
                      <th className="py-2.5 px-3">Producto</th>
                      <th className="py-2.5 px-3">Almacén</th>
                      <th className="py-2.5 px-3 text-right">Disponible</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.inventory.low_stock_items.map((item) => (
                      <tr key={`${item.product_id}-${item.warehouse_id}`} className="hover:bg-amber-50/30 transition-colors">
                        <td className="py-2.5 px-3">
                          <div className="font-semibold text-slate-900">
                            {item.product_name ?? `Producto #${item.product_id}`}
                          </div>
                          <div className="text-slate-400 text-xs font-mono">{item.sku ?? '-'}</div>
                        </td>
                        <td className="py-2.5 px-3 text-slate-600">
                          {item.warehouse_name ?? `Almacén #${item.warehouse_id}`}
                        </td>
                        <td className="py-2.5 px-3 text-right font-mono font-bold text-rose-600 tabular-nums">
                          {item.quantity_available}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        <div className="bg-white rounded-2xl border border-slate-200/90 shadow-sm p-5 flex flex-col justify-between">
          <div>
            <div className="border-b border-slate-100 pb-3 mb-4">
              <h4 className="font-bold text-slate-900 text-sm">Lectura ejecutiva</h4>
              <p className="text-xs text-slate-500">
                Resumen analítico del periodo seleccionado (ventas, tickets POS, cajas y saldos).
              </p>
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              <div className="bg-slate-50/80 p-4 rounded-xl border border-slate-200">
                <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Balance operativo</div>
                <div className="text-2xl font-black font-mono mt-1 text-emerald-600">
                  {formatMoney(
                    data.finance.accounts_receivable_balance_base_amount -
                      data.finance.accounts_payable_balance_base_amount,
                  )}
                </div>
                <p className="text-[10px] text-slate-400 mt-1">CxC neta menos CxP acumuladas</p>
              </div>
              <div className="bg-slate-50/80 p-4 rounded-xl border border-slate-200">
                <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Ventas promedio</div>
                <div className="text-2xl font-black font-mono mt-1 text-slate-900">
                  {formatMoney(
                    data.sales.confirmed_count > 0
                      ? data.sales.total_base_amount / data.sales.confirmed_count
                      : 0,
                  )}
                </div>
                <p className="text-[10px] text-slate-400 mt-1">Por venta confirmada en periodo</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    </>
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
  const toneConfig = {
    primary: {
      value: 'text-slate-900',
      box: 'bg-orange-50 border-orange-200 text-orange-600',
      stripe: 'bg-gradient-to-r from-orange-500 to-amber-400',
    },
    success: {
      value: 'text-emerald-600',
      box: 'bg-emerald-50 border-emerald-200 text-emerald-600',
      stripe: 'bg-emerald-500',
    },
    warning: {
      value: 'text-amber-600',
      box: 'bg-amber-50 border-amber-200 text-amber-600',
      stripe: 'bg-amber-400',
    },
    danger: {
      value: 'text-rose-600',
      box: 'bg-rose-50 border-rose-200 text-rose-600',
      stripe: 'bg-rose-500',
    },
    info: {
      value: 'text-sky-600',
      box: 'bg-sky-50 border-sky-200 text-sky-600',
      stripe: 'bg-sky-500',
    },
    default: {
      value: 'text-slate-900',
      box: 'bg-slate-50 border-slate-200 text-slate-600',
      stripe: 'bg-slate-300',
    },
  } as const;

  const config = toneConfig[tone] ?? toneConfig.default;

  return (
    <div className="bg-white p-4 rounded-2xl border border-slate-200/90 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden group">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">{title}</span>
          <span className={`mt-1 text-2xl font-black tabular-nums font-mono tracking-tight block ${config.value}`}>
            {value}
          </span>
        </div>
        <div className={`size-10 rounded-xl border flex items-center justify-center shrink-0 group-hover:scale-110 transition-transform ${config.box}`}>
          <Icon className="size-5" aria-hidden="true" />
        </div>
      </div>
      <div className="mt-3 flex items-center text-xs text-slate-500 font-medium">
        <span className="size-2 rounded-full bg-slate-300 mr-1.5 shrink-0" />
        <span className="truncate">{helper}</span>
      </div>
      <div className={`absolute bottom-0 left-0 right-0 h-1 ${config.stripe}`} />
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="w-full md:w-52">
      <Label className="text-xs font-bold text-slate-500">{label}</Label>
      <div className="mt-1">{children}</div>
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
