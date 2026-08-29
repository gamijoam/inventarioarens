/**
 * QuotationCreateDialog: crea una cotizacion (POST /api/quotations).
 * Puede partir de items de un carrito (POS /pos/armar) o construir los
 * items desde cero con busqueda de producto + variante.
 */
import { useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SingleSelectCombobox } from '@/components/ui/SingleSelectCombobox';
import { useCustomers } from '@/features/customers/api';
import { useProductsForTransfer } from '@/features/transfers/api';
import { VariantSelect } from '@/features/transfers/components/VariantSelect';
import { useWarehouses } from '@/features/inventory-center/api';
import { useCreateQuotation } from './api';

export interface QuotationCartItem {
  product_id: number;
  product_variant_id: number | null;
  product_variant_name?: string | null;
  quantity: number;
  price_list_id?: number | null;
  price_list_name?: string | null;
  name?: string | null;
  unit_price?: number | null;
}

interface QuotationCreateDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (quotationId: number) => void;
  initialItems?: QuotationCartItem[];
  defaultWarehouseId?: number | null;
  defaultCustomerName?: string;
}

interface BuilderRow {
  product_id: number | null;
  product_name: string;
  product_sku: string;
  product_variant_id: number | null;
  quantity: number;
}

function emptyBuilderRow(): BuilderRow {
  return {
    product_id: null,
    product_name: '',
    product_sku: '',
    product_variant_id: null,
    quantity: 1,
  };
}

export function QuotationCreateDialog(props: QuotationCreateDialogProps) {
  if (!props.open) return null;

  return <QuotationCreateDialogInner {...props} />;
}

