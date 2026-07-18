import { useMemo, useState } from 'react';
import {
  AlertTriangle,
  ArrowDownToLine,
  ArrowUpFromLine,
  BarChart3,
  Boxes,
  Download,
  Landmark,
  RefreshCw,
  Wallet,
} from 'lucide-react';

import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { formatMoney } from '@/lib/money';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  downloadCsv,
  useFinancePayables,
  useFinanceReceivables,
  useFinanceSummary,
  useLowStockReport,
  useMovementReport,
  useStockReport,
  type FinancePayableRow,
  type FinanceReceivableRow,
  type MovementReportRow,
  type ReportFilters,
  type StockReportRow,
} from './api';

const MOVEMENT_TYPES = [
  { value: 'all', label: 'Todos' },
  { value: 'purchase', label: 'Compra' },
  { value: 'sale', label: 'Venta' },
  { value: 'sale_return', label: 'Devolución' },
  { value: 'adjustment_in', label: 'Ajuste entrada' },
  { value: 'adjustment_out', label: 'Ajuste salida' },
  { value: 'transfer_in', label: 'Traslado entrada' },
  { value: 'transfer_out', label: 'Traslado salida' },
  { value: 'damaged', label: 'Dañado' },
];

const FINANCE_STATUS = [
  { value: 'all', label: 'Todos' },
  { value: 'pending', label: 'Pendientes' },
  { value: 'partial', label: 'Parciales' },
  { value: 'overdue', label: 'Vencidas' },
  { value: 'paid', label: 'Pagadas' },
];

type TabValue = 'stock' | 'movements' | 'finance' | 'cash';

