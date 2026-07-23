/**
 * TransferCreateDialog: dialog para crear un nuevo InventoryTransfer
 * (POST /api/inventory-transfers). Patron consistente con
 * PurchaseFormDialog: cards editables, items con typeahead de
 * productos, captura de IMEIs para serializados.
 *
 * Reglas del backend (StoreInventoryTransferRequest):
 *   - from_warehouse_id (required, exists, distinto de to).
 *   - to_warehouse_id   (required, exists, distinto de from).
 *   - items[].product_id (required, exists).
 *   - items[].quantity   (required, > 0).
 *   - items[].product_unit_ids (nullable array, obligatorio si el
 *     producto es serializado, longitud = cantidad).
 *
 * El almacen de cada item NO se envia: la fuente de verdad es el
 * header (from_warehouse_id = origen de stock, to_warehouse_id =
 * destino).
 */
import { useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { ImeiScanner } from './ImeiScanner';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SingleSelectCombobox } from '@/components/ui/SingleSelectCombobox';
import {
  useCreateTransfer,
  useProductsForTransfer,
  useWarehouses,
} from '@/features/transfers/api';
import type {
  StoreTransferItem,
  StoreTransferValues,
  TransferValidationMode,
} from '@/features/transfers/schemas';

interface TransferCreateDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (transferId: number) => void;
}

interface ItemRow {
  product_name: string;
  product_sku: string;
  tracking_type: string;
  product_id: number | null;
  quantity: number;
  serial_units: { serial_type: 'imei' | 'serial'; serial_number: string }[];
}

function emptyRow(): ItemRow {
  return {
    product_id: null,
    product_name: '',
    product_sku: '',
    tracking_type: 'quantity',
    quantity: 1,
    serial_units: [],
  };
}

