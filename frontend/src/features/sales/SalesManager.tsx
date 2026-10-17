import { useEffect, useMemo, useState } from 'react';
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  FileText,
  RotateCcw,
  Search,
  ShieldCheck,
  X,
  XCircle,
  RefreshCw,
  DollarSign,
  TrendingUp,
  ShoppingBag,
  Store,
  Calendar,
} from 'lucide-react';
import { toast } from '@/lib/toast';

import { cn } from '@/lib/cn';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  useCashSessions,
  useCurrentExchangeRatesForPos,
  type CashRegisterSession,
  type CurrentExchangeRate,
} from '@/features/pos/api';
import { activeUsdVesRate, currentLocalBalance } from '@/features/receivables/currentBalance';
import { useCreateSalesReturn, type SalesReturnPayload } from '@/features/sales-returns/api';
import { useCreateWarrantyClaim, type WarrantyClaimPayload } from '@/features/warranties/api';
import { useCancelSale, useSale, useSales, type SaleListFilters } from './api';
import { ReverseSaleDialog } from './ReverseSaleDialog';
import { SALE_STATUS_LABELS, type Sale, type SaleItem, type SaleStatus } from './schemas';

const RECEIVABLE_STATUS_LABELS: Record<string, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  paid: 'Pagada',
  overdue: 'Vencida',
};

const PROMOTION_OPTIONS: { value: NonNullable<SaleListFilters['promotion_scope']>; label: string }[] = [
  { value: 'all', label: 'Promociones: todas' },
  { value: 'any', label: 'Con promoción' },
  { value: 'combo', label: 'Con combo' },
  { value: 'invoice', label: 'Descuento de factura' },
  { value: 'product_offer', label: 'Oferta por producto' },
  { value: 'none', label: 'Sin promoción' },
];

function statusVariant(status: SaleStatus): 'default' | 'success' | 'danger' | 'warning' {
  if (status === 'confirmed') return 'success';
  if (status === 'cancelled' || status === 'voided') return 'danger';
  return 'warning';
}

function formatMoney(value: number | null | undefined, currency = '$'): string {
  const n = Number(value ?? 0);
  return `${currency}${n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const BUSINESS_TIMEZONE = 'America/Caracas';

function businessDateKey(date: Date): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: BUSINESS_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);
  const part = (type: string) => parts.find((item) => item.type === type)?.value ?? '';
  return `${part('year')}-${part('month')}-${part('day')}`;
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('es-VE', {
    timeZone: BUSINESS_TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}



function receivableStatusLabel(sale: Sale): string {
  if (!sale.receivable) return 'Sin CxC';
  return RECEIVABLE_STATUS_LABELS[sale.receivable.status] ?? sale.receivable.status;
}

function receivableVariant(status: string | undefined): 'default' | 'success' | 'danger' | 'warning' | 'info' {
  if (status === 'paid') return 'success';
  if (status === 'overdue') return 'danger';
  if (status === 'partial') return 'info';
  if (status === 'pending') return 'warning';
  return 'default';
}

function promotionScopeLabel(scope: string): string {
  if (scope === 'combo') return 'COMBO';
  if (scope === 'invoice') return 'FACTURA';
  if (scope === 'product_offer') return 'OFERTA';
  return 'PROMO';
}

function promotionApplicationsLabel(sale: Sale): string[] {
  return (sale.promotion_applications ?? []).map(
    (application) => `${promotionScopeLabel(application.scope)}: ${application.promotion_name}`,
  );
}

function returnedQuantityForItem(sale: Sale, itemId: number): number {
  return (sale.sales_returns ?? []).reduce((total, salesReturn) => {
    if (salesReturn.status !== 'processed') return total;
    return total + (salesReturn.items ?? []).reduce((itemTotal, returnItem) => (
      returnItem.sale_item_id === itemId ? itemTotal + Number(returnItem.quantity ?? 0) : itemTotal
    ), 0);
  }, 0);
}

function returnedUnitIdsForItem(sale: Sale, itemId: number): Set<number> {
  const ids = new Set<number>();
  for (const salesReturn of sale.sales_returns ?? []) {
    if (salesReturn.status !== 'processed') continue;
    for (const item of salesReturn.items ?? []) {
      if (item.sale_item_id !== itemId) continue;
      for (const unitId of item.product_unit_ids ?? []) ids.add(Number(unitId));
    }
  }
  return ids;
}

function returnableQuantityForItem(sale: Sale, item: SaleItem): number {
  return Math.max(0, Number(item.quantity) - returnedQuantityForItem(sale, item.id));
}

function hasReturnableItems(sale: Sale): boolean {
  return (sale.items ?? []).some((item) => returnableQuantityForItem(sale, item) > 0);
}

function isWarrantyValid(item: SaleItem): boolean {
  if (!item.warranty_policy_id || !item.warranty_expires_at) return false;
  const expiresAt = new Date(item.warranty_expires_at);
  if (Number.isNaN(expiresAt.getTime())) return false;
  return expiresAt >= new Date();
}

function warrantyStatusLabel(item: SaleItem): string {
  if (!item.warranty_policy_id) return 'Sin garantía';
  if (!item.warranty_expires_at) return 'Sin vencimiento';
  return isWarrantyValid(item) ? `Vigente hasta ${formatDate(item.warranty_expires_at)}` : `Vencida ${formatDate(item.warranty_expires_at)}`;
}

function returnStatusLabel(sale: Sale): string | null {
  const items = sale.items ?? [];
  const returns = sale.sales_returns ?? [];
  if (items.length === 0 || returns.length === 0) return null;
  if (returns.some((item) => item.status === 'requested')) return 'Devolución solicitada';
  if (returns.some((item) => item.status === 'approved')) return 'Devolución aprobada';
  if (returns.some((item) => item.status === 'rejected')) return 'Devolución rechazada';
  if (returns.some((item) => item.status === 'cancelled')) return 'Devolución cancelada';
  const total = items.reduce((sum, item) => sum + Number(item.quantity ?? 0), 0);
  const returned = items.reduce((sum, item) => sum + returnedQuantityForItem(sale, item.id), 0);
  if (returned <= 0) return null;
  return returned >= total ? 'Devuelta total' : 'Devuelta parcial';
}

export interface ReturnedProductInfo {
  saleItemId: number;
  productId: number;
  productName: string;
  productSku?: string | null;
  returnedQuantity: number;
  soldQuantity: number;
  reasons: string[];
}

function getReturnedProducts(sale: Sale, statuses: string[] = ['processed']): ReturnedProductInfo[] {
  const items = sale.items ?? [];
  const returns = (sale.sales_returns ?? []).filter((r) => statuses.includes(r.status));
  if (returns.length === 0) return [];

  const productMap = new Map<number, ReturnedProductInfo>();

  for (const ret of returns) {
    for (const ri of ret.items ?? []) {
      const matchedSaleItem = items.find((si) => si.id === ri.sale_item_id);
      const key = ri.sale_item_id || ri.product_id || 0;
      const qty = Number(ri.quantity ?? 0);
      const name =
        matchedSaleItem?.product_name ||
        (ri as unknown as { product_name?: string }).product_name ||
        (ri as unknown as { product?: { name?: string } }).product?.name ||
        `Producto #${ri.product_id ?? matchedSaleItem?.product_id ?? key}`;
      const sku =
        matchedSaleItem?.product_sku ||
        (ri as unknown as { product_sku?: string }).product_sku ||
        (ri as unknown as { product?: { sku?: string } }).product?.sku ||
        null;
      const soldQty = Number(matchedSaleItem?.quantity ?? qty);
      const reason = ri.reason;

      const existing = productMap.get(key);
      if (existing) {
        existing.returnedQuantity += qty;
        if (reason && !existing.reasons.includes(reason)) {
          existing.reasons.push(reason);
        }
      } else {
        productMap.set(key, {
          saleItemId: ri.sale_item_id,
          productId: ri.product_id ?? matchedSaleItem?.product_id ?? 0,
          productName: name,
          productSku: sku,
          returnedQuantity: qty,
          soldQuantity: soldQty,
          reasons: reason ? [reason] : [],
        });
      }
    }
  }

  return Array.from(productMap.values());
}

