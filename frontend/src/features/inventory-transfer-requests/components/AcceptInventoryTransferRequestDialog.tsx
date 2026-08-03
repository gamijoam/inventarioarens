import { useEffect, useState } from 'react';
import { ArrowRight, Info, PackageCheck, Send, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import { useAcceptTransferRequest } from '@/features/inventory-transfer-requests/api';
import { ImeiScanner } from '@/features/transfers/components/ImeiScanner';
import { useWarehouses } from '@/features/inventory-center/api';
import type { Product } from '@/features/inventory-center/schemas';
import { scoreMatch, type MatchType } from '../scoreMatch';
import type { TransferRequest } from '../schemas';
import { TransferRequestProductSearch } from './TransferRequestProductSearch';

interface AcceptInventoryTransferRequestDialogProps {
  request: TransferRequest;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAccepted?: (id: number) => void;
}

interface ItemMapping {
  destinationProductId: string;
  destinationProduct: Product | null;
  serialUnits: string[];
}

const emptyMapping = (): ItemMapping => ({
  destinationProductId: '',
  destinationProduct: null,
  serialUnits: [],
});

export function AcceptInventoryTransferRequestDialog({
  request,
  open,
  onOpenChange,
  onAccepted,
}: AcceptInventoryTransferRequestDialogProps) {
  const { data: warehouses = [], isLoading: loadingWarehouses } = useWarehouses();
  const accept = useAcceptTransferRequest();
  const isShipmentOffer = request.flow_type === 'shipment_offer';

  const [warehouseId, setWarehouseId] = useState('');
  const [responseNotes, setResponseNotes] = useState('');
  const [logisticsMode, setLogisticsMode] = useState(false);
  const [mapping, setMapping] = useState<Record<number, ItemMapping>>({});
  const [submitting, setSubmitting] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const collectsSerialsNow = !isShipmentOffer && !logisticsMode;

  useEffect(() => {
    if (!open) return;

    setWarehouseId('');
    setResponseNotes('');
    setLogisticsMode(request.flow_type === 'shipment_offer');
    setFormErrors({});
    setMapping(
      Object.fromEntries((request.items ?? []).map((item) => [item.id, emptyMapping()])),
    );
  }, [open, request]);

  if (!open) return null;

  function itemMapping(itemId: number): ItemMapping {
    return mapping[itemId] ?? emptyMapping();
  }

  function updateItemMapping(itemId: number, patch: Partial<ItemMapping>) {
    setMapping((current) => ({
      ...current,
      [itemId]: { ...(current[itemId] ?? emptyMapping()), ...patch },
    }));
    setFormErrors((current) => {
      const next = { ...current };
      delete next[`items.${itemId}.destination_product_id`];
      delete next[`items.${itemId}.serial_units`];
      return next;
    });
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    if (!warehouseId) {
      toast.error(
        isShipmentOffer ? 'Selecciona el almacén receptor.' : 'Selecciona el almacén de salida.',
      );
      return;
    }
    if (!request.items?.length) {
      toast.error('La solicitud no tiene productos.');
      return;
    }

    for (const item of request.items) {
      const current = itemMapping(item.id);
      if (!current.destinationProductId) {
        setFormErrors({
          [`items.${item.id}.destination_product_id`]: isShipmentOffer
            ? 'Relaciona el producto con tu catálogo.'
            : 'Selecciona el producto que enviarás.',
        });
        toast.error('Falta relacionar uno de los productos.');
        return;
      }

      if (current.destinationProduct?.tracking_type === 'serialized' && collectsSerialsNow) {
        const expected = Number(item.quantity);
        const selected = current.serialUnits.filter((serial) => serial.trim()).length;
        if (selected !== expected) {
          setFormErrors({
            [`items.${item.id}.serial_units`]: `Selecciona ${expected} IMEI(s) o serial(es). Llevas ${selected}.`,
          });
          toast.error('Faltan IMEIs o seriales de los productos que enviarás.');
          return;
        }
      }
    }

    const items = request.items.map((item) => {
      const current = itemMapping(item.id);
      const payload: {
        request_item_id: number;
        destination_product_id: number;
        serial_units?: { serial_type: 'imei'; serial_number: string }[];
      } = {
        request_item_id: item.id,
        destination_product_id: Number(current.destinationProductId),
      };

      if (
        collectsSerialsNow &&
        current.destinationProduct?.tracking_type === 'serialized' &&
        current.serialUnits.length
      ) {
        payload.serial_units = current.serialUnits
          .filter((serial) => serial.trim())
          .map((serial) => ({ serial_type: 'imei', serial_number: serial }));
      }

      return payload;
    });

    setSubmitting(true);
    try {
      const accepted = await accept.mutateAsync({
        id: request.id,
        values: {
          destination_warehouse_id: Number(warehouseId),
          response_notes: responseNotes.trim() || null,
          items,
          logistics_mode: isShipmentOffer || logisticsMode,
        },
      });
      toast.success(
        isShipmentOffer
          ? 'Propuesta aceptada. La empresa remitente ya puede preparar la guía.'
          : logisticsMode
            ? 'Solicitud aceptada. Ya puedes preparar la guía de envío.'
            : 'Solicitud aceptada. Stock transferido.',
      );
      onAccepted?.(accepted.id);
      onOpenChange(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo aceptar la solicitud.');
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
        className="border-border bg-surface max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-lg border"
        onClick={(event) => event.stopPropagation()}
      >
        <header className="border-border bg-surface sticky top-0 z-10 flex items-start justify-between gap-4 border-b px-5 py-4">
          <div className="flex min-w-0 items-start gap-3">
            <div className="bg-primary/10 text-primary mt-0.5 rounded p-2">
              {isShipmentOffer ? <PackageCheck className="size-5" /> : <Send className="size-5" />}
            </div>
            <div>
              <h2 id="accept-req-title" className="text-lg font-semibold">
                {isShipmentOffer ? 'Revisar propuesta de envío' : 'Atender solicitud de stock'}{' '}
                {request.document_number ?? `#${request.id}`}
              </h2>
              <p className="text-text-muted mt-0.5 text-xs">
                {isShipmentOffer
                  ? 'Relaciona lo que recibirás con tu catálogo. La empresa remitente seleccionará las unidades que despacha.'
                  : 'Selecciona los productos de tu catálogo que enviarás para atender la solicitud.'}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="text-text-muted hover:bg-bg hover:text-text-primary rounded p-1"
            aria-label="Cerrar"
          >
            <X className="size-4" />
          </button>
        </header>

        <form onSubmit={handleSubmit} className="space-y-4 p-5">
          {isShipmentOffer ? (
            <div className="border-info/30 bg-info/5 text-text-secondary flex gap-3 rounded border p-3 text-sm">
              <Info className="text-info mt-0.5 size-4 shrink-0" />
              <p>
                Estás recibiendo una propuesta. Aquí no eliges IMEIs: los selecciona la empresa que
                envía al preparar la guía y tú los verificas durante la recepción.
              </p>
            </div>
          ) : (
            <label className="border-border bg-bg/30 flex cursor-pointer items-start gap-3 rounded border p-3">
              <input
                type="checkbox"
                checked={logisticsMode}
                onChange={(event) => setLogisticsMode(event.target.checked)}
                className="mt-1"
              />
              <span>
                <span className="block text-sm font-medium">Usar guía logística</span>
                <span className="text-text-muted block text-xs">
                  Permite preparar cantidades e IMEIs, despachar, entregar y recibir. Si la desactivas,
                  el traslado se aplica inmediatamente al aceptar.
                </span>
              </span>
            </label>
          )}

          <div>
            <Label htmlFor="accept-warehouse">
              {isShipmentOffer ? 'Almacén de recepción' : 'Almacén de salida'}
            </Label>
            {loadingWarehouses ? (
              <Skeleton className="h-9 w-full" />
            ) : (
              <select
                id="accept-warehouse"
                value={warehouseId}
                onChange={(event) => setWarehouseId(event.target.value)}
                className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                required
              >
                <option value="">
                  {isShipmentOffer
                    ? 'Selecciona dónde recibirás...'
                    : 'Selecciona desde dónde enviarás...'}
                </option>
                {warehouses.map((warehouse) => (
                  <option key={warehouse.id} value={warehouse.id}>
                    {warehouse.code}
                  </option>
                ))}
              </select>
            )}
          </div>

          <div className="space-y-3">
            <Label>{isShipmentOffer ? 'Productos ofrecidos' : 'Productos solicitados'}</Label>
            {(request.items ?? []).map((item) => (
              <ItemCard
                key={item.id}
                item={item}
                mapping={itemMapping(item.id)}
                onChange={(patch) => updateItemMapping(item.id, patch)}
                warehouseId={warehouseId ? Number(warehouseId) : null}
                flowType={request.flow_type}
                collectsSerials={collectsSerialsNow}
                error={
                  formErrors[`items.${item.id}.destination_product_id`] ??
                  formErrors[`items.${item.id}.serial_units`]
                }
              />
            ))}
          </div>

          <div>
            <Label htmlFor="response-notes">
              {isShipmentOffer
                ? 'Notas para la empresa remitente (opcional)'
                : 'Notas para la empresa solicitante (opcional)'}
            </Label>
            <textarea
              id="response-notes"
              value={responseNotes}
              onChange={(event) => setResponseNotes(event.target.value)}
              maxLength={1000}
              rows={2}
              className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
              placeholder={
                isShipmentOffer
                  ? 'Observaciones sobre la recepción...'
                  : 'Observaciones sobre el envío...'
              }
            />
          </div>

          <div className="border-border flex justify-end gap-2 border-t pt-3">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancelar
            </Button>
            <Button type="submit" loading={submitting} data-testid="submit-accept">
              {isShipmentOffer
                ? 'Aceptar propuesta y crear guía'
                : logisticsMode
                  ? 'Aceptar y crear guía'
                  : 'Aceptar y transferir ahora'}
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
  warehouseId: number | null;
  flowType?: TransferRequest['flow_type'];
  collectsSerials: boolean;
  error?: string;
}

function ItemCard({
  item,
  mapping,
  onChange,
  warehouseId,
  flowType,
  collectsSerials,
  error,
}: ItemCardProps) {
  const origin = item.origin_product;
  const originName = origin?.name ?? `Producto #${item.origin_product_id}`;
  const quantity = Number(item.quantity ?? 0);
  const destinationProduct = mapping.destinationProduct;
  const isSerialized = destinationProduct?.tracking_type === 'serialized';
  const isShipmentOffer = flowType === 'shipment_offer';
  const originLite = origin
    ? { name: originName, sku: origin.sku ?? null, barcode: origin.barcode ?? null }
    : null;
  const selectedMatch = destinationProduct
    ? scoreMatch(originLite, destinationProduct)
    : { score: 0, matchType: 'none' as const };

  return (
    <div
      className={`rounded-lg border-2 p-4 ${matchCardBorderClass(selectedMatch.matchType)}`}
      data-testid={`accept-card-${item.id}`}
    >
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Badge variant={matchVariantFor(selectedMatch.matchType)} data-testid={`accept-card-badge-${item.id}`}>
          {matchLabelFor(selectedMatch.matchType, selectedMatch.score)}
        </Badge>
        <span className="text-text-muted text-xs">
          {isSerialized && collectsSerials && mapping.serialUnits.length
            ? `${mapping.serialUnits.length} IMEI(s) elegido(s) / ${quantity}`
            : isSerialized
              ? collectsSerials
                ? 'Falta seleccionar unidades'
                : isShipmentOffer
                  ? 'IMEIs definidos por quien envía'
                  : 'IMEIs definidos al preparar la guía'
              : 'Control por cantidad'}
        </span>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_auto_1fr] md:items-stretch">
        <div className="border-border bg-bg/40 rounded-md border p-3">
          <div className="text-text-muted mb-1 text-xs tracking-wide uppercase">
            {isShipmentOffer ? 'Te ofrecen' : 'Te solicitan'}
          </div>
          <div className="font-medium">{originName}</div>
          <dl className="mt-2 space-y-0.5 text-xs">
            {origin?.sku && <Detail label="SKU" value={origin.sku} code />}
            {origin?.barcode && <Detail label="Código" value={origin.barcode} code />}
            <Detail label="Cantidad" value={quantity.toLocaleString()} strong />
            <Detail
              label="Control"
              value={origin?.tracking_type === 'serialized' ? 'Serializado (IMEI)' : 'Cantidad'}
            />
          </dl>
        </div>

        <div className="flex items-center justify-center md:px-2">
          <div className="text-text-muted flex flex-col items-center gap-1">
            <ArrowRight className="size-5 rotate-90 md:rotate-0" />
            <span className="text-[10px] tracking-wide uppercase">
              {isShipmentOffer ? 'recibirás' : 'enviarás'}
            </span>
          </div>
        </div>

        <div>
          <div className="text-text-muted mb-1 text-xs tracking-wide uppercase">
            {isShipmentOffer ? 'Producto en tu catálogo' : 'Producto que enviarás'}
          </div>
          <TransferRequestProductSearch
            index={item.id}
            value={mapping.destinationProductId}
            selectedProduct={destinationProduct}
            onChange={(productId, product) =>
              onChange({
                destinationProductId: productId,
                destinationProduct: product,
                serialUnits: [],
              })
            }
            initialQuery={origin?.sku ?? origin?.barcode ?? originName}
            trackingType={origin?.tracking_type}
            matchSource={originLite}
            autoSelectExact
            invalid={Boolean(error)}
          />

          {isSerialized && collectsSerials && mapping.destinationProductId && warehouseId && (
            <div className="mt-2" data-testid={`accept-imeis-${item.id}`}>
              <ImeiScanner
                productId={Number(mapping.destinationProductId)}
                warehouseId={warehouseId}
                serialType="imei"
                selected={mapping.serialUnits}
                onChange={(serials) => onChange({ serialUnits: serials.slice(0, Math.max(1, quantity)) })}
                max={Math.max(1, quantity)}
                dataTestIdPrefix={`accept-imei-${item.id}`}
              />
            </div>
          )}
          {isSerialized && collectsSerials && !mapping.destinationProductId && (
            <p className="text-text-muted mt-1 text-[11px]">
              Selecciona primero el producto que enviarás para ver sus IMEIs disponibles.
            </p>
          )}
          {isSerialized && collectsSerials && mapping.destinationProductId && !warehouseId && (
            <p className="text-text-muted mt-1 text-[11px]">
              Selecciona primero el almacén de salida para ver sus IMEIs disponibles.
            </p>
          )}
          {isSerialized && !collectsSerials && mapping.destinationProductId && (
            <p className="border-info/20 bg-info/5 text-text-secondary mt-2 rounded border px-3 py-2 text-xs">
              {isShipmentOffer
                ? 'La empresa remitente seleccionará los IMEIs al preparar la guía. Los verificarás cuando recibas.'
                : 'Los IMEIs se seleccionarán en Preparar guía, antes del despacho.'}
            </p>
          )}
        </div>
      </div>

      {error && <p className="text-danger mt-2 text-xs">{error}</p>}
    </div>
  );
}

function Detail({
  label,
  value,
  code = false,
  strong = false,
}: {
  label: string;
  value: string;
  code?: boolean;
  strong?: boolean;
}) {
  return (
    <div className="flex gap-2">
      <dt className="text-text-muted w-16">{label}</dt>
      <dd className={strong ? 'font-semibold' : undefined}>
        {code ? <code className="bg-bg rounded px-1.5 py-0.5">{value}</code> : value}
      </dd>
    </div>
  );
}

function matchVariantFor(matchType: MatchType | undefined): 'success' | 'warning' | 'default' {
  if (matchType === 'sku' || matchType === 'barcode') return 'success';
  if (matchType === 'name') return 'warning';
  return 'default';
}

function matchCardBorderClass(matchType: MatchType | undefined): string {
  if (matchType === 'sku' || matchType === 'barcode') return 'border-success/40 bg-success/5';
  if (matchType === 'name') return 'border-warning/40 bg-warning/5';
  return 'border-border bg-surface';
}

function matchLabelFor(matchType: MatchType | undefined, score: number): string {
  if (matchType === 'sku') return `Coincidencia por SKU (${score})`;
  if (matchType === 'barcode') return `Coincidencia por código (${score})`;
  if (matchType === 'name') return `Similar por nombre (${score})`;
  return 'Sin coincidencia automática';
}
