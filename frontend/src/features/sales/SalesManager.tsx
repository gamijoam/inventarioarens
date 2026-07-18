import { useState } from 'react';
import { ChevronDown, ChevronLeft, ChevronRight, FileText, Search, XCircle } from 'lucide-react';
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
  cancelling,
  onToggle,
  onCancel,
}: {
  sale: Sale;
  activeRate: CurrentExchangeRate | null;
  expanded: boolean;
  canCancel: boolean;
  cancelling: boolean;
  onToggle: () => void;
  onCancel: () => void;
}) {
  const date = sale.confirmed_at ?? sale.created_at;
  const localBalance = sale.receivable ? currentLocalBalance(sale.receivable, activeRate) : null;
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
          <Badge variant={statusVariant(sale.status)}>{SALE_STATUS_LABELS[sale.status]}</Badge>
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
            <SaleDetail saleId={sale.id} sale={sale} canCancel={canCancel} cancelling={cancelling} onCancel={onCancel} />
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
  cancelling,
  onCancel,
}: {
  saleId: number;
  sale: Sale;
  canCancel: boolean;
  cancelling: boolean;
  onCancel: () => void;
}) {
  const { data: detail, isLoading } = useSale(saleId);
  const current = detail ?? sale;
  const items = current.items ?? [];
  const actor = current.pos_order?.cashier_name ?? current.created_by_name ?? 'Sin usuario';

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
            ) : items.map((item) => <SaleItemRow key={item.id} item={item} />)}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="text-sm text-text-muted">
          Total: <strong className="text-text-primary">{formatMoney(current.total_base_amount)}</strong>
          <span className="mx-2">·</span>
          {formatMoney(current.total_local_amount, 'Bs ')}
        </div>
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

function SaleItemRow({ item }: { item: SaleItem }) {
  const serials = item.serial_units?.map((serial) => serial.serial_number).filter(Boolean).join(', ');
  const rate = item.exchange_rate_type_code
    ? `${item.exchange_rate_type_code} @ ${formatMoney(item.exchange_rate, '')}`
    : '-';
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
        {item.warranty_ends_at && <div>Garantía hasta {formatDate(item.warranty_ends_at)}</div>}
      </td>
    </tr>
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
