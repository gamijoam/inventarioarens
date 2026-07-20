/**
 * TransferPrepareDialog: dialog para preparar mercancia de un traslado
 * (POST /api/inventory-transfers/{id}/prepare).
 *
 * Solo valido para validation_mode = 'logistics'. El backend rechaza con
 * 422 si se intenta preparar un traslado simple (que ya esta completed).
 *
 * Para cada item del transfer, el user confirma la cantidad preparada
 * (default = requested_quantity). Si el item es serializado, captura
 * los IMEIs/seriales REALES (no IDs falsos). El backend resuelve cada
 * serial_number a un ProductUnit existente o lo crea AVAILABLE en el
 * almacen de origen.
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

interface SerialEntry {
  serial_type: 'imei' | 'serial';
  serial_number: string;
}

interface ItemRow {
  transfer_item_id: number;
  product_id: number;
  product_name: string;
  product_sku: string;
  tracking_type: string;
  requested: number;
  preparing_quantity: number;
  preparing_serials: SerialEntry[];
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
      preparing_serials: [],
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
    () => rows.filter((r) => r.preparing_quantity > 0 || r.preparing_serials.length > 0),
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
      const items = itemsToSubmit.map((r) => {
        const isSerialized = r.tracking_type === 'serialized';
        return {
          inventory_transfer_item_id: r.transfer_item_id,
          prepared_quantity: isSerialized ? undefined : r.preparing_quantity,
          // Para serializados: enviamos serial_units con los IMEIs/seriales
          // REALES que el usuario ingreso. El backend los resuelve a IDs.
          prepared_serial_units: isSerialized && r.preparing_serials.length > 0 ? r.preparing_serials : undefined,
          // Mantenemos prepared_product_unit_ids vacio (legacy path).
          prepared_product_unit_ids: undefined,
        };
      });
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
                      IMEIs/seriales preparados: <span className="font-mono">{r.preparing_serials.length}</span>
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
                        placeholder="Escanear o escribir IMEI/serial..."
                        className="flex-1"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          const num = r.new_unit_serial.trim();
                          if (!num) return;
                          // Evitar duplicados locales
                          if (r.preparing_serials.some((s) => s.serial_number === num)) {
                            toast.error('Ese IMEI/serial ya esta en la lista.');
                            return;
                          }
                          updateRow(r.transfer_item_id, {
                            preparing_serials: [
                              ...r.preparing_serials,
                              { serial_type: r.new_unit_type, serial_number: num },
                            ],
                            new_unit_serial: '',
                          });
                        }}
                      >
                        Agregar
                      </Button>
                    </div>
                    {r.preparing_serials.length > 0 && (
                      <ul className="mt-1 space-y-1 text-xs">
                        {r.preparing_serials.map((s, idx) => (
                          <li
                            key={`${s.serial_number}-${idx}`}
                            className="flex items-center justify-between rounded border border-border bg-bg/50 px-2 py-1"
                          >
                            <span className="font-mono">
                              [{s.serial_type}] {s.serial_number}
                            </span>
                            <button
                              type="button"
                              className="text-danger hover:underline"
                              onClick={() =>
                                updateRow(r.transfer_item_id, {
                                  preparing_serials: r.preparing_serials.filter((_, i) => i !== idx),
                                })
                              }
                            >
                              Quitar
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
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