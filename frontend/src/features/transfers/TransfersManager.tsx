/**
 * TransfersManager: listado + filtros + paginacion + acciones rapidas para
 * InventoryTransfers (intra-tenant). Patron consistente con
 * PurchasesManager, CustomersManager, SuppliersManager.
 *
 * Filtros visibles (Fase T2):
 *   - search (documento o guia)
 *   - status (todos + cada estado)
 *   - validation_mode (todos + simple/logistics)
 *   - from_warehouse_id (selector)
 *   - to_warehouse_id (selector)
 *   - date_from / date_to (rango de processed_at)
 *
 * Paginacion con meta.current_page / last_page / total del backend.
 */
import { useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
  Plus,
  Search,
  XCircle,
  Package,
  Truck,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import {
  useTransfers,
  useCancelTransfer,
  type TransferListFilters,
} from '@/features/transfers/api';
import {
  TRANSFER_STATUS_LABELS,
  type Transfer,
  type TransferStatus,
} from '@/features/transfers/schemas';
import { useWarehouses } from '@/features/inventory-center/api';
import { formatMoney } from '@/lib/money';

const STATUS_FILTER_OPTIONS: { value: TransferListFilters['status']; label: string }[] = [
  { value: 'all', label: 'Todos los estados' },
  { value: 'requested', label: 'Solicitado' },
  { value: 'prepared', label: 'Preparado' },
  { value: 'prepared_with_differences', label: 'Preparado con diferencias' },
  { value: 'dispatched', label: 'Despachado' },
  { value: 'completed', label: 'Completado' },
  { value: 'completed_with_differences', label: 'Completado con diferencias' },
  { value: 'cancelled', label: 'Cancelado' },
];

const VALIDATION_MODE_OPTIONS: { value: TransferListFilters['validation_mode']; label: string }[] = [
  { value: 'all', label: 'Todos los modos' },
  { value: 'simple', label: 'Directo (simple)' },
  { value: 'logistics', label: 'Logistico (con checklist)' },
];

function statusVariant(status: TransferStatus): 'info' | 'warning' | 'success' | 'default' {
  switch (status) {
    case 'requested':
      return 'default';
    case 'prepared':
      return 'info';
    case 'prepared_with_differences':
      return 'warning';
    case 'dispatched':
      return 'info';
    case 'completed':
      return 'success';
    case 'completed_with_differences':
      return 'warning';
    case 'cancelled':
      return 'default';
  }
}

interface TransfersManagerProps {
  onNew?: () => void;
  onReceive?: (transferId: number) => void;
}

export function TransfersManager({ onNew, onReceive }: TransfersManagerProps = {}) {
  const navigate = useNavigate();
  const [filters, setFilters] = useState<TransferListFilters>({
    search: '',
    status: 'all',
    validation_mode: 'all',
    from_warehouse_id: undefined,
    to_warehouse_id: undefined,
    date_from: undefined,
    date_to: undefined,
    page: 1,
    per_page: 25,
  });
  const { data: warehouses = [] } = useWarehouses();
  const { data: queryResult, isLoading } = useTransfers(filters);
  const transfers = queryResult?.data ?? [];
  const meta = queryResult?.meta;
  const cancel = useCancelTransfer();
  const [cancelling, setCancelling] = useState<Transfer | null>(null);

  function setPage(page: number) {
    setFilters((f) => ({ ...f, page }));
  }

  function clearFilters() {
    setFilters({
      search: '',
      status: 'all',
      validation_mode: 'all',
      from_warehouse_id: undefined,
      to_warehouse_id: undefined,
      date_from: undefined,
      date_to: undefined,
      page: 1,
      per_page: 25,
    });
  }

  const hasActiveFilters =
    !!filters.search ||
    filters.status !== 'all' ||
    filters.validation_mode !== 'all' ||
    !!filters.from_warehouse_id ||
    !!filters.to_warehouse_id ||
    !!filters.date_from ||
    !!filters.date_to;

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-6">
        <div className="relative lg:col-span-2">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
            aria-hidden="true"
          />
          <Input
            value={filters.search ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value, page: 1 }))}
            placeholder="Buscar por documento o guia..."
            className="pl-8"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="status-filter" className="whitespace-nowrap text-xs text-text-muted">Estado</Label>
          <Select
            id="status-filter"
            value={filters.status ?? 'all'}
            onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value as TransferListFilters['status'], page: 1 }))}
            className="flex-1"
          >
            {STATUS_FILTER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="vm-filter" className="whitespace-nowrap text-xs text-text-muted">Modo</Label>
          <Select
            id="vm-filter"
            value={filters.validation_mode ?? 'all'}
            onChange={(e) => setFilters((f) => ({ ...f, validation_mode: e.target.value as TransferListFilters['validation_mode'], page: 1 }))}
            className="flex-1"
          >
            {VALIDATION_MODE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="from-w-filter" className="whitespace-nowrap text-xs text-text-muted">Origen</Label>
          <Select
            id="from-w-filter"
            value={filters.from_warehouse_id ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, from_warehouse_id: e.target.value ? Number(e.target.value) : undefined, page: 1 }))}
            className="flex-1"
          >
            <option value="">Todos</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>{w.code}</option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="to-w-filter" className="whitespace-nowrap text-xs text-text-muted">Destino</Label>
          <Select
            id="to-w-filter"
            value={filters.to_warehouse_id ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, to_warehouse_id: e.target.value ? Number(e.target.value) : undefined, page: 1 }))}
            className="flex-1"
          >
            <option value="">Todos</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>{w.code}</option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2 lg:col-span-2">
          <Label htmlFor="date-from" className="whitespace-nowrap text-xs text-text-muted">Desde</Label>
          <Input
            id="date-from"
            type="date"
            value={filters.date_from ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, date_from: e.target.value || undefined, page: 1 }))}
            className="flex-1"
          />
          <Label htmlFor="date-to" className="whitespace-nowrap text-xs text-text-muted">Hasta</Label>
          <Input
            id="date-to"
            type="date"
            value={filters.date_to ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, date_to: e.target.value || undefined, page: 1 }))}
            className="flex-1"
          />
        </div>
        <div className="flex items-center justify-end gap-2 lg:col-span-2">
          {hasActiveFilters && (
            <Button size="sm" variant="ghost" onClick={clearFilters}>
              Limpiar filtros
            </Button>
          )}
          <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={onNew}>
            Nuevo traslado
          </Button>
        </div>
      </div>

      {transfers.length === 0 ? (
        <EmptyState
          icon={<Truck className="size-8" />}
          title={hasActiveFilters ? 'Sin resultados' : 'Sin traslados'}
          description={
            hasActiveFilters
              ? 'Ningun traslado coincide con los filtros.'
              : 'Crea el primer traslado para registrar movimiento de stock entre almacenes.'
          }
        />
      ) : (
        <>
          <div className="rounded-lg border border-border bg-surface">
            <table className="w-full table-dense">
              <thead className="border-b border-border bg-bg/60 text-left">
                <tr>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Documento</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Modo</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Origen</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Destino</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Total (USD)</th>
                  <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Items</th>
                  <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {transfers.map((t) => {
                  const totalBase = Number(t.total_base_amount ?? 0);
                  const receivedBase = Number(t.received_base_amount ?? 0);
                  const progress = totalBase > 0 ? Math.min(100, Math.round((receivedBase / totalBase) * 100)) : 0;
                  const canReceive = t.status === 'dispatched';
                  const canCancel = t.status === 'requested' || t.status === 'prepared' || t.status === 'prepared_with_differences';
                  return (
                    <tr
                      key={t.id}
                      className="cursor-pointer border-b border-border last:border-b-0 transition-colors hover:bg-bg/40"
                      onClick={() => navigate({ to: '/transfers/$transferId', params: { transferId: String(t.id) } })}
                    >
                      <td className="px-3 py-2 font-medium">
                        <code className="rounded bg-bg px-1.5 py-0.5 text-xs">
                          {t.document_number ?? `#${t.id}`}
                        </code>
                      </td>
                      <td className="px-3 py-2 text-text-muted">
                        <Badge variant={t.validation_mode === 'logistics' ? 'info' : 'default'} className="text-[10px]">
                          {t.validation_mode === 'logistics' ? 'Logistico' : 'Directo'}
                        </Badge>
                      </td>
                      <td className="px-3 py-2 text-text-muted">
                        {(t.from_warehouse as { code?: string } | null | undefined)?.code ?? `Almacen #${t.from_warehouse_id}`}
                      </td>
                      <td className="px-3 py-2 text-text-muted">
                        {(t.to_warehouse as { code?: string } | null | undefined)?.code ?? `Almacen #${t.to_warehouse_id}`}
                      </td>
                      <td className="px-3 py-2">
                        <Badge variant={statusVariant(t.status)}>
                          {TRANSFER_STATUS_LABELS[t.status]}
                        </Badge>
                        {(t.status === 'prepared_with_differences' || t.status === 'completed_with_differences') && progress > 0 && progress < 100 && (
                          <span className="ml-2 text-[10px] uppercase tracking-wide text-warning">{progress}%</span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">{formatMoney(t.total_base_amount)}</td>
                      <td className="px-3 py-2 text-text-muted tabular-nums">{t.items_count ?? '-'}</td>
                      <td className="px-3 py-2 text-right">
                        <div className="flex justify-end gap-1">
                          {canReceive && onReceive && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={(e) => {
                                e.stopPropagation();
                                onReceive(t.id);
                              }}
                              aria-label={`Recibir traslado ${t.document_number ?? t.id}`}
                              title="Recibir mercancia"
                            >
                              <Package className="size-4 text-primary" />
                            </Button>
                          )}
                          {canCancel && (
                            <Button
                              size="icon-sm"
                              variant="ghost"
                              onClick={(e) => {
                                e.stopPropagation();
                                setCancelling(t);
                              }}
                              aria-label={`Cancelar traslado ${t.document_number ?? t.id}`}
                              title="Cancelar traslado"
                            >
                              <XCircle className="size-4 text-danger" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {meta && meta.last_page > 1 && (
            <div className="mt-3 flex items-center justify-between text-sm">
              <div className="text-text-muted">
                Pagina {meta.current_page} de {meta.last_page} ({meta.total} resultados)
              </div>
              <div className="flex gap-1">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage(meta.current_page - 1)}
                  leftIcon={<ChevronLeft className="size-4" />}
                >
                  Anterior
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage(meta.current_page + 1)}
                  rightIcon={<ChevronRight className="size-4" />}
                >
                  Siguiente
                </Button>
              </div>
            </div>
          )}
        </>
      )}

      {cancelling && (
        <CancelDialog
          transfer={cancelling}
          cancel={cancel}
          onClose={() => setCancelling(null)}
        />
      )}
    </>
  );
}

function CancelDialog({
  transfer,
  cancel,
  onClose,
}: {
  transfer: Transfer;
  cancel: ReturnType<typeof useCancelTransfer>;
  onClose: () => void;
}) {
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (reason.trim().length < 5) {
      toast.error('El motivo debe tener al menos 5 caracteres.');
      return;
    }
    setSubmitting(true);
    try {
      await cancel.mutateAsync({
        id: transfer.id,
        values: { cancellation_reason: reason, cancelled_at: null },
      });
      toast.success('Traslado cancelado.');
      onClose();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al cancelar.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-md rounded-lg border border-border bg-surface p-5" onClick={(e) => e.stopPropagation()}>
        <h2 className="text-lg font-semibold">Cancelar traslado "{transfer.document_number ?? '#' + transfer.id}"</h2>
        <p className="mt-1 text-sm text-text-muted">El traslado quedara en estado cancelado. No se puede deshacer.</p>
        <form onSubmit={handleSubmit} className="mt-4 space-y-3">
          <div>
            <label className="block text-xs font-semibold uppercase tracking-wide text-text-muted">
              Motivo <span className="text-danger">*</span>
            </label>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              minLength={5}
              maxLength={1000}
              required
              rows={3}
              className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
              placeholder="Describe brevemente por que se cancela (min. 5 chars)"
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" variant="danger" loading={submitting}>
              Confirmar cancelacion
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
