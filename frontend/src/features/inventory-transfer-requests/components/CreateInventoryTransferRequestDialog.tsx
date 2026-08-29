/**
 * CreateInventoryTransferRequestDialog: dialog para crear una solicitud
 * de stock a OTRA empresa del grupo. Usa el hook useCreateTransferRequest.
 *
 * Campos:
 *   - destination_tenant_slug (Combobox dropdown de empresas hermanas)
 *     o destination_user_email (alternativa).
 *   - from_warehouse_id (almacen origen de MI empresa).
 *   - reason / reference / notes.
 *   - items: product_id + quantity.
 *
 * IMPORTANTE: Los IMEIs/seriales especificos NO se eligen aqui. Eso es
 * responsabilidad de la EMPRESA DESTINO al aceptar la solicitud (ella es
 * quien decide que IMEIs especificos de SU stock envia). Este dialog
 * solo pide producto + cantidad; el matching y captura de IMEIs ocurre
 * en AcceptInventoryTransferRequestDialog.
 */
import { useMemo, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Skeleton } from '@/components/ui/Skeleton';
import {
  useCreateTransferRequest,
  useSiblingCompanies,
} from '@/features/inventory-transfer-requests/api';
import { useWarehouses } from '@/features/inventory-center/api';
import type { Product } from '@/features/inventory-center/schemas';
import { useSessionStore } from '@/stores/session';
import { StoreTransferRequestSchema } from '../schemas';
import { TransferRequestProductSearch } from './TransferRequestProductSearch';
import { VariantSelect } from '@/features/transfers/components/VariantSelect';

interface CreateInventoryTransferRequestDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (id: number) => void;
  flowType?: 'stock_request' | 'shipment_offer';
}

interface ItemRow {
  product_id: string;
  product: Product | null;
  product_variant_id: string;
  quantity: string;
}

const EMPTY_ITEM: ItemRow = { product_id: '', product: null, product_variant_id: '', quantity: '' };

