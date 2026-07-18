import { useState } from 'react';
import { ChevronDown, ChevronLeft, ChevronRight, FileText, RotateCcw, Search, ShieldCheck, XCircle } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useCurrentExchangeRatesForPos, type CurrentExchangeRate } from '@/features/pos/api';
import { activeUsdVesRate, currentLocalBalance } from '@/features/receivables/currentBalance';
import { useCreateSalesReturn, type SalesReturnPayload } from '@/features/sales-returns/api';
import { useCreateWarrantyClaim, type WarrantyClaimPayload } from '@/features/warranties/api';
import { useCancelSale, useSale, useSales, type SaleListFilters } from './api';
import { SALE_STATUS_LABELS, type Sale, type SaleItem, type SaleStatus } from './schemas';

const RECEIVABLE_STATUS_LABELS: Record<string, string> = {
  pending: 'Pendiente',
  partial: 'Parcial',
  paid: 'Pagada',
  overdue: 'Vencida',
};

const STATUS_OPTIONS: { value: SaleListFilters['status']; label: string }[] = [
  { value: 'all', label: 'Todos' },
  { value: 'draft', label: 'Borradores' },
  { value: 'confirmed', label: 'Confirmadas' },
  { value: 'cancelled', label: 'Canceladas' },
];

function statusVariant(status: SaleStatus): 'default' | 'success' | 'danger' | 'warning' {
  if (status === 'confirmed') return 'success';
  if (status === 'cancelled') return 'danger';
  return 'warning';
}

