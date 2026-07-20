/**
 * TransferResolveDifferencesDialog: dialog para resolver las diferencias
 * de un traslado en estado completed_with_differences. Usa el hook
 * `useResolveTransferDifferences` para enviar las acciones por item.
 *
 * Acciones posibles por item (modelo backend):
 *   - investigating: solo marcar como en investigacion (no genera movimiento).
 *   - accepted_loss: aceptar la perdida (sin movimiento; limpia units).
 *   - adjusted_manually: ajustar manualmente (requiere quantity > 0,
 *     genera adjustment_out en toWarehouse).
 */
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import {
  useResolveTransferDifferences,
} from '@/features/transfers/api';
import { formatMoney } from '@/lib/money';
import type { Transfer, TransferItem } from '@/features/transfers/schemas';

type ResolutionAction = 'investigating' | 'accepted_loss' | 'adjusted_manually';

const ACTIONS: { value: ResolutionAction; label: string; description: string }[] = [
  { value: 'investigating', label: 'En investigacion', description: 'Marcar el item para investigar mas tarde.' },
  { value: 'accepted_loss', label: 'Aceptar perdida', description: 'Confirmar la perdida; no genera movimiento adicional.' },
  { value: 'adjusted_manually', label: 'Ajuste manual', description: 'Registrar una salida de inventario por la cantidad indicada.' },
];

interface TransferResolveDifferencesDialogProps {
  transfer: Transfer;
  onClose: () => void;
  onResolved?: () => void;
}

export function TransferResolveDifferencesDialog({
  transfer,
  onClose,
  onResolved,
}: TransferResolveDifferencesDialogProps) {
  const resolve = useResolveTransferDifferences();
  const itemsWithDiff = useMemo<TransferItem[]>(
    () => (transfer.items ?? []).filter((it) => Number(it.difference_quantity ?? 0) > 0),
    [transfer.items],
  );
  const [choices, setChoices] = useState<Record<number, ResolutionAction>>(() =>
    Object.fromEntries(itemsWithDiff.map((it) => [it.id, 'accepted_loss' as ResolutionAction])),
  );
  const [quantities, setQuantities] = useState<Record<number, number>>(() =>
    Object.fromEntries(itemsWithDiff.map((it) => [it.id, Number(it.difference_quantity ?? 0)])),
  );
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  if (itemsWithDiff.length === 0) {
    return (
      <DialogShell title="Resolver diferencias" onClose={onClose}>
        <p className="text-sm text-text-muted">Este traslado no tiene diferencias pendientes.</p>
      </DialogShell>
    );
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload: Array<{
      inventory_transfer_item_id: number;
      action: ResolutionAction;
      notes?: string;
      quantity?: number;
    }> = [];
    for (const it of itemsWithDiff) {
      const action = choices[it.id] ?? 'accepted_loss';
      const item: {
        inventory_transfer_item_id: number;
        action: ResolutionAction;
        notes?: string;
        quantity?: number;
      } = { inventory_transfer_item_id: it.id, action };
      if (action === 'adjusted_manually') {
        const q = Number(quantities[it.id] ?? 0);
        if (q <= 0) {
          toast.error(`La cantidad de ajuste para ${it.product?.name ?? 'item'} debe ser mayor a cero.`);
          return;
        }
        item.quantity = q;
      }
      payload.push(item);
    }
    setSubmitting(true);
    try {
      await resolve.mutateAsync({
        id: transfer.id,
        values: { items: payload, notes: notes.trim() || null },
      });
      toast.success('Diferencias resueltas.');
      onResolved?.();
      onClose();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al resolver diferencias.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <DialogShell title={`Resolver diferencias del traslado ${transfer.document_number ?? '#' + transfer.id}`} onClose={onClose}>
      <form onSubmit={handleSubmit} className="space-y-3">
        <p className="text-xs text-text-muted">
          Total recibido: {formatMoney(transfer.received_base_amount)} / Total esperado:{' '}
          {formatMoney(transfer.total_base_amount)}
        </p>
        <table className="w-full text-sm">
          <thead className="border-b border-border text-left text-xs uppercase text-text-muted">
            <tr>
              <th className="py-1">Producto</th>
              <th className="py-1 text-right">Diferencia</th>
              <th className="py-1">Accion</th>
              <th className="py-1 text-right">Cantidad</th>
            </tr>
          </thead>
          <tbody>
            {itemsWithDiff.map((it) => (
              <tr key={it.id} className="border-b border-border last:border-b-0">
                <td className="py-2">
                  <div className="font-medium">{it.product?.name ?? `Producto #${it.product_id}`}</div>
                  <div className="text-xs text-text-muted">{it.product?.sku ?? ''}</div>
                </td>
                <td className="py-2 text-right tabular-nums">{Number(it.difference_quantity ?? 0)}</td>
                <td className="py-2">
                  <Select
                    value={choices[it.id] ?? 'accepted_loss'}
                    onChange={(e) =>
                      setChoices((c) => ({ ...c, [it.id]: e.target.value as ResolutionAction }))
                    }
                  >
                    {ACTIONS.map((a) => (
                      <option key={a.value} value={a.value}>{a.label}</option>
                    ))}
                  </Select>
                </td>
                <td className="py-2 text-right">
                  {choices[it.id] === 'adjusted_manually' ? (
                    <input
                      type="number"
                      min={0}
                      step="0.01"
                      value={quantities[it.id] ?? 0}
                      onChange={(e) =>
                        setQuantities((q) => ({ ...q, [it.id]: Number(e.target.value) }))
                      }
                      className="w-24 rounded border border-border-strong bg-surface px-2 py-1 text-right text-sm"
                    />
                  ) : (
                    <span className="text-text-muted">-</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div>
          <label className="block text-xs font-semibold uppercase tracking-wide text-text-muted">
            Notas (opcional)
          </label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            maxLength={2000}
            rows={2}
            className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
            placeholder="Notas sobre la resolucion..."
          />
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancelar
          </Button>
          <Button type="submit" variant="primary" loading={submitting}>
            Confirmar resolucion
          </Button>
        </div>
      </form>
    </DialogShell>
  );
}

function DialogShell({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: React.ReactNode;
}) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="resolve-diff-title"
    >
      <div
        className="w-full max-w-3xl rounded-lg border border-border bg-surface p-5"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="resolve-diff-title" className="text-lg font-semibold">{title}</h2>
        <div className="mt-3">{children}</div>
      </div>
    </div>
  );
}
