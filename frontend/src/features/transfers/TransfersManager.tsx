/**
 * TransfersManager: listado + filtros + acciones rapidas para
 * InventoryTransfers (intra-tenant). Patron consistente con
 * PurchasesManager, CustomersManager, SuppliersManager.
 *
 * Estado inicial: filtros por search, status, validation_mode,
 * warehouse_id. Acciones rapidas: crear borrador, recibir, cancelar.
 */
import { useState } from 'react';
import { Plus, Search, XCircle, Package, Truck } from 'lucide-react';
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
  const { data: transfers = [], isLoading } = useTransfers(filters);
  const cancel = useCancelTransfer();
  const [cancelling, setCancelling] = useState<Transfer | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex flex-wrap items-end gap-2">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search
            className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
            aria-hidden="true"
          />
          <Input
            value={filters.search ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))}
            onKeyDown={(e) => {
              if (e.key === 'Enter') setFilters((f) => ({ ...f, search: f.search }));
            }}
            placeholder="Buscar por documento o guia..."
            className="pl-8"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="status-filter" className="text-xs text-text-muted">Estado</Label>
          <Select
            id="status-filter"
            value={filters.status ?? 'all'}
            onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value as TransferListFilters['status'] }))}
            className="w-48"
          >
            {STATUS_FILTER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="vm-filter" className="text-xs text-text-muted">Modo</Label>
          <Select
            id="vm-filter"
            value={filters.validation_mode ?? 'all'}
            onChange={(e) => setFilters((f) => ({ ...f, validation_mode: e.target.value as TransferListFilters['validation_mode'] }))}
            className="w-44"
          >
            {VALIDATION_MODE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </div>
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={onNew} className="ml-auto">
          Nuevo traslado
        </Button>
      </div>

      {transfers.length === 0 ? (
        <EmptyState
          icon={<Truck className="size-8" />}
          title={filters.search || filters.status !== 'all' ? 'Sin resultados' : 'Sin traslados'}
          description={
            filters.search || filters.status !== 'all'
              ? 'Ningun traslado coincide con los filtros.'
              : 'Crea el primer traslado para registrar movimiento de stock entre almacenes.'
          }
        />
      ) : (
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
                const canReceive = t.status === 'requested' || t.status === 'prepared' || t.status === 'prepared_with_differences';
                const canCancel = t.status === 'requested' || t.status === 'prepared' || t.status === 'prepared_with_differences';
                return (
                  <tr key={t.id} className="border-b border-border last:border-b-0">
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
                            onClick={() => onReceive(t.id)}
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
                            onClick={() => setCancelling(t)}
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
