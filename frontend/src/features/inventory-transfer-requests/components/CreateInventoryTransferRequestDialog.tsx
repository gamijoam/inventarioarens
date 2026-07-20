/**
 * CreateInventoryTransferRequestDialog: dialog para crear una solicitud
 * de stock a OTRA empresa del grupo. Usa el hook useCreateTransferRequest.
 *
 * Campos:
 *   - destination_tenant_id | destination_tenant_slug (Combobox dropdown)
 *     | destination_user_email (fallback opcional si conoce el email).
 *   - from_warehouse_id (almacen origen de MI empresa).
 *   - reason / reference / notes.
 *   - items: product_id + quantity (opcionalmente product_unit_ids).
 *
 * El dropdown principal lista las empresas hermanas del grupo (consume
 * useSiblingCompanies) y al seleccionar setea destination_tenant_slug
 * automaticamente. El usuario puede colapsar el dropdown y usar email
 * directo si lo prefiere.
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
import { useProductsForTransfer } from '@/features/transfers/api';
import { useWarehouses } from '@/features/inventory-center/api';
import type { Product } from '@/features/inventory-center/schemas';
import { useSessionStore } from '@/stores/session';
import { StoreTransferRequestSchema, type StoreTransferRequestValues } from '../schemas';

interface CreateInventoryTransferRequestDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (id: number) => void;
}

interface ItemRow {
  product_id: string;
  quantity: string;
}

const EMPTY_ITEM: ItemRow = { product_id: '', quantity: '' };

export function CreateInventoryTransferRequestDialog({
  open,
  onOpenChange,
  onCreated,
}: CreateInventoryTransferRequestDialogProps) {
  const { data: warehouses = [], isLoading: loadingWh } = useWarehouses();
  const { data: products = [], isLoading: loadingProd } = useProductsForTransfer();
  const create = useCreateTransferRequest();

  // Tenant actual (lectura no-reactiva via getState para evitar re-renders
  // innecesarios; los valores se usan dentro del dialog que ya esta montado).
  const currentTenantId = useSessionStore.getState().tenant?.id;
  const currentParentId = useSessionStore.getState().tenant?.parent_id ?? null;
  const currentIsGroup = useSessionStore.getState().tenant?.is_group ?? false;

  // Empresas hermanas del grupo (excluye la propia).
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

  const productOptions = useMemo(
    () =>
      products.map((p: Product) => ({
        id: String(p.id),
        label: `${p.name}${p.sku ? ` (${p.sku})` : ''}`,
        tracking: p.tracking_type,
      })),
    [products],
  );

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
          quantity: Number(it.quantity),
        })),
    };

    const parsed = StoreTransferRequestSchema.safeParse(payload);
    if (!parsed.success) {
      const errs: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const key = issue.path.join('.') || 'form';
        if (!errs[key]) errs[key] = issue.message;
      }
      setFormErrors(errs);
      return;
    }

    setSubmitting(true);
    try {
      const created = await create.mutateAsync(parsed.data as StoreTransferRequestValues);
      toast.success('Solicitud enviada a la empresa destino.');
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
        className="w-full max-w-3xl rounded-lg border border-border bg-surface p-5"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="create-req-title" className="text-lg font-semibold">
          Nueva solicitud a otra empresa
        </h2>
        <p className="mt-1 text-sm text-text-muted">
          Pedi stock de tu catalogo a otra empresa del grupo. La empresa destino debera
          aceptar la solicitud para que se materialice el movimiento.
        </p>
        <form onSubmit={handleSubmit} className="mt-4 space-y-3" data-testid="create-form">
          <fieldset className="space-y-3">
            <div>
              <Label htmlFor="dest-company">Empresa destino (del grupo)</Label>
              {loadingSiblings ? (
                <Skeleton className="h-9 w-full" />
              ) : (
                <select
                  id="dest-company"
                  value={destinationSlug}
                  onChange={(e) => setDestinationSlug(e.target.value)}
                  className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
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
                <p className="mt-1 text-xs text-warning">
                  Tu empresa no pertenece a un grupo con otras empresas. Usa el email de destino.
                </p>
              )}
              {selectedSibling && (
                <p className="mt-1 text-xs text-text-muted" data-testid="dest-preview">
                  Enviar a: <strong>{selectedSibling.name}</strong> (slug: {selectedSibling.slug})
                </p>
              )}
              {formErrors['destination_tenant_slug'] && (
                <p className="mt-1 text-xs text-danger">{formErrors['destination_tenant_slug']}</p>
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
              <Label htmlFor="from-wh">Almacen origen</Label>
              {loadingWh ? (
                <Skeleton className="h-9 w-full" />
              ) : (
                <select
                  id="from-wh"
                  value={fromWarehouseId}
                  onChange={(e) => setFromWarehouseId(e.target.value)}
                  className="mt-1 w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                  required
                >
                  <option value="">Selecciona...</option>
                  {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>{w.code}</option>
                  ))}
                </select>
              )}
              {formErrors['from_warehouse_id'] && (
                <p className="mt-1 text-xs text-danger">{formErrors['from_warehouse_id']}</p>
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
            <Label>Items solicitados</Label>
            <table className="mt-1 w-full text-sm">
              <thead className="border-b border-border text-left text-xs uppercase text-text-muted">
                <tr>
                  <th className="py-1">Producto</th>
                  <th className="py-1 text-right">Cantidad</th>
                  <th className="py-1 text-right">-</th>
                </tr>
              </thead>
              <tbody>
                {items.map((it, idx) => (
                  <tr key={idx} className="border-b border-border last:border-b-0">
                    <td className="py-2">
                      {loadingProd ? (
                        <Skeleton className="h-9 w-full" />
                      ) : (
                        <select
                          value={it.product_id}
                          onChange={(e) => updateItem(idx, { product_id: e.target.value })}
                          className="w-full rounded border border-border-strong bg-surface px-2 py-1 text-sm"
                          required
                          data-testid={`item-product-${idx}`}
                        >
                          <option value="">Selecciona...</option>
                          {productOptions.map((p) => (
                            <option key={p.id} value={p.id}>{p.label}</option>
                          ))}
                        </select>
                      )}
                    </td>
                    <td className="py-2 text-right">
                      <input
                        type="number"
                        min={0}
                        step="0.01"
                        value={it.quantity}
                        onChange={(e) => updateItem(idx, { quantity: e.target.value })}
                        className="w-28 rounded border border-border-strong bg-surface px-2 py-1 text-right text-sm"
                        required
                        data-testid={`item-qty-${idx}`}
                      />
                    </td>
                    <td className="py-2 text-right">
                      <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        onClick={() => removeItem(idx)}
                        disabled={items.length === 1}
                        aria-label={`Eliminar linea ${idx + 1}`}
                      >
                        <Trash2 className="size-4 text-danger" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="mt-1 flex items-center justify-between">
              <Button type="button" size="sm" variant="outline" leftIcon={<Plus className="size-4" />} onClick={addItem}>
                Agregar linea
              </Button>
              {formErrors['items'] && <p className="text-xs text-danger">{formErrors['items']}</p>}
            </div>
          </div>

          <fieldset className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
              <Label htmlFor="reference">Referencia</Label>
              <Input id="reference" value={reference} onChange={(e) => setReference(e.target.value)} maxLength={150} />
            </div>
            <div>
              <Label htmlFor="notes">Notas</Label>
              <Input id="notes" value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000} />
            </div>
          </fieldset>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" loading={submitting} data-testid="submit-create">
              Enviar solicitud
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}
