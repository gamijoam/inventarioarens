import { createFileRoute } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  Boxes,
  Building2,
  CalendarDays,
  CircleDollarSign,
  Landmark,
  Receipt,
  ShoppingCart,
  SlidersHorizontal,
  Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { getOne } from '@/api/client';
import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
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
import { useGroupSpinoffs, useTenantGroups } from '@/features/access/tenantGroupsApi';
import {
  type DashboardVisibility,
  getStoredDashboardVisibility,
  saveStoredDashboardVisibility,
  resetStoredDashboardVisibility,
} from '@/features/dashboard/dashboardConfig';
import { CustomizeDashboardDialog } from '@/features/dashboard/dialogs/CustomizeDashboardDialog';

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
    returned_base_amount?: number;
    net_base_amount?: number;
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
    stock_cost_value?: number;
    stock_retail_value?: number;
    stock_total_units?: number;
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
  const isOwner = roles?.includes('Owner') ?? false;
  const isGroup = Boolean(tenant?.is_group);

  const groupId = isGroup ? tenant?.id : null;
  const { data: tenantGroups = [] } = useTenantGroups();
  const currentGroup = tenantGroups.find((g) => g.id === tenant?.id);
  const knownChildrenCount = currentGroup?.children_count;

  const { data: spinoffs = [] } = useGroupSpinoffs(
    groupId ?? 0,
    Boolean(groupId && isOwner && canViewOrganization && (knownChildrenCount === undefined || knownChildrenCount > 0)),
  );

  const hasMultipleCompanies =
    (knownChildrenCount !== undefined ? knownChildrenCount > 0 : false) || spinoffs.length > 0;
  const canShowOrganization = isGroup && isOwner && canViewOrganization && hasMultipleCompanies;

  const [userSelectedScope, setUserSelectedScope] = useState<DashboardScope | null>(null);
  const scope: DashboardScope = userSelectedScope ?? (canShowOrganization ? 'organization' : 'tenant');

  const [customizeOpen, setCustomizeOpen] = useState(false);
  const [visibility, setVisibility] = useState<DashboardVisibility>(() =>
    getStoredDashboardVisibility(tenant?.id),
  );

  useEffect(() => {
    setVisibility(getStoredDashboardVisibility(tenant?.id));
  }, [tenant?.id]);

  const handleVisibilityChange = (newVisibility: DashboardVisibility) => {
    setVisibility(newVisibility);
    saveStoredDashboardVisibility(newVisibility, tenant?.id);
  };

  const handleVisibilityReset = () => {
    const defaultVis = resetStoredDashboardVisibility(tenant?.id);
    setVisibility(defaultVis);
  };

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
    enabled: canShowOrganization && scope === 'organization',
  });

  const isOrganization = canShowOrganization && scope === 'organization';
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
          {canShowOrganization && (
            <Field label="Ámbito">
              <Select
                value={scope}
                onChange={(event) => setUserSelectedScope(event.target.value as DashboardScope)}
              >
                <option value="organization">Todo el grupo</option>
                <option value="tenant">Esta empresa</option>
              </Select>
            </Field>
          )}
          <div className="flex flex-wrap items-center gap-2 md:ml-auto">
            {data && (
              <div className="text-text-muted flex items-center gap-2 text-sm">
                {isOrganization ? (
                  <Building2 className="size-4" />
                ) : (
                  <CalendarDays className="size-4" />
                )}
                {data.period.from} al {data.period.to}
              </div>
            )}
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => setCustomizeOpen(true)}
              className="gap-1.5"
              data-testid="customize-dashboard-btn"
            >
              <SlidersHorizontal className="size-4" />
              <span>Personalizar</span>
            </Button>
          </div>
        </CardContent>
      </Card>

      {isLoading && <DashboardSkeleton />}

      {isError && (
        <EmptyState
          title="No se pudo cargar el dashboard"
          description="Verifica tu conexión o intenta refrescar."
        />
      )}

      {orgData && <OrganizationDashboardView data={orgData} />}

      {tenantData && <TenantDashboardSummary data={tenantData} visibility={visibility} />}

      <CustomizeDashboardDialog
        open={customizeOpen}
        onOpenChange={setCustomizeOpen}
        visibility={visibility}
        onChange={handleVisibilityChange}
        onReset={handleVisibilityReset}
      />
    </PageLayout>
  );
}