function formatMoney(value: number | null | undefined, currency = '$'): string {
  const n = Number(value ?? 0);
  return `${currency}${n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';
  return date.toLocaleString('es-VE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function customerLabel(sale: Sale): string {
  if (!sale.customer) return 'Consumidor Final';
  const doc = sale.customer.document_number ? ` - ${sale.customer.document_number}` : '';
  return `${sale.customer.name}${doc}`;
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

export function SalesManager() {
  const [filters, setFilters] = useState<SaleListFilters>({
    search: '',
    status: 'all',
    page: 1,
    per_page: 25,
  });
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const { data, isLoading, isError, refetch } = useSales(filters);
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const activeRate = activeUsdVesRate(rates);
  const canCancel = useCan(PERMISSIONS.SALES_CANCEL);
  const canCreateReturn = useCan(PERMISSIONS.SALES_RETURNS_CREATE);
  const canCreateWarranty = useCan(PERMISSIONS.WARRANTIES_CREATE);
  const cancelSale = useCancelSale();
  const sales = data?.data ?? [];
  const meta = data?.meta;
  const pageTotals = sales.reduce(
    (acc, sale) => {
      acc.base += sale.total_base_amount;
      acc.local += sale.total_local_amount;
      acc.confirmed += sale.status === 'confirmed' ? 1 : 0;
      acc.draft += sale.status === 'draft' ? 1 : 0;
      acc.cancelled += sale.status === 'cancelled' ? 1 : 0;
      acc.pos += sale.pos_order ? 1 : 0;
      return acc;
    },
    { base: 0, local: 0, confirmed: 0, draft: 0, cancelled: 0, pos: 0 },
  );

  function updateFilters(next: Partial<SaleListFilters>) {
    setFilters((current) => ({ ...current, ...next, page: 1 }));
  }

  async function handleCancel(id: number) {
    const ok = window.confirm('¿Cancelar esta venta en borrador?');
    if (!ok) return;
    await cancelSale.mutateAsync(id);
    toast.success('Venta cancelada.');
  }

  if (isLoading && !data) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="space-y-3">
      <Card>
        <CardContent className="flex flex-wrap items-end gap-3 pt-4">
          <div className="min-w-[240px] flex-1">
            <Label htmlFor="sales-search" className="text-xs text-text-muted">Buscar</Label>
            <div className="relative mt-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
              <Input
                id="sales-search"
                value={filters.search ?? ''}
                onChange={(e) => updateFilters({ search: e.target.value })}
                placeholder="Cliente, documento, producto, SKU o venta #"
                className="pl-9"
              />
            </div>
          </div>
          <div className="w-44">
            <Label htmlFor="sales-status" className="text-xs text-text-muted">Estado</Label>
            <Select
              id="sales-status"
              value={filters.status ?? 'all'}
              onChange={(e) => updateFilters({ status: e.target.value as SaleListFilters['status'] })}
              className="mt-1"
            >
              {STATUS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </Select>
          </div>
          <div className="w-40">
            <Label htmlFor="sales-from" className="text-xs text-text-muted">Desde</Label>
            <Input
              id="sales-from"
              type="date"
              value={filters.date_from ?? ''}
              onChange={(e) => updateFilters({ date_from: e.target.value || undefined })}
              className="mt-1"
            />
          </div>
          <div className="w-40">
            <Label htmlFor="sales-to" className="text-xs text-text-muted">Hasta</Label>
            <Input
              id="sales-to"
              type="date"
              value={filters.date_to ?? ''}
              onChange={(e) => updateFilters({ date_to: e.target.value || undefined })}
              className="mt-1"
            />
          </div>
          <Button variant="secondary" onClick={() => void refetch()}>
            Actualizar
          </Button>
        </CardContent>
      </Card>

      <div className="grid gap-3 md:grid-cols-5">
        <InfoTile label="Ventas visibles" value={String(meta?.total ?? sales.length)} />
        <InfoTile label="Total página" value={`${formatMoney(pageTotals.base)} · ${formatMoney(pageTotals.local, 'Bs ')}`} />
        <InfoTile label="Confirmadas" value={String(pageTotals.confirmed)} />
        <InfoTile label="Borradores/Canceladas" value={`${pageTotals.draft} / ${pageTotals.cancelled}`} />
        <InfoTile label="Origen POS" value={String(pageTotals.pos)} />
      </div>

      {isError ? (
        <EmptyState
          title="No se pudo cargar ventas"
          description="Revisa tu conexión o intenta actualizar el listado."
          action={<Button onClick={() => void refetch()}>Reintentar</Button>}
        />
      ) : sales.length === 0 ? (
        <EmptyState
          icon={<FileText className="size-8" aria-hidden="true" />}
          title="Sin ventas"
          description="Cuando POS o ventas manuales generen documentos, aparecerán aquí para auditoría."
        />
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="w-8 px-2 py-2" />
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Venta</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Fecha</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Cliente</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Estado</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Cobranza</th>
                  <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Origen</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Total USD</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Total VES</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Items</th>
                </tr>
              </thead>
              <tbody>
                {sales.map((sale) => (
                  <SaleRow
                    key={sale.id}
                    sale={sale}
                    activeRate={activeRate}
                    expanded={expandedId === sale.id}
                    canCancel={canCancel}
                    canCreateReturn={canCreateReturn}
                    canCreateWarranty={canCreateWarranty}
                    cancelling={cancelSale.isPending}
                    onToggle={() => setExpandedId((current) => (current === sale.id ? null : sale.id))}
                    onCancel={() => void handleCancel(sale.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
          {meta && (
            <div className="flex items-center justify-between border-t border-border px-4 py-3 text-sm text-text-muted">
              <span>
                Página {meta.current_page} de {meta.last_page} · {meta.total} ventas
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={meta.current_page <= 1}
                  leftIcon={<ChevronLeft className="size-4" />}
                  onClick={() => setFilters((f) => ({ ...f, page: Math.max(1, (f.page ?? 1) - 1) }))}
                >
                  Anterior
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={meta.current_page >= meta.last_page}
                  rightIcon={<ChevronRight className="size-4" />}
                  onClick={() => setFilters((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
                >
                  Siguiente
                </Button>
              </div>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}

function SaleRow({
  sale,
  activeRate,
  expanded,
  canCancel,
  canCreateReturn,
  canCreateWarranty,
  cancelling,
  onToggle,
  onCancel,
}: {
  sale: Sale;
  activeRate: CurrentExchangeRate | null;
  expanded: boolean;
  canCancel: boolean;
  canCreateReturn: boolean;
  canCreateWarranty: boolean;
  cancelling: boolean;
  onToggle: () => void;
  onCancel: () => void;
}) {
  const date = sale.confirmed_at ?? sale.created_at;
  const localBalance = sale.receivable ? currentLocalBalance(sale.receivable, activeRate) : null;
  const returnLabel = returnStatusLabel(sale);
  return (
    <>
      <tr className="cursor-pointer border-b border-border hover:bg-bg/50" onClick={onToggle}>
        <td className="px-2 py-2 text-text-muted">
          <ChevronDown className={`size-4 transition-transform ${expanded ? '' : '-rotate-90'}`} />
        </td>
        <td className="px-3 py-2 font-medium">#{sale.id}</td>
        <td className="px-3 py-2 text-text-muted">{formatDate(date)}</td>
        <td className="px-3 py-2">{customerLabel(sale)}</td>
        <td className="px-3 py-2">
          <div className="flex flex-col gap-1">
            <Badge variant={statusVariant(sale.status)}>{SALE_STATUS_LABELS[sale.status]}</Badge>
            {returnLabel && (
            <Badge variant={returnLabel === 'Devuelta total' ? 'success' : returnLabel === 'Devolución rechazada' ? 'danger' : 'warning'}>{returnLabel}</Badge>
            )}
          </div>
        </td>
        <td className="px-3 py-2">
          <div className="flex flex-col gap-1">
            <Badge variant={receivableVariant(sale.receivable?.status)}>{receivableStatusLabel(sale)}</Badge>
            {sale.receivable && sale.receivable.balance_base_amount > 0 && (
              <span className="text-xs font-medium text-warning">
                Saldo {formatMoney(sale.receivable.balance_base_amount)}
                {localBalance === null ? ' · sin tasa activa USD/VES' : ` · ${formatMoney(localBalance, 'Bs ')} hoy`}
              </span>
            )}
          </div>
        </td>
        <td className="px-3 py-2 text-text-muted">{sale.pos_order ? 'POS' : 'Manual'}</td>
        <td className="px-3 py-2 text-right tabular-nums">{formatMoney(sale.total_base_amount)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{formatMoney(sale.total_local_amount, 'Bs ')}</td>
        <td className="px-3 py-2 text-right tabular-nums">{sale.items_count ?? sale.items?.length ?? '-'}</td>
      </tr>
      {expanded && (
        <tr className="border-b border-border bg-bg/20">
          <td colSpan={10} className="px-4 py-4">
            <SaleDetail
              saleId={sale.id}
              sale={sale}
              canCancel={canCancel}
              canCreateReturn={canCreateReturn}
              canCreateWarranty={canCreateWarranty}
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
  canCreateReturn,
  canCreateWarranty,
  cancelling,
  onCancel,
}: {
  saleId: number;
  sale: Sale;
  canCancel: boolean;
  canCreateReturn: boolean;
  canCreateWarranty: boolean;
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
  const actor = current.pos_order?.cashier_name ?? current.created_by_name ?? 'Sin usuario';
  const returnLabel = returnStatusLabel(current);
  const canReturnCurrentSale = canCreateReturn && current.status === 'confirmed' && hasReturnableItems(current);

  if (isLoading && !detail) return <Skeleton className="h-36 w-full" />;

  return (
    <div className="space-y-4" onClick={(event) => event.stopPropagation()}>
      <div className="grid gap-3 md:grid-cols-4">
        <InfoTile label="Cliente" value={customerLabel(current)} />
        <InfoTile label="Responsable" value={actor} />
        <InfoTile label="Origen" value={current.pos_order ? `POS #${current.pos_order.id}` : 'Manual'} />
        <InfoTile label="Fecha" value={formatDate(current.confirmed_at ?? current.created_at)} />
      </div>

      <div className="grid gap-3 lg:grid-cols-2">
        <ReceivableAudit sale={current} />
        <PosAudit sale={current} />
      </div>

      <div className="overflow-x-auto rounded-lg border border-border bg-surface">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Producto</th>
              <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Almacén</th>
              <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Cant.</th>
              <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Precio</th>
              <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Desc.</th>
              <th className="px-3 py-2 text-right font-semibold uppercase text-text-secondary">Total</th>
              <th className="px-3 py-2 font-semibold uppercase text-text-secondary">Tasa/Seriales</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-3 py-6 text-center text-text-muted">Sin items cargados en el detalle.</td>
              </tr>
            ) : items.map((item) => (
              <SaleItemRow
                key={item.id}
                item={item}
                canCreateWarranty={canCreateWarranty && current.status === 'confirmed'}
                onCreateWarranty={() => setWarrantyItem(item)}
              />
            ))}
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

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="text-sm text-text-muted">
          Total: <strong className="text-text-primary">{formatMoney(current.total_base_amount)}</strong>
          <span className="mx-2">·</span>
          {formatMoney(current.total_local_amount, 'Bs ')}
          {returnLabel && (
            <>
              <span className="mx-2">·</span>
              <Badge variant={returnLabel === 'Devuelta total' ? 'success' : returnLabel === 'Devolución rechazada' ? 'danger' : 'warning'}>{returnLabel}</Badge>
            </>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {canReturnCurrentSale && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<RotateCcw className="size-4" />}
              onClick={() => setShowReturnForm((value) => !value)}
            >
              Devolver
            </Button>
          )}
          {canCreateReturn && current.status === 'confirmed' && items.length > 0 && !hasReturnableItems(current) && (
            <Badge variant="success">Sin saldo por devolver</Badge>
          )}
          {canCancel && current.status === 'draft' && (
            <Button
              variant="danger"
              size="sm"
              loading={cancelling}
              leftIcon={<XCircle className="size-4" />}
              onClick={onCancel}
            >
              Cancelar borrador
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

function ReceivableAudit({ sale }: { sale: Sale }) {
  const receivable = sale.receivable;
  if (!receivable) {
    return (
      <section className="rounded border border-border bg-surface p-3">
        <h3 className="text-sm font-semibold">Cobranza</h3>
        <p className="mt-2 text-sm text-text-muted">No hay cuenta por cobrar asociada a esta venta.</p>
      </section>
    );
  }

  return (
    <section className="rounded border border-border bg-surface p-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">Cobranza</h3>
        <Badge variant={receivableVariant(receivable.status)}>
          {RECEIVABLE_STATUS_LABELS[receivable.status] ?? receivable.status}
        </Badge>
      </div>
      <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
        <Metric label="Original" value={formatMoney(receivable.original_base_amount)} />
        <Metric label="Cobrado" value={formatMoney(receivable.collected_base_amount)} />
        <Metric label="Saldo" value={formatMoney(receivable.balance_base_amount)} strong />
        <Metric label="Vence" value={receivable.due_date ?? '-'} />
      </dl>
    </section>
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
            <thead className="border-b border-border bg-bg/60 text-left text-xs uppercase text-text-muted">
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
                      <div className="text-xs text-text-muted">
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
                        <span className="text-xs text-text-muted">{remaining <= 0 ? 'Ya devuelto' : 'No serializado'}</span>
                      ) : (
                        <div className="max-h-24 space-y-1 overflow-auto">
                          {serialUnits.map((unit) => {
                            const unitId = Number(unit.id);
                            return (
                              <label key={unitId} className="flex items-center gap-2 text-xs">
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

function PosAudit({ sale }: { sale: Sale }) {
  const order = sale.pos_order;
  if (!order) {
    return (
      <section className="rounded border border-border bg-surface p-3">
        <h3 className="text-sm font-semibold">Auditoría POS</h3>
        <p className="mt-2 text-sm text-text-muted">Esta venta no tiene una orden POS relacionada.</p>
      </section>
    );
  }
  const session = order.cash_register_session;

  return (
    <section className="rounded border border-border bg-surface p-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">Auditoría POS</h3>
        <Badge variant={order.status === 'paid' ? 'success' : order.status === 'cancelled' ? 'danger' : 'warning'}>
          {order.status}
        </Badge>
      </div>
      <div className="mt-3 grid gap-2 text-sm md:grid-cols-2">
        <Metric label="Cajero" value={order.cashier_name ?? 'Sin cajero'} />
        <Metric label="Pagado" value={formatMoney(order.paid_base_amount)} strong />
        <Metric label="Caja" value={session?.cash_register_name ?? '-'} />
        <Metric label="Sucursal" value={session?.branch_name ?? '-'} />
      </div>
      <div className="mt-3 max-h-32 space-y-2 overflow-auto">
        {(order.payments ?? []).length === 0 ? (
          <p className="text-sm text-text-muted">Sin pagos POS registrados.</p>
        ) : order.payments?.map((payment) => (
          <div key={payment.id} className="rounded border border-border bg-bg/40 px-3 py-2 text-sm">
            <div className="flex items-center justify-between gap-2">
              <span className="font-medium">{payment.payment_method_name ?? payment.method}</span>
              <span className="tabular-nums">
                {formatMoney(payment.amount, payment.currency === 'VES' ? 'Bs ' : '$')}
              </span>
            </div>
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-text-muted">
              <span>Base {formatMoney(payment.amount_base)}</span>
              {payment.exchange_rate_type_code && <span>{payment.exchange_rate_type_code} @ {formatMoney(payment.exchange_rate, '')}</span>}
              {payment.reference && <span>Ref. {payment.reference}</span>}
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

function Metric({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div>
      <dt className="text-xs uppercase text-text-muted">{label}</dt>
      <dd className={strong ? 'font-semibold text-text-primary' : 'text-text-primary'}>{value}</dd>
    </div>
  );
}

function SaleItemRow({
  item,
  canCreateWarranty = false,
  onCreateWarranty,
}: {
  item: SaleItem;
  canCreateWarranty?: boolean;
  onCreateWarranty?: () => void;
}) {
  const serials = item.serial_units?.map((serial) => serial.serial_number).filter(Boolean).join(', ');
  const rate = item.exchange_rate_type_code
    ? `${item.exchange_rate_type_code} @ ${formatMoney(item.exchange_rate, '')}`
    : '-';
  const warrantyValid = isWarrantyValid(item);
  return (
    <tr className="border-b border-border last:border-0">
      <td className="px-3 py-2">
        <div className="font-medium">{item.product_name ?? `Producto #${item.product_id}`}</div>
        <div className="text-xs text-text-muted">{item.product_sku ?? '-'}</div>
      </td>
      <td className="px-3 py-2 text-text-muted">{item.warehouse_name ?? `#${item.warehouse_id}`}</td>
      <td className="px-3 py-2 text-right tabular-nums">{item.quantity}</td>
      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(item.unit_price, item.sale_currency === 'VES' ? 'Bs ' : '$')}</td>
      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(item.discount_amount)}</td>
      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(item.total_base_amount)}</td>
      <td className="px-3 py-2 text-xs text-text-muted">
        <div>{rate}</div>
        {serials && <div>Seriales: {serials}</div>}
        <div className="mt-1 flex flex-wrap items-center gap-2">
          <Badge variant={warrantyValid ? 'success' : item.warranty_policy_id ? 'danger' : 'default'}>
            {warrantyStatusLabel(item)}
          </Badge>
          {canCreateWarranty && warrantyValid && (
            <Button size="sm" variant="outline" leftIcon={<ShieldCheck className="size-4" />} onClick={onCreateWarranty}>
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
          <p className="text-sm text-text-muted">
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

function InfoTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded border border-border bg-surface px-3 py-2">
      <div className="text-xs uppercase text-text-muted">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}
