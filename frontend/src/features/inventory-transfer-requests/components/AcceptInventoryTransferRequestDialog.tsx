/**
 * AcceptInventoryTransferRequestDialog: dialog para que la empresa destino
 * ACEPTE una solicitud. Aqui la empresa destino decide:
 *   1. Que producto de SU catalogo corresponde a cada item solicitado.
 *   2. Para items serializados: QUE IMEIs/seriales especificos de SU stock envia.
 *
 * Layout: cada item se renderiza como una CARD HORIZONTAL con 3 zonas:
 *   - IZQUIERDA: producto ORIGEN (lo que me piden).
 *   - CENTRO: flecha con label 'recibo'.
 *   - DERECHA: SELECT para producto destino (con scoring) + ImeiScanner
 *     para serializados.
 *
 * Card completa con borde de color segun tipo de match:
 *   - verde: SKU/Barcode exacto.
 *   - amarillo: Similar (nombre).
 *   - gris: sin match.
 */
import { useEffect, useMemo, useState } from 'react';
import { ArrowRight, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { useAcceptTransferRequest } from '@/features/inventory-transfer-requests/api';
import { useProductsForTransfer } from '@/features/transfers/api';
import { ImeiScanner } from '@/features/transfers/components/ImeiScanner';
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

interface ItemMapping {
  destinationProductId: string;
  /** IMEIs/seriales del stock del destino que se envian. */
  serialUnits: string[];
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
  const [mapping, setMapping] = useState<Record<number, ItemMapping>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setDestinationWarehouseId('');
    setResponseNotes('');
    const initial: Record<number, ItemMapping> = {};
    for (const item of request.items ?? []) {
      initial[item.id] = { destinationProductId: '', serialUnits: [] };
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

  function getItemMapping(itemId: number): ItemMapping {
    return mapping[itemId] ?? { destinationProductId: '', serialUnits: [] };
  }

  function setItemMapping(itemId: number, patch: Partial<ItemMapping>) {
    setMapping((m) => ({
      ...m,
      [itemId]: { ...(m[itemId] ?? { destinationProductId: '', serialUnits: [] }), ...patch },
    }));
  }

  function getItemTracking(productId: string): 'quantity' | 'serialized' | undefined {
    const p = products.find((x) => String(x.id) === productId);
    return p?.tracking_type;
  }

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

    // Pre-validacion: cada item debe tener destination_product_id.
    // Si es serializado, tambien debe tener la cantidad de IMEIs.
    for (const it of request.items) {
      const m = getItemMapping(it.id);
      if (!m.destinationProductId) {
        setFormErrors({ [`items.${it.id}.destination_product_id`]: 'Selecciona un producto destino.' });
        toast.error('Falta mapear un producto destino.');
        return;
      }
      const tracking = getItemTracking(m.destinationProductId);
      if (tracking === 'serialized') {
        const qty = Number(it.quantity);
        const filled = m.serialUnits.filter((s) => s.trim().length > 0).length;
        if (qty > 0 && filled !== qty) {
          setFormErrors({ [`items.${it.id}.serial_units`]: `Debes seleccionar ${qty} IMEI(s)/seriale(s) (llevas ${filled}).` });
          toast.error('Faltan IMEIs/seriales en items serializados.');
          return;
        }
      }
    }

    const itemsPayload = request.items.map((it) => {
      const m = getItemMapping(it.id);
      const tracking = getItemTracking(m.destinationProductId);
      const item: {
        request_item_id: number;
        destination_product_id: number;
        serial_units?: Array<{ serial_type: 'imei' | 'serial'; serial_number: string }>;
      } = {
        request_item_id: it.id,
        destination_product_id: Number(m.destinationProductId),
      };
      if (tracking === 'serialized' && m.serialUnits.length > 0) {
        item.serial_units = m.serialUnits
          .filter((s) => s.trim().length > 0)
          .map((sn) => ({ serial_type: 'imei', serial_number: sn }));
      }
      return item;
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
              Mapea cada item a un producto de tu catalogo y, si es serializado, elige los IMEIs/seriales que envias.
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
              <ItemCard
                key={it.id}
                item={it}
                mapping={getItemMapping(it.id)}
                onChange={(patch) => setItemMapping(it.id, patch)}
                products={products}
                productOptions={productOptions}
                loadingProd={loadingProd}
                destinationWarehouseId={Number(destinationWarehouseId) || null}
                error={formErrors[`items.${it.id}.destination_product_id`] || formErrors[`items.${it.id}.serial_units`]}
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

interface ItemCardProps {
  item: NonNullable<TransferRequest['items']>[number];
  mapping: ItemMapping;
  onChange: (patch: Partial<ItemMapping>) => void;
  products: Product[];
  productOptions: Array<{ id: string; label: string; tracking?: 'quantity' | 'serialized' }>;
  loadingProd: boolean;
  destinationWarehouseId: number | null;
  error?: string;
}

function ItemCard({
  item,
  mapping,
  onChange,
  products,
  productOptions,
  loadingProd,
  destinationWarehouseId,
  error,
}: ItemCardProps) {
  const origin = item.origin_product;
  const originName = origin?.name ?? `Producto #${item.origin_product_id}`;
  const originTracking = origin?.tracking_type;
  const qtyNum = Number(item.quantity ?? 0);
  const compatibleProducts = productOptions.filter(
    (p) => !originTracking || p.tracking === originTracking,
  );
  const destinationProduct = products.find((x) => String(x.id) === mapping.destinationProductId);
  const destinationTracking = destinationProduct?.tracking_type;

  const originLite = origin
    ? { name: originName, sku: origin.sku ?? null, barcode: origin.barcode ?? null }
    : null;

  const scored = compatibleProducts
    .map((p) => {
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

  const isSerialized = destinationTracking === 'serialized';

  return (
    <div
      className={`rounded-lg border-2 p-4 ${matchCardBorderClass(best?.match.matchType)}`}
      data-testid={`accept-card-${item.id}`}
    >
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Badge variant={matchVariant} data-testid={`accept-card-badge-${item.id}`}>
          {matchLabel}
        </Badge>
        <span className="text-xs text-text-muted">
          {isSerialized && mapping.serialUnits.length > 0
            ? `${mapping.serialUnits.length} IMEI(s) elegido(s) / ${qtyNum}`
            : 'Sin IMEIs adjuntos'}
        </span>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto_1fr] md:items-stretch">
        {/* IZQUIERDA: producto ORIGEN */}
        <div className="rounded-md border border-border bg-bg/40 p-3">
          <div className="mb-1 flex items-center gap-1 text-xs uppercase tracking-wide text-text-muted">
            Te piden
          </div>
          <div className="font-medium">{originName}</div>
          <dl className="mt-2 space-y-0.5 text-xs">
            {origin?.sku && (
              <div className="flex gap-2">
                <dt className="w-14 text-text-muted">SKU</dt>
                <dd>
                  <code className="rounded bg-bg px-1.5 py-0.5">{origin.sku}</code>
                </dd>
              </div>
            )}
            {origin?.barcode && (
              <div className="flex gap-2">
                <dt className="w-14 text-text-muted">Barcode</dt>
                <dd>
                  <code className="rounded bg-bg px-1.5 py-0.5">{origin.barcode}</code>
                </dd>
              </div>
            )}
            <div className="flex gap-2">
              <dt className="w-14 text-text-muted">Cantidad</dt>
              <dd className="font-semibold">{qtyNum.toLocaleString()}</dd>
            </div>
            <div className="flex gap-2">
              <dt className="w-14 text-text-muted">Control</dt>
              <dd>{originTracking === 'serialized' ? 'Serializado (IMEI)' : 'Cantidad'}</dd>
            </div>
          </dl>
        </div>

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
            Producto destino
          </div>
          {loadingProd ? (
            <Skeleton className="h-9 w-full" />
          ) : (
            <select
              value={mapping.destinationProductId}
              onChange={(e) => onChange({ destinationProductId: e.target.value })}
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

          {best && (
            <div
              className="mt-1 text-[11px] text-text-muted"
              data-testid={`accept-hint-${item.id}`}
            >
              Sugerencia: <strong>{best.label.replace(/^\[[^\]]+\]\s*/, '')}</strong>
            </div>
          )}

          {isSerialized && mapping.destinationProductId && destinationWarehouseId && (
            <div className="mt-2" data-testid={`accept-imeis-${item.id}`}>
              <ImeiScanner
                productId={Number(mapping.destinationProductId)}
                warehouseId={destinationWarehouseId}
                serialType="imei"
                selected={mapping.serialUnits}
                onChange={(sel) => onChange({ serialUnits: sel.slice(0, Math.max(1, qtyNum)) })}
                max={Math.max(1, qtyNum)}
                dataTestIdPrefix={`accept-imei-${item.id}`}
              />
            </div>
          )}
          {isSerialized && !mapping.destinationProductId && (
            <p className="mt-1 text-[11px] text-text-muted">
              Selecciona primero un producto destino para ver los IMEIs disponibles.
            </p>
          )}
          {isSerialized && mapping.destinationProductId && !destinationWarehouseId && (
            <p className="mt-1 text-[11px] text-text-muted">
              Selecciona primero un almacen destino para ver los IMEIs disponibles.
            </p>
          )}
        </div>
      </div>

      {error && <p className="mt-2 text-xs text-danger">{error}</p>}
    </div>
  );
}

function extractSkuFromLabel(label: string): string {
  const m = /\(([^)]+)\)\s*$/.exec(label);
  return m?.[1]?.trim() ?? '';
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