function TenantDashboardSummary({
  data,
  visibility,
}: {
  data: DashboardSummary;
  visibility: DashboardVisibility;
}) {
  const costVal = data.inventory.stock_cost_value ?? 0;
  const retailVal = data.inventory.stock_retail_value ?? 0;
  const totalUnits = data.inventory.stock_total_units ?? 0;
  const inventoryDisplayValue = costVal > 0 ? costVal : retailVal;
  const inventoryHelper = costVal > 0
    ? `${totalUnits} unid. · PVP: ${formatMoney(retailVal)}`
    : `${totalUnits} unid. en stock`;

  const anyKpiVisible =
    visibility.sales ||
    visibility.pos ||
    visibility.cash_register ||
    visibility.inventory_value ||
    visibility.inventory_retail_value ||
    visibility.low_stock ||
    visibility.receivables ||
    visibility.payables;

  return (
    <>
      {anyKpiVisible && (
        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-7">
          {visibility.sales && (
            <MetricCard
              title="Ventas netas"
              icon={ShoppingCart}
              value={formatMoney(data.sales.net_base_amount ?? data.sales.total_base_amount)}
              helper={
                (data.sales.returned_base_amount ?? 0) > 0
                  ? `${data.sales.confirmed_count} confirmadas · Dev. ${formatMoney(data.sales.returned_base_amount ?? 0)}`
                  : `${data.sales.confirmed_count} confirmadas`
              }
              tone="primary"
            />
          )}
          {visibility.pos && (
            <MetricCard
              title="POS cobrado"
              icon={Wallet}
              value={formatMoney(data.pos.paid_base_amount)}
              helper={`${data.pos.paid_orders_count} tickets pagados`}
              tone="success"
            />
          )}
          {visibility.cash_register && (
            <MetricCard
              title="Cajas abiertas"
              icon={Receipt}
              value={String(data.cash_register.open_sessions_count)}
              helper="Turnos activos"
              tone="info"
            />
          )}
          {visibility.inventory_value && (
            <MetricCard
              title="Valor inventario"
              icon={CircleDollarSign}
              value={formatMoney(inventoryDisplayValue)}
              helper={inventoryHelper}
              tone="success"
            />
          )}
          {visibility.inventory_retail_value && (
            <MetricCard
              title="Valor inventario (Venta)"
              icon={CircleDollarSign}
              value={formatMoney(retailVal)}
              helper={`${totalUnits} unid. a precio venta`}
              tone="info"
            />
          )}
          {visibility.low_stock && (
            <MetricCard
              title="Bajo stock"
              icon={Boxes}
              value={String(data.inventory.low_stock_count)}
              helper={`Umbral ${data.inventory.low_stock_threshold}`}
              tone={data.inventory.low_stock_count > 0 ? 'danger' : 'default'}
            />
          )}
          {visibility.receivables && (
            <MetricCard
              title="CxC abierta"
              icon={Wallet}
              value={formatMoney(data.finance.accounts_receivable_balance_base_amount)}
              helper={`${data.finance.accounts_receivable_count} cuentas`}
              tone="warning"
            />
          )}
          {visibility.payables && (
            <MetricCard
              title="CxP abierta"
              icon={Landmark}
              value={formatMoney(data.finance.accounts_payable_balance_base_amount)}
              helper={`${data.finance.accounts_payable_count} cuentas`}
              tone="danger"
            />
          )}
        </section>
      )}

      {(visibility.low_stock_table || visibility.executive_reading) && (
        <section className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {visibility.low_stock_table && (
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
          )}

          {visibility.executive_reading && (
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
          )}
        </section>
      )}
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
