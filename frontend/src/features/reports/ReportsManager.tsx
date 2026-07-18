import { Fragment, useMemo, useState } from 'react';
import {
  Banknote,
  Boxes,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  ClipboardList,
  Download,
  Landmark,
  ReceiptText,
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
import { formatMoney } from '@/lib/money';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  downloadCsv,
  useCashSessions,
  useDailyOperations,
  useFinancePayables,
  useFinanceReceivables,
  useFinanceSummary,
  useLowStockReport,
  useMovementReport,
  usePaymentMethodsReport,
  useSalesDetail,
  useStockReport,
  type CashSessions,
  type DailyOperations,
  type FinancePayableRow,
  type FinanceReceivableRow,
  type MovementReportRow,
  type PaymentMethodsReport,
  type ReportFilters,
  type SalesDetail,
  type StockReportRow,
} from './api';

const MODULES = [
  { key: 'daily', label: 'Dia operativo', icon: CalendarDays },
  { key: 'sales', label: 'Ventas detalladas', icon: ReceiptText },
  { key: 'cash', label: 'Cajas y POS', icon: Banknote },
  { key: 'payments', label: 'Metodos de pago', icon: Wallet },
  { key: 'stock', label: 'Inventario', icon: Boxes },
  { key: 'movements', label: 'Movimientos', icon: ClipboardList },
  { key: 'finance', label: 'Finanzas', icon: Landmark },
] as const;

const MOVEMENT_TYPES = [
  { value: 'all', label: 'Todos' },
  { value: 'purchase', label: 'Compra' },
  { value: 'sale', label: 'Venta' },
  { value: 'sale_return', label: 'Devolucion' },
  { value: 'adjustment_in', label: 'Ajuste entrada' },
  { value: 'adjustment_out', label: 'Ajuste salida' },
  { value: 'transfer_in', label: 'Traslado entrada' },
  { value: 'transfer_out', label: 'Traslado salida' },
  { value: 'damaged', label: 'Danado' },
];

const FINANCE_STATUS = [
  { value: 'all', label: 'Todos' },
  { value: 'pending', label: 'Pendientes' },
  { value: 'partial', label: 'Parciales' },
  { value: 'overdue', label: 'Vencidas' },
  { value: 'paid', label: 'Pagadas' },
];

const SALES_STATUS = [
  { value: 'all', label: 'Todas' },
  { value: 'confirmed', label: 'Confirmadas' },
  { value: 'draft', label: 'Borradores' },
  { value: 'cancelled', label: 'Canceladas' },
];

const CASH_STATUS = [
  { value: 'all', label: 'Todas' },
  { value: 'open', label: 'Abiertas' },
  { value: 'closed', label: 'Cerradas' },
  { value: 'cancelled', label: 'Canceladas' },
];

type ModuleKey = (typeof MODULES)[number]['key'];

export type ReportsSearch = Partial<ReportFilters> & {
  module?: ModuleKey;
};

