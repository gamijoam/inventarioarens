import { useMemo, useState } from 'react';
import { BarChart3, Banknote, Boxes, Landmark, ReceiptText, RefreshCw, RotateCcw } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { Select } from '@/components/ui/Select';
import { cn } from '@/lib/cn';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { ReportsManager, type ReportsSearch } from '@/features/reports/ReportsManager';
import { ReportsV2Manager, type ReportV2WorkspaceSearch } from '@/features/reports-v2/ReportsV2Manager';
import { useReportV2Catalog } from '@/features/reports-v2/api';
import { REPORT_DOMAIN_LABELS, type ReportV2CatalogItem } from '@/features/reports-v2/schemas';

type Area = 'ventas' | 'caja' | 'inventario' | 'finanzas';
type ReportChoice = {
  id: string;
  label: string;
  description: string;
  area: Area;
  kind: 'v2' | 'legacy';
  code?: string;
  module?: NonNullable<ReportsSearch['module']>;
};

const AREA_META: Record<Area, { label: string; icon: typeof BarChart3 }> = {
  ventas: { label: 'Ventas', icon: ReceiptText },
  caja: { label: 'Caja', icon: Banknote },
  inventario: { label: 'Inventario', icon: Boxes },
  finanzas: { label: 'Finanzas', icon: Landmark },
};

const LEGACY_CHOICES: ReportChoice[] = [
  { id: 'legacy_sales', label: 'Ventas detalladas', description: 'Ventas, artículos, pagos, devoluciones y saldos.', area: 'ventas', kind: 'legacy', module: 'sales' },
  { id: 'legacy_daily', label: 'Resumen operativo de hoy', description: 'Actividad y alertas operativas del día seleccionado.', area: 'caja', kind: 'legacy', module: 'daily' },
  { id: 'legacy_cash', label: 'Turnos y diferencias de caja', description: 'Sesiones, arqueos, diferencias y revisión.', area: 'caja', kind: 'legacy', module: 'cash' },
  { id: 'legacy_payments', label: 'Pagos y referencias', description: 'Métodos de pago y referencias faltantes.', area: 'caja', kind: 'legacy', module: 'payments' },
  { id: 'legacy_stock', label: 'Existencias y bajo stock', description: 'Disponibilidad, valorización y productos por reponer.', area: 'inventario', kind: 'legacy', module: 'stock' },
  { id: 'legacy_movements', label: 'Movimientos de inventario', description: 'Entradas, salidas, ajustes y traslados.', area: 'inventario', kind: 'legacy', module: 'movements' },
  { id: 'legacy_finance', label: 'Cartera y saldos', description: 'Resumen financiero y detalle de cuentas por cobrar y pagar.', area: 'finanzas', kind: 'legacy', module: 'finance' },
];

const LEGACY_REPORT_BY_MODULE: Record<string, string> = {
  daily: 'legacy_daily',
  sales: 'legacy_sales',
  cash: 'legacy_cash',
  payments: 'legacy_payments',
  stock: 'legacy_stock',
  movements: 'legacy_movements',
  finance: 'legacy_finance',
};

