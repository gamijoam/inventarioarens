/**
 * AcceptInventoryTransferRequestDialog: dialog para que la empresa destino
 * acepte una solicitud. Mapea cada item de la solicitud a un producto de
 * su propio catalogo (que debe tener el mismo tracking_type).
 *
 * Para ayudar al usuario a encontrar el producto correcto sin tener que
 * adivinar, cada dropdown reordena sus opciones por scoreMatch:
 *   - SKU exacto (case-insensitive) primero + badge verde.
 *   - Barcode exacto segundo + badge verde.
 *   - Match parcial por nombre tercero + badge amarillo "Similar".
 *   - Resto alfabético sin badge.
 *
 * Esto resuelve el ~80% de los casos cuando las empresas del grupo usan
 * SKUs/barcodes comunes. Si los SKUs divergen totalmente, el usuario
 * sigue eligiendo manualmente (limitacion conocida).
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
import { compareMatches, scoreMatch, type MatchType } from '../scoreMatch';
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
                  // 1. Filtrar por tracking_type compatible.
                  const compatibleProducts = productOptions.filter(
                    (p: { tracking?: 'quantity' | 'serialized' }) => !originTracking || p.tracking === originTracking,
                  );
                  // 2. Calcular scoreMatch contra el origen y ordenar descendente.
                  const originLite = it.origin_product
                    ? {
                        name: originName,
                        sku: it.origin_product.sku ?? null,
                        barcode: it.origin_product.barcode ?? null,
                      }
                    : null;
                  const scored = compatibleProducts
                    .map((p) => {
                      // productOptions trae name + sku + tracking; necesitamos
                      // tambien barcode del ProductSchema completo.
                      const product = products.find((x: Product) => String(x.id) === p.id);
                      return {
                        ...p,
                        match: scoreMatch(originLite, {
                          name: p.label.split(' (')[0] ?? p.label,
                          sku: extractSkuFromLabel(p.label),
                          barcode: product?.barcode ?? null,
                        }),
                      };
                    })
                    .sort((a, b) => compareMatches(a.match, b.match));
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
                            {scored.map((p) => (
                              <option key={p.id} value={p.id}>
                                {optionLabel(p.label, p.match.matchType)}
                              </option>
                            ))}
                          </select>
                        )}
                        {bestMatchHint(scored) && (
                          <div
                            className="mt-1 text-[11px] text-text-muted"
                            data-testid={`accept-hint-${it.id}`}
                          >
                            Sugerencia: <strong>{bestMatchHint(scored)}</strong>
                          </div>
                        )}

                        {originTracking === 'serialized' && (
                          <div
                            className="mt-2 rounded border border-border bg-bg/30 p-2"
                            data-testid={`accept-imeis-${it.id}`}
                          >
                            <div className="text-[10px] uppercase tracking-wide text-text-muted">
                              IMEIs / seriales que llegaran a tu stock
                            </div>
                            {(() => {
                              const list = Array.isArray(it.serial_units) ? it.serial_units : [];
                              const numbers = list
                                .map((s) => (typeof s === 'string' ? s : s?.serial_number))
                                .filter((s): s is string => !!s && s.length > 0);
                              if (numbers.length === 0) {
                                return (
                                  <p className="mt-1 text-[11px] text-warning">
                                    La solicitud no incluye IMEIs/seriales. Si aceptas sin ellos,
                                    las unidades quedaran sin identificar en tu stock.
                                  </p>
                                );
                              }
                              return (
                                <ul className="mt-1 flex flex-wrap gap-1">
                                  {numbers.map((sn, idx) => (
                                    <li
                                      key={`${it.id}-sn-${idx}`}
                                      className="inline-flex items-center rounded bg-primary/10 px-1.5 py-0.5 font-mono text-[11px] text-primary"
                                      data-testid={`accept-imei-${it.id}-${idx}`}
                                    >
                                      {sn}
                                    </li>
                                  ))}
                                </ul>
                              );
                            })()}
                          </div>
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

/**
 * Extrae el SKU del label "Nombre (SKU)" generado por productOptions.label.
 * Si no encuentra el patron, devuelve string vacio.
 */
function extractSkuFromLabel(label: string): string {
  const m = /\(([^)]+)\)\s*$/.exec(label);
  return m?.[1]?.trim() ?? '';
}

/**
 * Sufijo que se agrega al label del <option> segun el tipo de match.
 * Los <option> nativos no soportan HTML, asi que usamos texto plano
 * con prefijos reconocibles: "[SKU] ", "[Barcode] ", "[Similar] ".
 */
function optionLabel(baseLabel: string, matchType: MatchType): string {
  switch (matchType) {
    case 'sku':
      return `[SKU] ${baseLabel}`;
    case 'barcode':
      return `[Barcode] ${baseLabel}`;
    case 'name':
      return `[Similar] ${baseLabel}`;
    case 'none':
    default:
      return baseLabel;
  }
}

interface ScoredProduct {
  id: string;
  label: string;
  tracking?: 'quantity' | 'serialized';
  match: { score: number; matchType: MatchType };
}

/**
 * Devuelve el label del mejor match (score > 0) para mostrar como sugerencia
 * debajo del select. Null si no hay ningun match.
 */
function bestMatchHint(scored: ScoredProduct[]): string | null {
  const best = scored[0];
  if (!best || best.match.score === 0) return null;
  // Quitar prefijo "[SKU] " etc para mostrar el nombre limpio.
  return best.label.replace(/^\[[^\]]+\]\s*/, '');
}
