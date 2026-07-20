/**
 * AcceptInventoryTransferRequestDialog: dialog para que la empresa destino
 * acepte una solicitud. Mapea cada item de la solicitud a un producto de
 * su propio catalogo (que debe tener el mismo tracking_type).
 *
 * Layout visual: cada item se renderiza como una CARD HORIZONTAL con 3 zonas:
 *   - IZQUIERDA: producto ORIGEN (lo que me piden), con SKU, barcode,
 *     cantidad y los IMEIs si es serializado.
 *   - CENTRO: flecha + badge del tipo de match sugerido (verde SKU,
 *     verde Barcode, amarillo Similar, gris sin match).
 *   - DERECHA: SELECT para elegir el producto DESTINO de mi catalogo,
 *     ordenado por scoreMatch con prefijos [SKU]/[Barcode]/[Similar].
 *
 * Cards tienen borde de color segun el tipo de match para que el flujo
 * "lo que envio -> lo que recibo -> lo que matchea" sea obvio a simple vista.
 */
import { useEffect, useMemo, useState } from 'react';
import { ArrowRight, Package, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
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

interface ScoredProduct {
  id: string;
  label: string;
  tracking?: 'quantity' | 'serialized';
  match: { score: number; matchType: MatchType };
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
        className="w-full max-w-5xl max-h-[90vh] overflow-y-auto rounded-lg border border-border bg-surface"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between border-b border-border bg-surface px-5 py-3">
          <div>
            <h2 id="accept-req-title" className="text-lg font-semibold">
              Aceptar solicitud {request.document_number ?? '#' + request.id}
            </h2>
            <p className="mt-0.5 text-xs text-text-muted">
              Mapea cada producto solicitado a un producto equivalente de tu catalogo.
              El match recomendado aparece primero con su badge.
            </p>
          </div>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="rounded p-1 text-text-muted hover:bg-bg hover:text-text-primary"
            aria-label="Cerrar"
          >
            <X className="size-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4 p-5">
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
                <option value="">Selecciona almacen destino...</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={w.id}>{w.code}</option>
                ))}
              </select>
            )}
          </div>

          <div className="space-y-3">
            <Label>Items de la solicitud</Label>
            {(request.items ?? []).map((it) => (
              <ItemMatchCard
                key={it.id}
                item={it}
                products={products}
                productOptions={productOptions}
                loadingProd={loadingProd}
                selectedProductId={mapping[it.id] ?? ''}
                onSelect={(productId) => setMapping((m) => ({ ...m, [it.id]: productId }))}
              />
            ))}
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
              placeholder="Comentarios para el solicitante..."
            />
          </div>

          <div className="flex justify-end gap-2 border-t border-border pt-3">
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
 * Card visual por item: ORIGEN (lo que me piden) -> DESTINO (lo que elijo).
 * Muestra el match recomendado con su badge y los IMEIs si es serializado.
 */
interface ItemMatchCardProps {
  item: NonNullable<TransferRequest['items']>[number];
  products: Product[];
  productOptions: Array<{ id: string; label: string; tracking?: 'quantity' | 'serialized' }>;
  loadingProd: boolean;
  selectedProductId: string;
  onSelect: (productId: string) => void;
}

