/**
 * ReceiveDialog: dialog full-width para recibir mercancia de un PurchaseOrder
 * (PATCH /api/purchases/{id}/receive). FASE 3 del modulo de Compras.
 *
 * UX:
 * - Muestra los items pendientes del PO (quantity - received_quantity > 0).
 * - Default: recibir todo el pendiente.
 * - El user puede ajustar la cantidad a recibir por item (recepcion
 *   parcial).
 * - Si el item es serializado, los IMEIs capturados en el draft se
 *   muestran readonly (editarlos al recibir sera FASE 4).
 * - Footer: total a recibir + botones Cancelar / Recibir.
 * - Validacion: ninguna cantidad puede superar el pendiente.
 *
 * El dialog se abre desde el PurchasesManager al hacer click en "Recibir"
 * en una fila con status `draft` o `partially_received`.
 */
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Label } from '@/components/ui/Label';
import { usePurchase, useReceivePurchase } from '@/features/purchases/api';
import type { Purchase } from '@/features/purchases/schemas';
import { formatMoney } from '@/lib/money';
import {
  ReceiveItemRow,
  type ReceiveItemRowValue,
} from './ReceiveItemRow';

interface ReceiveDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  purchaseId: number | null;
  onReceived?: (purchase: Purchase) => void;
}

/**
 * Construye el estado local del dialog a partir del Purchase detallado.
 * - Filtra items con pendiente > 0.
 * - Inicializa `receiving_quantity = pending` para cada item.
 */
function buildInitialValues(purchase: Purchase): ReceiveItemRowValue[] {
  return (purchase.items ?? [])
    .map((it): ReceiveItemRowValue | null => {
      const ordered = Number(it.quantity ?? 0);
      const received = Number(it.received_quantity ?? 0);
      const pending = Math.max(0, ordered - received);
      if (pending <= 0) return null;

      const product = it.product as
        | { id: number; name: string; sku?: string | null; tracking_type?: string }
        | null
        | undefined;
      const warehouse = it.warehouse as { code: string } | null | undefined;
      const unitCost = it.base_unit_cost != null ? Number(it.base_unit_cost) : null;
      const serialUnits = Array.isArray(it.serial_units) ? (it.serial_units as never) : [];

      return {
        purchase_item_id: it.id,
        product_id: product?.id ?? it.product_id,
        product_name: product?.name ?? `Producto #${it.product_id}`,
        product_sku: product?.sku ?? null,
        product_tracking_type: product?.tracking_type,
        warehouse_code: warehouse?.code ?? `Almacen #${it.warehouse_id}`,
        ordered_quantity: ordered,
        received_quantity: received,
        receiving_quantity: pending,
        unit_cost: unitCost,
        serial_units: serialUnits,
      };
    })
    .filter((v): v is ReceiveItemRowValue => v !== null);
}

export function ReceiveDialog({ open, onOpenChange, purchaseId, onReceived }: ReceiveDialogProps) {
  const { data: purchase, isLoading } = usePurchase(purchaseId ?? 0);
  const receive = useReceivePurchase();
  const [items, setItems] = useState<ReceiveItemRowValue[]>([]);
  const [receivedAt, setReceivedAt] = useState<string>(new Date().toISOString().slice(0, 10));
  const [submitting, setSubmitting] = useState(false);
  const [itemErrors, setItemErrors] = useState<Record<number, string>>({});

  // Cuando llega el purchase, inicializar el state local.
  useEffect(() => {
    if (purchase && open) {
      setItems(buildInitialValues(purchase));
      setItemErrors({});
      setReceivedAt(new Date().toISOString().slice(0, 10));
    }
  }, [purchase, open]);

  const totals = useMemo(() => {
    let base = 0;
    let count = 0;
    for (const it of items) {
      if (it.receiving_quantity > 0) {
        count += 1;
        if (it.unit_cost != null) {
          base += it.receiving_quantity * it.unit_cost;
        }
      }
    }
    return { base, count };
  }, [items]);

  function updateItem(purchaseItemId: number, next: ReceiveItemRowValue) {
    setItems((prev) => prev.map((it) => (it.purchase_item_id === purchaseItemId ? next : it)));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!purchaseId) return;

    // Validacion: no exceder pendientes.
    const newErrors: Record<number, string> = {};
    for (const it of items) {
      const pending = it.ordered_quantity - it.received_quantity;
      if (it.receiving_quantity < 0) {
        newErrors[it.purchase_item_id] = 'Debe ser >= 0.';
      } else if (it.receiving_quantity > pending) {
        newErrors[it.purchase_item_id] = `Excede el pendiente (${pending}).`;
      } else if (it.receiving_quantity === 0) {
        newErrors[it.purchase_item_id] = 'No puede ser 0. Usa el boton Cancelar si no quieres recibir este item.';
      }
    }
    if (Object.keys(newErrors).length > 0) {
      setItemErrors(newErrors);
      toast.error('Hay errores en los items. Corrije las cantidades.');
      return;
    }
    setItemErrors({});

    const payload = {
      received_at: receivedAt || null,
      items: items
        .filter((it) => it.receiving_quantity > 0)
        .map((it) => ({
          purchase_item_id: it.purchase_item_id,
          quantity: it.receiving_quantity,
          serial_units: it.serial_units,
        })),
    };

    setSubmitting(true);
    try {
      const result = await receive.mutateAsync({ id: purchaseId, values: payload });
      toast.success('Mercancia recibida. Stock actualizado.');
      onReceived?.(result);
      onOpenChange(false);
    } catch (err) {
      if (err instanceof Error) {
        toast.error(err.message);
      } else {
        toast.error('Error al recibir la mercancia.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Recibir mercancia</DialogTitle>
          <DialogDescription>
            {purchase
              ? `Compra ${purchase.document_number ?? '#' + purchase.id} - ${purchase.supplier ? (purchase.supplier as { name: string }).name : 'sin proveedor'}. Confirma las cantidades que llegaron.`
              : 'Cargando compra...'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          {isLoading || !purchase ? (
            <Skeleton className="h-32 w-full" />
          ) : items.length === 0 ? (
            <div className="rounded border border-border bg-bg/30 p-6 text-center text-sm text-text-muted">
              Esta compra ya no tiene items pendientes. Todo fue recibido.
            </div>
          ) : (
            <>
              {/* Fecha de recepcion */}
              <div className="flex items-end gap-3">
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
              </div>

              {/* Lista de items pendientes */}
              <div className="space-y-2">
                <h3 className="text-sm font-semibold uppercase tracking-wide text-text-secondary">
                  Items pendientes ({items.length})
                </h3>
                {items.map((it) => (
                  <ReceiveItemRow
                    key={it.purchase_item_id}
                    value={{ ...it, error: itemErrors[it.purchase_item_id] }}
                    onChange={(next) => updateItem(it.purchase_item_id, next)}
                    disabled={submitting}
                  />
                ))}
              </div>

              {/* Total */}
              <div className="flex items-center justify-end gap-3 border-t-2 border-border pt-3">
                <span className="text-sm font-semibold uppercase tracking-wide text-text-secondary">
                  Total a recibir ({totals.count} {totals.count === 1 ? 'item' : 'items'}):
                </span>
                <span className="text-xl font-bold tabular-nums">
                  {formatMoney(totals.base)}
                </span>
              </div>
            </>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" loading={submitting} disabled={isLoading || items.length === 0}>
              Recibir mercancia
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
