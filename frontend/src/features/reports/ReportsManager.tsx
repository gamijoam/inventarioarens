import { Fragment, useEffect, useState } from 'react';
import {
  Banknote,
  Boxes,
  CalendarDays,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Download,
  Landmark,
  ReceiptText,
  RefreshCw,
  RotateCcw,
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
import { todayDateString } from '@/lib/format';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import type { PaginationMeta } from '@/types/api';
import {
  downloadCsv,
  downloadLegacyReport,
  downloadFinanceReport,
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
  type MovementReportResponse,
  type MovementReportRow,
  type PaymentMethodsReport,
  type ReportFilters,
  type SalesDetail,
  type StockReportResponse,
  type StockReportRow,
} from './api';
import { useBranches, useWarehouses } from '@/features/inventory-center/api';
import { cashMovementLabel } from './movementLabels';

const MODULES = [
  { key: 'daily', label: 'Día operativo', icon: CalendarDays },
  { key: 'sales', label: 'Ventas detalladas', icon: ReceiptText },
  { key: 'cash', label: 'Cajas y POS', icon: Banknote },
  { key: 'payments', label: 'Métodos de pago', icon: Wallet },
  { key: 'stock', label: 'Inventario', icon: Boxes },
  { key: 'movements', label: 'Movimientos', icon: ClipboardList },
  { key: 'finance', label: 'Finanzas', icon: Landmark },
] as const;

const MOVEMENT_TYPES = [
  { value: 'all', label: 'Todos' },
  { value: 'purchase', label: 'Compra' },
  { value: 'sale', label: 'Venta' },
  { value: 'sale_return', label: 'Devolución' },
  { value: 'adjustment_in', label: 'Ajuste entrada' },
  { value: 'adjustment_out', label: 'Ajuste salida' },
  { value: 'transfer_in', label: 'Traslado entrada' },
  { value: 'transfer_out', label: 'Traslado salida' },
  { value: 'transfer_request_in', label: 'Entrada interempresa' },
  { value: 'transfer_request_out', label: 'Salida interempresa' },
  { value: 'damaged', label: 'Dañado' },
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
  { value: 'voided', label: 'Anuladas' },
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
  report?: string;
  dimension?: string;
  scope?: 'tenant' | 'organization';
  low_stock_only?: boolean;
  company_id?: number;
  chart?: 'table' | 'bar' | 'line';
};

export function ReportsManager({
  search,
  onSearchChange,
  showNavigation = true,
}: {
  search: ReportsSearch;
  onSearchChange: (patch: ReportsSearch) => void;
  showNavigation?: boolean;
}) {
  const canLegacyReports = useCan(PERMISSIONS.REPORTS_VIEW);
  const canSalesPermission = useCan(PERMISSIONS.REPORTS_SALES_VIEW);
  const canCashPermission = useCan(PERMISSIONS.REPORTS_CASH_VIEW);
  const canInventoryPermission = useCan(PERMISSIONS.REPORTS_INVENTORY_VIEW);
  const canMovementPermission = useCan(PERMISSIONS.REPORTS_MOVEMENTS_VIEW);
  const canFinanceReports = useCan(PERMISSIONS.FINANCE_REPORTS_VIEW);
  const canExportPermission = useCan(PERMISSIONS.REPORTS_EXPORT);
  const canFinanceExport = useCan(PERMISSIONS.FINANCE_REPORTS_EXPORT);
  const canSalesReports = canLegacyReports || canSalesPermission;
  const canCashReports = canLegacyReports || canCashPermission;
  const canInventoryReports = canLegacyReports || canInventoryPermission;
  const canMovementReports = canLegacyReports || canMovementPermission;
  const canExport = canExportPermission;

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

  const [draftDateFrom, setDraftDateFrom] = useState(search.date_from ?? '');
  const [draftDateTo, setDraftDateTo] = useState(search.date_to ?? '');
  useEffect(() => {
    setDraftDateFrom(search.date_from ?? '');
    setDraftDateTo(search.date_to ?? '');
  }, [search.date_from, search.date_to]);

  const filters: ReportFilters = {
    ...search,
    date: search.date ?? today(),
    status: search.status ?? 'all',
    type: search.type ?? 'all',
    threshold: search.threshold ?? 3,
    per_page: search.per_page ?? 25,
  };

  const daily = useDailyOperations(filters, activeModule === 'daily' && !!activeModule);
  const sales = useSalesDetail(filters, activeModule === 'sales');
  const cash = useCashSessions(filters, activeModule === 'cash');
  const payments = usePaymentMethodsReport(filters, activeModule === 'payments');
  const stock = useStockReport(filters, activeModule === 'stock');
  const lowStock = useLowStockReport(filters, activeModule === 'stock' && canInventoryReports);
  const usesBranchFilter = ['sales', 'cash', 'payments'].includes(activeModule ?? '');
  const usesWarehouseFilter = ['sales', 'stock', 'movements'].includes(activeModule ?? '');
  const { data: branches = [] } = useBranches({ enabled: usesBranchFilter });
  const { data: warehouses = [] } = useWarehouses({ enabled: usesWarehouseFilter });
  const movements = useMovementReport(filters, activeModule === 'movements');
  const financeSummary = useFinanceSummary(
    filters,
    canFinanceReports && activeModule === 'finance',
  );
  const receivables = useFinanceReceivables(
    filters,
    canFinanceReports && activeModule === 'finance',
  );
  const payables = useFinancePayables(filters, canFinanceReports && activeModule === 'finance');
  const activeError = activeModule === 'daily'
    ? daily.isError
    : activeModule === 'sales'
      ? sales.isError
      : activeModule === 'cash'
        ? cash.isError
        : activeModule === 'payments'
          ? payments.isError
          : activeModule === 'stock'
            ? stock.isError
            : activeModule === 'movements'
              ? movements.isError
              : activeModule === 'finance'
                ? financeSummary.isError || receivables.isError || payables.isError
                : false;

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
    onSearchChange({ ...search, [key]: value, ...(key === 'page' ? {} : { page: 1 }) });
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
    <div className="space-y-3">
      {showNavigation && <section className="grid grid-cols-2 gap-2 lg:grid-cols-7">
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
      </section>}

      <Card>
        <CardContent className="grid grid-cols-1 gap-2 p-3 sm:grid-cols-2 lg:grid-cols-6">
          {activeModule === 'daily' ? (
            <Field label="Día del reporte">
              <Input
                type="date"
                value={filters.date ?? ''}
                onChange={(event) => updateFilter('date', event.target.value || undefined)}
              />
            </Field>
          ) : (
            <>
              <Field label="Desde">
                <Input
                  type="date"
                  className="min-h-11"
                  value={draftDateFrom}
                  onChange={(event) => setDraftDateFrom(event.target.value)}
                />
              </Field>
              <Field label="Hasta">
                <Input
                  type="date"
                  className="min-h-11"
                  value={draftDateTo}
                  onChange={(event) => setDraftDateTo(event.target.value)}
                />
              </Field>
            </>
          )}
          {usesBranchFilter && (
            <Field label="Sucursal">
              <Select
                className="min-h-11"
                value={filters.branch_id ? String(filters.branch_id) : ''}
                onChange={(event) => updateFilter('branch_id', parseOptionalNumber(event.target.value))}
              >
                <option value="">Todas las sucursales</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>{branch.name}</option>
                ))}
              </Select>
            </Field>
          )}
          {usesWarehouseFilter && (
            <Field label="Almacén">
              <Select
                className="min-h-11"
                value={filters.warehouse_id ? String(filters.warehouse_id) : ''}
                onChange={(event) => updateFilter('warehouse_id', parseOptionalNumber(event.target.value))}
              >
                <option value="">Todos los almacenes</option>
                {warehouses.map((warehouse) => (
                  <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>
                ))}
              </Select>
            </Field>
          )}
          <div className="flex flex-wrap items-end gap-2">
            {activeModule !== 'daily' && (draftDateFrom !== (search.date_from ?? '') || draftDateTo !== (search.date_to ?? '')) && (
              <Button
                type="button"
                variant="primary"
                className="min-h-11 flex-1"
                onClick={() => onSearchChange({
                  ...search,
                  date_from: draftDateFrom || undefined,
                  date_to: draftDateTo || undefined,
                  page: 1,
                })}
              >
                Aplicar período
              </Button>
            )}
            <Button type="button" variant="outline" className="min-h-11 flex-1" onClick={refreshActive}>
              <RefreshCw className="size-4" /> Actualizar
            </Button>
          </div>
        </CardContent>
      </Card>

      {activeError && <ReportLoadError onRetry={refreshActive} />}

      {!activeError && activeModule === 'daily' && (
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
      {!activeError && activeModule === 'sales' && (
        <SalesDetailPanel
          data={sales.data}
          isLoading={sales.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
          onPageChange={(page) => updateFilter('page', page)}
        />
      )}
      {!activeError && activeModule === 'cash' && (
        <CashSessionsPanel
          data={cash.data}
          isLoading={cash.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
          onPageChange={(page) => updateFilter('page', page)}
        />
      )}
      {!activeError && activeModule === 'payments' && (
        <PaymentsPanel
          rows={payments.data ?? []}
          isLoading={payments.isLoading}
          canExport={canExport}
          filters={filters}
        />
      )}
      {!activeError && activeModule === 'stock' && (
        <StockPanel
          data={stock.data}
          lowStockCount={stock.data?.summary?.low_stock_count ?? lowStock.data?.length ?? 0}
          isLoading={stock.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
          onPageChange={(page) => updateFilter('page', page)}
        />
      )}
      {!activeError && activeModule === 'movements' && (
        <MovementsPanel
          data={movements.data}
          isLoading={movements.isLoading}
          canExport={canExport}
          filters={filters}
          updateFilter={updateFilter}
          onPageChange={(page) => updateFilter('page', page)}
        />
      )}
      {!activeError && activeModule === 'finance' && (
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
  if (!data)
    return <EmptyState title="Sin datos" description="No se pudo cargar el dia operativo." />;

  return (
    <ReportPanel
      title="Dia operativo"
      description="Auditoria del dia sin exigir que todas las cajas esten cerradas."
      onExport={onExport}
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Metric
          icon={ReceiptText}
          label="Ventas netas"
          value={formatMoney(data.sales.net_base_amount ?? data.sales.confirmed_base_amount)}
          helper={
            (data.sales.returned_base_amount ?? 0) > 0
              ? `${data.sales.confirmed_count} confirmadas · Dev. ${formatMoney(data.sales.returned_base_amount ?? 0)}`
              : `${data.sales.confirmed_count} confirmadas`
          }
        />
        <Metric
          icon={Wallet}
          label="POS cobrado"
          value={formatMoney(
            data.sales.pos_net_paid_base_amount ??
              (data.sales.pos_paid_base_amount - (data.sales.pos_returned_base_amount ?? 0)),
          )}
          helper={
            (data.sales.pos_returned_base_amount ?? 0) > 0
              ? `${data.sales.pos_paid_count} tickets · Dev. ${formatMoney(data.sales.pos_returned_base_amount ?? 0)}`
              : `${data.sales.pos_paid_count} tickets pagados`
          }
        />
        <Metric
          icon={RotateCcw}
          label="Devoluciones"
          value={formatMoney(data.sales.returned_base_amount ?? data.returns.processed_base_amount ?? 0)}
          helper={`${data.returns.processed_count} procesadas${data.returns.requested_count > 0 ? ` · ${data.returns.requested_count} solicitadas` : ''}`}
          tone={(data.sales.returned_base_amount ?? 0) > 0 ? 'warning' : 'default'}
        />
        <Metric
          icon={Landmark}
          label="CxC generada"
          value={formatMoney(data.sales.credit_balance_base_amount)}
          helper={`${data.sales.credit_count} saldos abiertos`}
          tone="warning"
        />
        <Metric
          icon={Banknote}
          label="Caja esperada"
          value={formatMoney(data.cash.expected_base_amount)}
          helper={`${data.cash.open_count} abiertas / ${data.cash.closed_count} cerradas`}
        />
      </div>
      <div className="mt-3 grid grid-cols-1 gap-3 xl:grid-cols-2">
        <div className="border-border rounded-md border p-3">
          <h3 className="font-semibold">Alertas operativas</h3>
          <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
            <AlertItem
              label="Cajas abiertas de dias previos"
              value={data.alerts.stale_open_sessions}
            />
            <AlertItem
              label="Cierres con diferencia"
              value={data.alerts.closed_sessions_with_difference}
            />
            <AlertItem
              label="Pagos sin referencia"
              value={data.alerts.payments_missing_reference}
            />
            <AlertItem
              label="POS pagados sin caja"
              value={data.alerts.paid_pos_without_cash_session}
            />
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
  onPageChange,
}: {
  data?: SalesDetail;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) => void;
  onPageChange: (page: number) => void;
}) {
  const rows = data?.rows ?? [];
  return (
    <ReportPanel
      title="Ventas detalladas"
      extra={
        <OptionsFilter
          value={filters.status ?? 'all'}
          options={SALES_STATUS}
          onChange={(value) => updateFilter('status', value)}
        />
      }
      onExport={
        canExport
          ? () => void downloadLegacyReport('sales', filters)
          : undefined
      }
      disabledExport={data?.meta?.total === 0}
    >
      {isLoading ? (
        <TableSkeleton />
      ) : (
        <>
          <SalesDetailTable rows={rows} />
          <ReportPagination meta={data?.meta} label="ventas" onPageChange={onPageChange} />
        </>
      )}
    </ReportPanel>
  );
}

function CashSessionsPanel({
  data,
  isLoading,
  canExport,
  filters,
  updateFilter,
  onPageChange,
}: {
  data?: CashSessions;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) => void;
  onPageChange: (page: number) => void;
}) {
  const rows = data?.rows ?? [];
  return (
    <ReportPanel
      title="Cajas y POS"
      description="Turnos abiertos y cerrados, esperado, contado, diferencia y movimientos."
      extra={
        <Select
          value={filters.status ?? 'all'}
          onChange={(event) => updateFilter('status', event.target.value)}
        >
          {CASH_STATUS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </Select>
      }
      onExport={canExport ? () => void downloadLegacyReport('cash', filters) : undefined}
      disabledExport={data?.meta?.total === 0}
    >
      {isLoading || !data ? (
        <TableSkeleton />
      ) : (
        <div className="space-y-3">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
            <MiniTotal label="Abiertas" value={String(data.summary.open_count)} />
            <MiniTotal label="Cerradas" value={String(data.summary.closed_count)} />
            <MiniTotal
              label="Esperado USD"
              value={formatMoney(data.summary.expected_base_amount)}
            />
            <MiniTotal
              label="Diferencia cerrada"
              value={formatMoney(data.summary.difference_base_amount)}
            />
          </div>
          <CashSessionsTable rows={rows} />
          <ReportPagination meta={data.meta} label="cajas" onPageChange={onPageChange} />
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
  filters,
}: {
  rows: PaymentMethodsReport;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
}) {
  return (
    <ReportPanel
      title="Metodos de pago"
      description="Desglose de cobros capturados por metodo, moneda y referencias faltantes."
      onExport={canExport ? () => void downloadLegacyReport('payments', { ...filters, limit: 100 }) : undefined}
      disabledExport={rows.length === 0}
    >
      {isLoading ? <TableSkeleton /> : <PaymentMethodsTable rows={rows} />}
    </ReportPanel>
  );
}

function StockPanel({
  data,
  lowStockCount,
  isLoading,
  canExport,
  filters,
  updateFilter,
  onPageChange,
}: {
  data?: StockReportResponse;
  lowStockCount: number;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) => void;
  onPageChange: (page: number) => void;
}) {
  const rows = data?.data ?? [];
  const summary = data?.summary;
  const availableTotal =
    summary?.available ??
    rows.reduce((acc, row) => acc + row.quantity_available, 0);
  const reservedTotal =
    summary?.reserved ??
    rows.reduce((acc, row) => acc + row.quantity_reserved, 0);
  const damagedTotal =
    summary?.damaged ??
    rows.reduce((acc, row) => acc + row.quantity_damaged, 0);
  const lowCount = summary?.low_stock_count ?? lowStockCount;

  return (
    <ReportPanel
      title="Inventario"
      description="Existencias por almacén y producto."
      extra={
        <div className="flex flex-wrap items-center gap-2">
          <Input
            placeholder="Buscar código o nombre..."
            className="w-48 lg:w-60"
            value={filters.search ?? ''}
            onChange={(event) => updateFilter('search', event.target.value || undefined)}
          />
          <div className="flex items-center gap-1.5">
            <span className="text-xs text-muted-foreground whitespace-nowrap">Bajo stock &le;:</span>
            <Input
              className="w-16"
              inputMode="decimal"
              value={filters.threshold ?? 3}
              onChange={(event) =>
                updateFilter('threshold', parseOptionalNumber(event.target.value) ?? 0)
              }
            />
          </div>
        </div>
      }
      onExport={canExport ? () => void downloadLegacyReport('stock', filters) : undefined}
      disabledExport={data?.meta?.total === 0}
    >
      <div className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
        <MiniTotal
          label="Disponible"
          value={`${formatQty(availableTotal)} und.`}
          helper={data?.meta ? `Total catálogo: ${data.meta.total} productos` : undefined}
        />
        <MiniTotal label="Reservado" value={`${formatQty(reservedTotal)} und.`} />
        <MiniTotal label="Dañado" value={`${formatQty(damagedTotal)} und.`} />
        <MiniTotal label="Bajo stock" value={String(lowCount)} />
      </div>
      {isLoading ? (
        <TableSkeleton />
      ) : (
        <>
          <StockTable rows={rows} />
          <ReportPagination meta={data?.meta} label="productos" onPageChange={onPageChange} />
        </>
      )}
    </ReportPanel>
  );
}

function MovementsPanel({
  data,
  isLoading,
  canExport,
  filters,
  updateFilter,
  onPageChange,
}: {
  data?: MovementReportResponse;
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) => void;
  onPageChange: (page: number) => void;
}) {
  const rows = data?.data ?? [];
  return (
    <ReportPanel
      title="Movimientos"
      description="Kardex operativo filtrado por fecha, almacen, producto, tipo o busqueda."
      extra={
        <div className="flex flex-wrap items-center gap-2">
          <Input
            placeholder="Buscar por código, nombre o motivo..."
            className="w-52 lg:w-72"
            value={filters.search ?? ''}
            onChange={(event) => updateFilter('search', event.target.value || undefined)}
          />
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
        </div>
      }
      onExport={canExport ? () => void downloadLegacyReport('movements', filters) : undefined}
      disabledExport={data?.meta?.total === 0}
    >
      {isLoading ? (
        <TableSkeleton />
      ) : (
        <>
          <MovementsTable rows={rows} />
          <ReportPagination meta={data?.meta} label="movimientos" onPageChange={onPageChange} />
        </>
      )}
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
  summary?: {
    cash_flow: { collections_base_amount: number; supplier_payments_base_amount: number };
    net_balance_base_amount: number;
  };
  receivables: FinanceReceivableRow[];
  payables: FinancePayableRow[];
  isLoading: boolean;
  canExport: boolean;
  filters: ReportFilters;
  updateFilter: <K extends keyof ReportFilters>(
    key: K,
    value: ReportFilters[K] | undefined,
  ) => void;
}) {
  return (
    <ReportPanel
      title="Finanzas"
      description="CxC, CxP, cobranza y pagos a proveedores en moneda base USD."
      extra={
        <StatusFilter
          value={filters.status ?? 'all'}
          onChange={(value) => updateFilter('status', value)}
        />
      }
      onExport={
        canExport
          ? () => void downloadFinanceReport(filters)
          : undefined
      }
      disabledExport={receivables.length + payables.length === 0}
    >
      {isLoading ? (
        <TableSkeleton />
      ) : (
        <div className="space-y-3">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <MiniTotal
              label="Cobranza recibida"
              value={formatMoney(summary?.cash_flow.collections_base_amount)}
            />
            <MiniTotal
              label="Pagos a proveedor"
              value={formatMoney(summary?.cash_flow.supplier_payments_base_amount)}
            />
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

function ReportLoadError({ onRetry }: { onRetry: () => void }) {
  return (
    <Card>
      <CardContent className="flex flex-col items-start justify-between gap-3 p-3 sm:flex-row sm:items-center">
        <div role="alert">
          <p className="font-semibold text-text-primary">No se pudo cargar este informe</p>
          <p className="text-text-muted mt-1 text-sm">Tus filtros siguen disponibles. Intenta actualizar el reporte.</p>
        </div>
        <Button type="button" variant="outline" className="min-h-11" onClick={onRetry}>
          <RefreshCw className="size-4" /> Reintentar
        </Button>
      </CardContent>
    </Card>
  );
}

export function ReportPagination({
  meta,
  label,
  onPageChange,
}: {
  meta?: PaginationMeta;
  label: string;
  onPageChange: (page: number) => void;
}) {
  if (!meta || meta.last_page <= 1) return null;

  return (
    <div className="border-border text-text-muted mt-4 flex items-center justify-between border-t px-1 pt-3 text-sm">
      <span>
        Página {meta.current_page} de {meta.last_page} · {meta.total} {label}
      </span>
      <div className="flex gap-2">
        <Button
          size="sm"
          variant="secondary"
          disabled={meta.current_page <= 1}
          leftIcon={<ChevronLeft className="size-4" />}
          onClick={() => onPageChange(Math.max(1, meta.current_page - 1))}
        >
          Anterior
        </Button>
        <Button
          size="sm"
          variant="secondary"
          disabled={meta.current_page >= meta.last_page}
          rightIcon={<ChevronRight className="size-4" />}
          onClick={() => onPageChange(Math.min(meta.last_page, meta.current_page + 1))}
        >
          Siguiente
        </Button>
      </div>
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
  description?: string;
  children: React.ReactNode;
  extra?: React.ReactNode;
  onExport?: () => void;
  disabledExport?: boolean;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2 px-3 pt-3 pb-1">
        <div>
          <CardTitle className="text-sm">{title}</CardTitle>
          {description && <p className="text-text-muted mt-0.5 text-xs">{description}</p>}
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
      <CardContent className="p-3 pt-1">{children}</CardContent>
    </Card>
  );
}

function SalesDetailTable({ rows }: { rows: SalesDetail['rows'] }) {
  const [expanded, setExpanded] = useState<number | null>(rows[0]?.id ?? null);

  if (rows.length === 0) {
    return <EmptyState title="Sin ventas" description="No hay ventas con los filtros actuales." />;
  }

  return (
    <>
      <div className="space-y-2 md:hidden">
        {rows.map((row) => (
          <article key={row.id} className="border-border bg-surface rounded-md border p-3">
            <button
              type="button"
              className="flex min-h-11 w-full items-start justify-between gap-3 text-left"
              aria-expanded={expanded === row.id}
              onClick={() => setExpanded(expanded === row.id ? null : row.id)}
            >
              <span className="min-w-0">
                <span className="block truncate font-semibold text-text-primary">Venta #{row.id} · {row.origin}</span>
                <span className="text-text-muted mt-1 block text-xs">{formatDate(row.confirmed_at ?? row.created_at)}</span>
              </span>
              <StatusBadge status={row.status} />
            </button>
            <div className="mt-2 grid grid-cols-2 gap-2 text-sm">
              <div><span className="block text-xs text-text-muted">Cliente</span><span className="break-words">{row.customer_name}</span></div>
              <div><span className="block text-xs text-text-muted">Cajero</span><span className="break-words">{row.cashier_name ?? row.created_by_name ?? '-'}</span></div>
              <div><span className="block text-xs text-text-muted">Total</span><span className="font-semibold tabular-nums">{formatMoney(row.total_base_amount)}</span></div>
              <div><span className="block text-xs text-text-muted">Cobranza</span><span>{statusLabel(row.collection.status)}</span></div>
            </div>
            {expanded === row.id && (
              <div className="border-border mt-3 space-y-2 border-t pt-3">
                <DetailList
                  title="Productos"
                  rows={row.items.map((item) => `${item.product_name ?? 'Producto'} · ${formatQty(item.quantity)} und. · ${formatMoney(item.base_total_amount)}`)}
                />
                <DetailList
                  title="Pagos"
                  rows={row.payments.map((payment) => `${payment.payment_method_name ?? methodLabel(payment.method)} · ${payment.currency} ${payment.amount}`)}
                  empty="Sin pagos registrados"
                />
                <DetailList
                  title="Devoluciones"
                  rows={row.returns.map((ret) => `#${ret.id} · ${statusLabel(ret.status)} · ${ret.reason ?? 'Sin motivo indicado'}`)}
                  empty="Sin devoluciones"
                />
              </div>
            )}
          </article>
        ))}
      </div>
      <div className="hidden overflow-auto rounded-md border md:block">

      <table className="w-full min-w-[1100px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-2 py-1.5">Venta</th>
            <th className="px-2 py-1.5">Fecha</th>
            <th className="px-2 py-1.5">Cliente</th>
            <th className="px-2 py-1.5">Cajero</th>
            <th className="px-2 py-1.5">Estado</th>
            <th className="px-2 py-1.5">Cobranza</th>
            <th className="px-2 py-1.5 text-right">Total</th>
            <th className="px-2 py-1.5 text-right">Items</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => {
            const returnedProducts = row.returns
              .filter((r) => r.status === 'processed')
              .flatMap((r) => r.items ?? [])
              .reduce(
                (acc, item) => {
                  const key = item.sale_item_id || item.product_id || item.product_name || 'unknown';
                  const existing = acc.find((p) => p.key === key);
                  if (existing) {
                    existing.quantity += item.quantity;
                  } else {
                    acc.push({
                      key,
                      name: item.product_name || 'Producto',
                      sku: item.product_sku,
                      quantity: item.quantity,
                    });
                  }
                  return acc;
                },
                [] as Array<{ key: string | number; name: string; sku?: string | null; quantity: number }>,
              );

            return (
              <Fragment key={row.id}>
                <tr
                  key={row.id}
                  className="cursor-pointer"
                  onClick={() => setExpanded(expanded === row.id ? null : row.id)}
                >
                  <td className="px-2 py-1.5 font-semibold">
                    <span className="inline-flex items-center gap-2">
                      {expanded === row.id ? (
                        <ChevronDown className="size-4" />
                      ) : (
                        <ChevronRight className="size-4" />
                      )}
                      #{row.id} - {row.origin}
                    </span>
                  </td>
                  <td className="text-text-muted px-2 py-1.5">
                    {formatDate(row.confirmed_at ?? row.created_at)}
                  </td>
                  <td className="px-2 py-1.5">{row.customer_name}</td>
                  <td className="px-2 py-1.5">{row.cashier_name ?? row.created_by_name ?? '-'}</td>
                  <td className="px-2 py-1.5">
                    <StatusBadge status={row.status} />
                    {returnedProducts.length > 0 && (
                      <div className="mt-1 flex flex-col gap-0.5">
                        {returnedProducts.map((p) => (
                          <span
                            key={p.key}
                            className="inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-300"
                          >
                            ↩ Devuelto: {p.name} ({formatQty(p.quantity)} und.)
                          </span>
                        ))}
                      </div>
                    )}
                  </td>
                  <td className="px-2 py-1.5">
                    <StatusBadge status={row.collection.status} />
                    {row.collection.balance_base_amount > 0 && (
                      <span className="ml-2 font-semibold">
                        {formatMoney(row.collection.balance_base_amount)}
                      </span>
                    )}
                  </td>
                  <td className="px-2 py-1.5 text-right font-semibold tabular-nums">
                    {formatMoney(row.total_base_amount)}
                  </td>
                  <td className="px-2 py-1.5 text-right">{row.items_count ?? row.items.length}</td>
                </tr>
                {expanded === row.id && (
                  <tr>
                    <td colSpan={8} className="bg-bg/60 px-3 py-3">
                      {returnedProducts.length > 0 && (
                        <div className="mb-3 rounded-md border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                          <span className="font-semibold">Productos con devolución en esta venta:</span>
                          <ul className="mt-1 list-inside list-disc space-y-0.5">
                            {returnedProducts.map((p) => (
                              <li key={p.key}>
                                <span className="font-medium">{p.name}</span>
                                {p.sku ? ` (${p.sku})` : ''}: {formatQty(p.quantity)} und. devuelta(s)
                              </li>
                            ))}
                          </ul>
                        </div>
                      )}
                      <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                        <DetailList
                          title="Productos"
                          rows={row.items.map((item) => {
                            const retQty =
                              item.returned_quantity ??
                              row.returns
                                .filter((r) => r.status === 'processed')
                                .flatMap((r) => r.items ?? [])
                                .filter((ri) =>
                                  ri.sale_item_id
                                    ? ri.sale_item_id === item.id
                                    : ri.product_id === item.product_id,
                                )
                                .reduce((sum, ri) => sum + ri.quantity, 0);
                            const retBadge =
                              retQty > 0 ? ` (Devuelto: ${formatQty(retQty)} und.)` : '';
                            return `${item.product_name ?? 'Producto'} - ${formatQty(item.quantity)} und.${retBadge} - ${formatMoney(item.base_total_amount)}${item.serial_units.length ? ` - ${item.serial_units.map((unit) => unit.serial_number).join(', ')}` : ''}`;
                          })}
                        />
                        <DetailList
                          title="Pagos"
                          rows={row.payments.map(
                            (payment) =>
                              `${payment.payment_method_name ?? methodLabel(payment.method)} - ${payment.currency} ${payment.amount} - Base ${formatMoney(payment.amount_base)}${payment.reference ? ` - Ref. ${payment.reference}` : ''}`,
                          )}
                          empty="Sin pagos registrados"
                        />
                        <DetailList
                          title="Devoluciones"
                          rows={row.returns.map((ret) => {
                            const itemsList = (ret.items ?? [])
                              .map(
                                (i) =>
                                  `${i.product_name ?? 'Producto'} (${formatQty(i.quantity)} und.)`,
                              )
                              .join(', ');
                            const details = itemsList ? `: ${itemsList}` : ` - ${ret.items_count} items`;
                            const reason = ret.reason ? ` · Motivo: ${ret.reason}` : '';
                            return `#${ret.id} - ${statusLabel(ret.status)}${details}${reason}`;
                          })}
                          empty="Sin devoluciones"
                        />
                        <DetailList
                          title="POS/Caja"
                          rows={[
                            row.pos_order
                              ? `Orden POS #${row.pos_order.id} (${statusLabel(row.pos_order.status)}) - ${row.pos_order.cash_register_name ?? 'Sin caja'} - ${row.pos_order.branch_name ?? 'Sin sucursal'}`
                              : 'Venta manual',
                          ]}
                        />
                      </div>
                    </td>
                  </tr>
                )}
              </Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
    </>
  );
}

function CashSessionsTable({ rows }: { rows: CashSessions['rows'] }) {
  if (rows.length === 0)
    return <EmptyState title="Sin cajas" description="No hay turnos con los filtros actuales." />;

  return (
    <>
      <div className="space-y-2 md:hidden">
        {rows.map((row) => (
          <article key={row.id} className="border-border bg-surface rounded-md border p-3">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <h3 className="truncate font-semibold text-text-primary">{row.cash_register_name ?? `Caja #${row.id}`}</h3>
                <p className="text-text-muted mt-1 text-xs">{row.branch_name ?? 'Sin sucursal'} · {row.cashier_name ?? 'Sin cajero'}</p>
              </div>
              <StatusBadge status={row.status} />
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
              <div><dt className="text-xs text-text-muted">Esperado</dt><dd className="font-medium tabular-nums">{formatMoney(row.expected_base_amount)}</dd></div>
              <div><dt className="text-xs text-text-muted">Contado</dt><dd className="font-medium tabular-nums">{row.counted_base_amount == null ? 'Pendiente de cierre' : formatMoney(row.counted_base_amount)}</dd></div>
              <div><dt className="text-xs text-text-muted">Diferencia</dt><dd className="font-medium tabular-nums">{row.difference_base_amount == null ? '—' : formatMoney(row.difference_base_amount)}</dd></div>
              <div><dt className="text-xs text-text-muted">Apertura</dt><dd>{formatDate(row.opened_at)}</dd></div>
            </dl>
          </article>
        ))}
      </div>
      <div className="hidden overflow-auto rounded-md border md:block">

      <table className="w-full min-w-[1000px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-2 py-1.5">Caja</th>
            <th className="px-2 py-1.5">Sucursal</th>
            <th className="px-2 py-1.5">Cajero</th>
            <th className="px-2 py-1.5">Estado</th>
            <th className="px-2 py-1.5 text-right">Fondo</th>
            <th className="px-2 py-1.5 text-right">Esperado</th>
            <th className="px-2 py-1.5 text-right">Contado</th>
            <th className="px-2 py-1.5 text-right">Diferencia</th>
            <th className="px-2 py-1.5">Apertura</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="px-2 py-1.5 font-semibold">
                {row.cash_register_name ?? `Caja #${row.id}`}
              </td>
              <td className="px-2 py-1.5">{row.branch_name ?? '-'}</td>
              <td className="px-2 py-1.5">{row.cashier_name ?? '-'}</td>
              <td className="px-2 py-1.5">
                <StatusBadge status={row.status} />
              </td>
              <td className="px-2 py-1.5 text-right">{formatMoney(row.opening_base_amount)}</td>
              <td className="px-2 py-1.5 text-right font-semibold">
                {formatMoney(row.expected_base_amount)}
              </td>
              <td className="px-2 py-1.5 text-right">
                {row.counted_base_amount === null || row.counted_base_amount === undefined
                  ? '-'
                  : formatMoney(row.counted_base_amount)}
              </td>
              <td className="px-2 py-1.5 text-right">
                {row.difference_base_amount === null || row.difference_base_amount === undefined
                  ? 'Pendiente de cierre'
                  : formatMoney(row.difference_base_amount)}
              </td>
              <td className="text-text-muted px-2 py-1.5">{formatDate(row.opened_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    </>
  );
}

function StockTable({ rows }: { rows: StockReportRow[] }) {
  if (rows.length === 0)
    return (
      <EmptyState title="Sin inventario" description="Ajusta los filtros o registra movimientos." />
    );

  return (
    <>
      <div className="space-y-2 md:hidden">
        {rows.map((row) => (
          <article key={`${row.warehouse_id}-${row.product_id}`} className="border-border bg-surface rounded-md border p-3">
            <div className="font-semibold text-text-primary">{row.product_name ?? `Producto #${row.product_id}`}</div>
            <div className="text-text-muted text-xs">{row.sku ?? 'Sin SKU'} · {row.warehouse_name ?? `Almacén #${row.warehouse_id}`}</div>
            <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
              <div><dt className="text-xs text-text-muted">Disponible</dt><dd className="font-semibold tabular-nums">{formatQty(row.quantity_available)}</dd></div>
              <div><dt className="text-xs text-text-muted">Reservado</dt><dd className="tabular-nums">{formatQty(row.quantity_reserved)}</dd></div>
              <div><dt className="text-xs text-text-muted">Dañado</dt><dd className="tabular-nums">{formatQty(row.quantity_damaged)}</dd></div>
            </dl>
          </article>
        ))}
      </div>
      <div className="hidden overflow-auto rounded-md border md:block">

      <table className="w-full min-w-[760px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-2 py-1.5">Producto</th>
            <th className="px-2 py-1.5">Almacen</th>
            <th className="px-2 py-1.5 text-right">Disponible</th>
            <th className="px-2 py-1.5 text-right">Reservado</th>
            <th className="px-2 py-1.5 text-right">Danado</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={`${row.warehouse_id}-${row.product_id}`}>
              <td className="px-2 py-1.5">
                <div className="font-medium">
                  {row.product_name ?? `Producto #${row.product_id}`}
                </div>
                <div className="text-text-muted text-xs">{row.sku ?? '-'}</div>
              </td>
              <td className="px-2 py-1.5">{row.warehouse_name ?? `Almacen #${row.warehouse_id}`}</td>
              <td className="px-2 py-1.5 text-right font-semibold tabular-nums">
                {formatQty(row.quantity_available)}
              </td>
              <td className="px-2 py-1.5 text-right tabular-nums">
                {formatQty(row.quantity_reserved)}
              </td>
              <td className="px-2 py-1.5 text-right tabular-nums">
                {formatQty(row.quantity_damaged)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    </>
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
    <>
      <div className="space-y-2 md:hidden">
        {rows.map((row) => (
          <article key={row.id} className="border-border bg-surface rounded-md border p-3">
            <div className="flex items-start justify-between gap-3">
              <div className="font-semibold text-text-primary">{row.product_name ?? `Producto #${row.product_id}`}</div>
              <Badge variant="info">{movementLabel(row.type)}</Badge>
            </div>
            <p className="text-text-muted mt-1 text-xs">{row.sku ?? 'Sin SKU'} · {row.warehouse_name ?? `Almacén #${row.warehouse_id}`}</p>
            <div className="mt-3 flex items-center justify-between gap-3 text-sm">
              <span className="font-medium tabular-nums">{formatQty(row.quantity)} und.</span>
              <span className="text-text-muted text-right">{formatDate(row.created_at)}</span>
            </div>
            {row.reason && <p className="mt-2 border-t pt-2 text-sm">{row.reason}</p>}
          </article>
        ))}
      </div>
      <div className="hidden overflow-auto rounded-md border md:block">

      <table className="w-full min-w-[920px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-2 py-1.5">Fecha</th>
            <th className="px-2 py-1.5">Tipo</th>
            <th className="px-2 py-1.5">Producto</th>
            <th className="px-2 py-1.5">Almacen</th>
            <th className="px-2 py-1.5 text-right">Cantidad</th>
            <th className="px-2 py-1.5">Motivo</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={row.id}>
              <td className="text-text-muted px-2 py-1.5">{formatDate(row.created_at)}</td>
              <td className="px-2 py-1.5">
                <Badge variant="info">{movementLabel(row.type)}</Badge>
              </td>
              <td className="px-2 py-1.5">
                <div className="font-medium">
                  {row.product_name ?? `Producto #${row.product_id}`}
                </div>
                <div className="text-text-muted text-xs">{row.sku ?? '-'}</div>
              </td>
              <td className="px-2 py-1.5">{row.warehouse_name ?? `Almacen #${row.warehouse_id}`}</td>
              <td className="px-2 py-1.5 text-right font-semibold tabular-nums">
                {formatQty(row.quantity)}
              </td>
              <td className="text-text-muted px-2 py-1.5">{row.reason ?? '-'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    </>
  );
}

function PaymentMethodsTable({ rows }: { rows: PaymentMethodsReport }) {
  if (rows.length === 0)
    return <EmptyState title="Sin pagos" description="No hay pagos capturados en el periodo." />;

  return (
    <>
      <div className="space-y-2 md:hidden">
        {rows.map((row) => (
          <article key={`${row.method}-${row.currency}-${row.name}`} className="border-border bg-surface rounded-md border p-3">
            <div className="flex items-start justify-between gap-3">
              <h3 className="font-semibold text-text-primary">{row.name}</h3>
              <span className="text-text-muted text-xs">{row.currency ?? '—'}</span>
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
              <div><dt className="text-xs text-text-muted">Pagos</dt><dd>{row.payments_count}</dd></div>
              <div><dt className="text-xs text-text-muted">Referencias faltantes</dt><dd>{row.missing_reference_count}</dd></div>
              <div><dt className="text-xs text-text-muted">Total ($)</dt><dd className="font-medium tabular-nums">{formatMoney(row.amount_base)}</dd></div>
              <div><dt className="text-xs text-text-muted">Total (Bs)</dt><dd className="tabular-nums">Bs {formatLocal(row.amount_local)}</dd></div>
            </dl>
          </article>
        ))}
      </div>
      <div className="hidden overflow-auto rounded-md border md:block">

      <table className="w-full min-w-[620px] text-sm">
        <thead className="bg-bg text-text-muted text-left text-xs uppercase">
          <tr>
            <th className="px-2 py-1.5">Metodo</th>
            <th className="px-2 py-1.5">Moneda</th>
            <th className="px-2 py-1.5 text-right">Pagos</th>
            <th className="px-2 py-1.5 text-right">Total USD</th>
            <th className="px-2 py-1.5 text-right">Total VES</th>
            <th className="px-2 py-1.5 text-right">Sin ref.</th>
          </tr>
        </thead>
        <tbody className="divide-border divide-y">
          {rows.map((row) => (
            <tr key={`${row.method}-${row.currency}-${row.name}`}>
              <td className="px-2 py-1.5 font-semibold">{row.name}</td>
              <td className="px-2 py-1.5">{row.currency ?? '-'}</td>
              <td className="px-2 py-1.5 text-right">{row.payments_count}</td>
              <td className="px-2 py-1.5 text-right font-semibold">{formatMoney(row.amount_base)}</td>
              <td className="px-2 py-1.5 text-right">Bs {formatLocal(row.amount_local)}</td>
              <td className="px-2 py-1.5 text-right">{row.missing_reference_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    </>
  );
}

function BreakdownTable({ rows }: { rows: CashSessions['movement_breakdown'] }) {
  if (rows.length === 0) return null;
  return (
    <div className="border-border rounded-md border">
      <div className="border-border border-b px-2 py-1.5 font-semibold">Movimientos por tipo</div>
      <PaymentMethodsTable
        rows={rows.map((row) => ({
          method: row.method,
          currency: row.currency,
          name: cashMovementLabel(row.type, row.method),
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
      <div className="border-border border-b px-2 py-1.5 font-semibold">{title}</div>
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
              <th className="px-2 py-1.5">{partyLabel}</th>
              <th className="px-2 py-1.5">Estado</th>
              <th className="px-2 py-1.5 text-right">Saldo</th>
            </tr>
          </thead>
          <tbody className="divide-border divide-y">
            {rows.slice(0, 10).map((row) => (
              <tr key={`${title}-${row.id}`}>
                <td className="px-2 py-1.5">{financePartyName(row)}</td>
                <td className="px-2 py-1.5">
                  <StatusBadge status={row.status} />
                </td>
                <td className="px-2 py-1.5 text-right font-semibold tabular-nums">
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

function DetailList({
  title,
  rows,
  empty = 'Sin datos',
}: {
  title: string;
  rows: string[];
  empty?: string;
}) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="mb-2 font-semibold">{title}</div>
      {rows.length === 0 ? (
        <div className="text-text-muted text-sm">{empty}</div>
      ) : (
        <ul className="space-y-1 text-sm">
          {rows.map((row, index) => (
            <li key={`${title}-${index}`}>{row}</li>
          ))}
        </ul>
      )}
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
      <CardContent className="flex items-start justify-between gap-2 p-3">
        <div>
          <p className="text-text-muted text-xs font-medium uppercase">{label}</p>
          <p className={`mt-0.5 text-lg font-semibold tabular-nums ${toneClass}`}>{value}</p>
          <p className="text-text-muted mt-1 text-xs">{helper}</p>
        </div>
        <Icon className="size-4" />
      </CardContent>
    </Card>
  );
}

function MiniTotal({
  label,
  value,
  helper,
}: {
  label: string;
  value: string;
  helper?: string;
}) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="text-text-muted text-sm">{label}</div>
      <div className="mt-1 text-lg font-semibold tabular-nums">{value}</div>
      {helper && <div className="text-text-muted mt-1 text-xs">{helper}</div>}
    </div>
  );
}

function AlertItem({ label, value }: { label: string; value: number }) {
  return (
    <div className="bg-bg rounded-md p-3">
      <div className="text-text-muted text-xs">{label}</div>
      <div className={`mt-1 text-lg font-semibold ${value > 0 ? 'text-warning' : 'text-success'}`}>
        {value}
      </div>
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
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </Select>
  );
}

function StatusBadge({ status }: { status: string }) {
  const variant =
    status === 'paid' || status === 'confirmed' || status === 'closed' || status === 'processed' || status === 'approved' || status === 'completed'
      ? 'success'
      : status === 'overdue' || status === 'cancelled' || status === 'voided' || status === 'void' || status === 'rejected'
        ? 'danger'
        : status === 'none'
          ? 'default'
          : 'warning';
  return <Badge variant={variant}>{statusLabel(status)}</Badge>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <Label className="text-xs">{label}</Label>
      <div className="mt-0.5">{children}</div>
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
  return todayDateString();
}


function formatQty(value: number): string {
  return new Intl.NumberFormat('es-VE', { maximumFractionDigits: 4 }).format(value);
}

function formatLocal(value?: number | string | null): string {
  return new Intl.NumberFormat('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value ?? 0));
}

function formatDate(value?: string | null): string {
  if (!value) return '-';
  return new Intl.DateTimeFormat('es-VE', { dateStyle: 'short', timeStyle: 'short' }).format(
    new Date(value),
  );
}

function movementLabel(type: string): string {
  return MOVEMENT_TYPES.find((option) => option.value === type)?.label ?? statusLabel(type);
}

function methodLabel(method?: string | null): string {
  return (
    {
      cash: 'Efectivo',
      card: 'Tarjeta',
      mobile_payment: 'Pago movil',
      transfer: 'Transferencia',
      zelle: 'Zelle',
      external_financing: 'Financiadora',
      other: 'Otro',
    }[method ?? ''] ??
    (method || 'Metodo')
  );
}

function statusLabel(status: string): string {
  return (
    {
      all: 'Todos',
      draft: 'Borrador',
      confirmed: 'Confirmada',
      cancelled: 'Cancelada',
      voided: 'Anulada',
      void: 'Anulada',
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
      returned: 'Devuelta',
      partial_return: 'Devuelta parcial',
      total_return: 'Devuelta total',
      refunded: 'Reembolsada',
      sellable: 'Apta para venta',
      damaged: 'Dañada',
      completed: 'Completada',
      in_progress: 'En progreso',
      prepared: 'Preparada',
      dispatched: 'Despachada',
      received: 'Recibida',
      delivered: 'Entregada',
    }[status] ?? status
  );
}

function financePartyName(row: FinanceReceivableRow | FinancePayableRow): string {
  if ('paid_base_amount' in row) return row.supplier_name ?? 'Proveedor sin nombre';
  return row.customer_name ?? 'Cliente sin nombre';
}

function flattenDaily(data: DailyOperations): Array<Record<string, unknown>> {
  return [
    {
      indicador: 'Ventas netas',
      valor: data.sales.net_base_amount ?? data.sales.confirmed_base_amount,
      cantidad: data.sales.confirmed_count,
    },
    {
      indicador: 'Ventas confirmadas (bruto)',
      valor: data.sales.confirmed_base_amount,
      cantidad: data.sales.confirmed_count,
    },
    {
      indicador: 'Devoluciones procesadas',
      valor: data.sales.returned_base_amount ?? data.returns.processed_base_amount ?? 0,
      cantidad: data.returns.processed_count,
    },
    {
      indicador: 'POS cobrado (neto)',
      valor:
        data.sales.pos_net_paid_base_amount ??
        data.sales.pos_paid_base_amount - (data.sales.pos_returned_base_amount ?? 0),
      cantidad: data.sales.pos_paid_count,
    },
    {
      indicador: 'POS cobrado (bruto)',
      valor: data.sales.pos_paid_base_amount,
      cantidad: data.sales.pos_paid_count,
    },
    {
      indicador: 'CxC generada',
      valor: data.sales.credit_balance_base_amount,
      cantidad: data.sales.credit_count,
    },
    {
      indicador: 'Caja esperada',
      valor: data.cash.expected_base_amount,
      cantidad: data.cash.open_count + data.cash.closed_count,
    },
    { indicador: 'Devoluciones solicitadas', valor: '', cantidad: data.returns.requested_count },
  ];
}
