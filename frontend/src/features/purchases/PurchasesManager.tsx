/**
 * PurchasesManager: listado + filtros + acciones rapidas para Compras.
 * Patron consistente con CustomersManager, SuppliersManager, etc.
 *
 * El boton "Nueva compra" abre el dialog de creacion (FASE 2). Las
 * acciones "Recibir" y "Cancelar" estan en el detalle (FASE 3).
 */
import { useState } from 'react';
import { Plus, Search, XCircle, Package } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import {
  usePurchases,
  useCancelPurchase,
  type PurchaseListFilters,
} from '@/features/purchases/api';
import {
  PURCHASE_STATUS_LABELS,
  type Purchase,
  type PurchaseStatus,
} from '@/features/purchases/schemas';

const STATUS_FILTER_OPTIONS: { value: PurchaseListFilters['status']; label: string }[] = [
  { value: 'all', label: 'Todos' },
  { value: 'draft', label: 'Borrador' },
  { value: 'partially_received', label: 'Recibido parcial' },
  { value: 'received', label: 'Recibido' },
  { value: 'cancelled', label: 'Cancelado' },
];

function statusVariant(status: PurchaseStatus): 'info' | 'warning' | 'success' | 'default' {
  switch (status) {
    case 'draft':
      return 'default';
    case 'partially_received':
      return 'warning';
    case 'received':
      return 'success';
    case 'cancelled':
      return 'default';
  }
}

function formatMoney(value: number | string | null | undefined): string {
  if (value === null || value === undefined) return '-';
  const n = typeof value === 'string' ? Number(value) : value;
  if (!Number.isFinite(n)) return '-';
  return n.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

interface PurchasesManagerProps {
  onNew?: () => void;
  onReceive?: (purchaseId: number) => void;
}

export function PurchasesManager({ onNew, onReceive }: PurchasesManagerProps = {}) {
  const [filters, setFilters] = useState<PurchaseListFilters>({
    search: '',
    status: 'all',
    date_from: undefined,
    date_to: undefined,
  });
  const { data: purchases = [], isLoading } = usePurchases(filters);
  const cancel = useCancelPurchase();
  const [cancelling, setCancelling] = useState<Purchase | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex flex-wrap items-end gap-2">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
          <Input
            value={filters.search ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))}
            placeholder="Buscar por documento o supplier..."
            className="pl-9"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="status-filter" className="text-xs text-text-muted">Estado</Label>
          <Select
            id="status-filter"
            value={filters.status ?? 'all'}
            onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value as PurchaseListFilters['status'] }))}
            className="w-44"
          >
            {STATUS_FILTER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="date-from" className="text-xs text-text-muted">Desde</Label>
          <Input
            id="date-from"
            type="date"
            value={filters.date_from ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, date_from: e.target.value || undefined }))}
            className="w-40"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="date-to" className="text-xs text-text-muted">Hasta</Label>
          <Input
            id="date-to"
            type="date"
            value={filters.date_to ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, date_to: e.target.value || undefined }))}
            className="w-40"
          />
        </div>
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={onNew} className="ml-auto">
          Nueva compra
        </Button>
      </div>

      {purchases.length === 0 ? (
        <EmptyState
          title={filters.search || filters.status !== 'all' ? 'Sin resultados' : 'Sin compras'}
          description={
            filters.search || filters.status !== 'all'
              ? 'Ninguna compra coincide con los filtros.'
              : 'Crea la primera compra para registrar mercancia y generar CxP.'
          }
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Documento</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Fecha</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Proveedor</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Total (USD)</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Recibido</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Items</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {purchases.map((p) => {
                const totalBase = Number(p.total_base_amount ?? 0);
                const receivedBase = Number(p.received_base_amount ?? 0);
                const progress = totalBase > 0 ? Math.min(100, Math.round((receivedBase / totalBase) * 100)) : 0;
                return (
                  <tr key={p.id} className="border-b border-border last:border-b-0">
                    <td className="px-3 py-2 font-medium">
                      <code className="rounded bg-bg px-1.5 py-0.5 text-xs">
                        {p.document_number ?? `#${p.id}`}
                      </code>
                    </td>
                    <td className="px-3 py-2 text-text-muted">
                      {p.issued_at ?? '-'}
                    </td>
                    <td className="px-3 py-2 text-text-muted">
                      {(p.supplier as { name?: string } | null | undefined)?.name ?? <span className="text-text-muted/60">Sin proveedor</span>}
                    </td>
                    <td className="px-3 py-2">
                      <Badge variant={statusVariant(p.status)}>
                        {PURCHASE_STATUS_LABELS[p.status]}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(p.total_base_amount)}</td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex flex-col items-end gap-0.5">
                        <span className="tabular-nums text-text-muted">{formatMoney(p.received_base_amount)}</span>
                        {p.status === 'partially_received' && (
                          <span className="text-[10px] uppercase tracking-wide text-warning">{progress}%</span>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-text-muted tabular-nums">{p.items_count ?? '-'}</td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex justify-end gap-1">
                        {(p.status === 'draft' || p.status === 'partially_received') && onReceive && (
                          <Button
                            size="icon-sm"
                            variant="ghost"
                            onClick={() => onReceive(p.id)}
                            aria-label={`Recibir compra ${p.document_number ?? p.id}`}
                            title="Recibir mercancia"
                          >
                            <Package className="size-4 text-primary" />
                          </Button>
                        )}
                        {p.status === 'draft' && (
                          <Button
                            size="icon-sm"
                            variant="ghost"
                            onClick={() => setCancelling(p)}
                            aria-label={`Cancelar compra ${p.document_number ?? p.id}`}
                            title="Cancelar compra"
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
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setCancelling(null); }}
          title={`Cancelar compra "${cancelling.document_number ?? '#' + cancelling.id}"`}
          description="La compra quedara en estado cancelado. No se puede deshacer."
          confirmLabel="Cancelar compra"
          variant="danger"
          loading={cancel.isPending}
          onConfirm={async () => {
            try {
              await cancel.mutateAsync(cancelling.id);
              setCancelling(null);
              toast.success('Compra cancelada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al cancelar.');
            }
          }}
        />
      )}
    </>
  );
}
