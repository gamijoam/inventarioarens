/**
 * PurchaseFormDialog: dialog full-width para crear un PurchaseOrder en
 * estado `draft` (POST /api/purchases). Une SupplierAutocomplete +
 * PurchaseItemRow[] + ImeiListInput por item.
 *
 * FASE 2 del modulo de Compras. La recepcion de mercancia (FASE 3)
 * usara el mismo Patron de dialog.
 */
import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Label } from '@/components/ui/Label';
import { useCreatePurchase } from '@/features/purchases/api';
import { useExchangeRateTypes } from '@/features/inventory-center/api';
import {
  StorePurchaseSchema,
  type PurchaseItemInput,
} from '@/features/purchases/schemas';
import { SupplierAutocomplete, type SupplierOption } from './SupplierAutocomplete';
import { PurchaseItemRow, type PurchaseItemRowValue } from './PurchaseItemRow';
import type { ImeiInput } from './ImeiListInput';
import { cn } from '@/lib/cn';

interface PurchaseFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (purchaseId: number) => void;
}

function emptyItem(): PurchaseItemRowValue {
  return {
    warehouse_id: null,
    product_id: null,
    product_info: null,
    quantity: 1,
    unit_cost: 0,
    serial_units: [],
  };
}

export function PurchaseFormDialog({ open, onOpenChange, onCreated }: PurchaseFormDialogProps) {
  const create = useCreatePurchase();
  const { data: rateTypes = [] } = useExchangeRateTypes();

  // Header state.
  const [supplierId, setSupplierId] = useState<number | null>(null);
  const [, setSupplier] = useState<SupplierOption | null>(null);
  const [documentNumber, setDocumentNumber] = useState('');
  const [issuedAt, setIssuedAt] = useState<string>(new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate] = useState<string>('');
  const [currency, setCurrency] = useState<'USD' | 'VES'>('USD');
  const [rateTypeId, setRateTypeId] = useState<number | null>(null);

  // Items state.
  const [items, setItems] = useState<PurchaseItemRowValue[]>([emptyItem()]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  // Snapshot de tasa actual (para mostrar preview en totales).
  const activeRate = useMemo(() => {
    if (currency !== 'VES' || !rateTypeId) return null;
    return null; // el rate se snapshot al enviar; aca no lo necesitamos
  }, [currency, rateTypeId]);

  // Totales en vivo.
  const totals = useMemo(() => {
    let base = 0;
    for (const item of items) {
      if (Number.isFinite(item.quantity) && Number.isFinite(item.unit_cost)) {
        base += item.quantity * item.unit_cost;
      }
    }
    return { base };
  }, [items]);

  function reset() {
    setSupplierId(null);
    setSupplier(null);
    setDocumentNumber('');
    setIssuedAt(new Date().toISOString().slice(0, 10));
    setDueDate('');
    setCurrency('USD');
    setRateTypeId(null);
    setItems([emptyItem()]);
    setFieldErrors({});
  }

  function addItem() {
    setItems((prev) => [...prev, emptyItem()]);
  }

  function updateItem(index: number, next: PurchaseItemRowValue) {
    setItems((prev) => prev.map((it, i) => (i === index ? next : it)));
  }

  function removeItem(index: number) {
    if (items.length <= 1) return;
    setItems((prev) => prev.filter((_, i) => i !== index));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const serializedItems: PurchaseItemInput[] = items.map((it) => {
      const serialUnits: ImeiInput[] = it.product_info?.tracking_type === 'serialized'
        ? it.serial_units.filter((s) => s.serial_number.trim() !== '')
        : [];
      return {
        warehouse_id: it.warehouse_id ?? 0,
        product_id: it.product_id ?? 0,
        quantity: it.quantity,
        unit_cost: it.unit_cost,
        serial_units: serialUnits,
      };
    });

    const payload = {
      supplier_id: supplierId ?? undefined,
      document_number: documentNumber.trim() || undefined,
      issued_at: issuedAt || undefined,
      due_date: dueDate || undefined,
      purchase_currency: currency,
      exchange_rate_type_id: currency === 'VES' ? (rateTypeId ?? undefined) : undefined,
      items: serializedItems,
    };

    const parsed = StorePurchaseSchema.safeParse(payload);
    if (!parsed.success) {
      const mapped: Record<string, string> = {};
      for (const issue of parsed.error.issues) {
        const key = issue.path.join('.');
        mapped[key] ??= issue.message;
      }
      setFieldErrors(mapped);
      toast.error('Hay errores en el formulario. Revisa los campos resaltados.');
      return;
    }

    setSubmitting(true);
    try {
      const result = await create.mutateAsync(parsed.data);
      toast.success('Compra creada en borrador.');
      onCreated?.((result as { id: number }).id);
      reset();
      onOpenChange(false);
    } catch (err) {
      if (err instanceof Error) {
        toast.error(err.message);
      } else {
        toast.error('Error al crear la compra.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-5xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Nueva compra</DialogTitle>
          <DialogDescription>
            Crea un borrador. Al recibir la mercancia se generara el stock, el WAC
            del producto y la cuenta por pagar (CxP) automaticamente.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          {/* ===== HEADER ===== */}
          <fieldset className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label>Proveedor</Label>
                <SupplierAutocomplete
                  value={supplierId}
                  onChange={(id, sup) => {
                    setSupplierId(id);
                    setSupplier(sup ?? null);
                  }}
                />
                <p className="text-xs text-text-muted">Opcional. Si no hay proveedor especifico, la CxP no se generara.</p>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="doc-number">Numero de documento</Label>
                <Input
                  id="doc-number"
                  value={documentNumber}
                  onChange={(e) => setDocumentNumber(e.target.value)}
                  placeholder="Auto si se deja vacio"
                  maxLength={100}
                />
                <p className="text-xs text-text-muted">Numero de factura del proveedor (opcional).</p>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="space-y-1.5">
                <Label htmlFor="issued-at">Fecha de emision</Label>
                <Input
                  id="issued-at"
                  type="date"
                  value={issuedAt}
                  onChange={(e) => setIssuedAt(e.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="due-date">Fecha de vencimiento</Label>
                <Input
                  id="due-date"
                  type="date"
                  value={dueDate}
                  onChange={(e) => setDueDate(e.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <Label>Moneda</Label>
                <Select value={currency} onChange={(e) => setCurrency(e.target.value as 'USD' | 'VES')}>
                  <option value="USD">USD (Dolar)</option>
                  <option value="VES">VES (Bolivar)</option>
                </Select>
              </div>
            </div>

            {currency === 'VES' && (
              <div className="space-y-1.5">
                <Label>Tipo de tasa de cambio</Label>
                <Select
                  value={rateTypeId ? String(rateTypeId) : ''}
                  onChange={(e) => setRateTypeId(e.target.value ? Number(e.target.value) : null)}
                  className={cn(fieldErrors.exchange_rate_type_id && 'border-danger')}
                >
                  <option value="">Seleccionar tipo de tasa...</option>
                  {rateTypes.map((rt) => (
                    <option key={rt.id} value={String(rt.id)}>
                      {rt.code} - {rt.name}
                    </option>
                  ))}
                </Select>
                {fieldErrors.exchange_rate_type_id && (
                  <p className="text-xs text-danger">{fieldErrors.exchange_rate_type_id}</p>
                )}
                {activeRate && (
                  <p className="text-xs text-text-muted">Rate actual: {activeRate}</p>
                )}
              </div>
            )}
          </fieldset>

          {/* ===== ITEMS ===== */}
          <fieldset className="space-y-2">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-text-secondary">Items ({items.length})</h3>
              <Button type="button" size="sm" variant="outline" onClick={addItem}>
                <Plus className="size-3.5" /> Agregar linea
              </Button>
            </div>

            {fieldErrors.items && (
              <p className="text-xs text-danger">{fieldErrors.items}</p>
            )}

            <div className="rounded-lg border border-border bg-surface overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-border bg-bg/60 text-left">
                  <tr>
                    <th className="px-2 py-2 font-semibold uppercase tracking-wide text-text-secondary">Almacen</th>
                    <th className="px-2 py-2 font-semibold uppercase tracking-wide text-text-secondary">Producto</th>
                    <th className="px-2 py-2 font-semibold uppercase tracking-wide text-text-secondary">Cantidad</th>
                    <th className="px-2 py-2 font-semibold uppercase tracking-wide text-text-secondary">Costo unit.</th>
                    <th className="px-2 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Subtotal</th>
                    <th className="px-2 py-2 font-semibold uppercase tracking-wide text-text-secondary">IMEIs / Seriales</th>
                    <th className="px-2 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {items.map((item, i) => (
                    <PurchaseItemRow
                      key={i}
                      value={item}
                      onChange={(next) => updateItem(i, next)}
                      onRemove={() => removeItem(i)}
                      canRemove={items.length > 1}
                    />
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-border bg-bg/40">
                    <td colSpan={4} className="px-2 py-2 text-right text-sm font-semibold uppercase tracking-wide text-text-secondary">
                      Total {currency}
                    </td>
                    <td className="px-2 py-2 text-right text-base font-bold tabular-nums">
                      {totals.base.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </td>
                    <td colSpan={2} />
                  </tr>
                </tfoot>
              </table>
            </div>
          </fieldset>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" loading={submitting}>
              Crear borrador
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