export function ReportsWorkspace({
  search,
  onSearchChange,
}: {
  search: ReportsSearch;
  onSearchChange: (patch: ReportsSearch) => void;
}) {
  const canLegacy = useCan(PERMISSIONS.REPORTS_VIEW);
  const canSalesPermission = useCan(PERMISSIONS.REPORTS_SALES_VIEW);
  const canCashPermission = useCan(PERMISSIONS.REPORTS_CASH_VIEW);
  const canInventoryPermission = useCan(PERMISSIONS.REPORTS_INVENTORY_VIEW);
  const canMovementPermission = useCan(PERMISSIONS.REPORTS_MOVEMENTS_VIEW);
  const canSales = canLegacy || canSalesPermission;
  const canCash = canLegacy || canCashPermission;
  const canInventory = canLegacy || canInventoryPermission;
  const canMovements = canLegacy || canMovementPermission;
  const canFinance = useCan(PERMISSIONS.FINANCE_REPORTS_VIEW);
  const hasAnyReports = canSales || canCash || canInventory || canMovements || canFinance;
  const { data: v2Catalog = [], isLoading: catalogLoading, isError: catalogError, refetch } =
    useReportV2Catalog(hasAnyReports);
  const [activeArea, setActiveArea] = useState<Area | null>(null);

  const legacyAllowed = useMemo(() => LEGACY_CHOICES.filter((item) => {
    if (item.module === 'daily') return canSales || canCash;
    if (item.module === 'sales') return canSales;
    if (item.module === 'cash' || item.module === 'payments') return canCash;
    if (item.module === 'stock') return canInventory;
    if (item.module === 'movements') return canMovements;
    return canFinance;
  }), [canSales, canCash, canInventory, canMovements, canFinance]);

  const choices = useMemo<ReportChoice[]>(() => {
    const v2 = v2Catalog.map((item) => v2Choice(item, canCash));
    return [...v2, ...legacyAllowed];
  }, [v2Catalog, legacyAllowed, canCash]);

  const oldLinkReport = search.report ?? (search.module ? LEGACY_REPORT_BY_MODULE[search.module] : undefined);
  const selectedId = oldLinkReport ?? 'sales_overview';
  const selected = choices.find((choice) => choice.id === selectedId)
    ?? (!search.report && !search.module
      ? (!catalogLoading ? choices.find((choice) => choice.id === 'sales_overview') ?? choices[0] ?? null : null)
      : null);
  const shownArea = selected?.area ?? activeArea ?? 'ventas';
  const areaChoices = choices.filter((choice) => choice.area === shownArea);

  function selectChoice(choice: ReportChoice): void {
    setActiveArea(choice.area);
    const today = localDate();
    const patch: ReportsSearch = {
      ...search,
      report: choice.id,
      module: choice.module,
      page: 1,
    };
    if (choice.module === 'daily') {
      patch.date = today;
      patch.date_from = undefined;
      patch.date_to = undefined;
    } else if (['legacy_stock', 'stock_by_product', 'stock_by_warehouse'].includes(choice.id)) {
      patch.date_from = undefined;
      patch.date_to = undefined;
      patch.date = undefined;
    } else {
      patch.date_from = search.date_from;
      patch.date_to = search.date_to;
      patch.date = undefined;
    }
    if (choice.kind === 'v2') {
      const report = v2Catalog.find((item) => item.code === choice.code);
      patch.dimension = report?.default_dimension;
      patch.scope = 'tenant';
      patch.chart = report
        ? ['day', 'week', 'month'].includes(report.default_dimension) ? 'line' : 'bar'
        : undefined;
      if (choice.id !== 'stock_by_warehouse' && choice.id !== 'stock_by_product') {
        patch.warehouse_id = undefined;
      }
      patch.low_stock_only = undefined;
      patch.company_id = undefined;
    } else {
      patch.dimension = undefined;
      patch.scope = undefined;
      patch.chart = undefined;
      if (!['legacy_stock', 'legacy_movements', 'legacy_sales'].includes(choice.id)) {
        patch.warehouse_id = undefined;
      }
      if (!['legacy_sales', 'legacy_cash', 'legacy_payments'].includes(choice.id)) {
        patch.branch_id = undefined;
      }
    }
    onSearchChange(patch);
  }

  function updateV2Search(patch: ReportV2WorkspaceSearch): void {
    onSearchChange({ ...search, ...patch } as ReportsSearch);
  }

  function resetFilters(): void {
    const today = localDate();
    onSearchChange({
      ...search,
      date: selected?.module === 'daily' ? today : undefined,
      date_from: selected?.module === 'daily' || ['legacy_stock', 'stock_by_product', 'stock_by_warehouse'].includes(selected?.id ?? '') ? undefined : today.slice(0, 8) + '01',
      date_to: selected?.module === 'daily' || ['legacy_stock', 'stock_by_product', 'stock_by_warehouse'].includes(selected?.id ?? '') ? undefined : today,
      branch_id: undefined,
      warehouse_id: undefined,
      cash_register_id: undefined,
      cashier_id: undefined,
      customer_id: undefined,
      status: undefined,
      type: undefined,
      review_status: undefined,
      payment_method: undefined,
      search: undefined,
      page: 1,
    });
  }

  if (!hasAnyReports) {
    return (
      <PermissionDenied
        permission="reports.view / finance_reports.view"
        message="No tienes permiso para consultar reportes."
      />
    );
  }

  return (
    <div className="reports-workspace space-y-3">
      {choices.length > 0 && (
        <section className="rounded-md border border-border bg-surface p-2">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
            <nav aria-label="Áreas de reportes" className="flex min-w-0 gap-1 overflow-x-auto">
              {(Object.keys(AREA_META) as Area[])
                .filter((area) => choices.some((choice) => choice.area === area))
                .map((area) => {
                  const Icon = AREA_META[area].icon;
                  const active = shownArea === area;
                  const count = choices.filter((choice) => choice.area === area).length;
                  return (
                    <button
                      key={area}
                      type="button"
                      aria-pressed={active}
                      onClick={() => {
                        const firstChoice = choices.find((choice) => choice.area === area);
                        if (firstChoice) selectChoice(firstChoice);
                      }}
                      className={cn(
                        'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded px-3 text-sm font-medium transition-colors',
                        active
                          ? 'bg-primary text-primary-foreground'
                          : 'text-text-secondary hover:bg-bg',
                      )}
                    >
                      <Icon className="size-4" />
                      {AREA_META[area].label}
                      <span className="text-xs opacity-75">{count}</span>
                    </button>
                  );
                })}
            </nav>

            <div className="flex min-w-0 flex-1 items-center gap-2">
              <label htmlFor="reports-choice" className="sr-only">Informe</label>
              <Select
                id="reports-choice"
                className="min-h-11 min-w-0 flex-1"
                value={selected?.id ?? ''}
                onChange={(event) => {
                  const choice = areaChoices.find((item) => item.id === event.target.value);
                  if (choice) selectChoice(choice);
                }}
              >
                {!selected && <option value="" disabled>Selecciona un informe</option>}
                {areaChoices.map((choice) => (
                  <option key={choice.id} value={choice.id}>{choice.label}</option>
                ))}
              </Select>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="min-h-11 min-w-11 shrink-0 px-3"
                aria-label="Restablecer filtros"
                title="Restablecer filtros"
                onClick={resetFilters}
              >
                <RotateCcw className="size-4" />
                <span className="hidden sm:inline">Limpiar</span>
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="min-h-11 min-w-11 shrink-0 px-3"
                aria-label="Actualizar catálogo"
                title="Actualizar catálogo"
                onClick={() => void refetch()}
                disabled={catalogLoading}
              >
                <RefreshCw className={cn('size-4', catalogLoading && 'animate-spin')} />
                <span className="hidden sm:inline">Actualizar</span>
              </Button>
            </div>
          </div>
          {catalogError && (
            <p role="alert" className="mt-2 text-xs text-danger">
              No se pudo actualizar el catálogo. Los informes operativos siguen disponibles.
            </p>
          )}
        </section>
      )}

      {selected ? (
        selected.kind === 'v2' ? (
          <ReportsV2Manager
            search={search}
            onSearchChange={updateV2Search}
            reportCode={selected.code}
            showCatalog={false}
          />
        ) : (
          <ReportsManager
            search={{ ...search, module: selected.module }}
            onSearchChange={onSearchChange}
            showNavigation={false}
          />
        )
      ) : (
        <Card>
          <CardContent className="p-4">
            <div className="flex items-start gap-3">
              <BarChart3 className="mt-0.5 size-5 text-primary" />
              <div>
                <h2 className="font-semibold text-text-primary">{catalogLoading ? 'Cargando informes…' : 'No hay informes disponibles'}</h2>
                <p className="text-sm text-text-muted">Verifica que tu usuario tenga permisos de lectura para alguna área.</p>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function v2Choice(item: ReportV2CatalogItem, canCash: boolean): ReportChoice {
  const area: Area = item.domain === 'inventario'
    ? 'inventario'
    : item.domain === 'finanzas'
      ? 'finanzas'
      : item.code === 'sales_by_payment_method' && canCash
        ? 'caja'
        : 'ventas';
  const labels: Record<string, string> = {
    sales_overview: 'Resumen gerencial',
    sales_by_payment_method: 'Ventas por método de pago',
    receivables_by_customer: 'Cuentas por cobrar',
    payables_by_supplier: 'Cuentas por pagar',
    stock_by_product: 'Existencias por producto',
    stock_by_warehouse: 'Existencias por almacén',
  };
  const descriptions: Record<string, string> = {
    sales_overview: 'Ventas, cantidad de operaciones y ticket promedio del período.',
    sales_by_payment_method: 'Distribución de ventas por método, moneda y equivalente.',
    receivables_by_customer: 'Saldos abiertos agrupados por cliente.',
    payables_by_supplier: 'Saldos abiertos agrupados por proveedor.',
    stock_by_product: 'Unidades disponibles y valor actual por producto.',
    stock_by_warehouse: 'Existencias y valor agrupados por almacén.',
  };
  return {
    id: item.code,
    label: labels[item.code] ?? item.name,
    description: descriptions[item.code] ?? 'Detalle de ' + (REPORT_DOMAIN_LABELS[item.domain]?.toLowerCase() ?? 'operaciones') + '.',
    area,
    kind: 'v2',
    code: item.code,
  };
}

function localDate(): string {
  const date = new Date();
  const offsetDate = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return offsetDate.toISOString().slice(0, 10);
}