export function CreateInventoryTransferRequestDialog({
  open,
  onOpenChange,
  onCreated,
  flowType = 'stock_request',
}: CreateInventoryTransferRequestDialogProps) {
  const { data: warehouses = [], isLoading: loadingWh } = useWarehouses();
  const create = useCreateTransferRequest();

  const currentTenantId = useSessionStore.getState().tenant?.id;
  const currentParentId = useSessionStore.getState().tenant?.parent_id ?? null;
  const currentIsGroup = useSessionStore.getState().tenant?.is_group ?? false;

  const { data: siblings = [], isLoading: loadingSiblings } = useSiblingCompanies({
    currentTenantId,
    parentId: currentParentId,
    isGroup: currentIsGroup,
  });

  const [destinationSlug, setDestinationSlug] = useState('');
  const [destinationEmail, setDestinationEmail] = useState('');
  const [fromWarehouseId, setFromWarehouseId] = useState('');
  const [reason, setReason] = useState('');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [items, setItems] = useState<ItemRow[]>([{ ...EMPTY_ITEM }]);
  const [submitting, setSubmitting] = useState(false);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const siblingOptions = useMemo(
    () =>
      siblings.map((s) => ({
        value: s.slug,
        label: s.name,
        hint: s.slug,
      })),
    [siblings],
  );

  const selectedSibling = useMemo(
    () => siblings.find((s) => s.slug === destinationSlug),
    [siblings, destinationSlug],
  );

  function reset() {
    setDestinationSlug('');
    setDestinationEmail('');
    setFromWarehouseId('');
    setReason('');
    setReference('');
    setNotes('');
    setItems([{ ...EMPTY_ITEM }]);
    setFormErrors({});
  }

  if (!open) return null;

  function addItem() {
    setItems((arr) => [...arr, { ...EMPTY_ITEM }]);
  }

  function removeItem(idx: number) {
    setItems((arr) => (arr.length === 1 ? arr : arr.filter((_, i) => i !== idx)));
  }

  function updateItem(idx: number, patch: Partial<ItemRow>) {
    setItems((arr) => arr.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormErrors({});

    const payload = {
      flow_type: flowType,
      destination_tenant_slug: destinationSlug.trim() || undefined,
      destination_user_email: destinationEmail.trim() || undefined,
      from_warehouse_id: Number(fromWarehouseId) || 0,
      reason: reason.trim() || undefined,
      reference: reference.trim() || undefined,
      notes: notes.trim() || undefined,
      items: items
        .filter((it) => it.product_id && it.quantity)
        .map((it) => ({
          product_id: Number(it.product_id),
          product_variant_id: it.product_variant_id ? Number(it.product_variant_id) : undefined,
          quantity: Number(it.quantity),
        })),
    };

    const parsed = StoreTransferRequestSchema.safeParse(payload);
    if (!parsed.success) {
      const errs: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const key = issue.path.join('.') || 'form';
        errs[key] ??= issue.message;
      }
      setFormErrors(errs);
      return;
    }

    setSubmitting(true);
    try {
      const created = await create.mutateAsync(parsed.data);
      toast.success(
        flowType === 'shipment_offer'
          ? 'Propuesta de envío enviada para aprobación.'
          : 'Solicitud de stock enviada para aprobación.',
      );
      onCreated?.(created.id);
      reset();
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al enviar la solicitud.');
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
      aria-labelledby="create-req-title"
    >
      <div
        className="border-border bg-surface max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-lg border p-5"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="create-req-title" className="text-lg font-semibold">
          {flowType === 'shipment_offer'
            ? 'Proponer envío a otra empresa'
            : 'Solicitar stock a otra empresa'}
        </h2>
        <p className="text-text-muted mt-1 text-sm">
          {flowType === 'shipment_offer'
            ? 'Selecciona mercancía de tu almacén. La empresa receptora inspeccionará y aprobará el ingreso antes del despacho.'
            : 'Solicita mercancía a otra empresa del grupo. La empresa proveedora aprobará y seleccionará los IMEIs o seriales que envía.'}
        </p>
        <form onSubmit={handleSubmit} className="mt-4 space-y-3" data-testid="create-form">
          <fieldset className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
              <Label htmlFor="dest-company">
                {flowType === 'shipment_offer' ? 'Empresa receptora' : 'Empresa proveedora'} (del
                grupo)
              </Label>
              {loadingSiblings ? (
                <Skeleton className="h-9 w-full" />
              ) : (
                <select
                  id="dest-company"
                  value={destinationSlug}
                  onChange={(e) => setDestinationSlug(e.target.value)}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                  required
                  disabled={!!destinationEmail}
                  data-testid="dest-company"
                >
                  <option value="">Selecciona una empresa hermana...</option>
                  {siblingOptions.map((s) => (
                    <option key={s.value} value={s.value}>
                      {s.label} ({s.hint})
                    </option>
                  ))}
                </select>
              )}
              {siblingOptions.length === 0 && !loadingSiblings && (
                <p className="text-warning mt-1 text-xs">
                  Tu empresa no pertenece a un grupo con otras empresas. Usa el email de destino.
                </p>
              )}
              {selectedSibling && (
                <p className="text-text-muted mt-1 text-xs" data-testid="dest-preview">
                  {flowType === 'shipment_offer' ? 'Proponer envío a' : 'Solicitar a'}:{' '}
                  <strong>{selectedSibling.name}</strong> (slug: {selectedSibling.slug})
                </p>
              )}
              {formErrors.destination_tenant_slug && (
                <p className="text-danger mt-1 text-xs">{formErrors.destination_tenant_slug}</p>
              )}
            </div>
            <div>
              <Label htmlFor="dest-email">o Email usuario destino (alternativa)</Label>
              <Input
                id="dest-email"
                type="email"
                value={destinationEmail}
                onChange={(e) => setDestinationEmail(e.target.value)}
                placeholder="usuario@otra-empresa.com"
                disabled={!!destinationSlug}
              />
            </div>
          </fieldset>

          <fieldset className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div>
              <Label htmlFor="from-wh">
                {flowType === 'shipment_offer' ? 'Tu almacén de salida' : 'Tu almacén receptor'}
              </Label>
              {loadingWh ? (
                <Skeleton className="h-9 w-full" />
              ) : (
                <select
                  id="from-wh"
                  value={fromWarehouseId}
                  onChange={(e) => setFromWarehouseId(e.target.value)}
                  className="border-border-strong bg-surface mt-1 w-full rounded border px-3 py-2 text-sm"
                  required
                >
                  <option value="">Selecciona...</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.code}
                    </option>
                  ))}
                </select>
              )}
              {formErrors.from_warehouse_id && (
                <p className="text-danger mt-1 text-xs">{formErrors.from_warehouse_id}</p>
              )}
            </div>
            <div className="md:col-span-2">
              <Label htmlFor="reason">Motivo</Label>
              <Input
                id="reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Reposicion, faltante, etc."
                maxLength={255}
              />
            </div>
          </fieldset>

          <div>
            <Label>
              {flowType === 'shipment_offer' ? 'Items que propones enviar' : 'Items solicitados'}
            </Label>
            <div className="mt-1 space-y-2">
              {items.map((it, idx) => (
                <div
                  key={idx}
                  className="border-border bg-bg/20 rounded border p-2"
                  data-testid={`item-row-${idx}`}
                >
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_120px_auto]">
                    <div>
                      <label className="text-text-muted text-[10px] tracking-wide uppercase">
                        Producto
                      </label>
                      <TransferRequestProductSearch
                        index={idx}
                        value={it.product_id}
                        selectedProduct={it.product}
                        onChange={(productId, product) =>
                          updateItem(idx, { product_id: productId, product, product_variant_id: '' })
                        }
                        invalid={Boolean(formErrors[`items.${idx}.product_id`])}
                      />
                      {it.product && it.product.tracking_type !== 'serialized' && (
                        <div className="pt-1">
                          <VariantSelect
                            productId={it.product.id}
                            warehouseId={fromWarehouseId ? Number(fromWarehouseId) : undefined}
                            value={it.product_variant_id ? Number(it.product_variant_id) : null}
                            onChange={(variantId) =>
                              updateItem(idx, {
                                product_variant_id: variantId ? String(variantId) : '',
                              })
                            }
                            testIdPrefix={`req-row-${idx}-variant`}
                          />
                        </div>
                      )}
                    </div>
                    <div>
                      <label className="text-text-muted text-[10px] tracking-wide uppercase">
                        Cantidad
                      </label>
                      <input
                        type="number"
                        min={0}
                        step="0.01"
                        value={it.quantity}
                        onChange={(e) => updateItem(idx, { quantity: e.target.value })}
                        className="border-border-strong bg-surface w-full rounded border px-2 py-1 text-right text-sm"
                        required
                        data-testid={`item-qty-${idx}`}
                      />
                    </div>
                    <div className="flex items-end">
                      {items.length > 1 && (
                        <Button
                          type="button"
                          size="icon-sm"
                          variant="ghost"
                          onClick={() => removeItem(idx)}
                          aria-label={`Eliminar linea ${idx + 1}`}
                        >
                          <Trash2 className="text-danger size-4" />
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
            <div className="mt-2 flex items-center justify-between">
              <Button
                type="button"
                size="sm"
                variant="outline"
                leftIcon={<Plus className="size-3.5" />}
                onClick={addItem}
                data-testid="add-item"
              >
                Agregar linea
              </Button>
              {formErrors.items && <p className="text-danger text-xs">{formErrors.items}</p>}
            </div>
          </div>

          <fieldset className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
              <Label htmlFor="reference">Referencia</Label>
              <Input
                id="reference"
                value={reference}
                onChange={(e) => setReference(e.target.value)}
                maxLength={150}
              />
            </div>
            <div>
              <Label htmlFor="notes">Notas</Label>
              <Input
                id="notes"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                maxLength={1000}
              />
            </div>
          </fieldset>

          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancelar
            </Button>
            <Button type="submit" loading={submitting} data-testid="submit-create">
              {flowType === 'shipment_offer' ? 'Enviar propuesta' : 'Enviar solicitud'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
