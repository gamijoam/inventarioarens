import { useMemo, useState } from 'react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { BarChart3, Download, LineChart as LineIcon, PieChart as PieIcon, Table2 } from 'lucide-react';

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
import { useGroupSpinoffs } from '@/features/access/tenantGroupsApi';

import { useReportV2, useReportV2Catalog, downloadReportV2, type ReportV2Params } from './api';
import {
  REPORT_DIMENSION_LABELS,
  REPORT_DOMAIN_LABELS,
  REPORT_MEASURE_LABELS,
  type ReportV2,
  type ReportV2CatalogItem,
} from './schemas';

type ChartKind = 'table' | 'bar' | 'line' | 'pie';
type Scope = 'tenant' | 'organization';

const CHART_COLORS = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6'];

export function ReportsV2Manager() {
  const canViewOrganization = useCan(PERMISSIONS.REPORTS_ORGANIZATION_VIEW);
  const tenant = useSessionStore((s) => s.tenant);
  const isOnGroup = Boolean(tenant?.is_group);

  const { data: catalog = [], isLoading: catalogLoading, isError: catalogError } =
    useReportV2Catalog(true);

  const [selectedCode, setSelectedCode] = useState<string | null>(null);
  const [dimension, setDimension] = useState<string>('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [scope, setScope] = useState<Scope>('tenant');
  const [warehouseId, setWarehouseId] = useState('');
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const [companyId, setCompanyId] = useState('');
  const [chartKind, setChartKind] = useState<ChartKind>('bar');

  const groupId = isOnGroup ? tenant?.id : null;
  const { data: spinoffs = [] } = useGroupSpinoffs(groupId ?? 0, Boolean(groupId));

  const selected = catalog.find((item) => item.code === selectedCode) ?? null;

  function selectReport(item: ReportV2CatalogItem): void {
    setSelectedCode(item.code);
    setDimension(item.default_dimension);
    setChartKind(item.dimensions.includes('day') || item.dimensions.includes('month') ? 'line' : 'bar');
    if (item.org_supported && canViewOrganization && isOnGroup) {
      setScope('organization');
    } else {
      setScope('tenant');
    }
    setCompanyId('');
  }

  const params = useMemo<ReportV2Params>(() => {
    const p: ReportV2Params = { scope };
    if (dimension) p.dimension = dimension;
    if (dateFrom) p.dateFrom = dateFrom;
    if (dateTo) p.dateTo = dateTo;
    if (warehouseId) p.warehouseId = Number(warehouseId);
    if (lowStockOnly) p.lowStockOnly = true;
    if (companyId) p.companyId = Number(companyId);
    return p;
  }, [scope, dimension, dateFrom, dateTo, warehouseId, lowStockOnly, companyId]);

  const { data, isLoading, isError, refetch } = useReportV2(
    selected?.code ?? '',
    params,
    Boolean(selected),
  );

  function exportCsv(): void {
    if (!data || !selected) return;
    const header = ['Dimensión', ...selected.measures.map((m) => REPORT_MEASURE_LABELS[m] ?? m)];
    const lines = data.rows.map((row) =>
      [row.label, ...selected.measures.map((m) => String(Number(row[m]) || 0))].join(','),
    );
    const totals = ['Totales', ...selected.measures.map((m) => String(Number(data.totals[m]) || 0))].join(',');
    const csv = [header.join(','), ...lines, totals].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${selected.code}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  async function exportFile(format: 'xlsx' | 'pdf'): Promise<void> {
    if (!selected) return;
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
    return Array.from(groups.entries()).sort(([a], [b]) => a.localeCompare(b));
  }, [catalog]);

  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-[280px_1fr]">
      {/* Plantillas */}
      <Card>
        <CardHeader>
          <CardTitle>Plantillas</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {catalogLoading && <Skeleton className="h-40" />}
          {catalogError && (
            <EmptyState
              title="No se pudo cargar el catálogo"
              description="Verifica tu conexión."
            />
          )}
          {!catalogLoading && !catalogError && (
            <div className="space-y-4">
              {domains.map(([domain, items]) => (
                <div key={domain}>
                  <p className="text-text-muted mb-1 text-[10px] font-semibold uppercase tracking-wider">
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
      </Card>

      {/* Resultado */}
      <div className="space-y-4">
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
              <CardHeader>
                <CardTitle>{selected.name}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-wrap items-end gap-3">
                {selected.dimensions.length > 0 && (
                  <Field label="Agrupar por">
                    <Select
                      value={dimension}
                      onChange={(event) => setDimension(event.target.value)}
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
                      <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                    </Field>
                    <Field label="Hasta">
                      <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                    </Field>
                  </>
                )}
                {selected.has_warehouse_filter && (
                  <Field label="Almacén (opcional)">
                    <Input
                      type="number"
                      placeholder="ID de almacén"
                      value={warehouseId}
                      onChange={(e) => setWarehouseId(e.target.value)}
                    />
                  </Field>
                )}
                {selected.has_low_stock_filter && (
                  <label className="flex h-9 cursor-pointer items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={lowStockOnly}
                      onChange={(e) => setLowStockOnly(e.target.checked)}
                    />
                    Solo bajo stock
                  </label>
                )}
                {selected.org_supported && canViewOrganization && (
                  <Field label="Ámbito">
                    <Select value={scope} onChange={(e) => setScope(e.target.value as Scope)}>
                      <option value="tenant">Esta empresa</option>
                      <option value="organization">Todo el grupo</option>
                    </Select>
                  </Field>
                )}
                {selected.org_supported && canViewOrganization && scope === 'organization' && (
                  <Field label="Empresa">
                    <Select value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
                      <option value="">Todas</option>
                      {spinoffs.map((spinoff) => (
                        <option key={spinoff.id} value={spinoff.id}>
                          {spinoff.name}
                        </option>
                      ))}
                    </Select>
                  </Field>
                )}
                <div className="ml-auto flex items-center gap-2">
                  <Button variant="outline" size="sm" onClick={() => void refetch()}>
                    Actualizar
                  </Button>
                  <Button variant="outline" size="sm" onClick={exportCsv}>
                    <Download className="size-4" /> CSV
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => void exportFile('xlsx')}>
                    <Download className="size-4" /> Excel
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => void exportFile('pdf')}>
                    <Download className="size-4" /> PDF
                  </Button>
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

            {data && !isLoading && (
              <>
                <TotalsBar data={data} selected={selected} />
                <Card>
                  <CardHeader>
                    <div className="flex items-center justify-between">
                      <CardTitle>Resultado</CardTitle>
                      <div className="flex items-center gap-1">
                        <ChartKindButton
                          active={chartKind === 'table'}
                          onClick={() => setChartKind('table')}
                          icon={<Table2 className="size-4" />}
                          label="Tabla"
                        />
                        <ChartKindButton
                          active={chartKind === 'bar'}
                          onClick={() => setChartKind('bar')}
                          icon={<BarChart3 className="size-4" />}
                          label="Barras"
                        />
                        <ChartKindButton
                          active={chartKind === 'line'}
                          onClick={() => setChartKind('line')}
                          icon={<LineIcon className="size-4" />}
                          label="Línea"
                        />
                        <ChartKindButton
                          active={chartKind === 'pie'}
                          onClick={() => setChartKind('pie')}
                          icon={<PieIcon className="size-4" />}
                          label="Torta"
                        />
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent>
                    {chartKind === 'table' && <ResultTable data={data} selected={selected} />}
                    {chartKind !== 'table' && (
                      <ReportChart data={data} selected={selected} kind={chartKind} />
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
    <div className="w-full md:w-48">
      <Label>{label}</Label>
      <div className="mt-1">{children}</div>
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
      className={cn(
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
  const isPaymentMethods = selected.measures.includes('usd_paid');
  return (
    <div className="flex flex-wrap gap-3">
      {selected.measures.map((measure) => (
        <div key={measure} className="border-border rounded-md border px-3 py-2">
          <div className="text-text-muted text-xs uppercase">
            {REPORT_MEASURE_LABELS[measure] ?? measure}
          </div>
          <div className="text-text-primary mt-0.5 font-semibold tabular-nums">
            {formatMeasure(measure, data.totals[measure], data.rate)}
          </div>
        </div>
      ))}
      {selected.has_local_amounts && data.rate != null && !isPaymentMethods && (
        <div className="border-primary/30 bg-primary/5 rounded-md border px-3 py-2">
          <div className="text-text-muted text-xs uppercase">Tasa promedio (Bs/USD)</div>
          <div className="text-primary mt-0.5 font-semibold tabular-nums">
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
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[560px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">{REPORT_DIMENSION_LABELS[data.report.dimension] ?? 'Dimensión'}</th>
            {selected.measures.map((measure) => (
              <th key={measure} className="px-3 py-2 text-right">
                {REPORT_MEASURE_LABELS[measure] ?? measure}
              </th>
            ))}
            {showRate && <th className="px-3 py-2 text-right">Tasa Bs/USD</th>}
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {data.rows.map((row) => (
            <tr key={row.group_key}>
              <td className="px-3 py-2 font-medium">{row.label}</td>
              {selected.measures.map((measure) => (
                <td key={measure} className="px-3 py-2 text-right tabular-nums">
                  {formatMeasure(measure, row[measure], row.rate)}
                </td>
              ))}
              {showRate && (
                <td className="px-3 py-2 text-right tabular-nums">
                  {formatRateCell(row)}
                </td>
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
  const chartData = data.rows.map((row) => ({
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

  if (kind === 'pie') {
    return (
      <ResponsiveContainer width="100%" height={320}>
        <PieChart>
          <Pie data={chartData} dataKey="value" nameKey="name" outerRadius={110} label>
            {chartData.map((entry, index) => (
              <Cell key={entry.name} fill={CHART_COLORS[index % CHART_COLORS.length]} />
            ))}
          </Pie>
          <Tooltip />
          <Legend />
        </PieChart>
      </ResponsiveContainer>
    );
  }

  const common = (
    <>
      <CartesianGrid strokeDasharray="3 3" />
      <XAxis dataKey="name" />
      <YAxis />
      <Tooltip />
      <Legend />
    </>
  );

  return (
    <ResponsiveContainer width="100%" height={320}>
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

function formatRateCell(row: ReportV2['rows'][number]): string {
  // La tasa es relevante solo para pagos en bolivares; para pagos en USD se omite.
  if (Number(row.usd_paid ?? 0) > 0) return '—';
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