function QuotationCreateDialogInner({
  open,
  onOpenChange,
  onCreated,
  initialItems,
  defaultWarehouseId,
  defaultCustomerName,
}: QuotationCreateDialogProps) {
  const { data: warehouses = [] } = useWarehouses();
  const { data: customers = [] } = useCustomers({ active_only: true });
  const [productSearch, setProductSearch] = useState('');
  const { data: products = [] } = useProductsForTransfer(productSearch);
  const create = useCreateQuotation();

  const fromCart = Boolean(initialItems && initialItems.length > 0);

  const [warehouseId, setWarehouseId] = useState<number | null>(null);
  const [customerName, setCustomerName] = useState('');
  const [validUntil, setValidUntil] = useState('');
  const [notes, setNotes] = useState('');
  const [status, setStatus] = useState<'draft' | 'issued'>('issued');
  const [rows, setRows] = useState<BuilderRow[]>([emptyBuilderRow()]);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    setWarehouseId(defaultWarehouseId ?? null);
    setCustomerName(defaultCustomerName ?? '');
    setValidUntil('');
    setNotes('');
    setStatus('issued');
    setProductSearch('');
    setRows([emptyBuilderRow()]);
  }, [open, defaultWarehouseId, defaultCustomerName]);

  const cartItems = useMemo(() => initialItems ?? [], [initialItems]);

  const warehouseOptions = useMemo(
    () =>
      warehouses.map((w: { id: number; code: string; name?: string }) => ({
        value: w.id,
        label: `${w.code} — ${w.name ?? ''}`,
      })),
    [warehouses],
  );

  const customerOptions = useMemo(
    () =>
      customers.map((c) => ({
        value: c.name,
        label: c.name,
      })),
    [customers],
  );

  const productOptions = useMemo(
    () =>
      products.map((p) => ({
        value: p.id,
        label: p.name,
        hint: p.sku ? `SKU: ${p.sku}` : undefined,
      })),
    [products],
  );

  function setRow(idx: number, patch: Partial<BuilderRow>) {
    setRows((prev) => prev.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
  }

  function pickProduct(idx: number, productId: number | null) {
    if (productId == null || productId === 0) {
      setRow(idx, emptyBuilderRow());
      return;
    }
    const p = products.find((x) => x.id === productId);
    setRow(idx, {
      product_id: productId,
      product_name: p?.name ?? '',
      product_sku: p?.sku ?? '',
      product_variant_id: null,
      quantity: 1,
    });
    if (p) setProductSearch(p.name);
  }

  async function handleSubmit(e: React.FormEvent) {    e.preventDefault();
    setSubmitting(true);

    const errs: string[] = [];
    if (!warehouseId) errs.push('Selecciona el almacen.');
    const items = fromCart
      ? cartItems.map((item) => ({
          product_id: item.product_id,
          product_variant_id: item.product_variant_id ?? undefined,
          quantity: item.quantity,
          price_list_id: item.price_list_id ?? undefined,
        }))
      : rows
          .filter((r) => r.product_id)
          .map((r) => ({
            product_id: r.product_id ?? 0,
            product_variant_id: r.product_variant_id ?? undefined,
            quantity: r.quantity,
          }));
    if (items.length === 0) errs.push('Agrega al menos un item.');

    if (errs.length > 0) {
      toast.error(errs.join(' '));
      setSubmitting(false);
      return;
    }

    try {
      const payload = {
        customer_name: customerName.trim() || undefined,
        warehouse_id: warehouseId,
        status,
        valid_until: validUntil || undefined,
        notes: notes.trim() || undefined,
        items,
      };
      const created = await create.mutateAsync(payload);
      toast.success(`Cotizacion ${created.document_number} creada.`);
      onCreated?.(created.id);
      onOpenChange(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear la cotizacion.');
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={() => onOpenChange(false)}
      role="dialog"
      aria-modal="true"
      aria-labelledby="quotation-create-title"
    >
      <form
        onSubmit={handleSubmit}
        onClick={(e) => e.stopPropagation()}
        className="border-border bg-surface max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-lg border"
      >
        <div className="border-border bg-surface sticky top-0 z-10 flex items-center justify-between border-b px-5 py-3">
          <h2 id="quotation-create-title" className="text-lg font-semibold">
            Nueva cotizacion
          </h2>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="text-text-muted hover:bg-bg hover:text-text-primary rounded p-1"
            aria-label="Cerrar"
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="space-y-4 p-5">
          <fieldset className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <Label htmlFor="quote-customer">Cliente</Label>
              <SingleSelectCombobox
                options={customerOptions}
                value={customerName}
                onChange={(next) => setCustomerName(next == null ? '' : String(next))}
                onQueryChange={(q) => setCustomerName(q)}
                placeholder="Nombre del cliente (o dejar vacio)"
                emptyMessage="Sin coincidencias"
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="quote-warehouse">Almacen</Label>
              <select
                id="quote-warehouse"
                className="border-border-strong bg-surface w-full rounded border px-3 py-2 text-sm"
                value={warehouseId ?? ''}
                onChange={(e) => setWarehouseId(e.target.value ? Number(e.target.value) : null)}
              >
                <option value="">Seleccionar almacen...</option>
                {warehouseOptions.map((w) => (
                  <option key={w.value} value={w.value}>
                    {w.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-1">
              <Label htmlFor="quote-valid">Valida hasta</Label>
              <Input
                id="quote-valid"
                type="date"
                value={validUntil}
                onChange={(e) => setValidUntil(e.target.value)}
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="quote-status">Estado</Label>
              <select
                id="quote-status"
                className="border-border-strong bg-surface w-full rounded border px-3 py-2 text-sm"
                value={status}
                onChange={(e) => setStatus(e.target.value as 'draft' | 'issued')}
              >
                <option value="issued">Emitida</option>
                <option value="draft">Borrador</option>
              </select>
            </div>
          </fieldset>

          <fieldset className="space-y-2">
            <div className="flex items-center justify-between">
              <h3 className="text-text-secondary text-sm font-semibold tracking-wide uppercase">
                Items ({fromCart ? cartItems.length : rows.filter((r) => r.product_id).length})
              </h3>
              {!fromCart && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setRows((prev) => [...prev, emptyBuilderRow()])}
                >
                  <Plus className="size-3.5" /> Agregar linea
                </Button>
              )}
            </div>

            {fromCart ? (
              <div className="space-y-2">
                {cartItems.map((item, idx) => (
                  <div
                    key={idx}
                    className="border-border bg-bg/30 grid grid-cols-1 items-center gap-2 rounded border p-3 text-sm sm:grid-cols-[1fr_auto_auto]"
                  >
                    <div>
                      <div className="font-medium">{item.name ?? `Producto #${item.product_id}`}</div>
                      {item.product_variant_name && (
                        <div className="text-text-muted text-xs">{item.product_variant_name}</div>
                      )}
                      <div className="text-text-muted text-xs">
                        {item.price_list_name
                          ? `Lista: ${item.price_list_name}`
                          : item.price_list_id
                            ? `Lista #${item.price_list_id}`
                            : 'Precio base'}
                      </div>
                    </div>
                    <div className="text-right tabular-nums">
                      {item.unit_price != null ? `$${item.unit_price.toFixed(2)}` : '—'}
                      <span className="text-text-muted block text-xs">x {item.quantity}</span>
                    </div>
                    <div className="text-right font-medium tabular-nums">
                      {item.unit_price != null ? `$${(item.unit_price * item.quantity).toFixed(2)}` : '—'}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="space-y-2">
                {rows.map((row, idx) => {
                  const product = row.product_id ? products.find((p) => p.id === row.product_id) : null;
                  const isSerialized = product?.tracking_type === 'serialized';
                  return (
                    <div key={idx} className="border-border bg-bg/30 rounded border p-3">
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_120px_auto]">
                        <div className="space-y-1">
                          <label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
                            Producto
                          </label>
                          <SingleSelectCombobox
                            options={productOptions}
                            value={row.product_id}
                            onChange={(next) => pickProduct(idx, next == null ? null : Number(next))}
                            onQueryChange={setProductSearch}
                            placeholder="Buscar producto..."
                            emptyMessage="Sin coincidencias"
                            aria-label={`Buscar producto de la linea ${idx + 1}`}
                          />
                          {!isSerialized && row.product_id && (
                            <div className="pt-1">
                              <VariantSelect
                                productId={row.product_id}
                                warehouseId={warehouseId}
                                value={row.product_variant_id}
                                onChange={(variantId) =>
                                  setRow(idx, { product_variant_id: variantId })
                                }
                                testIdPrefix={`quote-row-${idx}-variant`}
                              />
                            </div>
                          )}
                        </div>
                        <div className="space-y-1">
                          <label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
                            Cantidad
                          </label>
                          <Input
                            type="number"
                            min={0.0001}
                            step={0.0001}
                            value={row.quantity}
                            onChange={(e) => setRow(idx, { quantity: Number(e.target.value) })}
                            className="text-right"
                            data-testid={`quote-row-${idx}-quantity`}
                          />
                        </div>
                        <div className="flex items-end">
                          {rows.length > 1 && (
                            <Button
                              type="button"
                              size="icon-sm"
                              variant="ghost"
                              onClick={() =>
                                setRows((prev) =>
                                  prev.length > 1 ? prev.filter((_, i) => i !== idx) : prev,
                                )
                              }
                              aria-label={`Eliminar linea ${idx + 1}`}
                            >
                              <Trash2 className="text-danger size-4" />
                            </Button>
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </fieldset>

          <fieldset className="space-y-1">
            <Label htmlFor="quote-notes">Notas</Label>
            <Input
              id="quote-notes"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Condiciones, forma de pago, etc. (opcional)"
              maxLength={1000}
            />
          </fieldset>

          <div className="border-border flex justify-end gap-2 border-t pt-3">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancelar
            </Button>
            <Button type="submit" loading={submitting} data-testid="quote-create-submit">
              Crear cotizacion
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
