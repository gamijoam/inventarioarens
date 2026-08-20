/**
 * PurchaseFormDialog: dialog full-width para crear un PurchaseOrder en
 * estado `draft` (POST /api/purchases). Une SupplierAutocomplete +
 * PurchaseItemRow[] + ImeiListInput por item.
 *
 * FASE 2 del modulo de Compras. La recepcion de mercancia (FASE 3)
 * usara el mismo Patron de dialog.
 */
import { useMemo, useState } from 'react';
import { FileText, PackagePlus, Plus } from 'lucide-react';
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
import { StorePurchaseSchema, type PurchaseItemInput } from '@/features/purchases/schemas';
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
    product_variant_id: null,
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
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set());
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
    setCollapsed(new Set());
    setFieldErrors({});
  }

  function addItem() {
    setItems((prev) => [...prev, emptyItem()]);
    // Colapsa las lineas existentes para que la nueva quede visible.
    setCollapsed(new Set(Array.from({ length: items.length }, (_, i) => i)));
  }

  function updateItem(index: number, next: PurchaseItemRowValue) {
    setItems((prev) => prev.map((it, i) => (i === index ? next : it)));
  }

  function removeItem(index: number) {
    if (items.length <= 1) return;
    setItems((prev) => prev.filter((_, i) => i !== index));
    setCollapsed((prev) => {
      const next = new Set(prev);
      next.delete(index);
      return next;
    });
  }

  function toggleCollapse(index: number) {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(index)) next.delete(index);
      else next.add(index);
      return next;
    });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const localErrors: Record<string, string> = {};
    for (const [index, item] of items.entries()) {
      const isSerialized = item.product_info?.tracking_type === 'serialized';
      if (!isSerialized) continue;

      const quantity = Number(item.quantity);
      const serials = item.serial_units
        .map((serial) => serial.serial_number.trim())
        .filter(Boolean);
      const unique = new Set(
        item.serial_units.map(
          (serial) => `${serial.serial_type}:${serial.serial_number.trim().toUpperCase()}`,
        ),
      );

      if (!Number.isInteger(quantity) || quantity <= 0) {
        localErrors[`items.${index}.quantity`] =
          'Los productos serializados deben comprarse en unidades enteras.';
      } else if (serials.length !== quantity || item.serial_units.length !== quantity) {
        localErrors[`items.${index}.serial_units`] =
          `Captura ${quantity} IMEI/serial(es) para este producto.`;
      } else if (unique.size !== item.serial_units.length) {
        localErrors[`items.${index}.serial_units`] =
          'No puedes repetir IMEIs o seriales dentro de la misma linea.';
      }
    }

    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors);
      toast.error('Revisa los IMEIs/seriales: debe haber uno por cada unidad comprada.');
      return;
    }

    const serializedItems: PurchaseItemInput[] = items.map((it) => {
      const serialUnits: ImeiInput[] =
        it.product_info?.tracking_type === 'serialized'
          ? it.serial_units.filter((s) => s.serial_number.trim() !== '')
          : [];
      return {
        warehouse_id: it.warehouse_id ?? 0,
        product_id: it.product_id ?? 0,
        product_variant_id: it.product_variant_id ?? undefined,
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

    // Check adicional: cada item debe tener warehouse_id (Zod lo valida,
    // pero mostramos un toast mas descriptivo si falla especificamente aqui).
    const itemsWithoutWarehouse = items.filter((it) => !it.warehouse_id);
    if (itemsWithoutWarehouse.length > 0) {
      toast.error('Todos los items deben tener un almacen seleccionado.');
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
      <DialogContent className="flex h-[min(96vh,1100px)] max-w-[1400px] flex-col gap-0 overflow-hidden p-0">
        <DialogHeader className="border-border shrink-0 border-b px-6 py-5">
          <div className="flex items-start gap-3">
            <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-md">
              <PackagePlus className="size-5" />
            </div>
            <div>
              <DialogTitle>Nueva compra</DialogTitle>
              <DialogDescription className="mt-1">
                Registra los datos del proveedor y los productos que ingresaran al inventario.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
            <fieldset className="space-y-4">
              <div className="flex items-center gap-2">
                <FileText className="text-primary size-4" />
                <legend className="text-sm font-semibold">Datos de la compra</legend>
              </div>

              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div className="space-y-1.5">
                  <Label>Proveedor</Label>
                  <SupplierAutocomplete
                    value={supplierId}
                    onChange={(id, sup) => {
                      setSupplierId(id);
                      setSupplier(sup ?? null);
                    }}
                  />
                  <p className="text-text-muted text-xs">
                    Opcional. Sin proveedor no se generara una cuenta por pagar.
                  </p>
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
                  <p className="text-text-muted text-xs">
                    Numero de factura del proveedor (opcional).
                  </p>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
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
                  <Select
                    value={currency}
                    onChange={(e) => setCurrency(e.target.value as 'USD' | 'VES')}
                  >
                    <option value="USD">USD (Dolar)</option>
                    <option value="VES">VES (Bolivar)</option>
                  </Select>
                </div>
              </div>

              {currency === 'VES' && (
                <div className="space-y-1.5 md:max-w-md">
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
                    <p className="text-danger text-xs">{fieldErrors.exchange_rate_type_id}</p>
                  )}
                  {activeRate && (
                    <p className="text-text-muted text-xs">Tasa actual: {activeRate}</p>
                  )}
                </div>
              )}
            </fieldset>

            <fieldset className="space-y-3">
              <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                <div>
                  <legend className="text-sm font-semibold">Productos ({items.length})</legend>
                  <p className="text-text-muted mt-0.5 text-xs">
                    Busca, selecciona el almacen y registra cantidad y costo.
                  </p>
                </div>
                <Button type="button" size="sm" variant="outline" onClick={addItem}>
                  <Plus className="size-3.5" /> Agregar producto
                </Button>
              </div>

              {fieldErrors.items && <p className="text-danger text-xs">{fieldErrors.items}</p>}

              <div className="space-y-3">
                {items.map((item, index) => (
                  <PurchaseItemRow
                    key={index}
                    index={index}
                    value={{
                      ...item,
                      error:
                        fieldErrors[`items.${index}.serial_units`] ??
                        fieldErrors[`items.${index}.quantity`],
                    }}
                    onChange={(next) => updateItem(index, next)}
                    onRemove={() => removeItem(index)}
                    canRemove={items.length > 1}
                    collapsed={collapsed.has(index)}
                    onToggleCollapse={toggleCollapse}
                  />
                ))}
              </div>
            </fieldset>
          </div>

          <DialogFooter className="border-border bg-surface shrink-0 border-t px-6 py-4 sm:justify-between">
            <div className="mr-auto">
              <p className="text-text-muted text-xs font-semibold uppercase">Total de la compra</p>
              <p className="text-2xl font-bold tabular-nums">
                {currency}{' '}
                {totals.base.toLocaleString('es-VE', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })}
              </p>
            </div>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
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