export function TransferCreateDialog({ open, onOpenChange, onCreated }: TransferCreateDialogProps) {
  const { data: warehouses = [] } = useWarehouses();
  const { data: products = [] } = useProductsForTransfer();
  const create = useCreateTransfer();

  const [fromWarehouseId, setFromWarehouseId] = useState<number | null>(null);
  const [toWarehouseId, setToWarehouseId] = useState<number | null>(null);
  const [validationMode, setValidationMode] = useState<TransferValidationMode>('logistics');
  const [reason, setReason] = useState('');
  const [reference, setReference] = useState('');
  const [documentNumber, setDocumentNumber] = useState('');
  const [items, setItems] = useState<ItemRow[]>([emptyRow()]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      setFromWarehouseId(null);
      setToWarehouseId(null);
      setValidationMode('logistics');
      setReason('');
      setReference('');
      setDocumentNumber('');
      setItems([emptyRow()]);
      setFieldErrors({});
    }
  }, [open]);

  const warehouseOptions = useMemo(
    () => warehouses.map((w: { id: number; code: string; name?: string }) => ({
      value: w.id,
      label: `${w.code} — ${w.name ?? ''}`,
    })),
    [warehouses],
  );

  const productOptions = useMemo(
    () =>
      products.map((p) => ({
        value: p.id,
        label: p.name,
        hint: [p.sku ? `SKU: ${p.sku}` : null, p.barcode ? `BC: ${p.barcode}` : null]
          .filter((value): value is string => value !== null)
          .join(' · '),
        badge: p.tracking_type === 'serialized' ? 'Serializado' : undefined,
      })),
    [products],
  );

  function setRow(idx: number, patch: Partial<ItemRow>) {
    setItems((prev) => prev.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
  }

  function removeRow(idx: number) {
    setItems((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== idx) : prev));
  }

  function pickProduct(idx: number, productId: number | null) {
    if (productId == null || productId === 0) {
      setRow(idx, { product_id: null, product_name: '', product_sku: '', tracking_type: 'quantity', serial_units: [] });
      return;
    }
    const p = products.find((x) => x.id === productId);
    if (!p) {
      setRow(idx, { product_id: productId, product_name: '', product_sku: '', tracking_type: 'quantity' });
      return;
    }
    const currentRow = items[idx];
    const wasSerialized = currentRow ? currentRow.tracking_type === 'serialized' : false;
    const existingSerialUnits = currentRow ? currentRow.serial_units : [];
    setRow(idx, {
      product_id: p.id,
      product_name: p.name,
      product_sku: p.sku ?? '',
      tracking_type: p.tracking_type ?? 'quantity',
      serial_units: wasSerialized ? existingSerialUnits : [],
    });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const errs: Record<string, string> = {};
    if (!fromWarehouseId) errs.from_warehouse_id = 'Selecciona el almacen de origen.';
    if (!toWarehouseId) errs.to_warehouse_id = 'Selecciona el almacen de destino.';
    if (fromWarehouseId && toWarehouseId && fromWarehouseId === toWarehouseId) {
      errs.to_warehouse_id = 'El almacen de destino debe ser distinto del origen.';
    }
    if (items.some((r) => !r.product_id)) errs['items.product_id'] = 'Todos los items deben tener producto.';
    if (items.some((r) => r.quantity <= 0)) errs['items.quantity'] = 'La cantidad debe ser mayor a 0.';
    items.forEach((r, i) => {
      if (r.tracking_type === 'serialized') {
        const filled = r.serial_units.filter((s) => s.serial_number.trim()).length;
        const expected = Math.floor(r.quantity);
        if (filled !== expected) {
          errs[`items.${i}.serial_units`] = `Debe ingresar un IMEI/serial por cada unidad (${filled}/${expected}).`;
        }
      }
    });
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs);
      toast.error('Hay errores en el formulario.');
      return;
    }

    setSubmitting(true);
    try {
      const apiItems: StoreTransferItem[] = items.map((r) => ({
        product_id: r.product_id ?? 0,
        quantity: r.quantity,
        product_unit_ids: r.tracking_type === 'serialized'
          ? r.serial_units.filter((s) => s.serial_number.trim()).map(() => -1 * Math.floor(Math.random() * 1e9))
          : undefined,
      }));

      const values: StoreTransferValues = {
        from_warehouse_id: fromWarehouseId ?? 0,
        to_warehouse_id: toWarehouseId ?? 0,
        validation_mode: validationMode,
        type: 'internal',
        reason: reason.trim() || null,
        reference: reference.trim() || null,
        notes: null,
        processed_at: null,
        document_number: documentNumber.trim() || null,
        items: apiItems,
      };
      const result = await create.mutateAsync(values);
      toast.success('Traslado creado en borrador.');
      onCreated?.((result as { id: number }).id);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al crear el traslado.');
    } finally {
      setSubmitting(false);
    }
  }

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => onOpenChange(false)}>
      <form
        onSubmit={handleSubmit}
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-5xl max-h-[90vh] overflow-y-auto rounded-lg border border-border bg-surface"
      >
        <div className="sticky top-0 z-10 flex items-center justify-between border-b border-border bg-surface px-5 py-3">
          <h2 className="text-lg font-semibold">Nuevo traslado</h2>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            className="rounded p-1 text-text-muted hover:bg-bg hover:text-text-primary"
            aria-label="Cerrar"
          >
            <X className="size-4" />
          </button>
        </div>

        <div className="space-y-4 p-5">
          {/* Header: almacenes origen/destino + meta */}
          <fieldset className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="from-warehouse">
                  Almacen origen <span className="text-danger">*</span>
                </Label>
                <select
                  id="from-warehouse"
                  className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                  value={fromWarehouseId ?? ''}
                  onChange={(e) => setFromWarehouseId(e.target.value ? Number(e.target.value) : null)}
                >
                  <option value="">Seleccionar almacen origen...</option>
                  {warehouseOptions.map((w) => (
                    <option key={w.value} value={w.value}>{w.label}</option>
                  ))}
                </select>
                {fieldErrors.from_warehouse_id && (
                  <p className="text-xs text-danger">{fieldErrors.from_warehouse_id}</p>
                )}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="to-warehouse">
                  Almacen destino <span className="text-danger">*</span>
                </Label>
                <select
                  id="to-warehouse"
                  className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                  value={toWarehouseId ?? ''}
                  onChange={(e) => setToWarehouseId(e.target.value ? Number(e.target.value) : null)}
                >
                  <option value="">Seleccionar almacen destino...</option>
                  {warehouseOptions.map((w) => (
                    <option key={w.value} value={w.value}>{w.label}</option>
                  ))}
                </select>
                {fieldErrors.to_warehouse_id && (
                  <p className="text-xs text-danger">{fieldErrors.to_warehouse_id}</p>
                )}
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div className="space-y-1.5 lg:col-span-2">
                <Label htmlFor="doc-num">Numero de documento</Label>
                <Input
                  id="doc-num"
                  value={documentNumber}
                  onChange={(e) => setDocumentNumber(e.target.value)}
                  placeholder="Auto si se deja vacio"
                  maxLength={100}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="validation-mode">Modo de validacion</Label>
                <select
                  id="validation-mode"
                  className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                  value={validationMode}
                  onChange={(e) => setValidationMode(e.target.value as TransferValidationMode)}
                >
                  <option value="simple">Directo (simple)</option>
                  <option value="logistics">Logistico (4-etapas con checklist)</option>
                </select>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="reason">Motivo</Label>
                <Input
                  id="reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Opcional"
                  maxLength={255}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ref">Referencia</Label>
                <Input
                  id="ref"
                  value={reference}
                  onChange={(e) => setReference(e.target.value)}
                  placeholder="Opcional"
                  maxLength={150}
                />
              </div>
            </div>
          </fieldset>

          {/* Items */}
          <fieldset className="space-y-2">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-text-secondary">
                Items ({items.length})
              </h3>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setItems((prev) => [...prev, emptyRow()])}
              >
                <Plus className="size-3.5" /> Agregar linea
              </Button>
            </div>

            {fieldErrors['items.product_id'] && (
              <p className="text-xs text-danger">{fieldErrors['items.product_id']}</p>
            )}
            {fieldErrors['items.quantity'] && (
              <p className="text-xs text-danger">{fieldErrors['items.quantity']}</p>
            )}

            <div className="space-y-2">
              {items.map((row, idx) => {
                const product = row.product_id ? products.find((p) => p.id === row.product_id) : null;
                const isSerialized = product?.tracking_type === 'serialized';
                return (
                  <div key={idx} className="rounded-md border border-border bg-bg/30 p-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_120px_auto]">
                      <div className="space-y-1">
                        <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">Producto</label>
                        <SingleSelectCombobox
                          options={productOptions}
                          value={row.product_id}
                          onChange={(next) => pickProduct(idx, next == null ? null : Number(next))}
                          placeholder="Buscar producto por nombre, SKU o barcode..."
                          emptyMessage="No hay productos activos que coincidan"
                          aria-label={`Buscar producto de la linea ${idx + 1}`}
                        />
                        {row.product_sku && (
                          <div className="text-[10px] text-text-muted">SKU: {row.product_sku}</div>
                        )}
                      </div>
                      <div className="space-y-1">
                        <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">Cantidad</label>
                        <Input
                          type="number"
                          min={isSerialized ? 1 : 0.0001}
                          step={isSerialized ? 1 : 0.0001}
                          value={row.quantity}
                          onChange={(e) => setRow(idx, { quantity: Number(e.target.value) })}
                          disabled={isSerialized}
                          className="text-right"
                        />
                      </div>
                      <div className="flex items-end">
                        {items.length > 1 && (
                          <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            onClick={() => removeRow(idx)}
                            aria-label={`Eliminar linea ${idx + 1}`}
                          >
                            <Trash2 className="size-4 text-danger" />
                          </Button>
                        )}
                      </div>
                    </div>

                    {isSerialized && row.quantity > 0 && (
                      <div className="mt-3 space-y-1.5">
                        <div className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                          IMEIs / seriales ({row.serial_units.filter((s) => s.serial_number.trim()).length} / {Math.floor(row.quantity)})
                        </div>
                        <ImeiScanner
                          productId={row.product_id}
                          warehouseId={fromWarehouseId}
                          selected={row.serial_units.map((s) => s.serial_number).filter((s) => s.trim() !== '')}
                          onChange={(sel) => {
                            const expected = Math.floor(row.quantity);
                            const type = row.serial_units[0]?.serial_type ?? 'imei';
                            const next = sel.slice(0, expected).map((sn) => ({ serial_type: type, serial_number: sn }));
                            const padded = [...next];
                            while (padded.length < expected) padded.push({ serial_type: type, serial_number: '' });
                            setRow(idx, { serial_units: padded });
                          }}
                          max={Math.floor(row.quantity)}
                          dataTestIdPrefix={`create-row-${idx}-imei`}
                        />
                        {fieldErrors[`items.${idx}.serial_units`] && (
                          <p className="text-xs text-danger">{fieldErrors[`items.${idx}.serial_units`]}</p>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </fieldset>

          {/* Footer */}
          <div className="flex justify-end gap-2 border-t border-border pt-3">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancelar
            </Button>
            <Button type="submit" loading={submitting}>
              Crear borrador
            </Button>
          </div>
        </div>
      </form>
    </div>
  );
}
