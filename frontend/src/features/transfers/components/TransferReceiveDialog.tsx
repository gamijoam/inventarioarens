/**
 * TransferReceiveDialog: dialog para recibir mercancia de un traslado
 * (POST /api/inventory-transfers/{id}/receive).
 *
 * Para cada item, el user confirma la cantidad recibida. Si el item es
 * serializado, captura los IMEIs/seriales RECIBIDOS. El backend resuelve
 * cada serial_number a un ProductUnit existente o lo crea AVAILABLE en
 * el almacen destino.
 */
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { ImeiScanner } from './ImeiScanner';
import { Label } from '@/components/ui/Label';
import { useReceiveTransfer, useTransfer } from '@/features/transfers/api';
import type { ReceiveTransferValues, Transfer } from '@/features/transfers/schemas';

interface TransferReceiveDialogProps {
  transferId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onReceived?: (transfer: Transfer) => void;
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
  pending: number;
  receiving_quantity: number;
  receiving_serials: SerialEntry[];
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
      receiving_serials: [],
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

  if (itemsWithPending.length === 0) {
    return (
      <ModalShell open={open} onClose={() => onOpenChange(false)} title="Recibir mercancia">
        <p className="text-sm text-text-muted">
          Este traslado no tiene items pendientes de recepcion.
        </p>
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
        .filter((r) => r.receiving_quantity > 0 || r.receiving_serials.length > 0)
        .map((r) => {
          const isSerialized = r.tracking_type === 'serialized';
          return {
            inventory_transfer_item_id: r.transfer_item_id,
            received_quantity: isSerialized ? undefined : r.receiving_quantity,
            received_serial_units: isSerialized && r.receiving_serials.length > 0 ? r.receiving_serials : undefined,
            received_product_unit_ids: undefined,
            difference_reason: r.receiving_quantity < r.pending && r.difference_reason ? r.difference_reason : undefined,
            difference_notes: undefined,
          };
        });
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
        <p className="text-sm text-text-muted">
          Confirma las cantidades recibidas. Para productos serializados, registra cada IMEI/serial.
          El stock entra al almacen destino como AVAILABLE.
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
                    Pendiente: <span className="font-semibold tabular-nums">{r.pending.toFixed(2)}</span>
                  </div>
                </div>

                {isSerialized ? (
                  <div className="mt-3 space-y-2">
                    <ImeiScanner
                      productId={r.product_id}
                      warehouseId={transfer.to_warehouse_id}
                      selected={r.receiving_serials.map((s) => s.serial_number).filter((s) => s.trim() !== '')}
                      onChange={(sel) => {
                        const expected = r.receiving_quantity;
                        const type = r.new_unit_type;
                        const next = sel.slice(0, expected).map((sn) => ({ serial_type: type, serial_number: sn }));
                        const padded = [...next];
                        while (padded.length < expected) padded.push({ serial_type: type, serial_number: '' });
                        updateRow(r.transfer_item_id, { receiving_serials: padded });
                      }}
                      max={r.receiving_quantity}
                      dataTestIdPrefix={`receive-${r.transfer_item_id}-imei`}
                    />
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
              </div>
            );
          })}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="recv-date">Fecha de recepcion</Label>
          <Input
            id="recv-date"
            type="date"
            value={receivedAt}
            onChange={(e) => setReceivedAt(e.target.value)}
          />
        </div>

        <div className="flex justify-end gap-2 border-t border-border pt-3">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancelar
          </Button>
          <Button type="submit" loading={submitting} disabled={itemsWithPending.length === 0}>
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