function ItemMatchCard({
  item,
  products,
  productOptions,
  loadingProd,
  selectedProductId,
  onSelect,
}: ItemMatchCardProps) {
  const origin = item.origin_product;
  const originName = origin?.name ?? `Producto #${item.origin_product_id}`;
  const originTracking = origin?.tracking_type;
  const compatibleProducts = productOptions.filter(
    (p) => !originTracking || p.tracking === originTracking,
  );

  const originLite = origin
    ? { name: originName, sku: origin.sku ?? null, barcode: origin.barcode ?? null }
    : null;

  const scored = compatibleProducts
    .map((p): ScoredProduct => {
      const product = products.find((x) => String(x.id) === p.id);
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

  const best = scored[0];
  const matchVariant = matchVariantFor(best?.match.matchType);
  const matchLabel = matchLabelFor(best?.match.matchType, best?.match.score ?? 0);

  const serialList = Array.isArray(item.serial_units) ? item.serial_units : [];
  const serialNumbers = serialList
    .map((s) => (typeof s === 'string' ? s : s?.serial_number))
    .filter((s): s is string => !!s && s.length > 0);

  return (
    <div
      className={`rounded-lg border-2 p-4 ${matchCardBorderClass(best?.match.matchType)}`}
      data-testid={`accept-card-${item.id}`}
    >
      {/* Header de la card: badge de match + cantidad */}
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Badge variant={matchVariant} data-testid={`accept-card-badge-${item.id}`}>
          {matchLabel}
        </Badge>
        <span className="text-xs text-text-muted">
          {serialNumbers.length > 0
            ? `${serialNumbers.length} IMEI(s) / seriales`
            : 'Sin IMEIs adjuntos'}
        </span>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto_1fr] md:items-stretch">
        {/* IZQUIERDA: producto ORIGEN */}
        <OriginCard
          name={originName}
          sku={origin?.sku ?? null}
          barcode={origin?.barcode ?? null}
          trackingType={originTracking}
          quantity={Number(item.quantity ?? 0)}
          serialNumbers={serialNumbers}
        />

        {/* CENTRO: flecha */}
        <div className="flex items-center justify-center md:px-2">
          <div className="flex flex-col items-center gap-1 text-text-muted">
            <ArrowRight className="size-5 rotate-90 md:rotate-0" />
            <span className="text-[10px] uppercase tracking-wide">recibo</span>
          </div>
        </div>

        {/* DERECHA: producto DESTINO con select + IMEIs */}
        <div>
          <div className="mb-1 flex items-center gap-1 text-xs uppercase tracking-wide text-text-muted">
            <Package className="size-3.5" /> Producto destino
          </div>
          {loadingProd ? (
            <Skeleton className="h-9 w-full" />
          ) : (
            <select
              value={selectedProductId}
              onChange={(e) => onSelect(e.target.value)}
              className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
              required
              data-testid={`accept-product-${item.id}`}
            >
              <option value="">Selecciona producto destino...</option>
              {scored.map((p) => (
                <option key={p.id} value={p.id}>
                  {optionLabel(p.label, p.match.matchType)}
                </option>
              ))}
            </select>
          )}

          {originTracking === 'serialized' && (
            <div
              className="mt-2 rounded border border-border bg-bg/30 p-2"
              data-testid={`accept-imeis-${item.id}`}
            >
              <div className="text-[10px] uppercase tracking-wide text-text-muted">
                IMEIs / seriales que llegaran a tu stock
              </div>
              {serialNumbers.length === 0 ? (
                <p className="mt-1 text-[11px] text-warning">
                  La solicitud no incluye IMEIs/seriales. Si aceptas sin ellos,
                  las unidades quedaran sin identificar en tu stock.
                </p>
              ) : (
                <ul className="mt-1 flex flex-wrap gap-1">
                  {serialNumbers.map((sn, idx) => (
                    <li
                      key={`${item.id}-sn-${idx}`}
                      className="inline-flex items-center rounded bg-primary/10 px-1.5 py-0.5 font-mono text-[11px] text-primary"
                      data-testid={`accept-imei-${item.id}-${idx}`}
                    >
                      {sn}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function OriginCard({
  name,
  sku,
  barcode,
  trackingType,
  quantity,
  serialNumbers,
}: {
  name: string;
  sku: string | null;
  barcode: string | null;
  trackingType?: 'quantity' | 'serialized';
  quantity: number;
  serialNumbers: string[];
}) {
  return (
    <div className="rounded-md border border-border bg-bg/40 p-3">
      <div className="mb-1 flex items-center gap-1 text-xs uppercase tracking-wide text-text-muted">
        <Package className="size-3.5" /> Te piden
      </div>
      <div className="font-medium">{name}</div>
      <dl className="mt-2 space-y-0.5 text-xs">
        {sku && (
          <div className="flex gap-2">
            <dt className="w-14 text-text-muted">SKU</dt>
            <dd>
              <code className="rounded bg-bg px-1.5 py-0.5">{sku}</code>
            </dd>
          </div>
        )}
        {barcode && (
          <div className="flex gap-2">
            <dt className="w-14 text-text-muted">Barcode</dt>
            <dd>
              <code className="rounded bg-bg px-1.5 py-0.5">{barcode}</code>
            </dd>
          </div>
        )}
        <div className="flex gap-2">
          <dt className="w-14 text-text-muted">Cantidad</dt>
          <dd className="font-semibold">{quantity.toLocaleString()}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="w-14 text-text-muted">Control</dt>
          <dd>{trackingType === 'serialized' ? 'Serializado (IMEI)' : 'Cantidad'}</dd>
        </div>
      </dl>
      {trackingType === 'serialized' && serialNumbers.length > 0 && (
        <div className="mt-2 border-t border-border pt-2">
          <div className="text-[10px] uppercase tracking-wide text-text-muted">
            {serialNumbers.length} IMEI(s) que envian
          </div>
          <ul className="mt-1 flex flex-wrap gap-1">
            {serialNumbers.map((sn, idx) => (
              <li
                key={`origin-sn-${idx}`}
                className="inline-flex items-center rounded bg-warning/10 px-1.5 py-0.5 font-mono text-[11px] text-warning"
                data-testid={`accept-origin-imei-${idx}`}
              >
                {sn}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function matchVariantFor(matchType: MatchType | undefined): 'success' | 'warning' | 'default' {
  switch (matchType) {
    case 'sku':
    case 'barcode':
      return 'success';
    case 'name':
      return 'warning';
    default:
      return 'default';
  }
}

function matchCardBorderClass(matchType: MatchType | undefined): string {
  switch (matchType) {
    case 'sku':
    case 'barcode':
      return 'border-success/40 bg-success/5';
    case 'name':
      return 'border-warning/40 bg-warning/5';
    default:
      return 'border-border bg-surface';
  }
}

function matchLabelFor(matchType: MatchType | undefined, score: number): string {
  switch (matchType) {
    case 'sku':
      return `Match SKU (score ${score})`;
    case 'barcode':
      return `Match Barcode (score ${score})`;
    case 'name':
      return `Similar por nombre (score ${score})`;
    default:
      return 'Sin match automatico';
  }
}

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

function extractSkuFromLabel(label: string): string {
  const m = /\(([^)]+)\)\s*$/.exec(label);
  return m?.[1]?.trim() ?? '';
}
