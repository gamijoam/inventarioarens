import { createFileRoute } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  Boxes,
  Building2,
  CalendarDays,
  CircleDollarSign,
  Eye,
  EyeOff,
  Landmark,
  Lock,
  Receipt,
  ShoppingCart,
  SlidersHorizontal,
  TrendingUp,
  Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';

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
import { useGroupSpinoffs, useTenantGroups } from '@/features/access/tenantGroupsApi';
import { cn } from '@/lib/cn';
import {
  type DashboardVisibility,
  getStoredDashboardVisibility,
  saveStoredDashboardVisibility,
  resetStoredDashboardVisibility,
  getKpiGridClasses,
  getMetricCardSizeClass,
} from '@/features/dashboard/dashboardConfig';
import {
  hasDashboardPin,
  getStoredDashboardMasked,
  saveStoredDashboardMasked,
} from '@/features/dashboard/dashboardSecurity';
import { CustomizeDashboardDialog } from '@/features/dashboard/dialogs/CustomizeDashboardDialog';
import { UnlockDashboardPinDialog } from '@/features/dashboard/dialogs/UnlockDashboardPinDialog';
import { ManageDashboardPinDialog } from '@/features/dashboard/dialogs/ManageDashboardPinDialog';

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
  profit?: {
    gross_profit_base_amount: number;
    profit_margin_percent: number;
    sales_cost_base_amount: number;
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
  const [unlockPinOpen, setUnlockPinOpen] = useState(false);
  const [managePinOpen, setManagePinOpen] = useState(false);

  const [visibility, setVisibility] = useState<DashboardVisibility>(() =>
    getStoredDashboardVisibility(tenant?.id),
  );

  const [isMasked, setIsMasked] = useState<boolean>(() =>
    getStoredDashboardMasked(tenant?.id),
  );
  const [hasPin, setHasPin] = useState<boolean>(() =>
    hasDashboardPin(tenant?.id),
  );

  useEffect(() => {
    setVisibility(getStoredDashboardVisibility(tenant?.id));
    setIsMasked(getStoredDashboardMasked(tenant?.id));
    setHasPin(hasDashboardPin(tenant?.id));
  }, [tenant?.id]);

  const handleToggleEye = () => {
    if (isMasked) {
      if (hasPin) {
        setUnlockPinOpen(true);
      } else {
        setIsMasked(false);
        saveStoredDashboardMasked(false, tenant?.id);
      }
    } else {
      if (hasPin) {
        setIsMasked(true);
        saveStoredDashboardMasked(true, tenant?.id);
      } else {
        setManagePinOpen(true);
      }
    }
  };

  const handlePinSaved = () => {
    setHasPin(true);
    setIsMasked(true);
    saveStoredDashboardMasked(true, tenant?.id);
  };

  const handlePinRemoved = () => {
    setHasPin(false);
    setIsMasked(false);
    saveStoredDashboardMasked(false, tenant?.id);
  };

  const handleUnlockSuccess = () => {
    setIsMasked(false);
    saveStoredDashboardMasked(false, tenant?.id);
  };

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
          {canShowOrganization && (
            <Field label="Ámbito">
              <Select
                value={scope}
                onChange={(event) => setUserSelectedScope(event.target.value as DashboardScope)}
                className="rounded-xl"
              >
                <option value="organization">Todo el grupo</option>
                <option value="tenant">Esta empresa</option>
              </Select>
            </Field>
          )}
          <div className="flex flex-wrap items-center gap-2 md:ml-auto">
            {data && (
              <div className="text-slate-500 bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-200 flex items-center gap-2 text-xs font-semibold">
                {isOrganization ? (
                  <Building2 className="size-4 text-orange-600" />
                ) : (
                  <CalendarDays className="size-4 text-orange-600" />
                )}
                <span>{data.period.from} al {data.period.to}</span>
              </div>
            )}
            <button
              type="button"
              onClick={handleToggleEye}
              data-testid="toggle-privacy-btn"
              title={isMasked ? 'Mostrar cifras (requiere PIN)' : 'Ocultar cifras con PIN'}
              className={cn(
                'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-xs font-semibold shadow-xs transition-colors',
                isMasked
                  ? 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 ring-2 ring-amber-400/20'
                  : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-700',
              )}
            >
              {isMasked ? (
                <>
                  <EyeOff className="size-3.5 text-amber-600" />
                  <span>Oculto</span>
                </>
              ) : (
                <>
                  <Eye className="size-3.5 text-slate-500" />
                  <span>Ocultar</span>
                </>
              )}
            </button>
            <button
              type="button"
              onClick={() => setManagePinOpen(true)}
              data-testid="manage-pin-btn"
              title={hasPin ? 'Cambiar PIN de seguridad' : 'Configurar PIN de seguridad'}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 text-xs font-semibold shadow-xs transition-colors"
            >
              <Lock className="size-3.5 text-slate-500" />
              <span>{hasPin ? 'Cambiar PIN' : 'Configurar PIN'}</span>
            </button>
            <button
              type="button"
              onClick={() => setCustomizeOpen(true)}
              data-testid="customize-dashboard-btn"
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-orange-200 bg-white hover:bg-orange-50 text-orange-700 text-xs font-semibold shadow-xs transition-colors"
            >
              <SlidersHorizontal className="size-3.5" />
              <span>Personalizar</span>
            </button>
          </div>
        </div>
      </div>

      {isLoading && <DashboardSkeleton />}

      {isError && (
        <EmptyState
          title="No se pudo cargar el dashboard"
          description="Verifica tu conexión o intenta refrescar."
        />
      )}

      {orgData && <OrganizationDashboardView data={orgData} isMasked={isMasked} />}

      {tenantData && (
        <TenantDashboardSummary
          data={tenantData}
          visibility={visibility}
          isMasked={isMasked}
        />
      )}

      <CustomizeDashboardDialog
        open={customizeOpen}
        onOpenChange={setCustomizeOpen}
        visibility={visibility}
        onChange={handleVisibilityChange}
        onReset={handleVisibilityReset}
      />

      <UnlockDashboardPinDialog
        open={unlockPinOpen}
        onOpenChange={setUnlockPinOpen}
        tenantId={tenant?.id}
        onSuccess={handleUnlockSuccess}
      />

      <ManageDashboardPinDialog
        open={managePinOpen}
        onOpenChange={setManagePinOpen}
        tenantId={tenant?.id}
        hasPin={hasPin}
        onPinSaved={handlePinSaved}
        onPinRemoved={handlePinRemoved}
      />
    </PageLayout>
  );
}