export function ReportsManager() {
  const canInventoryReports = useCan(PERMISSIONS.REPORTS_VIEW);
  const canFinanceReports = useCan(PERMISSIONS.FINANCE_REPORTS_VIEW);
  const [tab, setTab] = useState<TabValue>(canInventoryReports ? 'stock' : 'finance');
  const [filters, setFilters] = useState<ReportFilters>({
    type: 'all',
    status: 'all',
    threshold: 3,
  });

  const stock = useStockReport(filters, canInventoryReports && tab === 'stock');
  const lowStock = useLowStockReport(filters, canInventoryReports);
  const movements = useMovementReport(filters, canInventoryReports && tab === 'movements');
  const financeSummary = useFinanceSummary(filters, canFinanceReports);
  const receivables = useFinanceReceivables(filters, canFinanceReports && tab === 'finance');
  const payables = useFinancePayables(filters, canFinanceReports && tab === 'finance');

  const activeStock = stock.data ?? [];
  const activeMovements = movements.data ?? [];
  const receivableRows = receivables.data ?? [];
  const payableRows = payables.data ?? [];

  const stockTotals = useMemo(() => {
    return activeStock.reduce(
      (totals, row) => ({
        available: totals.available + row.quantity_available,
        reserved: totals.reserved + row.quantity_reserved,
        damaged: totals.damaged + row.quantity_damaged,
      }),
      { available: 0, reserved: 0, damaged: 0 },
    );
  }, [activeStock]);

  if (!canInventoryReports && !canFinanceReports) {
    return (
      <PermissionDenied
        permission={`${PERMISSIONS.REPORTS_VIEW} / ${PERMISSIONS.FINANCE_REPORTS_VIEW}`}
        message="No tienes permiso para ver reportes operativos."
      />
    );
  }

  function updateFilter<K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) {
    setFilters((current) => ({ ...current, [key]: value }));
  }

  function refreshActive() {
    if (tab === 'stock') {
      void stock.refetch();
      void lowStock.refetch();
    }
    if (tab === 'movements') void movements.refetch();
    if (tab === 'finance') {
      void financeSummary.refetch();
      void receivables.refetch();
      void payables.refetch();
    }
  }

  return (
    <div className="space-y-4">
      <section className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        {canInventoryReports && (
          <>
            <Metric
              icon={Boxes}
              label="Stock visible"
              value={`${stockTotals.available.toLocaleString('es-VE')} und.`}
              helper={`${stockTotals.reserved.toLocaleString('es-VE')} reservadas`}
            />
            <Metric
              icon={AlertTriangle}
              label="Bajo stock"
              value={String(lowStock.data?.length ?? 0)}
              helper={`Umbral ${filters.threshold ?? 3}`}
              tone="warning"
            />
          </>
        )}
        {canFinanceReports && (
          <>
            <Metric
              icon={Wallet}
              label="CxC abierta"
              value={formatMoney(
                financeSummary.data?.accounts_receivable.total_balance_base_amount,
              )}
              helper={`${financeSummary.data?.accounts_receivable.partial_count ?? 0} parciales`}
            />
            <Metric
              icon={Landmark}
              label="CxP abierta"
              value={formatMoney(financeSummary.data?.accounts_payable.total_balance_base_amount)}
              helper={`${financeSummary.data?.accounts_payable.pending_count ?? 0} pendientes`}
              tone="danger"
            />
          </>
        )}
      </section>

      <Card>
        <CardContent className="grid grid-cols-1 gap-3 p-4 md:grid-cols-6">
          <Field label="Desde">
            <Input
              type="date"
              value={filters.date_from ?? ''}
              onChange={(event) => updateFilter('date_from', event.target.value || undefined)}
            />
          </Field>
          <Field label="Hasta">
            <Input
              type="date"
              value={filters.date_to ?? ''}
              onChange={(event) => updateFilter('date_to', event.target.value || undefined)}
            />
          </Field>
          <Field label="Almacén ID">
            <Input
              inputMode="numeric"
              value={filters.warehouse_id ?? ''}
              onChange={(event) =>
                updateFilter('warehouse_id', parseOptionalNumber(event.target.value))
              }
            />
          </Field>
          <Field label="Producto ID">
            <Input
              inputMode="numeric"
              value={filters.product_id ?? ''}
              onChange={(event) =>
                updateFilter('product_id', parseOptionalNumber(event.target.value))
              }
            />
          </Field>
          <Field label="Umbral bajo stock">
            <Input
              inputMode="decimal"
              value={filters.threshold ?? 3}
              onChange={(event) =>
                updateFilter('threshold', parseOptionalNumber(event.target.value) ?? 0)
              }
            />
          </Field>
          <div className="flex items-end">
            <Button type="button" variant="outline" className="w-full" onClick={refreshActive}>
              <RefreshCw className="size-4" /> Actualizar
            </Button>
          </div>
        </CardContent>
      </Card>

      <Tabs value={tab} onValueChange={(value) => setTab(value as TabValue)}>
        <TabsList>
          {canInventoryReports && <TabsTrigger value="stock">Inventario</TabsTrigger>}
          {canInventoryReports && <TabsTrigger value="movements">Movimientos</TabsTrigger>}
          {canFinanceReports && <TabsTrigger value="finance">Finanzas</TabsTrigger>}
          {canFinanceReports && <TabsTrigger value="cash">Caja/POS</TabsTrigger>}
        </TabsList>

        {canInventoryReports && (
          <TabsContent value="stock">
            <ReportPanel
              title="Inventario por almacén"
              description="Existencias disponibles, reservadas y dañadas por producto."
              onExport={() => downloadCsv('reporte-inventario.csv', activeStock)}
              disabledExport={activeStock.length === 0}
            >
              {stock.isLoading ? <TableSkeleton /> : <StockTable rows={activeStock} />}
            </ReportPanel>
          </TabsContent>
        )}

        {canInventoryReports && (
          <TabsContent value="movements">
            <ReportPanel
              title="Movimientos de inventario"
              description="Kardex operativo filtrado por fecha, almacén, producto o tipo."
              extra={
                <Select
                  value={filters.type ?? 'all'}
                  onChange={(event) => updateFilter('type', event.target.value)}
                >
                  {MOVEMENT_TYPES.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              }
              onExport={() => downloadCsv('reporte-movimientos.csv', activeMovements)}
              disabledExport={activeMovements.length === 0}
            >
              {movements.isLoading ? <TableSkeleton /> : <MovementsTable rows={activeMovements} />}
            </ReportPanel>
          </TabsContent>
        )}

        {canFinanceReports && (
          <TabsContent value="finance">
            <ReportPanel
              title="Finanzas operativas"
              description="CxC, CxP, cobranza y pagos a proveedores en moneda base USD."
              extra={
                <Select
                  value={filters.status ?? 'all'}
                  onChange={(event) => updateFilter('status', event.target.value)}
                >
                  {FINANCE_STATUS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              }
              onExport={() =>
                downloadCsv('reporte-finanzas.csv', [...receivableRows, ...payableRows])
              }
              disabledExport={receivableRows.length + payableRows.length === 0}
            >
              <FinanceSection
                summary={financeSummary.data}
                isLoading={financeSummary.isLoading || receivables.isLoading || payables.isLoading}
                receivables={receivableRows}
                payables={payableRows}
              />
            </ReportPanel>
          </TabsContent>
        )}

        {canFinanceReports && (
          <TabsContent value="cash">
            <ReportPanel
              title="Caja/POS"
              description="Lectura rápida del flujo cobrado y pagado en el periodo filtrado."
              disabledExport
            >
              <CashPosSummary
                collections={financeSummary.data?.cash_flow.collections_base_amount}
                supplierPayments={financeSummary.data?.cash_flow.supplier_payments_base_amount}
                net={financeSummary.data?.net_balance_base_amount}
                isLoading={financeSummary.isLoading}
              />
            </ReportPanel>
          </TabsContent>
        )}
      </Tabs>
    </div>
  );
}

function ReportPanel({
  title,
  description,
  children,
  extra,
  onExport,
  disabledExport,
}: {
  title: string;
  description: string;
  children: React.ReactNode;
  extra?: React.ReactNode;
  onExport?: () => void;
  disabledExport?: boolean;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-3">
        <div>
          <CardTitle>{title}</CardTitle>
          <p className="text-text-muted mt-1 text-sm">{description}</p>
        </div>
        <div className="flex items-center gap-2">
          {extra}
          {onExport && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onExport}
              disabled={disabledExport}
            >
              <Download className="size-4" /> CSV
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function StockTable({ rows }: { rows: StockReportRow[] }) {
  if (rows.length === 0)
    return (
      <EmptyState
        title="Sin inventario para mostrar"
        description="Ajusta los filtros o registra movimientos de inventario."
      />
    );

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[760px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Producto</th>
            <th className="px-3 py-2">Almacén</th>
            <th className="px-3 py-2 text-right">Disponible</th>
            <th className="px-3 py-2 text-right">Reservado</th>
            <th className="px-3 py-2 text-right">Dañado</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={`${row.warehouse_id}-${row.product_id}`}>
              <td className="px-3 py-2">
                <div className="font-medium">
                  {row.product_name ?? `Producto #${row.product_id}`}
                </div>
                <div className="text-text-muted text-xs">{row.sku ?? '-'}</div>
              </td>
              <td className="px-3 py-2">{row.warehouse_name ?? `Almacén #${row.warehouse_id}`}</td>
              <td className="px-3 py-2 text-right font-semibold tabular-nums">
                {formatQty(row.quantity_available)}
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                {formatQty(row.quantity_reserved)}
              </td>
              <td className="px-3 py-2 text-right tabular-nums">
                {formatQty(row.quantity_damaged)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function MovementsTable({ rows }: { rows: MovementReportRow[] }) {
  if (rows.length === 0)
    return (
      <EmptyState
        title="Sin movimientos"
        description="No hay movimientos con los filtros seleccionados."
      />
    );

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[920px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Fecha</th>
            <th className="px-3 py-2">Tipo</th>
            <th className="px-3 py-2">Producto</th>
            <th className="px-3 py-2">Almacén</th>
            <th className="px-3 py-2 text-right">Cantidad</th>
            <th className="px-3 py-2">Motivo</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="text-text-muted px-3 py-2">{formatDate(row.created_at)}</td>
              <td className="px-3 py-2">
                <Badge variant="info">{movementLabel(row.type)}</Badge>
              </td>
              <td className="px-3 py-2">
                <div className="font-medium">
                  {row.product_name ?? `Producto #${row.product_id}`}
                </div>
                <div className="text-text-muted text-xs">{row.sku ?? '-'}</div>
              </td>
              <td className="px-3 py-2">{row.warehouse_name ?? `Almacén #${row.warehouse_id}`}</td>
              <td className="px-3 py-2 text-right font-semibold tabular-nums">
                {formatQty(row.quantity)}
              </td>
              <td className="text-text-muted px-3 py-2">{row.reason ?? '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function FinanceSection({
  summary,
  isLoading,
  receivables,
  payables,
}: {
  summary?: {
    cash_flow: { collections_base_amount: number; supplier_payments_base_amount: number };
    net_balance_base_amount: number;
  };
  isLoading: boolean;
  receivables: FinanceReceivableRow[];
  payables: FinancePayableRow[];
}) {
  if (isLoading) return <TableSkeleton />;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <MiniTotal
          icon={ArrowDownToLine}
          label="Cobranza recibida"
          value={formatMoney(summary?.cash_flow.collections_base_amount)}
        />
        <MiniTotal
          icon={ArrowUpFromLine}
          label="Pagos a proveedor"
          value={formatMoney(summary?.cash_flow.supplier_payments_base_amount)}
        />
        <MiniTotal
          icon={BarChart3}
          label="Balance neto"
          value={formatMoney(summary?.net_balance_base_amount)}
        />
      </div>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <FinanceTable title="Cuentas por cobrar" rows={receivables} partyLabel="Cliente" />
        <FinanceTable title="Cuentas por pagar" rows={payables} partyLabel="Proveedor" />
      </div>
    </div>
  );
}

function FinanceTable({
  title,
  rows,
  partyLabel,
}: {
  title: string;
  rows: Array<FinanceReceivableRow | FinancePayableRow>;
  partyLabel: string;
}) {
  return (
    <div className="border-border rounded-md border">
      <div className="border-border border-b px-3 py-2 font-semibold">{title}</div>
      {rows.length === 0 ? (
        <div className="p-4">
          <EmptyState
            title="Sin registros"
            description="No hay cuentas con los filtros actuales."
          />
        </div>
      ) : (
        <table className="w-full text-sm">
          <thead className="bg-bg text-text-muted text-left text-xs uppercase">
            <tr>
              <th className="px-3 py-2">{partyLabel}</th>
              <th className="px-3 py-2">Estado</th>
              <th className="px-3 py-2 text-right">Saldo</th>
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {rows.slice(0, 10).map((row) => (
              <tr key={`${title}-${row.id}`}>
                <td className="px-3 py-2">{financePartyName(row)}</td>
                <td className="px-3 py-2">
                  <Badge
                    variant={
                      row.status === 'paid'
                        ? 'success'
                        : row.status === 'overdue'
                          ? 'danger'
                          : 'warning'
                    }
                  >
                    {statusLabel(row.status)}
                  </Badge>
                </td>
                <td className="px-3 py-2 text-right font-semibold tabular-nums">
                  {formatMoney(row.balance_base_amount)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function CashPosSummary({
  collections,
  supplierPayments,
  net,
  isLoading,
}: {
  collections?: number;
  supplierPayments?: number;
  net?: number;
  isLoading: boolean;
}) {
  if (isLoading) return <TableSkeleton />;
  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
      <MiniTotal
        icon={ArrowDownToLine}
        label="Entradas por cobranza"
        value={formatMoney(collections)}
      />
      <MiniTotal
        icon={ArrowUpFromLine}
        label="Salidas a proveedores"
        value={formatMoney(supplierPayments)}
      />
      <MiniTotal icon={BarChart3} label="Flujo neto" value={formatMoney(net)} />
    </div>
  );
}

function Metric({
  icon: Icon,
  label,
  value,
  helper,
  tone = 'default',
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
  helper: string;
  tone?: 'default' | 'warning' | 'danger';
}) {
  const toneClass =
    tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : 'text-primary';
  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-3 p-4">
        <div>
          <p className="text-text-muted text-xs font-medium uppercase">{label}</p>
          <p className={`mt-1 text-2xl font-semibold tabular-nums ${toneClass}`}>{value}</p>
          <p className="text-text-muted mt-1 text-xs">{helper}</p>
        </div>
        <Icon className={`size-5 ${toneClass}`} />
      </CardContent>
    </Card>
  );
}

function MiniTotal({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
}) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="text-text-muted flex items-center gap-2 text-sm">
        <Icon className="size-4" /> {label}
      </div>
      <div className="mt-2 text-xl font-semibold tabular-nums">{value}</div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <Label>{label}</Label>
      <div className="mt-1">{children}</div>
    </div>
  );
}

function TableSkeleton() {
  return <Skeleton className="h-72 w-full" />;
}

function parseOptionalNumber(value: string): number | undefined {
  if (!value.trim()) return undefined;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function formatQty(value: number): string {
  return new Intl.NumberFormat('es-VE', { maximumFractionDigits: 4 }).format(value);
}

function formatDate(value?: string | null): string {
  if (!value) return '-';
  return new Intl.DateTimeFormat('es-VE', { dateStyle: 'short', timeStyle: 'short' }).format(
    new Date(value),
  );
}

function movementLabel(type: string): string {
  return MOVEMENT_TYPES.find((option) => option.value === type)?.label ?? type;
}

function statusLabel(status: string): string {
  return FINANCE_STATUS.find((option) => option.value === status)?.label ?? status;
}

function financePartyName(row: FinanceReceivableRow | FinancePayableRow): string {
  if ('paid_base_amount' in row) {
    return row.supplier_name ?? 'Proveedor sin nombre';
  }

  return row.customer_name ?? 'Cliente sin nombre';
}
