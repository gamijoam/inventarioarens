/**
 * AcceptInventoryTransferRequestDialog: dialog para que la empresa destino
 * acepte una solicitud. Mapea cada item de la solicitud a un producto de
 * su propio catalogo (que debe tener el mismo tracking_type).
 */
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { useAcceptTransferRequest } from '@/features/inventory-transfer-requests/api';
import { useProductsForTransfer } from '@/features/transfers/api';
import { useWarehouses } from '@/features/inventory-center/api';
import type { Product } from '@/features/inventory-center/schemas';
import type { TransferRequest } from '../schemas';

interface AcceptInventoryTransferRequestDialogProps {
  request: TransferRequest;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAccepted?: (id: number) => void;
}

export function AcceptInventoryTransferRequestDialog({
  request,
  open,
  onOpenChange,
  onAccepted,
}: AcceptInventoryTransferRequestDialogProps) {
  const { data: warehouses = [], isLoading: loadingWh } = useWarehouses();
  const { data: products = [], isLoading: loadingProd } = useProductsForTransfer();
  const accept = useAcceptTransferRequest();

  const [destinationWarehouseId, setDestinationWarehouseId] = useState('');
  const [responseNotes, setResponseNotes] = useState('');
  const [mapping, setMapping] = useState<Record<number, string>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    setDestinationWarehouseId('');
    setResponseNotes('');
    const initial: Record<number, string> = {};
    for (const item of request.items ?? []) {
      initial[item.id] = '';
    }
    setMapping(initial);
  }, [open, request]);

  const productOptions = useMemo(
    () =>
      products.map((p: Product) => ({
        id: String(p.id),
        label: `${p.name}${p.sku ? ` (${p.sku})` : ''}`,
        tracking: p.tracking_type,
      })),
    [products],
  );

  if (!open) return null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!destinationWarehouseId) {
      toast.error('Selecciona el almacen destino.');
      return;
    }
    if (!request.items || request.items.length === 0) {
      toast.error('La solicitud no tiene items.');
      return;
    }
    const itemsPayload = request.items.map((it) => {
      const mappedId = mapping[it.id];
      if (!mappedId) {
        throw new Error(`Falta mapear el producto destino para ${it.origin_product?.name ?? 'item'}.`);
      }
      return {
        request_item_id: it.id,
        destination_product_id: Number(mappedId),
      };
    });

    setSubmitting(true);
    try {
      const accepted = await accept.mutateAsync({
        id: request.id,
        values: {
          destination_warehouse_id: Number(destinationWarehouseId),
          response_notes: responseNotes.trim() ? responseNotes.trim() : null,
          items: itemsPayload,
        },
      });
      toast.success('Solicitud aceptada. Stock transferido.');
      onAccepted?.(accepted.id);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al aceptar la solicitud.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={() => onOpenChange(false)}
      role="dialog"
      aria-modal="true"
      aria-labelledby="accept-req-title"
    >
      <div
        className="w-full max-w-3xl rounded-lg border border-border bg-surface p-5"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="accept-req-title" className="text-lg font-semibold">
          Aceptar solicitud {request.document_number ?? '#' + request.id}
        </h2>
        <p className="mt-1 text-sm text-text-muted">
          Mapea cada producto solicitado a un producto equivalente de tu catalogo
          (mismo tipo de control: quantity o serialized).
        </p>
        <form onSubmit={handleSubmit} className="mt-4 space-y-3">
          <div>
            <Label htmlFor="dest-wh">Almacen destino (en tu empresa)</Label>
            {loadingWh ? (
              <Skeleton className="h-9 w-full" />
            ) : (
              <select
                id="dest-wh"
                value={destinationWarehouseId}
                onChange={(e) => setDestinationWarehouseId(e.target.value)}
                className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                required
              >
                <option value="">Selecciona...</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={w.id}>{w.code}</option>
                ))}
              </select>
            )}
          </div>

          <div>
            <Label>Productos solicitados</Label>
            <table className="mt-1 w-full text-sm">
              <thead className="border-b border-border text-left text-xs uppercase text-text-muted">
                <tr>
                  <th className="py-1">Producto origen</th>
                  <th className="py-1 text-right">Cantidad</th>
                  <th className="py-1">Producto destino</th>
                </tr>
              </thead>
              <tbody>
                {(request.items ?? []).map((it) => {
                  const originName = it.origin_product?.name ?? `Producto #${it.origin_product_id}`;
                  const originTracking = it.origin_product?.tracking_type;
                  const compatibleProducts = productOptions.filter(
                    (p: { tracking?: 'quantity' | 'serialized' }) => !originTracking || p.tracking === originTracking,
                  );
                  return (
                    <tr key={it.id} className="border-b border-border last:border-b-0">
                      <td className="py-2">
                        <div className="font-medium">{originName}</div>
                        <div className="text-xs text-text-muted">{it.origin_product?.sku ?? ''}</div>
                      </td>
                      <td className="py-2 text-right tabular-nums">{Number(it.quantity ?? 0)}</td>
                      <td className="py-2">
                        {loadingProd ? (
                          <Skeleton className="h-9 w-full" />
                        ) : (
                          <select
                            value={mapping[it.id] ?? ''}
                            onChange={(e) =>
                              setMapping((m) => ({ ...m, [it.id]: e.target.value }))
                            }
                            className="w-full rounded border border-border-strong bg-surface px-2 py-1 text-sm"
                            required
                            data-testid={`accept-product-${it.id}`}
                          >
                            <option value="">Selecciona...</option>
                            {compatibleProducts.map((p) => (
                              <option key={p.id} value={p.id}>{p.label}</option>
                            ))}
                          </select>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div>
            <Label htmlFor="resp-notes">Notas de respuesta (opcional)</Label>
            <textarea
              id="resp-notes"
              value={responseNotes}
              onChange={(e) => setResponseNotes(e.target.value)}
              maxLength={1000}
              rows={2}
              className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" loading={submitting} data-testid="submit-accept">
              Confirmar aceptacion
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