function TenantDashboardSummary({
  data,
  visibility,
  isMasked = false,
}: {
  data: DashboardSummary;
  visibility: DashboardVisibility;
  isMasked?: boolean;
}) {
  const costVal = data.inventory.stock_cost_value ?? 0;
  const retailVal = data.inventory.stock_retail_value ?? 0;
  const totalUnits = data.inventory.stock_total_units ?? 0;
  const inventoryDisplayValue = costVal > 0 ? costVal : retailVal;
  const inventoryHelper = costVal > 0
    ? `${totalUnits} unid. · PVP: ${formatMoney(retailVal)}`
    : `${totalUnits} unid. en stock`;

  const visibleKpis = [
    visibility.sales,
    visibility.profit,
    visibility.pos,
    visibility.cash_register,
    visibility.inventory_value,
    visibility.inventory_retail_value,
    visibility.low_stock,
    visibility.receivables,
    visibility.payables,
  ];
  const kpiCount = visibleKpis.filter(Boolean).length;
  const anyKpiVisible = kpiCount > 0;
  const gridClasses = getKpiGridClasses(kpiCount);

  const lowerCount = (visibility.low_stock_table ? 1 : 0) + (visibility.executive_reading ? 1 : 0);
  const lowerGridClasses = lowerCount === 1 ? 'grid grid-cols-1 gap-6' : 'grid grid-cols-1 xl:grid-cols-2 gap-6';

  return (
    <>
      {anyKpiVisible && (
        <section className={gridClasses}>
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
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.profit && (
            <MetricCard
              title="Ganancia estimada"
              icon={TrendingUp}
              value={formatMoney(data.profit?.gross_profit_base_amount ?? 0)}
              helper={`Margen: ${(data.profit?.profit_margin_percent ?? 0).toFixed(1)}%`}
              tone="success"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.pos && (
            <MetricCard
              title="POS cobrado"
              icon={Wallet}
              value={formatMoney(data.pos.paid_base_amount)}
              helper={`${data.pos.paid_orders_count} tickets pagados`}
              tone="success"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.cash_register && (
            <MetricCard
              title="Cajas abiertas"
              icon={Receipt}
              value={String(data.cash_register.open_sessions_count)}
              helper="Turnos activos"
              tone="info"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.inventory_value && (
            <MetricCard
              title="Valor inventario"
              icon={CircleDollarSign}
              value={formatMoney(inventoryDisplayValue)}
              helper={inventoryHelper}
              tone="success"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.inventory_retail_value && (
            <MetricCard
              title="Valor inventario (Venta)"
              icon={CircleDollarSign}
              value={formatMoney(retailVal)}
              helper={`${totalUnits} unid. a precio venta`}
              tone="info"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.low_stock && (
            <MetricCard
              title="Bajo stock"
              icon={Boxes}
              value={String(data.inventory.low_stock_count)}
              helper={`Umbral ${data.inventory.low_stock_threshold}`}
              tone={data.inventory.low_stock_count > 0 ? 'danger' : 'default'}
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.receivables && (
            <MetricCard
              title="CxC abierta"
              icon={Wallet}
              value={formatMoney(data.finance.accounts_receivable_balance_base_amount)}
              helper={`${data.finance.accounts_receivable_count} cuentas`}
              tone="warning"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
          {visibility.payables && (
            <MetricCard
              title="CxP abierta"
              icon={Landmark}
              value={formatMoney(data.finance.accounts_payable_balance_base_amount)}
              helper={`${data.finance.accounts_payable_count} cuentas`}
              tone="danger"
              cardCount={kpiCount}
              isMasked={isMasked}
            />
          )}
        </section>
      )}

      {(visibility.low_stock_table || visibility.executive_reading) && (
        <section className={lowerGridClasses}>
          {visibility.low_stock_table && (
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
                              {isMasked ? (
                                <span className="tracking-widest text-slate-300 select-none">•••</span>
                              ) : (
                                item.quantity_available
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}

          {visibility.executive_reading && (
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
                      {isMasked ? (
                        <span className="tracking-widest text-slate-300 select-none">••••••</span>
                      ) : (
                        formatMoney(
                          data.finance.accounts_receivable_balance_base_amount -
                            data.finance.accounts_payable_balance_base_amount,
                        )
                      )}
                    </div>
                    <p className="text-[10px] text-slate-400 mt-1">CxC neta menos CxP acumuladas</p>
                  </div>
                  <div className="bg-slate-50/80 p-4 rounded-xl border border-slate-200">
                    <div className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Ventas promedio</div>
                    <div className="text-2xl font-black font-mono mt-1 text-slate-900">
                      {isMasked ? (
                        <span className="tracking-widest text-slate-300 select-none">••••••</span>
                      ) : (
                        formatMoney(
                          data.sales.confirmed_count > 0
                            ? data.sales.total_base_amount / data.sales.confirmed_count
                            : 0,
                        )
                      )}
                    </div>
                    <p className="text-[10px] text-slate-400 mt-1">Por venta confirmada en periodo</p>
                  </div>
                </div>
              </div>
            </div>
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
  cardCount?: number;
  isMasked?: boolean;
}

function MetricCard({ title, icon: Icon, value, helper, tone, cardCount = 8, isMasked = false }: MetricCardProps) {
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
  const size = getMetricCardSizeClass(cardCount);

  return (
    <div className={cn('bg-white border border-slate-200/90 relative overflow-hidden group transition-all', size.card)}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <span className={size.title}>{title}</span>
          <span className={cn(size.value, config.value)}>
            {isMasked ? (
              <span className="tracking-widest font-mono text-slate-300 select-none">••••••</span>
            ) : (
              value
            )}
          </span>
        </div>
        <div className={cn(size.iconBox, 'group-hover:scale-110 transition-transform', config.box)}>
          <Icon className={size.icon} aria-hidden="true" />
        </div>
      </div>
      <div className={size.helper}>
        <span className="size-2 rounded-full bg-slate-300 mr-2 shrink-0" />
        <span className="truncate">
          {isMasked ? (
            <span className="tracking-widest font-mono text-slate-300 select-none">••••••</span>
          ) : (
            helper
          )}
        </span>
      </div>
      <div className={cn('absolute bottom-0 left-0 right-0', size.stripe, config.stripe)} />
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
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-32 rounded-2xl" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Skeleton className="h-72 rounded-2xl" />
        <Skeleton className="h-72 rounded-2xl" />
      </div>
    </div>
  );
}