export function SalesManager() {
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [filters, setFilters] = useState<SaleListFilters>({
    status: 'all',
    page: 1,
    per_page: 25,
  });

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search);
      setFilters((current) => (current.page === 1 ? current : { ...current, page: 1 }));
    }, 250);
    return () => clearTimeout(timer);
  }, [search]);

  const queryFilters: SaleListFilters = useMemo(
    () => ({
      ...filters,
      search: debouncedSearch.trim() || undefined,
    }),
    [filters, debouncedSearch]
  );

  const [expandedId, setExpandedId] = useState<number | null>(null);
  const { data, isLoading, isFetching, isError, refetch } = useSales(queryFilters);
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const { data: cashSessions = [] } = useCashSessions();
  const activeRate = activeUsdVesRate(rates);
  const activeSession = cashSessions[0] ?? null;
  const canCancel = useCan(PERMISSIONS.SALES_CANCEL);
  const canReverse = useCan(PERMISSIONS.SALES_REVERSE);
  const canCreateReturn = useCan(PERMISSIONS.SALES_RETURNS_CREATE);
  const canCreateWarranty = useCan(PERMISSIONS.WARRANTIES_CREATE);
  const canViewCosts = useCan(PERMISSIONS.FINANCE_COSTS_VIEW);
  const cancelSale = useCancelSale();
  const sales = data?.data ?? [];
  const meta = data?.meta;
  const summary = data?.summary;
  const fallbackTotals = useMemo(() => {
    return sales.reduce(
      (acc, sale) => {
        const isConfirmed = sale.status === 'confirmed';
        if (isConfirmed) {
          acc.confirmed_base_total += sale.total_base_amount;
          acc.confirmed_local_total += sale.total_local_amount;
          acc.confirmed_count += 1;
          if (sale.cost_base_amount !== null && sale.cost_base_amount !== undefined) {
            acc.confirmed_cost_base_total = (acc.confirmed_cost_base_total ?? 0) + Number(sale.cost_base_amount);
            acc.confirmed_profit_base_total = (acc.confirmed_profit_base_total ?? 0) + Number(sale.profit_base_amount ?? 0);
          }
        } else if (sale.status === 'draft') {
          acc.draft_count += 1;
        } else if (sale.status === 'cancelled' || sale.status === 'voided') {
          acc.cancelled_count += 1;
        }

        if (isConfirmed && sale.sales_returns?.length) {
          for (const ret of sale.sales_returns) {
            if (ret.status === 'processed') {
              const retBase = Number(ret.refund_amount_base ?? sale.receivable?.returned_base_amount ?? 0);
              const retLocal = Number(ret.refund_amount_local ?? sale.receivable?.returned_local_amount ?? 0);
              acc.refund_base_total += retBase;
              acc.refund_local_total += retLocal;
              acc.refund_count += 1;
            }
          }
        }

        if (sale.pos_order) {
          acc.pos_count += 1;
        }
        return acc;
      },
      {
        total_count: meta?.total ?? sales.length,
        confirmed_base_total: 0,
        confirmed_local_total: 0,
        refund_base_total: 0,
        refund_local_total: 0,
        refund_count: 0,
        confirmed_count: 0,
        draft_count: 0,
        cancelled_count: 0,
        pos_count: 0,
        confirmed_cost_base_total: 0,
        confirmed_profit_base_total: 0,
        confirmed_profit_margin_percent: 0,
      },
    );
  }, [sales, meta?.total]);

  const metrics = useMemo(() => {
    if (summary) {
      return summary;
    }
    const netBase = Math.max(0, fallbackTotals.confirmed_base_total - fallbackTotals.refund_base_total);
    const netLocal = Math.max(0, fallbackTotals.confirmed_local_total - fallbackTotals.refund_local_total);
    const margin = fallbackTotals.confirmed_base_total > 0
      ? Math.round(((fallbackTotals.confirmed_profit_base_total ?? 0) / fallbackTotals.confirmed_base_total) * 1000) / 10
      : 0;
    return {
      ...fallbackTotals,
      net_base_total: netBase,
      net_local_total: netLocal,
      confirmed_profit_margin_percent: margin,
    };
  }, [summary, fallbackTotals]);

  function updateFilters(next: Partial<SaleListFilters>) {
    setFilters((current) => ({ ...current, ...next, page: 1 }));
  }

  async function handleCancel(id: number) {
    const ok = window.confirm('¿Cancelar esta venta en borrador?');
    if (!ok) return;
    await cancelSale.mutateAsync(id);
    toast.success('Venta cancelada.');
  }

  const hasActiveFilters = Boolean(
    search ||
      filters.date_from ||
      filters.date_to ||
      (filters.status && filters.status !== 'all') ||
      (filters.promotion_scope && filters.promotion_scope !== 'all'),
  );

  function resetAllFilters() {
    setSearch('');
    setFilters({
      status: 'all',
      page: 1,
      per_page: 25,
    });
  }

  return (
    <div className="space-y-4">
      {/* BARRA TÁCTICA DE FILTROS Y CONTROL (ESTILO POS CLÁSICO) */}
      <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 sm:p-5 shadow-sm space-y-4">
        {/* FILA 1: BUSCADOR + PESTAÑAS DE ESTADO + BOTÓN ACTUALIZAR */}
        <div className="flex flex-col items-stretch gap-3 lg:flex-row lg:items-center">
          {/* BUSCADOR ÁGIL */}
          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              id="sales-search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Cliente, documento, producto, SKU o venta #"
              className="h-10 pl-9 pr-9 font-medium text-ui-control rounded-xl border-slate-300 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/50 focus:bg-white dark:focus:bg-slate-900"
            />
            {search && (
              <button
                type="button"
                onClick={() => setSearch('')}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 p-1 rounded-md"
                aria-label="Limpiar búsqueda"
              >
                <X className="size-3.5" />
              </button>
            )}
          </div>

          {/* PESTAÑAS SEGMENTADAS DE ESTADO */}
          <div className="flex items-center gap-1 bg-slate-100 dark:bg-slate-800/80 p-1 rounded-xl border border-slate-200/70 dark:border-slate-700/60 shrink-0 overflow-x-auto">
            {[
              { value: 'all', label: 'Todas', count: metrics.total_count },
              {
                value: 'confirmed',
                label: 'Confirmadas',
                count: metrics.confirmed_count,
                activeClass: 'text-emerald-700 dark:text-emerald-300',
              },
              {
                value: 'draft',
                label: 'Borradores',
                count: metrics.draft_count,
                activeClass: 'text-amber-700 dark:text-amber-300',
              },
              {
                value: 'cancelled',
                label: 'Canceladas',
                count: metrics.cancelled_count,
                activeClass: 'text-rose-700 dark:text-rose-300',
              },
            ].map((tab) => {
              const active = (filters.status ?? 'all') === tab.value;
              return (
                <button
                  key={tab.value}
                  type="button"
                  onClick={() => updateFilters({ status: tab.value as SaleListFilters['status'] })}
                  className={cn(
                    'px-3 py-1.5 text-ui-caption font-bold rounded-lg transition-all flex items-center gap-1.5 cursor-pointer whitespace-nowrap',
                    active
                      ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs'
                      : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 hover:bg-white/50',
                  )}
                >
                  <span className={active && tab.activeClass ? tab.activeClass : ''}>{tab.label}</span>
                  {tab.count !== undefined && (
                    <span
                      className={cn(
                        'text-ui-caption font-mono px-1.5 py-0.2 rounded-full font-bold',
                        active
                          ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'
                          : 'bg-slate-200/70 dark:bg-slate-700 text-slate-600 dark:text-slate-400',
                      )}
                    >
                      ({tab.count})
                    </span>
                  )}
                </button>
              );
            })}
          </div>

          {/* BOTÓN ACTUALIZAR */}
          <Button
            variant="secondary"
            onClick={() => void refetch()}
            disabled={isFetching}
            className="h-10 px-3.5 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs gap-2 shrink-0 font-semibold text-ui-caption"
          >
            <RefreshCw className={cn('size-3.5 text-blue-600', isFetching && 'animate-spin')} />
            <span>Actualizar</span>
          </Button>
        </div>

        {/* FILA 2: FECHAS + PROMOCIÓN + LIMPIAR */}
        <div className="flex flex-wrap items-center gap-2.5 pt-1 text-ui-caption border-t border-slate-100 dark:border-slate-800/70">
          <div className="flex items-center gap-1.5 text-slate-500 font-semibold">
            <Calendar className="size-3.5 text-slate-400" />
            <span>Rango:</span>
          </div>

          <div className="flex items-center gap-1.5">
            <Input
              type="date"
              value={filters.date_from ?? ''}
              onChange={(e) => updateFilters({ date_from: e.target.value || undefined })}
              className="h-8 w-36 text-ui-caption rounded-lg font-mono border-slate-300 dark:border-slate-700"
              title="Fecha desde"
            />
            <span className="text-slate-400 font-bold">-</span>
            <Input
              type="date"
              value={filters.date_to ?? ''}
              onChange={(e) => updateFilters({ date_to: e.target.value || undefined })}
              className="h-8 w-36 text-ui-caption rounded-lg font-mono border-slate-300 dark:border-slate-700"
              title="Fecha hasta"
            />
          </div>

          {/* ATAJOS RÁPIDOS DE FECHA */}
          <div className="flex items-center gap-1">
            <button
              type="button"
              onClick={() => {
                const today = businessDateKey(new Date());
                updateFilters({ date_from: today, date_to: today });
              }}
              className="px-2 py-1 text-ui-caption font-semibold rounded-md bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors"
            >
              Hoy
            </button>
            <button
              type="button"
              onClick={() => {
                const now = new Date();
                const past7 = businessDateKey(new Date(now.getTime() - 6 * 24 * 60 * 60 * 1000));
                const today = businessDateKey(now);
                updateFilters({ date_from: past7, date_to: today });
              }}
              className="px-2 py-1 text-ui-caption font-semibold rounded-md bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors"
            >
              7 días
            </button>
            <button
              type="button"
              onClick={() => {
                const now = new Date();
                const [year, month] = businessDateKey(now).split('-').map(Number);
                const firstDay = `${year}-${String(month).padStart(2, '0')}-01`;
                const today = businessDateKey(now);
                updateFilters({ date_from: firstDay, date_to: today });
              }}
              className="px-2 py-1 text-ui-caption font-semibold rounded-md bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors"
            >
              Este mes
            </button>
          </div>

          <div className="h-4 w-px bg-slate-200 dark:bg-slate-700 hidden sm:block mx-1" />

          {/* FILTRO PROMOCIÓN */}
          <div className="flex items-center gap-1.5">
            <Select
              id="sales-promotion-scope"
              value={filters.promotion_scope ?? 'all'}
              onChange={(e) => updateFilters({ promotion_scope: e.target.value as SaleListFilters['promotion_scope'] })}
              className="h-8 text-ui-caption rounded-lg border-slate-300 dark:border-slate-700 w-44"
            >
              {PROMOTION_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
          </div>

          {/* BOTÓN RESET SI HAY FILTROS ACTIVOS */}
          {hasActiveFilters && (
            <button
              type="button"
              onClick={resetAllFilters}
              className="ml-auto flex items-center gap-1 text-ui-caption font-bold text-rose-600 hover:text-rose-700 dark:text-rose-400 hover:underline cursor-pointer"
            >
              <X className="size-3" />
              <span>Limpiar filtros</span>
            </button>
          )}
        </div>
      </div>

      {/* TARJETAS TÁCTICAS DE KPIS (RESUMEN OPERATIVO) */}
      <div className={`grid gap-3 sm:gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-3`}>
        <InfoTile
          label="Venta Neta (período)"
          value={formatMoney(metrics.net_base_total)}
          subvalue={formatMoney(metrics.net_local_total, 'Bs ')}
          helper={
            metrics.refund_count > 0 || metrics.refund_base_total > 0
              ? `Devuelto: -${formatMoney(metrics.refund_base_total)} (${metrics.refund_count})`
              : 'Sin devoluciones deducidas'
          }
          icon={DollarSign}
          tone="success"
        />
        {canViewCosts && (
          <InfoTile
            label="Ganancia bruta"
            value={formatMoney(metrics.confirmed_profit_base_total)}
            subvalue={
              metrics.confirmed_profit_margin_percent !== null && metrics.confirmed_profit_margin_percent !== undefined
                ? `Margen: ${Number(metrics.confirmed_profit_margin_percent).toFixed(1)}%`
                : undefined
            }
            helper={
              metrics.confirmed_cost_base_total
                ? `Costo: ${formatMoney(metrics.confirmed_cost_base_total)}`
                : 'Antes de devoluciones'
            }
            icon={TrendingUp}
            tone="success"
          />
        )}
        <InfoTile
          label="Venta Bruta Confirmada"
          value={formatMoney(metrics.confirmed_base_total)}
          subvalue={formatMoney(metrics.confirmed_local_total, 'Bs ')}
          helper="Total sin deducciones"
          icon={FileText}
          tone="default"
        />
        <InfoTile
          label="Ventas Confirmadas"
          value={String(metrics.confirmed_count)}
          subvalue={`de ${metrics.total_count} registradas`}
          helper="Efectivas de caja"
          icon={Store}
          tone="info"
        />
        <InfoTile
          label="Borrador / Canceladas"
          value={`${metrics.draft_count} / ${metrics.cancelled_count}`}
          helper="No suman al total"
          icon={ShoppingBag}
          tone={metrics.cancelled_count > 0 ? 'warning' : 'default'}
        />
        <InfoTile
          label="Origen POS"
          value={String(metrics.pos_count)}
          subvalue={metrics.total_count > 0 ? `${Math.round((metrics.pos_count / metrics.total_count) * 100)}% del total` : undefined}
          helper="Facturadas en caja POS"
          icon={Store}
          tone="default"
        />
      </div>

      {isError ? (
        <EmptyState
          title="No se pudo cargar ventas"
          description="Revisa tu conexión o intenta actualizar el listado."
          action={<Button onClick={() => void refetch()}>Reintentar</Button>}
        />
      ) : isLoading && sales.length === 0 ? (
        <Skeleton className="h-64 w-full" />
      ) : sales.length === 0 ? (
        <EmptyState
          icon={<FileText className="size-8" aria-hidden="true" />}
          title={debouncedSearch ? 'Sin resultados' : 'Sin ventas'}
          description={
            debouncedSearch
              ? 'Ninguna venta coincide con el criterio de búsqueda.'
              : 'Cuando POS o ventas manuales generen documentos, aparecerán aquí para auditoría.'
          }
        />
      ) : (
        <div className={cn('rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden', isFetching ? 'opacity-75 transition-opacity' : '')}>
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead className="border-b border-slate-200 dark:border-slate-800 bg-slate-900 text-white dark:bg-slate-950 text-ui-caption font-bold uppercase tracking-wider">
                <tr>
                  <th className="w-9 px-3 py-3" />
                  <th className="px-3.5 py-3">Venta #</th>
                  <th className="px-3.5 py-3">Fecha de venta</th>
                  <th className="px-3.5 py-3">Cliente</th>
                  <th className="px-3.5 py-3">Estado</th>
                  <th className="px-3.5 py-3">Cobranza</th>
                  <th className="px-3.5 py-3">Origen</th>
                  <th className="px-3.5 py-3 text-right">Total USD</th>
                  <th className="px-3.5 py-3 text-right">Total VES</th>
                  {canViewCosts && (
                    <th className="px-3.5 py-3 text-right">Ganancia</th>
                  )}
                  <th className="px-3.5 py-3 text-center">Ítems</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60 text-ui-table">
                {sales.map((sale) => (
                  <SaleRow
                    key={sale.id}
                    sale={sale}
                    activeRate={activeRate}
                    expanded={expandedId === sale.id}
                    canCancel={canCancel}
                    canReverse={canReverse}
                    activeSession={activeSession}
                    canCreateReturn={canCreateReturn}
                    canCreateWarranty={canCreateWarranty}
                    canViewCosts={canViewCosts}
                    cancelling={cancelSale.isPending}
                    onToggle={() => setExpandedId((current) => (current === sale.id ? null : sale.id))}
                    onCancel={() => void handleCancel(sale.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {meta && (
            <div className="flex items-center justify-between border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 px-4 py-3 text-ui-caption text-slate-500 font-medium">
              <span>
                Página <strong className="text-slate-900 dark:text-white font-mono">{meta.current_page}</strong> de{' '}
                <strong className="text-slate-900 dark:text-white font-mono">{meta.last_page}</strong> ·{' '}
                <strong className="text-slate-900 dark:text-white font-mono">{meta.total}</strong> ventas registradas
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={meta.current_page <= 1}
                  leftIcon={<ChevronLeft className="size-4" />}
                  onClick={() => setFilters((f) => ({ ...f, page: Math.max(1, (f.page ?? 1) - 1) }))}
                  className="h-8 text-ui-caption font-semibold rounded-lg shadow-xs"
                >
                  Anterior
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={meta.current_page >= meta.last_page}
                  rightIcon={<ChevronRight className="size-4" />}
                  onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
                  className="h-8 text-ui-caption font-semibold rounded-lg shadow-xs"
                >
                  Siguiente
                </Button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

function SaleRow({
  sale,
  activeRate,
  expanded,
  canCancel,
  canReverse,
  activeSession,
  canCreateReturn,
  canCreateWarranty,
  canViewCosts = false,
  cancelling,
  onToggle,
  onCancel,
}: {
  sale: Sale;
  activeRate: CurrentExchangeRate | null;
  expanded: boolean;
  canCancel: boolean;
  canReverse: boolean;
  activeSession: CashRegisterSession | null;
  canCreateReturn: boolean;
  canCreateWarranty: boolean;
  canViewCosts?: boolean;
  cancelling: boolean;
  onToggle: () => void;
  onCancel: () => void;
}) {
  const date = sale.confirmed_at ?? sale.created_at;
  const localBalance = sale.receivable ? currentLocalBalance(sale.receivable, activeRate) : null;
  const returnLabel = returnStatusLabel(sale);
  const returnedProducts = getReturnedProducts(sale);
  return (
    <>
      <tr
        className={cn(
          'cursor-pointer border-b border-slate-200/80 dark:border-slate-800/80 transition-colors',
          expanded ? 'bg-blue-50/50 dark:bg-blue-950/20' : 'hover:bg-slate-50/80 dark:hover:bg-slate-800/40',
        )}
        onClick={onToggle}
      >
        <td className="px-3 py-3 text-slate-400">
          <div className={cn(
            'size-6 rounded-full flex items-center justify-center transition-colors',
            expanded ? 'bg-blue-600 text-white' : 'hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500',
          )}>
            <ChevronDown className={cn('size-3.5 transition-transform duration-200', expanded ? '' : '-rotate-90')} />
          </div>
        </td>
        <td className="px-3.5 py-3">
          <span className="font-mono font-bold text-ui-caption px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-900">
            #{sale.id}
          </span>
        </td>
        <td className="px-3.5 py-3 text-ui-caption text-slate-600 dark:text-slate-300 font-mono">
          <div className="font-semibold text-slate-800 dark:text-slate-200">{formatDate(date).split(',')[0]}</div>
          <div className="text-ui-caption text-slate-400 mt-0.5">{formatDate(date).split(',')[1] || ''}</div>
        </td>
        <td className="px-3.5 py-3">
          <div className="font-bold text-slate-900 dark:text-white text-ui-table">
            {sale.customer?.name ?? 'Consumidor Final'}
          </div>
          {sale.customer?.document_number && (
            <div className="text-ui-caption font-mono text-slate-500 dark:text-slate-400 mt-0.5">
              Doc: {sale.customer.document_number}
            </div>
          )}
        </td>
        <td className="px-3.5 py-3">
          <div className="flex flex-col gap-1 items-start">
            <Badge variant={statusVariant(sale.status)} className="font-bold text-ui-caption shadow-2xs">
              {SALE_STATUS_LABELS[sale.status]}
            </Badge>
            {returnLabel && (
              <Badge variant={returnLabel === 'Devuelta total' ? 'success' : returnLabel === 'Devolución rechazada' ? 'danger' : 'warning'}>
                {returnLabel}
              </Badge>
            )}
            {returnedProducts.length > 0 && (
              <div className="mt-1 flex flex-col gap-1 max-w-[240px]">
                <div className="flex items-center gap-1 text-ui-caption font-semibold text-amber-800 dark:text-amber-300">
                  <RotateCcw className="size-3 shrink-0" />
                  <span>Devuelto:</span>
                </div>
                {returnedProducts.map((p) => (
                  <div
                    key={p.saleItemId || p.productId}
                    className="truncate rounded bg-amber-500/10 px-1.5 py-0.5 text-ui-caption font-medium text-amber-900 dark:text-amber-200 border border-amber-500/20"
                    title={`${p.productName}${p.productSku ? ` (${p.productSku})` : ''} - ${p.returnedQuantity} de ${p.soldQuantity} und devueltas${p.reasons.length > 0 ? ` · Motivo: ${p.reasons.join(', ')}` : ''}`}
                  >
                    <span className="font-semibold">{p.productName}</span>: <strong className="font-mono text-amber-800 dark:text-amber-300">{p.returnedQuantity}</strong>{p.soldQuantity > p.returnedQuantity ? <span className="opacity-75">/{p.soldQuantity}</span> : ''} und
                  </div>
                ))}
              </div>
            )}
          </div>
        </td>
        <td className="px-3.5 py-3">
          <div className="flex flex-col gap-1 items-start">
            <Badge variant={receivableVariant(sale.receivable?.status)} className="font-semibold text-ui-caption">
              {receivableStatusLabel(sale)}
            </Badge>
            {sale.receivable && sale.receivable.balance_base_amount > 0 && (
              <span className="text-ui-caption font-mono font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 px-1.5 py-0.5 rounded border border-amber-200/80 dark:border-amber-900/60 mt-0.5">
                Saldo {formatMoney(sale.receivable.balance_base_amount)}
                {localBalance !== null && ` · ${formatMoney(localBalance, 'Bs ')}`}
              </span>
            )}
          </div>
        </td>
        <td className="px-3.5 py-3 text-ui-caption">
          <div className="flex flex-col gap-1 items-start">
            <span className={cn(
              'px-2 py-0.5 rounded font-bold text-ui-caption font-mono uppercase',
              sale.pos_order
                ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300'
                : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
            )}>
              {sale.pos_order ? 'POS' : 'Manual'}
            </span>
            {promotionApplicationsLabel(sale).map((label) => (
              <Badge key={label} variant="info" className="text-ui-caption">{label}</Badge>
            ))}
          </div>
        </td>
        <td className="px-3.5 py-3 text-right font-mono font-black text-slate-900 dark:text-white text-base tabular-nums">
          {formatMoney(sale.total_base_amount)}
        </td>
        <td className="px-3.5 py-3 text-right font-mono font-bold text-emerald-700 dark:text-emerald-400 text-ui-caption tabular-nums">
          {formatMoney(sale.total_local_amount, 'Bs ')}
        </td>
        {canViewCosts && (
          <td className="px-3.5 py-3 text-right font-mono tabular-nums">
            {sale.profit_base_amount !== null && sale.profit_base_amount !== undefined ? (
              <div className="flex flex-col items-end">
                <span className={`font-black text-ui-caption ${Number(sale.profit_base_amount) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                  {Number(sale.profit_base_amount) >= 0 ? '+' : ''}{formatMoney(sale.profit_base_amount)}
                </span>
                <span className="text-ui-caption text-slate-400 font-semibold mt-0.5">
                  {Number(sale.profit_margin_percent ?? 0).toFixed(1)}%
                </span>
              </div>
            ) : (
              <span className="text-slate-400 text-ui-caption">-</span>
            )}
          </td>
        )}
        <td className="px-3.5 py-3 text-center tabular-nums">
          <span className="inline-flex items-center justify-center font-mono font-bold text-ui-caption bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-md text-slate-700 dark:text-slate-300">
            {sale.items_count ?? sale.items?.length ?? '-'}
          </span>
        </td>
      </tr>
      {expanded && (
        <tr className="bg-slate-50/70 dark:bg-slate-950/40 border-b border-slate-200 dark:border-slate-800">
          <td colSpan={canViewCosts ? 11 : 10} className="p-4 sm:p-5">
            <SaleDetail
              saleId={sale.id}
              sale={sale}
              canCancel={canCancel}
              canReverse={canReverse}
              activeSession={activeSession}
              canCreateReturn={canCreateReturn}
              canCreateWarranty={canCreateWarranty}
              canViewCosts={canViewCosts}
              cancelling={cancelling}
              onCancel={onCancel}
            />
          </td>
        </tr>
      )}
    </>
  );
}

function SaleDetail({
  saleId,
  sale,
  canCancel,
  canReverse,
  activeSession,
  canCreateReturn,
  canCreateWarranty,
  canViewCosts = false,
  cancelling,
  onCancel,
}: {
  saleId: number;
  sale: Sale;
  canCancel: boolean;
  canReverse: boolean;
  activeSession: CashRegisterSession | null;
  canCreateReturn: boolean;
  canCreateWarranty: boolean;
  canViewCosts?: boolean;
  cancelling: boolean;
  onCancel: () => void;
}) {
  const { data: detail, isLoading } = useSale(saleId);
  const createReturn = useCreateSalesReturn();
  const createWarranty = useCreateWarrantyClaim();
  const [showReturnForm, setShowReturnForm] = useState(false);
  const [warrantyItem, setWarrantyItem] = useState<SaleItem | null>(null);
  const [returnReason, setReturnReason] = useState('');
  const [returnLines, setReturnLines] = useState<Record<number, { quantity: number; condition: 'sellable' | 'damaged'; reason: string; unitIds: number[] }>>({});
  const current = detail ?? sale;
  const items = current.items ?? [];
  const returnLabel = returnStatusLabel(current);
  const returnedProducts = getReturnedProducts(current);
  const canReturnCurrentSale = canCreateReturn && current.status === 'confirmed' && hasReturnableItems(current);
  const canReverseCurrentSale = canReverse && current.status === 'confirmed' && current.pos_order?.status === 'paid';
  const [showReverseForm, setShowReverseForm] = useState(false);

  if (isLoading && !detail) return <Skeleton className="h-36 w-full" />;

  const posPayments = current.pos_order?.payments ?? [];
  const receivable = current.receivable;

  return (
    <div className="space-y-3" onClick={(event) => event.stopPropagation()}>
      {/* TIRA COMPACTA DE PAGO / COBRO ESTILO RECIBO POS (SOLO SI TIENE INFORMACIÓN) */}
      {(posPayments.length > 0 || (receivable && receivable.balance_base_amount > 0) || current.pos_order?.cashier_name) && (
        <div className="flex flex-wrap items-center justify-between gap-2.5 px-3.5 py-2 rounded-xl border border-slate-200/90 dark:border-slate-800 bg-slate-100/70 dark:bg-slate-900/90 text-ui-caption">
          <div className="flex flex-wrap items-center gap-3">
            {current.pos_order?.cashier_name && (
              <div className="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                <span className="font-semibold text-slate-400">Caja:</span>
                <span className="font-bold text-slate-800 dark:text-slate-200">
                  {current.pos_order.cash_register_session?.cash_register_name ?? 'POS'}
                </span>
                <span className="text-slate-400">·</span>
                <span className="font-medium text-slate-700 dark:text-slate-300">
                  {current.pos_order.cashier_name}
                </span>
              </div>
            )}

            {posPayments.length > 0 && (
              <div className="flex flex-wrap items-center gap-1.5">
                <span className="font-semibold text-slate-400">Pagos:</span>
                {posPayments.map((p) => (
                  <span
                    key={p.id}
                    className="inline-flex items-center gap-1 font-mono px-2 py-0.5 rounded-md bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-2xs text-ui-caption"
                  >
                    <span className="font-sans font-bold text-slate-700 dark:text-slate-300">
                      {p.payment_method_name ?? p.method}:
                    </span>
                    <strong className="font-bold text-emerald-600 dark:text-emerald-400">
                      {formatMoney(p.amount, p.currency === 'VES' ? 'Bs ' : '$')}
                    </strong>
                    {p.reference && (
                      <span className="text-slate-400 text-ui-caption">({p.reference})</span>
                    )}
                  </span>
                ))}
              </div>
            )}
          </div>

          {receivable && receivable.balance_base_amount > 0 && (
            <div className="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 text-amber-900 dark:text-amber-200">
              <span className="font-bold text-ui-caption uppercase tracking-wide">Crédito Pendiente:</span>
              <strong className="font-mono font-black text-ui-caption">
                {formatMoney(receivable.balance_base_amount)}
              </strong>
              {receivable.due_date && (
                <span className="text-ui-caption text-amber-700 dark:text-amber-300">
                  (Vence: {receivable.due_date})
                </span>
              )}
            </div>
          )}
        </div>
      )}

      {current.promotion_applications && current.promotion_applications.length > 0 && (
        <div className="rounded-xl border border-blue-200/80 bg-blue-50/50 dark:border-blue-900/60 dark:bg-blue-950/20 p-3.5">
          <p className="mb-2 text-ui-caption font-bold uppercase tracking-wider text-blue-900 dark:text-blue-300">
            Promociones aplicadas
          </p>
          <div className="flex flex-wrap gap-2">
            {current.promotion_applications.map((application) => (
              <Badge key={application.id ?? `${application.scope}-${application.promotion_name}`} variant="info" className="text-ui-caption font-semibold">
                {promotionScopeLabel(application.scope)}: {application.promotion_name}
              </Badge>
            ))}
          </div>
        </div>
      )}

      {returnedProducts.length > 0 && (
        <div className="rounded-xl border border-amber-300/80 bg-amber-50/80 p-3.5 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
          <div className="flex items-center gap-2 font-semibold text-ui-table">
            <RotateCcw className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <span>Productos con devolución en esta venta:</span>
          </div>
          <div className="mt-2.5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {returnedProducts.map((p) => (
              <div
                key={p.saleItemId || p.productId}
                className="flex flex-col justify-between rounded-lg border border-amber-200 bg-white/95 p-2.5 text-ui-caption shadow-xs dark:border-amber-800 dark:bg-slate-900/70"
              >
                <div>
                  <div className="font-semibold text-text-primary text-ui-caption">{p.productName}</div>
                  {p.productSku && <div className="text-ui-caption text-text-muted">SKU: {p.productSku}</div>}
                </div>
                <div className="mt-2 pt-1.5 border-t border-amber-100 dark:border-amber-900/40 space-y-0.5">
                  <div className="flex items-center justify-between font-medium">
                    <span className="text-text-muted">Cantidad devuelta:</span>
                    <span className="font-mono font-bold text-amber-700 dark:text-amber-300">
                      {p.returnedQuantity} {p.soldQuantity > p.returnedQuantity ? `de ${p.soldQuantity}` : ''} und.
                    </span>
                  </div>
                  {p.soldQuantity > p.returnedQuantity && (
                    <div className="flex items-center justify-between text-ui-caption text-text-muted">
                      <span>Restante activa:</span>
                      <span className="font-mono font-semibold">{p.soldQuantity - p.returnedQuantity} und.</span>
                    </div>
                  )}
                  {p.reasons.length > 0 && (
                    <div className="mt-1 text-ui-caption text-text-muted italic truncate" title={p.reasons.join(', ')}>
                      Motivo: {p.reasons.join(', ')}
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* TABLA DE RENGLONES VENDIDOS */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs">
        <table className="w-full text-left border-collapse">
          <thead className="border-b border-slate-200 dark:border-slate-800 bg-slate-900 text-white dark:bg-slate-950 text-ui-caption font-bold uppercase tracking-wider">
            <tr>
              <th className="px-3.5 py-2.5">Producto</th>
              <th className="px-3.5 py-2.5">Almacén</th>
              <th className="px-3.5 py-2.5 text-right">Cant.</th>
              <th className="px-3.5 py-2.5 text-right">Precio Unit.</th>
              {canViewCosts && (
                <th className="px-3.5 py-2.5 text-right">Costo Unit.</th>
              )}
              <th className="px-3.5 py-2.5 text-right">Desc.</th>
              <th className="px-3.5 py-2.5 text-right">Total</th>
              {canViewCosts && (
                <th className="px-3.5 py-2.5 text-right">Ganancia</th>
              )}
              <th className="px-3.5 py-2.5">Tasa / Seriales</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60 text-ui-table">
            {items.length === 0 ? (
              <tr>
                <td colSpan={canViewCosts ? 9 : 7} className="px-4 py-6 text-center text-slate-500 font-medium">
                  Sin ítems cargados en el detalle.
                </td>
              </tr>
            ) : (
              items.map((item) => (
                <SaleItemRow
                  key={item.id}
                  item={item}
                  returnedQuantity={returnedQuantityForItem(current, item.id)}
                  canViewCosts={canViewCosts}
                  canCreateWarranty={canCreateWarranty && current.status === 'confirmed'}
                  onCreateWarranty={() => setWarrantyItem(item)}
                />
              ))
            )}
          </tbody>
        </table>
      </div>

      {warrantyItem && (
        <WarrantyClaimForm
          sale={current}
          item={warrantyItem}
          loading={createWarranty.isPending}
          onCancel={() => setWarrantyItem(null)}
          onSubmit={async (payload) => {
            await createWarranty.mutateAsync(payload);
            toast.success('Caso de garantía creado.');
            setWarrantyItem(null);
          }}
        />
      )}

      {showReturnForm && (
        <SalesReturnForm
          sale={current}
          reason={returnReason}
          lines={returnLines}
          loading={createReturn.isPending}
          onReason={setReturnReason}
          onLines={setReturnLines}
          onCancel={() => setShowReturnForm(false)}
          onSubmit={async (payload) => {
            await createReturn.mutateAsync(payload);
            toast.success('Devolución registrada.');
            setShowReturnForm(false);
            setReturnReason('');
            setReturnLines({});
          }}
        />
      )}

      {canReverseCurrentSale && (
        <ReverseSaleDialog
          sale={current}
          activeSession={activeSession}
          open={showReverseForm}
          onOpenChange={setShowReverseForm}
        />
      )}

      {/* BARRA INFERIOR DE TOTALES Y ACCIONES RÁPIDAS */}
      <div className="flex flex-wrap items-center justify-between gap-4 p-4 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs">
        <div className="flex flex-wrap items-baseline gap-3 text-ui-table">
          <span className="text-slate-500 text-ui-caption font-bold uppercase tracking-wider">Total Factura:</span>
          <span className="font-mono font-black text-slate-900 dark:text-white text-xl">
            {formatMoney(current.total_base_amount)}
          </span>
          <span className="font-mono font-bold text-emerald-700 dark:text-emerald-400 text-ui-table">
            ({formatMoney(current.total_local_amount, 'Bs ')})
          </span>

          {canViewCosts && current.profit_base_amount !== null && current.profit_base_amount !== undefined && (
            <div className="flex items-center gap-2 border-l border-slate-200 dark:border-slate-800 pl-3 ml-1 text-ui-caption">
              <span className="text-slate-400">Costo: <strong className="font-mono text-slate-600 dark:text-slate-300">{formatMoney(current.cost_base_amount)}</strong></span>
              <span className="text-slate-400">·</span>
              <span className="text-slate-400">Ganancia: <strong className={cn('font-mono font-bold', Number(current.profit_base_amount) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400')}>
                {Number(current.profit_base_amount) >= 0 ? '+' : ''}{formatMoney(current.profit_base_amount)}
              </strong></span>
              <span className="text-ui-caption font-mono font-semibold text-slate-400">
                ({Number(current.profit_margin_percent ?? 0).toFixed(1)}%)
              </span>
            </div>
          )}

          {returnLabel && (
            <Badge variant={returnLabel === 'Devuelta total' ? 'success' : returnLabel === 'Devolución rechazada' ? 'danger' : 'warning'} className="ml-2">
              {returnLabel}
            </Badge>
          )}
        </div>

        <div className="flex flex-wrap gap-2">
          {canReverseCurrentSale && (
            <Button
              variant="danger"
              size="sm"
              leftIcon={<XCircle className="size-4" />}
              onClick={() => setShowReverseForm(true)}
              className="font-semibold text-ui-caption rounded-lg"
            >
              Anular
            </Button>
          )}
          {canReturnCurrentSale && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<RotateCcw className="size-4" />}
              onClick={() => setShowReturnForm((value) => !value)}
              className="font-semibold text-ui-caption rounded-lg"
            >
              Devolver
            </Button>
          )}
          {canCreateReturn && current.status === 'confirmed' && items.length > 0 && !hasReturnableItems(current) && (
            <Badge variant="success" className="text-ui-caption">Sin saldo por devolver</Badge>
          )}
          {canCancel && current.status === 'draft' && (
            <Button
              variant="danger"
              size="sm"
              loading={cancelling}
              leftIcon={<XCircle className="size-4" />}
              onClick={onCancel}
              className="font-semibold text-ui-caption rounded-lg"
            >
              Cancelar borrador
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}


function SalesReturnForm({
  sale,
  reason,
  lines,
  loading,
  onReason,
  onLines,
  onCancel,
  onSubmit,
}: {
  sale: Sale;
  reason: string;
  lines: Record<number, { quantity: number; condition: 'sellable' | 'damaged'; reason: string; unitIds: number[] }>;
  loading: boolean;
  onReason: (value: string) => void;
  onLines: (value: Record<number, { quantity: number; condition: 'sellable' | 'damaged'; reason: string; unitIds: number[] }>) => void;
  onCancel: () => void;
  onSubmit: (payload: SalesReturnPayload) => Promise<void>;
}) {
  const items = sale.items ?? [];

  function patchLine(item: SaleItem, patch: Partial<{ quantity: number; condition: 'sellable' | 'damaged'; reason: string; unitIds: number[] }>) {
    const current = lines[item.id] ?? { quantity: 0, condition: 'sellable' as const, reason: '', unitIds: [] };
    onLines({ ...lines, [item.id]: { ...current, ...patch } });
  }

  async function submit() {
    const selected = items.flatMap((item) => {
      const line = lines[item.id];
      if (!line || line.quantity <= 0) return [];
      const remaining = returnableQuantityForItem(sale, item);
      const returnedUnitIds = returnedUnitIdsForItem(sale, item.id);
      const serialCount = (item.serial_units ?? [])
        .filter((unit) => Number.isFinite(Number(unit.id)) && !returnedUnitIds.has(Number(unit.id))).length;
      return [{
        sale_item_id: item.id,
        quantity: Math.min(line.quantity, remaining),
        condition: line.condition,
        reason: line.reason || null,
        product_unit_ids: serialCount > 0 ? line.unitIds : undefined,
      }];
    });

    if (selected.length === 0) {
      toast.error('Selecciona al menos un item para devolver.');
      return;
    }

    const invalidSerial = selected.some((line) => {
      const item = items.find((candidate) => candidate.id === line.sale_item_id);
      return (item?.serial_units?.length ?? 0) > 0 && (line.product_unit_ids?.length ?? 0) !== line.quantity;
    });

    if (invalidSerial) {
      toast.error('Los productos serializados requieren seleccionar un IMEI/serial por unidad devuelta.');
      return;
    }

    await onSubmit({ sale_id: sale.id, reason: reason || null, items: selected });
  }

  return (
    <section className="rounded border border-border bg-surface p-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="font-semibold">Registrar devolución</h3>
        <Button size="sm" variant="ghost" onClick={onCancel}>Cerrar</Button>
      </div>
      <div className="mt-3 space-y-3">
        <div className="space-y-1">
          <Label>Motivo general</Label>
          <Input value={reason} onChange={(e) => onReason(e.target.value)} placeholder="Ej. cliente devuelve por cambio" />
        </div>
        <div className="overflow-x-auto rounded border border-border">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left text-ui-caption uppercase text-text-muted">
              <tr>
                <th className="px-3 py-2">Producto</th>
                <th className="px-3 py-2 text-right">Vendida</th>
                <th className="px-3 py-2">Devolver</th>
                <th className="px-3 py-2">Condición</th>
                <th className="px-3 py-2">Seriales</th>
                <th className="px-3 py-2">Motivo línea</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => {
                const line = lines[item.id] ?? { quantity: 0, condition: 'sellable' as const, reason: '', unitIds: [] };
                const returned = returnedQuantityForItem(sale, item.id);
                const remaining = returnableQuantityForItem(sale, item);
                const returnedUnitIds = returnedUnitIdsForItem(sale, item.id);
                const serialUnits = (item.serial_units ?? [])
                  .filter((unit) => Number.isFinite(Number(unit.id)) && !returnedUnitIds.has(Number(unit.id)));
                return (
                  <tr key={item.id} className="border-b border-border last:border-0">
                    <td className="px-3 py-2">
                      <div className="font-medium">{item.product_name ?? `Producto #${item.product_id}`}</div>
                      <div className="text-ui-caption text-text-muted">
                        {item.product_sku ?? '-'} · Devuelto {returned} · Disponible {remaining}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-right">{item.quantity}</td>
                    <td className="px-3 py-2">
                      <Input
                        type="number"
                        min="0"
                        max={remaining}
                        value={line.quantity}
                        disabled={remaining <= 0}
                        onChange={(e) => patchLine(item, { quantity: Math.min(Number(e.target.value || 0), remaining) })}
                        className="w-24"
                      />
                    </td>
                    <td className="px-3 py-2">
                      <Select value={line.condition} onChange={(e) => patchLine(item, { condition: e.target.value as 'sellable' | 'damaged' })}>
                        <option value="sellable">Vendible</option>
                        <option value="damaged">Dañado</option>
                      </Select>
                    </td>
                    <td className="px-3 py-2">
                      {serialUnits.length === 0 ? (
                        <span className="text-ui-caption text-text-muted">{remaining <= 0 ? 'Ya devuelto' : 'No serializado'}</span>
                      ) : (
                        <div className="max-h-24 space-y-1 overflow-auto">
                          {serialUnits.map((unit) => {
                            const unitId = Number(unit.id);
                            return (
                              <label key={unitId} className="flex items-center gap-2 text-ui-caption">
                                <input
                                  type="checkbox"
                                  checked={line.unitIds.includes(unitId)}
                                  onChange={(e) => {
                                    const next = e.target.checked
                                      ? [...line.unitIds, unitId]
                                      : line.unitIds.filter((id) => id !== unitId);
                                    patchLine(item, { unitIds: next, quantity: next.length });
                                  }}
                                />
                                {unit.serial_number}
                              </label>
                            );
                          })}
                        </div>
                      )}
                    </td>
                    <td className="px-3 py-2">
                      <Input value={line.reason} onChange={(e) => patchLine(item, { reason: e.target.value })} placeholder="Opcional" />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onCancel}>Cancelar</Button>
          <Button loading={loading} onClick={() => void submit()}>
            Registrar devolución
          </Button>
        </div>
      </div>
    </section>
  );
}

function SaleItemRow({
  item,
  returnedQuantity = 0,
  canViewCosts = false,
  canCreateWarranty = false,
  onCreateWarranty,
}: {
  item: SaleItem;
  returnedQuantity?: number;
  canViewCosts?: boolean;
  canCreateWarranty?: boolean;
  onCreateWarranty?: () => void;
}) {
  const serials = item.serial_units?.map((serial) => serial.serial_number).filter(Boolean).join(', ');
  const rate = item.exchange_rate_type_code
    ? `${item.exchange_rate_type_code} @ ${formatMoney(item.exchange_rate, '')}`
    : '-';
  const warrantyValid = isWarrantyValid(item);
  return (
    <tr className="border-b border-slate-100 dark:border-slate-800/60 hover:bg-slate-50/70 dark:hover:bg-slate-850/50 transition-colors">
      <td className="px-3.5 py-2.5">
        <div className="font-bold text-slate-900 dark:text-white text-ui-table">
          {item.product_name ?? `Producto #${item.product_id}`}
        </div>
        <div className="text-ui-caption font-mono text-slate-500 mt-0.5">
          {item.product_sku ?? 'Sin SKU'}
        </div>
        {returnedQuantity > 0 && (
          <div className="mt-1">
            <span className="inline-flex items-center gap-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-ui-caption font-semibold text-amber-800 dark:text-amber-300 border border-amber-500/30">
              <RotateCcw className="size-2.5 shrink-0" />
              Devuelto: {returnedQuantity} {returnedQuantity < Number(item.quantity) ? `de ${item.quantity}` : ''} und.
            </span>
          </div>
        )}
      </td>
      <td className="px-3.5 py-2.5 text-ui-caption text-slate-600 dark:text-slate-400 font-medium">
        {item.warehouse_name ?? `#${item.warehouse_id}`}
      </td>
      <td className="px-3.5 py-2.5 text-right tabular-nums">
        <div className="font-mono font-bold text-slate-900 dark:text-white text-ui-table">{item.quantity}</div>
        {returnedQuantity > 0 && (
          <div className="text-ui-caption font-semibold text-amber-600 dark:text-amber-400">
            -{returnedQuantity} dev.
            {Number(item.quantity) > returnedQuantity && (
              <span className="text-slate-400 block font-normal text-ui-caption">({Number(item.quantity) - returnedQuantity} rest.)</span>
            )}
          </div>
        )}
      </td>
      <td className="px-3.5 py-2.5 text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-ui-caption tabular-nums">
        {formatMoney(item.unit_price, item.sale_currency === 'VES' ? 'Bs ' : '$')}
      </td>
      {canViewCosts && (
        <td className="px-3.5 py-2.5 text-right font-mono text-ui-caption tabular-nums text-slate-500">
          {item.base_unit_cost !== null && item.base_unit_cost !== undefined ? formatMoney(item.base_unit_cost) : '-'}
        </td>
      )}
      <td className="px-3.5 py-2.5 text-right font-mono text-ui-caption tabular-nums text-slate-600 dark:text-slate-400">
        {Number(item.discount_amount) > 0 ? (
          <span className="text-amber-600 font-semibold">-{formatMoney(item.discount_amount)}</span>
        ) : (
          '-'
        )}
      </td>
      <td className="px-3.5 py-2.5 text-right font-mono font-black text-slate-900 dark:text-white text-ui-table tabular-nums">
        {formatMoney(item.total_base_amount)}
      </td>
      {canViewCosts && (
        <td className="px-3.5 py-2.5 text-right font-mono tabular-nums">
          {item.profit_base_amount !== null && item.profit_base_amount !== undefined ? (
            <div className="flex flex-col items-end">
              <span className={`font-bold text-ui-caption ${Number(item.profit_base_amount) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                {Number(item.profit_base_amount) >= 0 ? '+' : ''}{formatMoney(item.profit_base_amount)}
              </span>
              <span className="text-ui-caption text-slate-400 font-semibold mt-0.5">
                {Number(item.profit_margin_percent ?? 0).toFixed(1)}%
              </span>
            </div>
          ) : (
            <span className="text-slate-400 text-ui-caption">-</span>
          )}
        </td>
      )}
      <td className="px-3.5 py-2.5 text-ui-caption text-slate-500">
        <div className="font-mono text-ui-caption">{rate}</div>
        {serials && <div className="font-mono text-ui-caption text-slate-600 mt-0.5">Seriales: {serials}</div>}
        <div className="mt-1 flex flex-wrap items-center gap-1.5">
          <Badge variant={warrantyValid ? 'success' : item.warranty_policy_id ? 'danger' : 'default'} className="text-ui-caption">
            {warrantyStatusLabel(item)}
          </Badge>
          {canCreateWarranty && warrantyValid && (
            <Button size="sm" variant="outline" leftIcon={<ShieldCheck className="size-3.5" />} onClick={onCreateWarranty} className="h-6 text-ui-caption px-2 rounded-md">
              Garantía
            </Button>
          )}
        </div>
      </td>
    </tr>
  );
}

function WarrantyClaimForm({
  sale,
  item,
  loading,
  onCancel,
  onSubmit,
}: {
  sale: Sale;
  item: SaleItem;
  loading: boolean;
  onCancel: () => void;
  onSubmit: (payload: WarrantyClaimPayload) => Promise<void>;
}) {
  const serialUnits = item.serial_units ?? [];
  const [productUnitId, setProductUnitId] = useState<number | null>(serialUnits[0]?.id ? Number(serialUnits[0].id) : null);
  const [quantity, setQuantity] = useState(serialUnits.length > 0 ? 1 : 1);
  const [customerName, setCustomerName] = useState(sale.customer?.name ?? '');
  const [customerPhone, setCustomerPhone] = useState(sale.customer?.phone ?? '');
  const [issue, setIssue] = useState('');
  const [notes, setNotes] = useState('');
  const maxQuantity = Math.max(1, Number(item.quantity ?? 1));

  async function submit() {
    if (!issue.trim()) {
      toast.error('Describe la falla reportada por el cliente.');
      return;
    }
    if (serialUnits.length > 0 && !productUnitId) {
      toast.error('Selecciona el IMEI o serial recibido.');
      return;
    }

    await onSubmit({
      sale_item_id: item.id,
      product_unit_id: serialUnits.length > 0 ? productUnitId : null,
      quantity: serialUnits.length > 0 ? 1 : Math.min(Math.max(quantity, 1), maxQuantity),
      customer_name: customerName || null,
      customer_phone: customerPhone || null,
      issue_description: issue,
      received_notes: notes || null,
    });
  }

  return (
    <section className="rounded border border-border bg-surface p-3">
      <div className="flex items-center justify-between gap-2">
        <div>
          <h3 className="font-semibold">Crear caso de garantía</h3>
          <p className="text-ui-table text-text-muted">
            {item.product_name ?? `Producto #${item.product_id}`} · {warrantyStatusLabel(item)}
          </p>
        </div>
        <Button size="sm" variant="ghost" onClick={onCancel}>Cerrar</Button>
      </div>
      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <div className="space-y-1">
          <Label>Cliente</Label>
          <Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Nombre del cliente" />
        </div>
        <div className="space-y-1">
          <Label>Teléfono</Label>
          <Input value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} placeholder="Teléfono de contacto" />
        </div>
        {serialUnits.length > 0 ? (
          <div className="space-y-1">
            <Label>IMEI / serial recibido</Label>
            <Select value={productUnitId ?? ''} onChange={(e) => setProductUnitId(Number(e.target.value))}>
              {serialUnits.map((unit) => (
                <option key={unit.id} value={unit.id}>{unit.serial_number}</option>
              ))}
            </Select>
          </div>
        ) : (
          <div className="space-y-1">
            <Label>Cantidad</Label>
            <Input
              type="number"
              min="1"
              max={maxQuantity}
              value={quantity}
              onChange={(e) => setQuantity(Math.min(Math.max(Number(e.target.value || 1), 1), maxQuantity))}
            />
          </div>
        )}
        <div className="space-y-1 md:col-span-2">
          <Label>Falla reportada</Label>
          <Input value={issue} onChange={(e) => setIssue(e.target.value)} placeholder="Ej. no enciende, falla de carga, pantalla intermitente" />
        </div>
        <div className="space-y-1 md:col-span-2">
          <Label>Notas de recepción</Label>
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Estado físico, accesorios recibidos, observaciones" />
        </div>
      </div>
      <div className="mt-3 flex justify-end gap-2">
        <Button variant="outline" onClick={onCancel}>Cancelar</Button>
        <Button loading={loading} leftIcon={<ShieldCheck className="size-4" />} onClick={() => void submit()}>
          Crear garantía
        </Button>
      </div>
    </section>
  );
}

function InfoTile({
  label,
  value,
  subvalue,
  helper,
  icon: Icon,
  tone = 'default',
}: {
  label: string;
  value: string;
  subvalue?: string;
  helper?: string;
  icon?: React.ComponentType<{ className?: string }>;
  tone?: 'default' | 'success' | 'warning' | 'danger' | 'info';
}) {
  const toneClasses = {
    default: 'text-slate-900 dark:text-white',
    success: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-rose-600 dark:text-rose-400',
    info: 'text-blue-600 dark:text-blue-400',
  }[tone];

  const toneBorder = {
    default: 'border-slate-200/90 dark:border-slate-800',
    success: 'border-emerald-200/80 dark:border-emerald-900/50 bg-emerald-50/20 dark:bg-emerald-950/10',
    warning: 'border-amber-200/80 dark:border-amber-900/50 bg-amber-50/20 dark:bg-amber-950/10',
    danger: 'border-rose-200/80 dark:border-rose-900/50 bg-rose-50/20 dark:bg-rose-950/10',
    info: 'border-blue-200/80 dark:border-blue-900/50 bg-blue-50/20 dark:bg-blue-950/10',
  }[tone];

  return (
    <div
      className={cn(
        'rounded-2xl border bg-white dark:bg-slate-900 p-4 sm:p-4.5 shadow-xs transition-all hover:shadow-md flex flex-col justify-between',
        toneBorder,
      )}
    >
      <div>
        <div className="flex items-center justify-between gap-2">
          <span className="text-ui-caption font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            {label}
          </span>
          {Icon && <Icon className={cn('size-4 shrink-0', toneClasses)} />}
        </div>
        <div className={`mt-2 text-xl sm:text-2xl font-black font-mono tabular-nums leading-none ${toneClasses}`}>
          {value}
        </div>
        {subvalue && (
          <div className="mt-1 text-ui-caption font-semibold font-mono tabular-nums text-slate-500 dark:text-slate-400">
            {subvalue}
          </div>
        )}
      </div>
      {helper && (
        <div className="mt-2.5 text-ui-caption font-medium text-slate-400 dark:text-slate-500 border-t border-slate-100 dark:border-slate-800/80 pt-1.5 truncate">
          {helper}
        </div>
      )}
    </div>
  );
}