export function ReportsManager({
  search,
  onSearchChange,
}: {
  search: ReportsSearch;
  onSearchChange: (patch: ReportsSearch) => void;
}) {
  const canLegacyReports = useCan(PERMISSIONS.REPORTS_VIEW);
  const canSalesReports = canLegacyReports || useCan(PERMISSIONS.REPORTS_SALES_VIEW);
  const canCashReports = canLegacyReports || useCan(PERMISSIONS.REPORTS_CASH_VIEW);
  const canInventoryReports = canLegacyReports || useCan(PERMISSIONS.REPORTS_INVENTORY_VIEW);
  const canMovementReports = canLegacyReports || useCan(PERMISSIONS.REPORTS_MOVEMENTS_VIEW);
  const canFinanceReports = useCan(PERMISSIONS.FINANCE_REPORTS_VIEW);
  const canExport = canLegacyReports || useCan(PERMISSIONS.REPORTS_EXPORT);
  const canFinanceExport = useCan(PERMISSIONS.FINANCE_REPORTS_EXPORT);

  const availableModules = MODULES.filter((module) => {
    if (module.key === 'daily') return canSalesReports || canCashReports;
    if (module.key === 'sales') return canSalesReports;
    if (module.key === 'cash' || module.key === 'payments') return canCashReports;
    if (module.key === 'stock') return canInventoryReports;
    if (module.key === 'movements') return canMovementReports;
    return canFinanceReports;
  });

  const activeModule = availableModules.some((module) => module.key === search.module)
    ? (search.module as ModuleKey)
    : availableModules[0]?.key;

  const filters: ReportFilters = {
    ...search,
    date: search.date ?? today(),
    status: search.status ?? 'all',
    type: search.type ?? 'all',
    threshold: search.threshold ?? 3,
    limit: search.limit ?? 25,
  };

  const daily = useDailyOperations(filters, activeModule === 'daily' && !!activeModule);
  const sales = useSalesDetail(filters, activeModule === 'sales');
  const cash = useCashSessions(filters, activeModule === 'cash');
  const payments = usePaymentMethodsReport(filters, activeModule === 'payments');
  const stock = useStockReport(filters, activeModule === 'stock');
  const lowStock = useLowStockReport(filters, canInventoryReports);
  const movements = useMovementReport(filters, activeModule === 'movements');
  const financeSummary = useFinanceSummary(filters, canFinanceReports && activeModule === 'finance');
  const receivables = useFinanceReceivables(filters, canFinanceReports && activeModule === 'finance');
  const payables = useFinancePayables(filters, canFinanceReports && activeModule === 'finance');

  if (availableModules.length === 0) {
    return (
      <PermissionDenied
        permission={`${PERMISSIONS.REPORTS_VIEW} / ${PERMISSIONS.FINANCE_REPORTS_VIEW}`}
        message="No tienes permiso para ver reportes."
      />
    );
  }

  function setModule(module: ModuleKey) {
    onSearchChange({ ...search, module });
  }

  function updateFilter<K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) {
    onSearchChange({ ...search, [key]: value });
  }

  function refreshActive() {
    if (activeModule === 'daily') void daily.refetch();
    if (activeModule === 'sales') void sales.refetch();
    if (activeModule === 'cash') void cash.refetch();
    if (activeModule === 'payments') void payments.refetch();
    if (activeModule === 'stock') {
      void stock.refetch();
      void lowStock.refetch();
    }
    if (activeModule === 'movements') void movements.refetch();
    if (activeModule === 'finance') {
      void financeSummary.refetch();
      void receivables.refetch();
      void payables.refetch();
    }
  }

  return (
    <div className="space-y-4">
      <section className="grid grid-cols-2 gap-2 lg:grid-cols-7">
        {availableModules.map((module) => {
          const Icon = module.icon;
          const active = activeModule === module.key;
          return (
            <button
              key={module.key}
              type="button"
              onClick={() => setModule(module.key)}
              className={`border-border rounded-md border p-3 text-left transition ${
                active ? 'bg-primary text-primary-foreground shadow-sm' : 'bg-surface hover:bg-bg'
              }`}
            >
              <Icon className="mb-2 size-4" />
              <span className="text-sm font-semibold">{module.label}</span>
            </button>
          );
        })}
      </section>

      <Card>
        <CardContent className="grid grid-cols-1 gap-3 p-4 md:grid-cols-6">
          <Field label="Dia">
            <Input
              type="date"
              value={filters.date ?? ''}
              onChange={(event) => updateFilter('date', event.target.value || undefined)}
            />
          </Field>
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
          <Field label="Sucursal ID">
            <Input
              inputMode="numeric"
              value={filters.branch_id ?? ''}
              onChange={(event) => updateFilter('branch_id', parseOptionalNumber(event.target.value))}
            />
          </Field>
          <Field label="Almacen ID">
            <Input
              inputMode="numeric"
              value={filters.warehouse_id ?? ''}
              onChange={(event) =>
                updateFilter('warehouse_id', parseOptionalNumber(event.target.value))
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

      {activeModule === 'daily' && (
        <DailyPanel
          data={daily.data}
          isLoading={daily.isLoading}
          onExport={
            canExport && daily.data
              ? () => downloadCsv('reporte-dia-operativo.csv', flattenDaily(daily.data))
              : undefined
          }
        />
      )}
      {activeModule === 'sales' && (
        <SalesDetailPanel
          data={sales.data}
          isLoading={sales.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
        />
      )}
      {activeModule === 'cash' && (
        <CashSessionsPanel
          data={cash.data}
          isLoading={cash.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
        />
      )}
      {activeModule === 'payments' && (
        <PaymentsPanel rows={payments.data ?? []} isLoading={payments.isLoading} canExport={canExport} />
      )}
      {activeModule === 'stock' && (
        <StockPanel
          rows={stock.data ?? []}
          lowStockCount={lowStock.data?.length ?? 0}
          isLoading={stock.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
        />
      )}
      {activeModule === 'movements' && (
        <MovementsPanel
          rows={movements.data ?? []}
          isLoading={movements.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
        />
      )}
      {activeModule === 'finance' && (
        <FinancePanel
          summary={financeSummary.data}
          receivables={receivables.data ?? []}
          payables={payables.data ?? []}
          isLoading={financeSummary.isLoading || receivables.isLoading || payables.isLoading}
          canExport={canFinanceExport}
          filters={filters}
          updateFilter={updateFilter}
        />
      )}
    </div>
  );
}

function DailyPanel({
  data,
  isLoading,
  onExport,
}: {
  data?: DailyOperations;
  isLoading: boolean;
  onExport?: () => void;
}) {
  if (isLoading) return <TableSkeleton />;
  if (!data) return <EmptyState title="Sin datos" description="No se pudo cargar el dia operativo." />;

  return (
    <ReportPanel
      title="Dia operativo"
      description="Auditoria del dia sin exigir que todas las cajas esten cerradas."
      onExport={onExport}
    >
      <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
        <Metric icon={ReceiptText} label="Ventas confirmadas" value={formatMoney(data.sales.confirmed_base_amount)} helper={`${data.sales.confirmed_count} ventas`} />
        <Metric icon={Wallet} label="POS cobrado" value={formatMoney(data.sales.pos_paid_base_amount)} helper={`${data.sales.pos_paid_count} tickets`} />
        <Metric icon={Landmark} label="CxC generada" value={formatMoney(data.sales.credit_balance_base_amount)} helper={`${data.sales.credit_count} saldos abiertos`} tone="warning" />
        <Metric icon={Banknote} label="Caja esperada" value={formatMoney(data.cash.expected_base_amount)} helper={`${data.cash.open_count} abiertas / ${data.cash.closed_count} cerradas`} />
      </div>
      <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
        <div className="border-border rounded-md border p-3">
          <h3 className="font-semibold">Alertas operativas</h3>
          <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
            <AlertItem label="Cajas abiertas de dias previos" value={data.alerts.stale_open_sessions} />
            <AlertItem label="Cierres con diferencia" value={data.alerts.closed_sessions_with_difference} />
            <AlertItem label="Pagos sin referencia" value={data.alerts.payments_missing_reference} />
            <AlertItem label="POS pagados sin caja" value={data.alerts.paid_pos_without_cash_session} />
          </div>
        </div>
        <PaymentMethodsTable rows={data.payment_methods} />
      </div>
    </ReportPanel>
  );
}

function SalesDetailPanel({
  data,
  isLoading,
  canExport,
  filters,
  updateFilter,
}: {
  data?: SalesDetail;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(key: K, value: ReportFilters[K] | undefined) => void;
}) {
  const rows = data?.rows ?? [];
  return (
    <ReportPanel
      title="Ventas detalladas"
      description="Venta, cliente, cajero, cobros, productos, IMEIs, garantias y devoluciones."
      extra={
        <OptionsFilter
          value={filters.status ?? 'all'}
          options={SALES_STATUS}
          onChange={(value) => updateFilter('status', value)}
        />
      }
      onExport={canExport ? () => downloadCsv('reporte-ventas-detalladas.csv', rows.map(compactSale)) : undefined}
      disabledExport={rows.length === 0}
    >
      {isLoading ? <TableSkeleton /> : <SalesDetailTable rows={rows} />}
    </ReportPanel>
  );
}

function CashSessionsPanel({
  data,
  isLoading,
  canExport,
  filters,
  updateFilter,
}: {
  data?: CashSessions;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(key: K, value: ReportFilters[K] | undefined) => void;
}) {
  const rows = data?.rows ?? [];
  return (
    <ReportPanel
      title="Cajas y POS"
      description="Turnos abiertos y cerrados, esperado, contado, diferencia y movimientos."
      extra={
        <Select value={filters.status ?? 'all'} onChange={(event) => updateFilter('status', event.target.value)}>
          {CASH_STATUS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      }
      onExport={canExport ? () => downloadCsv('reporte-cajas.csv', rows) : undefined}
      disabledExport={rows.length === 0}
    >
      {isLoading || !data ? (
        <TableSkeleton />
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
            <MiniTotal label="Abiertas" value={String(data.summary.open_count)} />
            <MiniTotal label="Cerradas" value={String(data.summary.closed_count)} />
            <MiniTotal label="Esperado USD" value={formatMoney(data.summary.expected_base_amount)} />
            <MiniTotal label="Diferencia cerrada" value={formatMoney(data.summary.difference_base_amount)} />
          </div>
          <CashSessionsTable rows={rows} />
          <BreakdownTable rows={data.movement_breakdown} />
        </div>
      )}
    </ReportPanel>
  );
}

function PaymentsPanel({
  rows,
  isLoading,
  canExport,
}: {
  rows: PaymentMethodsReport;
  isLoading: boolean;
  canExport: boolean;
}) {
  return (
    <ReportPanel
      title="Metodos de pago"
      description="Desglose de cobros capturados por metodo, moneda y referencias faltantes."
      onExport={canExport ? () => downloadCsv('reporte-metodos-pago.csv', rows) : undefined}
      disabledExport={rows.length === 0}
    >
      {isLoading ? <TableSkeleton /> : <PaymentMethodsTable rows={rows} />}
    </ReportPanel>
  );
}

function StockPanel({
  rows,
  lowStockCount,
  isLoading,
  canExport,
  filters,
  updateFilter,
}: {
  rows: StockReportRow[];
  lowStockCount: number;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(key: K, value: ReportFilters[K] | undefined) => void;
}) {
  const totals = useMemo(
    () =>
      rows.reduce(
        (acc, row) => ({
          available: acc.available + row.quantity_available,
          reserved: acc.reserved + row.quantity_reserved,
          damaged: acc.damaged + row.quantity_damaged,
        }),
        { available: 0, reserved: 0, damaged: 0 },
      ),
    [rows],
  );

  return (
    <ReportPanel
      title="Inventario"
      description="Existencias por almacen y producto."
      extra={
        <Input
          className="w-32"
          inputMode="decimal"
          value={filters.threshold ?? 3}
          onChange={(event) => updateFilter('threshold', parseOptionalNumber(event.target.value) ?? 0)}
        />
      }
      onExport={canExport ? () => downloadCsv('reporte-inventario.csv', rows) : undefined}
      disabledExport={rows.length === 0}
    >
      <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <MiniTotal label="Disponible" value={`${formatQty(totals.available)} und.`} />
        <MiniTotal label="Reservado" value={`${formatQty(totals.reserved)} und.`} />
        <MiniTotal label="Danado" value={`${formatQty(totals.damaged)} und.`} />
        <MiniTotal label="Bajo stock" value={String(lowStockCount)} />
      </div>
      {isLoading ? <TableSkeleton /> : <StockTable rows={rows} />}
    </ReportPanel>
  );
}

function MovementsPanel({
  rows,
  isLoading,
  canExport,
  filters,
  updateFilter,
}: {
  rows: MovementReportRow[];
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(key: K, value: ReportFilters[K] | undefined) => void;
}) {
  return (
    <ReportPanel
      title="Movimientos"
      description="Kardex operativo filtrado por fecha, almacen, producto o tipo."
      extra={
        <Select value={filters.type ?? 'all'} onChange={(event) => updateFilter('type', event.target.value)}>
          {MOVEMENT_TYPES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      }
      onExport={canExport ? () => downloadCsv('reporte-movimientos.csv', rows) : undefined}
      disabledExport={rows.length === 0}
    >
      {isLoading ? <TableSkeleton /> : <MovementsTable rows={rows} />}
    </ReportPanel>
  );
}

function FinancePanel({
  summary,
  receivables,
  payables,
  isLoading,
  canExport,
  filters,
  updateFilter,
}: {
  summary?: { cash_flow: { collections_base_amount: number; supplier_payments_base_amount: number }; net_balance_base_amount: number };
  receivables: FinanceReceivableRow[];
  payables: FinancePayableRow[];
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(key: K, value: ReportFilters[K] | undefined) => void;
}) {
  return (
    <ReportPanel
      title="Finanzas"
      description="CxC, CxP, cobranza y pagos a proveedores en moneda base USD."
      extra={<StatusFilter value={filters.status ?? 'all'} onChange={(value) => updateFilter('status', value)} />}
      onExport={canExport ? () => downloadCsv('reporte-finanzas.csv', [...receivables, ...payables]) : undefined}
      disabledExport={receivables.length + payables.length === 0}
    >
      {isLoading ? (
        <TableSkeleton />
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <MiniTotal label="Cobranza recibida" value={formatMoney(summary?.cash_flow.collections_base_amount)} />
            <MiniTotal label="Pagos a proveedor" value={formatMoney(summary?.cash_flow.supplier_payments_base_amount)} />
            <MiniTotal label="Balance neto" value={formatMoney(summary?.net_balance_base_amount)} />
          </div>
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <FinanceTable title="Cuentas por cobrar" rows={receivables} partyLabel="Cliente" />
            <FinanceTable title="Cuentas por pagar" rows={payables} partyLabel="Proveedor" />
          </div>
        </div>
      )}
    </ReportPanel>
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
            <Button type="button" variant="outline" size="sm" onClick={onExport} disabled={disabledExport}>
              <Download className="size-4" /> CSV
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function SalesDetailTable({ rows }: { rows: SalesDetail['rows'] }) {
  const [expanded, setExpanded] = useState<number | null>(rows[0]?.id ?? null);

  if (rows.length === 0) {
    return <EmptyState title="Sin ventas" description="No hay ventas con los filtros actuales." />;
  }

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[1100px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Venta</th>
            <th className="px-3 py-2">Fecha</th>
            <th className="px-3 py-2">Cliente</th>
            <th className="px-3 py-2">Cajero</th>
            <th className="px-3 py-2">Estado</th>
            <th className="px-3 py-2">Cobranza</th>
            <th className="px-3 py-2 text-right">Total</th>
            <th className="px-3 py-2 text-right">Items</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <Fragment key={row.id}>
              <tr key={row.id} className="cursor-pointer" onClick={() => setExpanded(expanded === row.id ? null : row.id)}>
                <td className="px-3 py-2 font-semibold">
                  <span className="inline-flex items-center gap-2">
                    {expanded === row.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                    #{row.id} - {row.origin}
                  </span>
                </td>
                <td className="text-text-muted px-3 py-2">{formatDate(row.confirmed_at ?? row.created_at)}</td>
                <td className="px-3 py-2">{row.customer_name}</td>
                <td className="px-3 py-2">{row.cashier_name ?? row.created_by_name ?? '-'}</td>
                <td className="px-3 py-2"><StatusBadge status={row.status} /></td>
                <td className="px-3 py-2">
                  <StatusBadge status={row.collection.status} />
                  {row.collection.balance_base_amount > 0 && <span className="ml-2 font-semibold">{formatMoney(row.collection.balance_base_amount)}</span>}
                </td>
                <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatMoney(row.total_base_amount)}</td>
                <td className="px-3 py-2 text-right">{row.items_count ?? row.items.length}</td>
              </tr>
              {expanded === row.id && (
                <tr>
                  <td colSpan={8} className="bg-bg/60 px-3 py-3">
                    <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                      <DetailList title="Productos" rows={row.items.map((item) => `${item.product_name ?? 'Producto'} - ${formatQty(item.quantity)} und. - ${formatMoney(item.base_total_amount)}${item.serial_units.length ? ` - ${item.serial_units.map((unit) => unit.serial_number).join(', ')}` : ''}`)} />
                      <DetailList title="Pagos" rows={row.payments.map((payment) => `${payment.payment_method_name ?? methodLabel(payment.method)} - ${payment.currency} ${payment.amount} - Base ${formatMoney(payment.amount_base)}${payment.reference ? ` - Ref. ${payment.reference}` : ''}`)} empty="Sin pagos registrados" />
                      <DetailList title="Devoluciones" rows={row.returns.map((item) => `#${item.id} - ${statusLabel(item.status)} - ${item.items_count} items`)} empty="Sin devoluciones" />
                      <DetailList title="POS/Caja" rows={[row.pos_order ? `Orden POS #${row.pos_order.id} - ${row.pos_order.cash_register_name ?? 'Sin caja'} - ${row.pos_order.branch_name ?? 'Sin sucursal'}` : 'Venta manual']} />
                    </div>
                  </td>
                </tr>
              )}
            </Fragment>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function CashSessionsTable({ rows }: { rows: CashSessions['rows'] }) {
  if (rows.length === 0) return <EmptyState title="Sin cajas" description="No hay turnos con los filtros actuales." />;

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[1000px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Caja</th>
            <th className="px-3 py-2">Sucursal</th>
            <th className="px-3 py-2">Cajero</th>
            <th className="px-3 py-2">Estado</th>
            <th className="px-3 py-2 text-right">Fondo</th>
            <th className="px-3 py-2 text-right">Esperado</th>
            <th className="px-3 py-2 text-right">Contado</th>
            <th className="px-3 py-2 text-right">Diferencia</th>
            <th className="px-3 py-2">Apertura</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="px-3 py-2 font-semibold">{row.cash_register_name ?? `Caja #${row.id}`}</td>
              <td className="px-3 py-2">{row.branch_name ?? '-'}</td>
              <td className="px-3 py-2">{row.cashier_name ?? '-'}</td>
              <td className="px-3 py-2"><StatusBadge status={row.status} /></td>
              <td className="px-3 py-2 text-right">{formatMoney(row.opening_base_amount)}</td>
              <td className="px-3 py-2 text-right font-semibold">{formatMoney(row.expected_base_amount)}</td>
              <td className="px-3 py-2 text-right">{row.counted_base_amount === null || row.counted_base_amount === undefined ? '-' : formatMoney(row.counted_base_amount)}</td>
              <td className="px-3 py-2 text-right">{row.difference_base_amount === null || row.difference_base_amount === undefined ? 'Pendiente de cierre' : formatMoney(row.difference_base_amount)}</td>
              <td className="text-text-muted px-3 py-2">{formatDate(row.opened_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function StockTable({ rows }: { rows: StockReportRow[] }) {
  if (rows.length === 0) return <EmptyState title="Sin inventario" description="Ajusta los filtros o registra movimientos." />;

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[760px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Producto</th>
            <th className="px-3 py-2">Almacen</th>
            <th className="px-3 py-2 text-right">Disponible</th>
            <th className="px-3 py-2 text-right">Reservado</th>
            <th className="px-3 py-2 text-right">Danado</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={`${row.warehouse_id}-${row.product_id}`}>
              <td className="px-3 py-2"><div className="font-medium">{row.product_name ?? `Producto #${row.product_id}`}</div><div className="text-text-muted text-xs">{row.sku ?? '-'}</div></td>
              <td className="px-3 py-2">{row.warehouse_name ?? `Almacen #${row.warehouse_id}`}</td>
              <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatQty(row.quantity_available)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatQty(row.quantity_reserved)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatQty(row.quantity_damaged)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function MovementsTable({ rows }: { rows: MovementReportRow[] }) {
  if (rows.length === 0) return <EmptyState title="Sin movimientos" description="No hay movimientos con los filtros seleccionados." />;

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[920px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Fecha</th>
            <th className="px-3 py-2">Tipo</th>
            <th className="px-3 py-2">Producto</th>
            <th className="px-3 py-2">Almacen</th>
            <th className="px-3 py-2 text-right">Cantidad</th>
            <th className="px-3 py-2">Motivo</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="text-text-muted px-3 py-2">{formatDate(row.created_at)}</td>
              <td className="px-3 py-2"><Badge variant="info">{movementLabel(row.type)}</Badge></td>
              <td className="px-3 py-2"><div className="font-medium">{row.product_name ?? `Producto #${row.product_id}`}</div><div className="text-text-muted text-xs">{row.sku ?? '-'}</div></td>
              <td className="px-3 py-2">{row.warehouse_name ?? `Almacen #${row.warehouse_id}`}</td>
              <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatQty(row.quantity)}</td>
              <td className="text-text-muted px-3 py-2">{row.reason ?? '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PaymentMethodsTable({ rows }: { rows: PaymentMethodsReport }) {
  if (rows.length === 0) return <EmptyState title="Sin pagos" description="No hay pagos capturados en el periodo." />;

  return (
    <div className="border-border overflow-auto rounded-md border">
      <table className="w-full min-w-[620px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-3 py-2">Metodo</th>
            <th className="px-3 py-2">Moneda</th>
            <th className="px-3 py-2 text-right">Pagos</th>
            <th className="px-3 py-2 text-right">Total USD</th>
            <th className="px-3 py-2 text-right">Total VES</th>
            <th className="px-3 py-2 text-right">Sin ref.</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={`${row.method}-${row.currency}-${row.name}`}>
              <td className="px-3 py-2 font-semibold">{row.name}</td>
              <td className="px-3 py-2">{row.currency ?? '-'}</td>
              <td className="px-3 py-2 text-right">{row.payments_count}</td>
              <td className="px-3 py-2 text-right font-semibold">{formatMoney(row.amount_base)}</td>
              <td className="px-3 py-2 text-right">Bs {formatLocal(row.amount_local)}</td>
              <td className="px-3 py-2 text-right">{row.missing_reference_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function BreakdownTable({ rows }: { rows: CashSessions['movement_breakdown'] }) {
  if (rows.length === 0) return null;
  return (
    <div className="border-border rounded-md border">
      <div className="border-border border-b px-3 py-2 font-semibold">Movimientos por tipo</div>
      <PaymentMethodsTable
        rows={rows.map((row) => ({
          method: row.method,
          currency: row.currency,
          name: `${movementLabel(row.type)} - ${methodLabel(row.method)}`,
          requires_reference: false,
          payments_count: row.movements_count,
          amount_base: row.amount_base,
          amount_local: row.amount_local,
          missing_reference_count: 0,
        }))}
      />
    </div>
  );
}

function FinanceTable({ title, rows, partyLabel }: { title: string; rows: Array<FinanceReceivableRow | FinancePayableRow>; partyLabel: string }) {
  return (
    <div className="border-border rounded-md border">
      <div className="border-border border-b px-3 py-2 font-semibold">{title}</div>
      {rows.length === 0 ? (
        <div className="p-4"><EmptyState title="Sin registros" description="No hay cuentas con los filtros actuales." /></div>
      ) : (
        <table className="w-full text-sm">
          <thead className="bg-bg text-text-muted text-left text-xs uppercase">
            <tr><th className="px-3 py-2">{partyLabel}</th><th className="px-3 py-2">Estado</th><th className="px-3 py-2 text-right">Saldo</th></tr>
          </thead>
          <tbody className="divide-border divide-y">
            {rows.slice(0, 10).map((row) => (
              <tr key={`${title}-${row.id}`}>
                <td className="px-3 py-2">{financePartyName(row)}</td>
                <td className="px-3 py-2"><StatusBadge status={row.status} /></td>
                <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatMoney(row.balance_base_amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function DetailList({ title, rows, empty = 'Sin datos' }: { title: string; rows: string[]; empty?: string }) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="mb-2 font-semibold">{title}</div>
      {rows.length === 0 ? (
        <div className="text-text-muted text-sm">{empty}</div>
      ) : (
        <ul className="space-y-1 text-sm">
          {rows.map((row, index) => <li key={`${title}-${index}`}>{row}</li>)}
        </ul>
      )}
    </div>
  );
}

function Metric({ icon: Icon, label, value, helper, tone = 'default' }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string; helper: string; tone?: 'default' | 'warning' | 'danger' }) {
  const toneClass = tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : 'text-primary';
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

function MiniTotal({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="text-text-muted text-sm">{label}</div>
      <div className="mt-2 text-xl font-semibold tabular-nums">{value}</div>
    </div>
  );
}

function AlertItem({ label, value }: { label: string; value: number }) {
  return (
    <div className="bg-bg rounded-md p-3">
      <div className="text-text-muted text-xs">{label}</div>
      <div className={`mt-1 text-lg font-semibold ${value > 0 ? 'text-warning' : 'text-success'}`}>{value}</div>
    </div>
  );
}

function StatusFilter({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return <OptionsFilter value={value} options={FINANCE_STATUS} onChange={onChange} />;
}

function OptionsFilter({
  value,
  options,
  onChange,
}: {
  value: string;
  options: Array<{ value: string; label: string }>;
  onChange: (value: string) => void;
}) {
  return (
    <Select value={value} onChange={(event) => onChange(event.target.value)}>
      {options.map((option) => (
        <option key={option.value} value={option.value}>{option.label}</option>
      ))}
    </Select>
  );
}

function StatusBadge({ status }: { status: string }) {
  const variant = status === 'paid' || status === 'confirmed' || status === 'closed' || status === 'processed'
    ? 'success'
    : status === 'overdue' || status === 'cancelled'
      ? 'danger'
      : status === 'none'
        ? 'default'
        : 'warning';
  return <Badge variant={variant}>{statusLabel(status)}</Badge>;
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

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function formatQty(value: number): string {
  return new Intl.NumberFormat('es-VE', { maximumFractionDigits: 4 }).format(value);
}

function formatLocal(value?: number | string | null): string {
  return new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value ?? 0));
}

function formatDate(value?: string | null): string {
  if (!value) return '-';
  return new Intl.DateTimeFormat('es-VE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}

function movementLabel(type: string): string {
  return MOVEMENT_TYPES.find((option) => option.value === type)?.label ?? statusLabel(type);
}

function methodLabel(method?: string | null): string {
  return {
    cash: 'Efectivo',
    card: 'Tarjeta',
    mobile_payment: 'Pago movil',
    transfer: 'Transferencia',
    zelle: 'Zelle',
    external_financing: 'Financiadora',
    other: 'Otro',
  }[method ?? ''] ?? (method || 'Metodo');
}

function statusLabel(status: string): string {
  return {
    all: 'Todos',
    draft: 'Borrador',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    paid: 'Pagada',
    pending: 'Pendiente',
    partial: 'Parcial',
    overdue: 'Vencida',
    open: 'Abierta',
    closed: 'Cerrada',
    requested: 'Solicitada',
    approved: 'Aprobada',
    processed: 'Procesada',
    rejected: 'Rechazada',
    none: 'Sin CxC',
  }[status] ?? status;
}

function financePartyName(row: FinanceReceivableRow | FinancePayableRow): string {
  if ('paid_base_amount' in row) return row.supplier_name ?? 'Proveedor sin nombre';
  return row.customer_name ?? 'Cliente sin nombre';
}

function compactSale(row: SalesDetail['rows'][number]): Record<string, unknown> {
  return {
    venta: row.id,
    fecha: row.confirmed_at ?? row.created_at,
    cliente: row.customer_name,
    cajero: row.cashier_name ?? row.created_by_name,
    estado: row.status,
    cobranza: row.collection.status,
    saldo: row.collection.balance_base_amount,
    total: row.total_base_amount,
    items: row.items_count,
  };
}

function flattenDaily(data: DailyOperations): Array<Record<string, unknown>> {
  return [
    { indicador: 'Ventas confirmadas', valor: data.sales.confirmed_base_amount, cantidad: data.sales.confirmed_count },
    { indicador: 'POS cobrado', valor: data.sales.pos_paid_base_amount, cantidad: data.sales.pos_paid_count },
    { indicador: 'CxC generada', valor: data.sales.credit_balance_base_amount, cantidad: data.sales.credit_count },
    { indicador: 'Caja esperada', valor: data.cash.expected_base_amount, cantidad: data.cash.open_count + data.cash.closed_count },
    { indicador: 'Devoluciones solicitadas', valor: '', cantidad: data.returns.requested_count },
    { indicador: 'Devoluciones procesadas', valor: '', cantidad: data.returns.processed_count },
  ];
}


