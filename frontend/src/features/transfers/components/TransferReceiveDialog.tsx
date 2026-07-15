/**
 * TransferReceiveDialog: dialog para recibir mercancia de un traslado
 * (PATCH /api/inventory-transfers/{id}/receive).
 *
 * Para cada item del transfer, el user confirma la cantidad recibida
 * (default = todo lo pendiente). Si el item es serializado, captura
 * los IMEIs/seriales. Solo se envia lo que se modifico.
 */
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Label } from '@/components/ui/Label';
import { EmptyState } from '@/components/ui/EmptyState';
import { useReceiveTransfer, useTransfer } from '@/features/transfers/api';
import type { ReceiveTransferValues, Transfer } from '@/features/transfers/schemas';

interface TransferReceiveDialogProps {
  transferId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onReceived?: (transfer: Transfer) => void;
}

interface ItemRow {
  transfer_item_id: number;
  product_id: number;
  product_name: string;
  product_sku: string;
  tracking_type: 'quantity' | 'serialized' | string;
  pending: number;
  receiving_quantity: number;
  receiving_unit_ids: number[];
  new_unit_serial: string;
  new_unit_type: 'imei' | 'serial';
  difference_reason: string;
}

function buildInitialRows(transfer: Transfer): ItemRow[] {
  return (transfer.items ?? []).map((it) => {
    const ordered = Number(it.requested_quantity ?? it.quantity ?? 0);
    const received = Number(it.received_quantity ?? 0);
    const pending = Math.max(0, ordered - received);
    const product = it.product as { name?: string; sku?: string; tracking_type?: string } | null | undefined;
    return {
      transfer_item_id: it.id,
      product_id: it.product_id,
      product_name: product?.name ?? `Producto #${it.product_id}`,
      product_sku: product?.sku ?? '-',
      tracking_type: product?.tracking_type ?? 'quantity',
      pending,
      receiving_quantity: pending,
      receiving_unit_ids: Array.isArray(it.prepared_product_unit_ids) ? it.prepared_product_unit_ids : [],
      new_unit_serial: '',
      new_unit_type: 'imei',
      difference_reason: '',
    };
  });
}

