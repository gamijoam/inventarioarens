import { useEffect, useMemo, useState } from 'react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { BarChart3, Download, LineChart as LineIcon, Table2 } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/cn';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useSessionStore } from '@/stores/session';
import { useGroupSpinoffs, useTenantGroups } from '@/features/access/tenantGroupsApi';
import { useWarehouses } from '@/features/inventory-center/api';

import { useReportV2, useReportV2Catalog, downloadReportV2, type ReportV2Params } from './api';
import {
  REPORT_DIMENSION_LABELS,
  REPORT_DOMAIN_LABELS,
  REPORT_MEASURE_LABELS,
  type ReportV2,
  type ReportV2CatalogItem,
} from './schemas';

type ChartKind = 'table' | 'bar' | 'line';
type Scope = 'tenant' | 'organization';

export type ReportV2WorkspaceSearch = {
  report?: string;
  dimension?: string;
  date_from?: string;
  date_to?: string;
  scope?: Scope;
  warehouse_id?: number;
  low_stock_only?: boolean;
  company_id?: number;
  chart?: ChartKind;
  page?: number;
  per_page?: number;
};

const CHART_COLORS = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6'];

export function ReportsV2Manager({
  search,
  onSearchChange,
  reportCode,
  showCatalog = true,
}: {
  search?: ReportV2WorkspaceSearch;
  onSearchChange?: (patch: ReportV2WorkspaceSearch) => void;
  reportCode?: string;
  showCatalog?: boolean;
} = {}) {
  const tenant = useSessionStore((s) => s.tenant);
  const roles = useSessionStore((s) => s.roles);
  const canViewOrganization = useCan(PERMISSIONS.REPORTS_ORGANIZATION_VIEW);
  const isOnGroup = Boolean(tenant?.is_group);
  const isOwner = roles?.includes('Owner') ?? false;

  const { data: catalog = [], isLoading: catalogLoading, isError: catalogError } =
    useReportV2Catalog(true);

  const [internalSelectedCode, setInternalSelectedCode] = useState<string | null>(null);
  const [dimension, setDimension] = useState<string>('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [draftDateFrom, setDraftDateFrom] = useState(search?.date_from ?? '');
  const [draftDateTo, setDraftDateTo] = useState(search?.date_to ?? '');
  const [scope, setScope] = useState<Scope>('tenant');
  const [warehouseId, setWarehouseId] = useState('');
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const [companyId, setCompanyId] = useState('');
  const [chartKind, setChartKind] = useState<ChartKind>('bar');
  const selectedCode = search?.report ?? reportCode ?? internalSelectedCode;
  const selected = catalog.find((item) => item.code === selectedCode) ?? null;
  const currentDimension = search?.dimension ?? dimension;
  const currentDateFrom = search?.date_from ?? dateFrom;
  const currentDateTo = search?.date_to ?? dateTo;
  const currentScope = search?.scope ?? scope;
  const currentWarehouseId = search?.warehouse_id !== undefined ? String(search.warehouse_id) : warehouseId;
  const currentLowStockOnly = search?.low_stock_only ?? lowStockOnly;
  const currentCompanyId = search?.company_id !== undefined ? String(search.company_id) : companyId;
  const currentTemporal = ['day', 'week', 'month'].includes(currentDimension || selected?.default_dimension || '');
  const currentChartKind = search?.chart ?? chartKind;
  const currentPage = search?.page ?? 1;
  const perPage = search?.per_page ?? 25;

  const groupId = isOnGroup ? tenant?.id : null;
  const { data: tenantGroups = [] } = useTenantGroups();
  const currentGroup = tenantGroups.find((g) => g.id === tenant?.id);
  const knownChildrenCount = currentGroup?.children_count;

  const { data: spinoffs = [] } = useGroupSpinoffs(
    groupId ?? 0,
    Boolean(groupId && isOwner && canViewOrganization && (knownChildrenCount === undefined || knownChildrenCount > 0)),
  );

  const hasMultipleCompanies =
    (knownChildrenCount !== undefined ? knownChildrenCount > 0 : false) || spinoffs.length > 0;
  const canShowOrganization = isOnGroup && isOwner && canViewOrganization && hasMultipleCompanies;


  useEffect(() => {
    setDateFrom(search?.date_from ?? '');
    setDateTo(search?.date_to ?? '');
    setDraftDateFrom(search?.date_from ?? '');
    setDraftDateTo(search?.date_to ?? '');
  }, [search?.date_from, search?.date_to]);

  useEffect(() => {
    if (!selected) return;
    const nextDimension = search?.dimension ?? selected.default_dimension;
    setDimension(nextDimension);
    setChartKind(search?.chart ?? (['day', 'week', 'month'].includes(nextDimension) ? 'line' : 'bar'));
    setScope(search?.scope ?? (selected.org_supported && canShowOrganization ? 'organization' : 'tenant'));
    setWarehouseId(search?.warehouse_id ? String(search.warehouse_id) : '');
    setLowStockOnly(search?.low_stock_only ?? false);
    setCompanyId(search?.company_id ? String(search.company_id) : '');
  }, [selectedCode, selected?.default_dimension, search?.dimension, search?.chart, search?.scope, search?.warehouse_id, search?.low_stock_only, search?.company_id, canShowOrganization]);


  function selectReport(item: ReportV2CatalogItem): void {
    const nextScope = item.org_supported && canShowOrganization ? 'organization' : 'tenant';
    const nextChart = item.dimensions.includes('day') || item.dimensions.includes('month') ? 'line' : 'bar';
    setInternalSelectedCode(item.code);
    setDimension(item.default_dimension);
    setChartKind(nextChart);
    setScope(nextScope);
    setCompanyId('');
    onSearchChange?.({
      report: item.code,
      dimension: item.default_dimension,
      scope: nextScope,
      chart: nextChart,
      page: 1,
      company_id: undefined,
    });
  }

  const effectiveScope: Scope = canShowOrganization ? currentScope : 'tenant';
  const params = useMemo<ReportV2Params>(() => {
    const p: ReportV2Params = { scope: effectiveScope };
    if (currentDimension) p.dimension = currentDimension;
    if (currentDateFrom) p.dateFrom = currentDateFrom;
    if (currentDateTo) p.dateTo = currentDateTo;
    if (currentWarehouseId) p.warehouseId = Number(currentWarehouseId);
    if (currentLowStockOnly) p.lowStockOnly = true;
    if (effectiveScope === 'organization' && currentCompanyId) p.companyId = Number(currentCompanyId);
    p.page = currentPage;
    p.perPage = perPage;
    return p;
  }, [effectiveScope, currentDimension, currentDateFrom, currentDateTo, currentWarehouseId, currentLowStockOnly, currentCompanyId, currentPage, perPage]);

  const { data, isLoading, isError, refetch } = useReportV2(
    selected?.code ?? '',
    params,
    Boolean(selected),
  );
  const canExport = useCan(
    selected?.domain === 'finanzas' ? PERMISSIONS.FINANCE_REPORTS_EXPORT : PERMISSIONS.REPORTS_EXPORT,
  );
  const { data: warehouses = [] } = useWarehouses({ enabled: selected?.has_warehouse_filter ?? false });

  async function exportFile(format: 'csv' | 'xlsx' | 'pdf'): Promise<void> {
    if (!selected || !canExport) return;
    try {
      await downloadReportV2(selected.code, params, format);
    } catch {
      // El interceptor de api muestra el toast de error.
    }
  }

  const domains = useMemo(() => {
    const groups = new Map<string, ReportV2CatalogItem[]>();
    for (const item of catalog) {
      const list = groups.get(item.domain) ?? [];
      list.push(item);
      groups.set(item.domain, list);
    }
    for (const item of catalog) {
      if (item.code === 'sales_by_payment_method') {
        const sales = groups.get('ventas') ?? [];
        groups.set('ventas', sales.filter((entry) => entry.code !== item.code));
        const cash = groups.get('caja') ?? [];
        cash.push(item);
        groups.set('caja', cash);
      }
    }
    return Array.from(groups.entries()).sort(([a], [b]) => a.localeCompare(b));
  }, [catalog]);

  return (
    <div className={cn('grid grid-cols-1 gap-3', showCatalog && 'xl:grid-cols-[280px_1fr]')}>
      {/* Plantillas */}
      {showCatalog && <Card>
        <CardHeader>
          <CardTitle>Plantillas</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {catalogLoading && <Skeleton className="h-40" />}
          {catalogError && (
            <EmptyState
              title="No se pudo cargar el catálogo"
              description="Verifica tu conexión."
            />
          )}
          {!catalogLoading && !catalogError && (
            <div className="space-y-3">
              {domains.map(([domain, items]) => (
                <div key={domain}>
                  <p className="text-text-muted mb-1 text-xs font-semibold uppercase tracking-wider">
                    {REPORT_DOMAIN_LABELS[domain] ?? domain}
                  </p>
                  <div className="flex flex-col gap-1">
                    {items.map((item) => (
                      <button
                        key={item.code}
                        type="button"
                        onClick={() => selectReport(item)}
                        className={cn(
                          'text-left text-sm transition-colors',
                          'hover:bg-bg rounded px-2 py-1.5',
                          selected?.code === item.code
                            ? 'bg-primary/10 text-primary font-medium'
                            : 'text-text-secondary',
                        )}
                      >
                        {item.name}
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>}

      {/* Resultado */}
      <div className="space-y-3">
        {!selected && (
          <EmptyState
            icon={<BarChart3 className="size-8" />}
            title="Selecciona una plantilla"
            description="Elige un reporte del catálogo para empezar a explorar métricas y gráficas."
          />
        )}

        {selected && (
          <>
            <Card>
              {showCatalog && (
                <CardHeader className="px-3 pt-3 pb-0">
                  <CardTitle className="text-sm">{selected.name}</CardTitle>
                </CardHeader>
              )}
              <CardContent className="flex flex-wrap items-end gap-2 p-3">
                {selected.dimensions.length > 0 && (
                  <Field label="Agrupar por">
                    <Select
                      className="min-h-11"
                      value={currentDimension || selected.default_dimension}
                      onChange={(event) => {
                        const nextDimension = event.target.value;
                        setDimension(nextDimension);
                        const nextChart = ['day', 'week', 'month'].includes(nextDimension) ? 'line' : 'bar';
                        setChartKind(nextChart);
                        onSearchChange?.({ dimension: nextDimension, chart: nextChart, page: 1 });
                      }}
                    >
                      {selected.dimensions.map((dim) => (
                        <option key={dim} value={dim}>
                          {REPORT_DIMENSION_LABELS[dim] ?? dim}
                        </option>
                      ))}
                    </Select>
                  </Field>
                )}
                {selected.date_range_required && (
                  <>
                    <Field label="Desde">
                      <Input type="date" className="min-h-11" value={draftDateFrom} onChange={(e) => setDraftDateFrom(e.target.value)} />
                    </Field>
                    <Field label="Hasta">
                      <Input type="date" className="min-h-11" value={draftDateTo} onChange={(e) => setDraftDateTo(e.target.value)} />
                    </Field>
                  </>
                )}
                {selected.has_warehouse_filter && (
                  <Field label="Almacén">
                    <Select className="min-h-11" value={currentWarehouseId} onChange={(e) => {
                      setWarehouseId(e.target.value);
                      onSearchChange?.({ warehouse_id: e.target.value ? Number(e.target.value) : undefined, page: 1 });
                    }}>
                      <option value="">Todos los almacenes</option>
                      {warehouses.map((warehouse) => (
                        <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>
                      ))}
                    </Select>
                  </Field>
                )}
                {selected.has_low_stock_filter && (
                  <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={currentLowStockOnly}
                      onChange={(e) => {
                        setLowStockOnly(e.target.checked);
                        onSearchChange?.({ low_stock_only: e.target.checked, page: 1 });
                      }}
                    />
                    Solo bajo stock
                  </label>
                )}
                {selected.org_supported && canShowOrganization && (
                  <Field label="Ámbito">
                    <Select className="min-h-11" value={currentScope} onChange={(e) => {
                      setScope(e.target.value as Scope);
                      onSearchChange?.({ scope: e.target.value as Scope, page: 1 });
                    }}>
                      <option value="organization">Todo el grupo</option>
                      <option value="tenant">Esta empresa</option>
                    </Select>
                  </Field>
                )}
                {selected.org_supported && canShowOrganization && currentScope === 'organization' && (
                  <Field label="Empresa">
                    <Select className="min-h-11" value={currentCompanyId} onChange={(e) => {
                      setCompanyId(e.target.value);
                      onSearchChange?.({ company_id: e.target.value ? Number(e.target.value) : undefined, page: 1 });
                    }}>
                      <option value="">Todas</option>
                      {spinoffs.map((spinoff) => (
                        <option key={spinoff.id} value={spinoff.id}>
                          {spinoff.name}
                        </option>
                      ))}
                    </Select>
                  </Field>
                )}
                {(selected.date_range_required && (draftDateFrom !== currentDateFrom || draftDateTo !== currentDateTo)) && (
                  <Button
                    variant="primary"
                    className="min-h-11"
                    onClick={() => {
                      setDateFrom(draftDateFrom);
                      setDateTo(draftDateTo);
                      onSearchChange?.({
                        date_from: draftDateFrom || undefined,
                        date_to: draftDateTo || undefined,
                        page: 1,
                      });
                    }}
                  >
                    Aplicar período
                  </Button>
                )}
                <div className="ml-auto flex flex-wrap items-center gap-2">
                  <Button variant="outline" className="min-h-11" onClick={() => void refetch()}>
                    Actualizar
                  </Button>
                  {canExport && (
                    <>
                      <Button variant="outline" className="min-h-11" onClick={() => void exportFile('csv')}>
                        <Download className="size-4" /> CSV
                      </Button>
                      <Button variant="outline" className="min-h-11" onClick={() => void exportFile('xlsx')}>
                        <Download className="size-4" /> Excel
                      </Button>
                      <Button variant="outline" className="min-h-11" onClick={() => void exportFile('pdf')}>
                        <Download className="size-4" /> PDF
                      </Button>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>

            {isLoading && <Skeleton className="h-80" />}
            {isError && (
              <EmptyState
                title="No se pudo cargar el reporte"
                description="Ajusta filtros o verifica permisos."
              />
            )}

            {data && !isLoading && !isError && (
              <>
                <TotalsBar data={data} selected={selected} />
                <Card>
                  <CardHeader className="flex-row items-center justify-between gap-2 px-2 py-1.5">
                      <CardTitle className="text-sm">Resultado</CardTitle>
                      <div className="flex items-center gap-1">
                        <ChartKindButton
                          active={currentChartKind === 'table'}
                          onClick={() => {
                            setChartKind('table');
                            onSearchChange?.({ chart: 'table' });
                          }}
                          icon={<Table2 className="size-4" />}
                          label="Tabla"
                        />
                        {currentTemporal ? (
                          <ChartKindButton
                            active={currentChartKind === 'line'}
                            onClick={() => {
                              setChartKind('line');
                              onSearchChange?.({ chart: 'line' });
                            }}
                            icon={<LineIcon className="size-4" />}
                            label="Línea"
                          />
                        ) : (
                          <ChartKindButton
                            active={currentChartKind === 'bar'}
                            onClick={() => {
                              setChartKind('bar');
                              onSearchChange?.({ chart: 'bar' });
                            }}
                            icon={<BarChart3 className="size-4" />}
                            label="Barras"
                          />
                        )}
                      </div>
                  </CardHeader>
                  <CardContent className="p-3">
                    {currentChartKind === 'table' && (
                      <>
                        <ResultTable data={data} selected={selected} />
                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                          <span className="text-text-muted">
                            {data.meta
                              ? `Mostrando ${data.rows.length ? (data.meta.current_page - 1) * data.meta.per_page + 1 : 0}–${Math.min(data.meta.current_page * data.meta.per_page, data.meta.total_rows)} de ${data.meta.total_rows}`
                              : `${data.rows.length} resultados`}
                          </span>
                          <div className="flex gap-2">
                            <Button variant="outline" className="min-h-11" disabled={!data.meta || currentPage <= 1} onClick={() => onSearchChange?.({ page: currentPage - 1 })}>Anterior</Button>
                            <Button variant="outline" className="min-h-11" disabled={!data.meta || currentPage >= data.meta.last_page} onClick={() => onSearchChange?.({ page: currentPage + 1 })}>Siguiente</Button>
                          </div>
                        </div>
                      </>
                    )}
                    {currentChartKind !== 'table' && (
                      <ReportChart data={data} selected={selected} kind={currentChartKind} />
                    )}
                  </CardContent>
                </Card>
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="w-full md:w-40 xl:w-44">
      <Label className="text-xs">{label}</Label>
      <div className="mt-0.5">{children}</div>
    </div>
  );
}

function ChartKindButton({
  active,
  onClick,
  icon,
  label,
}: {
  active: boolean;
  onClick: () => void;
  icon: React.ReactNode;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={label}
      aria-label={label}
      aria-pressed={active}
      className={cn('min-h-11 min-w-11 justify-center',
        'flex items-center gap-1 rounded px-2 py-1 text-xs transition-colors',
        active ? 'bg-primary/10 text-primary' : 'text-text-muted hover:bg-bg hover:text-text-primary',
      )}
    >
      {icon}
      <span className="hidden sm:inline">{label}</span>
    </button>
  );
}

function TotalsBar({ data, selected }: { data: ReportV2; selected: ReportV2CatalogItem }) {
  const isPaymentMethods = selected.code === 'sales_by_payment_method';
  return (
    <div className="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]">
      {selected.measures.map((measure) => (
        <div key={measure} className="min-w-0 bg-surface px-2 py-1.5">
          <div className="truncate text-xs text-text-muted">
            {REPORT_MEASURE_LABELS[measure] ?? measure}
          </div>
          <div className="mt-0.5 truncate text-base font-semibold tabular-nums text-text-primary">
            {formatMeasure(measure, data.totals[measure], data.rate ?? undefined)}
          </div>
        </div>
      ))}
      {selected.has_local_amounts && data.rate != null && !isPaymentMethods && (
        <div className="min-w-0 bg-surface px-2 py-1.5">
          <div className="truncate text-xs text-text-muted">Tasa promedio (Bs/USD)</div>
          <div className="mt-0.5 truncate text-base font-semibold tabular-nums text-primary">
            Bs {formatRate(data.rate)}
          </div>
        </div>
      )}
    </div>
  );
}

function ResultTable({ data, selected }: { data: ReportV2; selected: ReportV2CatalogItem }) {
  const showRate = selected.has_local_amounts;
  return (
    <>
      <div className="space-y-2 md:hidden">
        {data.rows.map((row) => (
          <article key={row.group_key} className="border-border bg-surface rounded-md border p-3">
            <h3 className="font-semibold text-text-primary">{row.label}</h3>
            <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-2">
              {selected.measures.map((measure) => (
                <div key={measure} className="min-w-0">
                  <dt className="text-xs text-text-muted">{REPORT_MEASURE_LABELS[measure] ?? measure}</dt>
                  <dd className="break-words text-sm font-medium tabular-nums text-text-primary">
                    {formatMeasure(measure, row[measure], row.rate ?? undefined)}
                  </dd>
                </div>
              ))}
              {showRate && (
                <div>
                  <dt className="text-xs text-text-muted">Tasa Bs/USD</dt>
                  <dd className="text-sm font-medium tabular-nums text-text-primary">{formatRateCell(row)}</dd>
                </div>
              )}
            </dl>
          </article>
        ))}
        {data.rows.length === 0 && (
          <p className="border-border text-text-muted rounded-md border p-4 text-center text-sm">
            Sin resultados para los filtros seleccionados.
          </p>
        )}
      </div>
      <div className="border-border hidden overflow-auto rounded-md border md:block">
        <table className="w-full min-w-[560px] text-sm">
          <thead className="bg-bg text-text-muted text-left text-xs uppercase">
            <tr>
              <th className="px-2 py-1.5">{REPORT_DIMENSION_LABELS[data.report.dimension] ?? 'Dimensión'}</th>
              {selected.measures.map((measure) => (
                <th key={measure} className="px-2 py-1.5 text-right">
                  {REPORT_MEASURE_LABELS[measure] ?? measure}
                </th>
              ))}
              {showRate && <th className="px-2 py-1.5 text-right">Tasa Bs/USD</th>}
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {data.rows.map((row) => (
              <tr key={row.group_key}>
                <td className="px-2 py-1.5 font-medium">{row.label}</td>
                {selected.measures.map((measure) => (
                  <td key={measure} className="px-2 py-1.5 text-right tabular-nums">
                    {formatMeasure(measure, row[measure], row.rate ?? undefined)}
                  </td>
                ))}
                {showRate && (
                  <td className="px-2 py-1.5 text-right tabular-nums">{formatRateCell(row)}</td>
                )}
              </tr>
            ))}
            {data.rows.length === 0 && (
              <tr>
                <td colSpan={selected.measures.length + (showRate ? 2 : 1)} className="text-text-muted px-3 py-6 text-center">
                  Sin resultados para los filtros seleccionados.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}

function ReportChart({
  data,
  selected,
  kind,
}: {
  data: ReportV2;
  selected: ReportV2CatalogItem;
  kind: Exclude<ChartKind, 'table'>;
}) {
  const measure = selected.default_measure;
  const chartData = (data.chart_rows ?? data.rows).map((row) => ({
    name: row.label,
    value: Number(row[measure] ?? 0),
  }));

  if (chartData.length === 0) {
    return (
      <EmptyState
        title="Sin datos"
        description="No hay filas para graficar con los filtros actuales."
      />
    );
  }

  const isTemporal = ['day', 'week', 'month'].includes(data.report.dimension);
  const common = (
    <>
      <CartesianGrid strokeDasharray="3 3" />
      <XAxis dataKey="name" tickFormatter={isTemporal ? (value) => formatTemporalLabel(String(value)) : undefined} />
      <YAxis tickFormatter={(value) => formatChartValue(measure, Number(value), data.rate ?? undefined)} />
      <Tooltip
        formatter={(value) => formatChartValue(measure, Number(value), data.rate ?? undefined)}
        labelFormatter={(label) => isTemporal ? formatTemporalLabel(String(label)) : String(label)}
      />
      <Legend />
    </>
  );

  return (
    <ResponsiveContainer width="100%" height={280}>
      {kind === 'line' ? (
        <LineChart data={chartData} margin={{ top: 8, right: 16, bottom: 8, left: 8 }}>
          {common}
          <Line type="monotone" dataKey="value" name={REPORT_MEASURE_LABELS[measure] ?? measure} stroke="#4f46e5" strokeWidth={2} />
        </LineChart>
      ) : (
        <BarChart data={chartData} margin={{ top: 8, right: 16, bottom: 8, left: 8 }}>
          {common}
          <Bar dataKey="value" name={REPORT_MEASURE_LABELS[measure] ?? measure} fill="#4f46e5" radius={[4, 4, 0, 0]}>
            {chartData.map((entry, index) => (
              <Cell key={entry.name} fill={CHART_COLORS[index % CHART_COLORS.length]} />
            ))}
          </Bar>
        </BarChart>
      )}
    </ResponsiveContainer>
  );
}

const VES_MEASURES = new Set(['sales_total_local', 'amount_local', 'ves_paid']);
const USD_MEASURES = new Set([
  'sales_total',
  'amount',
  'amount_base',
  'usd_paid',
  'usd_equiv',
  'stock_value',
  'balance',
]);

function formatMeasure(measure: string, value: unknown, rate?: number): string {
  const numeric = Number(value ?? 0);
  if (VES_MEASURES.has(measure)) {
    if (numeric === 0) return '—';
    const formatted = `Bs ${formatAmount(numeric)}`;
    if (measure === 'sales_total_local' && rate && rate > 0) {
      return `${formatted} (~$${formatAmount(numeric / rate)})`;
    }
    return formatted;
  }
  if (USD_MEASURES.has(measure)) {
    return numeric === 0 ? '—' : formatMoney(numeric);
  }
  return Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(2);
}

function formatChartValue(measure: string, value: number, rate?: number): string {
  if (value === 0) {
    if (VES_MEASURES.has(measure)) return `Bs ${formatAmount(0)}`;
    if (USD_MEASURES.has(measure)) return `$${formatAmount(0)}`;
    return '0';
  }
  return formatMeasure(measure, value, rate);
}

function formatTemporalLabel(value: string): string {
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return new Intl.DateTimeFormat('es-VE', { day: '2-digit', month: 'short' })
      .format(new Date(`${value}T00:00:00`))
      .replace(/[.\-]/g, ' ').trim();
  }
  if (/^\d{4}-\d{2}$/.test(value)) {
    return new Intl.DateTimeFormat('es-VE', { month: 'short', year: 'numeric' })
      .format(new Date(`${value}-01T00:00:00`))
      .replace(/[.\-]/g, ' ').trim();
  }
  const week = /^(\d{4})-?W(\d{1,2})$/i.exec(value);
  if (week) return `Sem. ${Number(week[2])} · ${week[1]}`;
  return value;
}

function formatRateCell(row: ReportV2['rows'][number]): string {
  // La tasa es relevante solo cuando hay pagos en bolivares; si no hay Bs se omite.
  const hasBs = Number(row.ves_paid ?? 0) > 0 || Number(row.usd_equiv ?? 0) > 0;
  if (!hasBs) return '—';
  return row.rate != null ? `Bs ${formatRate(row.rate)}` : '—';
}

function formatAmount(value: number): string {
  return new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function formatRate(value: number): string {
  return new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  }).format(value);
}
