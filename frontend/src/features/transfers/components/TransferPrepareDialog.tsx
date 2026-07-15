/**
 * TransferPrepareDialog: dialog para preparar mercancia de un traslado
 * (POST /api/inventory-transfers/{id}/prepare).
 *
 * Solo valido para validation_mode = 'logistics'. El backend rechaza con
 * 422 si se intenta preparar un traslado simple (que ya esta completed).
 *
 * Para cada item del transfer, el user confirma la cantidad preparada
 * (default = requested_quantity). Si el item es serializado, captura
 * los IMEIs/seriales preparados.
 */
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Label } from '@/components/ui/Label';
import { EmptyState } from '@/components/ui/EmptyState';
import { usePrepareTransfer, useTransfer } from '@/features/transfers/api';
import type { PrepareTransferValues, Transfer } from '@/features/transfers/schemas';

interface TransferPrepareDialogProps {
  transferId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onPrepared?: (transfer: Transfer) => void;
}

interface ItemRow {
  transfer_item_id: number;
  product_id: number;
  product_name: string;
  product_sku: string;
  tracking_type: string;
  requested: number;
  preparing_quantity: number;
  preparing_unit_ids: number[];
  new_unit_serial: string;
  new_unit_type: 'imei' | 'serial';
}

function buildInitialRows(transfer: Transfer): ItemRow[] {
  return (transfer.items ?? []).map((it) => {
    const requested = Number(it.requested_quantity ?? it.quantity ?? 0);
    const product = it.product as { name?: string; sku?: string; tracking_type?: string } | null | undefined;
    return {
      transfer_item_id: it.id,
      product_id: it.product_id,
      product_name: product?.name ?? `Producto #${it.product_id}`,
      product_sku: product?.sku ?? '-',
      tracking_type: product?.tracking_type ?? 'quantity',
      requested,
      preparing_quantity: requested,
      preparing_unit_ids: Array.isArray(it.prepared_product_unit_ids) ? it.prepared_product_unit_ids : [],
      new_unit_serial: '',
      new_unit_type: 'imei',
    };
  });
}

export function TransferPrepareDialog({
  transferId,
  open,
  onOpenChange,
  onPrepared,
}: TransferPrepareDialogProps) {
  const { data: transfer } = useTransfer(transferId);
  const prepare = usePrepareTransfer();
  const [rows, setRows] = useState<ItemRow[]>([]);
  const [notes, setNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (transfer && open) {
      setRows(buildInitialRows(transfer));
      setNotes('');
    }
  }, [transfer, open]);

  const itemsToSubmit = useMemo(
    () => rows.filter((r) => r.preparing_quantity > 0 || r.preparing_unit_ids.length > 0),
    [rows],
  );

  if (!transfer) return null;

  if (transfer.validation_mode !== 'logistics') {
    return (
      <ModalShell open={open} onClose={() => onOpenChange(false)} title="Preparar mercancia">
        <EmptyState
          title="Solo traslados logisticos"
          description="Este traslado esta en modo 'simple' y ya fue completado al crearlo. No requiere preparacion."
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
      const items = itemsToSubmit.map((r) => ({
        inventory_transfer_item_id: r.transfer_item_id,
        prepared_quantity: r.tracking_type === 'serialized' ? undefined : r.preparing_quantity,
        prepared_product_unit_ids: r.tracking_type === 'serialized' ? r.preparing_unit_ids : undefined,
      }));
      const values: PrepareTransferValues = {
        prepared_at: null,
        notes: notes.trim() || null,
        items,
      };
      const result = await prepare.mutateAsync({ id: transferId, values });
      toast.success('Mercancia preparada. Stock reservado en origen.');
      onPrepared?.(result);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al preparar.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ModalShell open={open} onClose={() => onOpenChange(false)} title={`Preparar mercancia — ${transfer.document_number ?? '#' + transfer.id}`}>
      <form onSubmit={handleSubmit} className="space-y-4">
        <p className="text-sm text-text-muted">
          Confirma las cantidades preparadas. Para productos serializados, registra cada IMEI/serial.
          El stock se mueve a RESERVED en el almacen de origen.
        </p>

        <div className="space-y-2">
          {rows.map((r) => {
            const isSerialized = r.tracking_type === 'serialized';
            return (
              <div key={r.transfer_item_id} className="rounded-md border border-border bg-bg/30 p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-medium">{r.product_name}</div>
                    <div className="text-xs text-text-muted">SKU: {r.product_sku}</div>
                  </div>
                  <div className="text-xs text-text-muted">
                    Solicitado: <span className="font-semibold tabular-nums">{r.requested.toFixed(2)}</span>
                  </div>
                </div>

                {isSerialized ? (
                  <div className="mt-3 space-y-2">
                    <div className="text-xs text-text-muted">
                      IMEIs/seriales preparados: <span className="font-mono">{r.preparing_unit_ids.length}</span>
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
                            preparing_unit_ids: [...r.preparing_unit_ids, -1 * (Date.now() + r.preparing_unit_ids.length)],
                            new_unit_serial: '',
                          });
                        }}
                      >
                        Agregar
                      </Button>
                    </div>
                  </div>
                ) : (
                  <div className="mt-3 flex items-center gap-2">
                    <Label htmlFor={`prep-${r.transfer_item_id}`} className="text-xs">Cantidad a preparar</Label>
                    <Input
                      id={`prep-${r.transfer_item_id}`}
                      type="number"
                      min={0}
                      max={r.requested}
                      step={0.01}
                      value={r.preparing_quantity}
                      onChange={(e) => updateRow(r.transfer_item_id, { preparing_quantity: Number(e.target.value) })}
                      className="w-32"
                    />
                    <span className="text-xs text-text-muted">/ {r.requested.toFixed(2)} solicitado</span>
                  </div>
                )}
              </div>
            );
          })}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="prep-notes">Notas (opcional)</Label>
          <Input
            id="prep-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Ej: faltaron 2 unidades por rotura"
            maxLength={1000}
          />
        </div>

        <div className="flex justify-end gap-2 border-t border-border pt-3">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancelar
          </Button>
          <Button type="submit" loading={submitting} disabled={itemsToSubmit.length === 0}>
            Confirmar preparacion
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