export function TransferReceiveDialog({
  transferId,
  open,
  onOpenChange,
  onReceived,
}: TransferReceiveDialogProps) {
  const { data: transfer } = useTransfer(transferId);
  const receive = useReceiveTransfer();
  const [rows, setRows] = useState<ItemRow[]>([]);
  const [receivedAt, setReceivedAt] = useState<string>(new Date().toISOString().slice(0, 10));
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (transfer && open) {
      setRows(buildInitialRows(transfer));
      setReceivedAt(new Date().toISOString().slice(0, 10));
    }
  }, [transfer, open]);

  const itemsWithPending = useMemo(() => rows.filter((r) => r.pending > 0), [rows]);

  if (!transfer) return null;
  // 'isSerialized' (deprecated) se mantiene por compat con la UI previa.

  if (itemsWithPending.length === 0) {
    return (
      <ModalShell open={open} onClose={() => onOpenChange(false)} title="Recibir mercancia">
        <EmptyState
          title="Sin items pendientes"
          description="Este traslado no tiene items pendientes de recibir. Todo fue recibido en recepciones anteriores."
        />
      </ModalShell>
    );
  }

  function updateRow(id: number, patch: Partial<ItemRow>) {
    setRows((prev) => prev.map((r) => (r.transfer_item_id === id ? { ...r, ...patch } : r)));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      const items = rows
        .filter((r) => r.receiving_quantity > 0 || r.receiving_unit_ids.length > 0)
        .map((r) => ({
          inventory_transfer_id: r.transfer_item_id,
          received_quantity: r.tracking_type === 'serialized' ? undefined : r.receiving_quantity,
          received_product_unit_ids: r.tracking_type === 'serialized' ? r.receiving_unit_ids : undefined,
          difference_reason: r.receiving_quantity < r.pending && r.difference_reason ? r.difference_reason : undefined,
          difference_notes: undefined,
        }));
      const values: ReceiveTransferValues = {
        received_at: receivedAt,
        items,
        notes: null,
      };
      const result = await receive.mutateAsync({ id: transferId, values });
      toast.success('Mercancia recibida. Stock actualizado.');
      onReceived?.(result);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al recibir.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ModalShell open={open} onClose={() => onOpenChange(false)} title={`Recibir mercancia — ${transfer.document_number ?? '#' + transfer.id}`}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="received-at">Fecha de recepcion</Label>
          <Input
            id="received-at"
            type="date"
            value={receivedAt}
            onChange={(e) => setReceivedAt(e.target.value)}
            className="w-48"
          />
        </div>

        <div className="space-y-2">
          {itemsWithPending.map((r) => {
            const isSerialized = r.tracking_type === 'serialized';
            const willReceiveLess = r.receiving_quantity < r.pending;
            return (
              <div key={r.transfer_item_id} className="rounded-md border border-border bg-bg/30 p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-medium">{r.product_name}</div>
                    <div className="text-xs text-text-muted">SKU: {r.product_sku}</div>
                  </div>
                  <div className="text-xs text-text-muted">Pendiente: <span className="font-semibold tabular-nums">{r.pending.toFixed(2)}</span></div>
                </div>

                {isSerialized ? (
                  <div className="mt-3 space-y-2">
                    <div className="text-xs text-text-muted">
                      IMEIs/seriales ya preparadas: <span className="font-mono">{r.receiving_unit_ids.length}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <Select
                        value={r.new_unit_type}
                        onChange={(e) => updateRow(r.transfer_item_id, { new_unit_type: e.target.value as 'imei' | 'serial' })}
                        className="w-28"
                      >
                        <option value="imei">IMEI</option>
                        <option value="serial">Serial</option>
                      </Select>
                      <Input
                        value={r.new_unit_serial}
                        onChange={(e) => updateRow(r.transfer_item_id, { new_unit_serial: e.target.value })}
                        placeholder="Escanear o escribir..."
                        className="flex-1"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          if (!r.new_unit_serial.trim()) return;
                          updateRow(r.transfer_item_id, {
                            receiving_unit_ids: [...r.receiving_unit_ids, -1 * (Date.now() + r.receiving_unit_ids.length)],
                            new_unit_serial: '',
                          });
                        }}
                      >
                        Agregar
                      </Button>
                    </div>
                    <div className="text-xs text-text-muted">
                      Recibir: {r.receiving_unit_ids.length} IMEIs/seriales
                    </div>
                  </div>
                ) : (
                  <div className="mt-3 flex items-center gap-2">
                    <Label htmlFor={`recv-${r.transfer_item_id}`} className="text-xs">Cantidad a recibir</Label>
                    <Input
                      id={`recv-${r.transfer_item_id}`}
                      type="number"
                      min={0}
                      max={r.pending}
                      step={0.01}
                      value={r.receiving_quantity}
                      onChange={(e) => updateRow(r.transfer_item_id, { receiving_quantity: Number(e.target.value) })}
                      className="w-32"
                    />
                    <span className="text-xs text-text-muted">/ {r.pending.toFixed(2)} pendiente</span>
                  </div>
                )}

                {willReceiveLess && (
                  <div className="mt-2">
                    <Input
                      value={r.difference_reason}
                      onChange={(e) => updateRow(r.transfer_item_id, { difference_reason: e.target.value })}
                      placeholder="Motivo de la diferencia (obligatorio si recibes menos)"
                      className="text-xs"
                    />
                  </div>
                )}
              </div>
            );
          })}
        </div>

        <div className="flex justify-end gap-2 border-t border-border pt-3">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancelar
          </Button>
          <Button type="submit" loading={submitting}>
            Confirmar recepcion
          </Button>
        </div>
      </form>
    </ModalShell>
  );
}

function ModalShell({
  open,
  onClose,
  title,
  children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
}) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-3xl rounded-lg border border-border bg-surface max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="sticky top-0 flex items-center justify-between border-b border-border bg-surface px-5 py-3">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button type="button" onClick={onClose} className="rounded p-1 text-text-muted hover:bg-bg hover:text-text-primary" aria-label="Cerrar">
            <X className="size-4" />
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}
