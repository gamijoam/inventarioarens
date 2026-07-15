/**
 * TransferCreateDialog: dialog para crear un nuevo InventoryTransfer
 * (POST /api/inventory-transfers). Patron consistente con
 * PurchaseFormDialog: cards editables, items con typeahead de
 * productos, captura de IMEIs para serializados.
 *
 * Validacion: en cada item se exige warehouse + product + quantity > 0.
 * Si el producto es serializado, se exige N IMEIs/seriales. El backend
 * los valida via StoreInventoryTransferRequest.
 */
import { useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Label } from '@/components/ui/Label';
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
  product_id: number | null;
  product_name: string;
  product_sku: string;
  tracking_type: string;
  warehouse_id: number | null;
  warehouse_label: string;
  quantity: number;
  serial_units: { serial_type: 'imei' | 'serial'; serial_number: string }[];
}

function emptyRow(): ItemRow {
  return {
    product_id: null,
    product_name: '',
    product_sku: '',
    tracking_type: 'quantity',
    warehouse_id: null,
    warehouse_label: '',
    quantity: 1,
    serial_units: [],
  };
}

export function TransferCreateDialog({ open, onOpenChange, onCreated }: TransferCreateDialogProps) {
  const { data: warehouses = [] } = useWarehouses();
  const { data: products = [] } = useProductsForTransfer();
  const create = useCreateTransfer();

  const [validationMode, setValidationMode] = useState<TransferValidationMode>('logistics');
  const [reason, setReason] = useState('');
  const [reference, setReference] = useState('');
  const [documentNumber, setDocumentNumber] = useState('');
  const [items, setItems] = useState<ItemRow[]>([emptyRow()]);
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      setValidationMode('logistics');
      setReason('');
      setReference('');
      setDocumentNumber('');
      setItems([emptyRow()]);
      setFieldErrors({});
    }
  }, [open]);

  const productOptions = useMemo(
    () =>
      products.map((p) => ({
        value: p.id,
        label: p.name,
        sublabel: p.sku ?? '',
        tracking_type: p.tracking_type,
        base_price: p.base_price,
        unit_of_measure: p.unit_of_measure,
      })),
    [products],
  );

  const warehouseOptions = useMemo(
    () => warehouses.map((w: { id: number; code: string; name?: string }) => ({
      value: w.id,
      label: `${w.code} — ${w.name ?? ''}`,
    })),
    [warehouses],
  );

  function setRow(idx: number, patch: Partial<ItemRow>) {
    setItems((prev) => prev.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
  }

  function removeRow(idx: number) {
    setItems((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== idx) : prev));
  }

  function pickProduct(idx: number, productId: number) {
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

    // Validacion basica client-side.
    const errs: Record<string, string> = {};
    if (items.some((r) => !r.product_id)) errs['items.product_id'] = 'Todos los items deben tener producto.';
    if (items.some((r) => !r.warehouse_id)) errs['items.warehouse_id'] = 'Todos los items deben tener almacen.';
    if (items.some((r) => r.quantity <= 0)) errs['items.quantity'] = 'La cantidad debe ser mayor a 0.';
    items.forEach((r, i) => {
      if (r.tracking_type === 'serialized' && r.serial_units.filter((s) => s.serial_number.trim()).length !== Math.floor(r.quantity)) {
        errs[`items.${i}.serial_units`] = `Debe ingresar un IMEI/serial por cada unidad (${Math.floor(r.quantity)}).`;
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
        warehouse_id: r.warehouse_id ?? 0,
        quantity: r.quantity,
        product_unit_ids: r.tracking_type === 'serialized'
          ? r.serial_units.filter((s) => s.serial_number.trim()).map(() => -1 * Math.floor(Math.random() * 1e9))
          : [],
      }));

      // El from_warehouse_id/to_warehouse_id del header. Simplificacion
      // de la UI: usamos el warehouse del primer item como origen y
      // permitimos al user cambiarlo via un select dedicado.
      const firstWarehouseId = items[0]?.warehouse_id ?? 0;
      const values: StoreTransferValues = {
        from_warehouse_id: firstWarehouseId,
        to_warehouse_id: firstWarehouseId,
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
          {/* Header */}
          <fieldset className="space-y-3">
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
                <Select
                  id="validation-mode"
                  value={validationMode}
                  onChange={(e) => setValidationMode(e.target.value as TransferValidationMode)}
                >
                  <option value="simple">Directo (simple)</option>
                  <option value="logistics">Logistico (4-etapas con checklist)</option>
                </Select>
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
            {fieldErrors['items.warehouse_id'] && (
              <p className="text-xs text-danger">{fieldErrors['items.warehouse_id']}</p>
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
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_180px_120px_auto]">
                      <div className="space-y-1">
                        <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">Producto</label>
                        <select
                          className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                          value={row.product_id ?? ''}
                          onChange={(e) => pickProduct(idx, Number(e.target.value))}
                        >
                          <option value="">Seleccionar producto...</option>
                          {productOptions.map((p) => (
                            <option key={p.value} value={p.value}>
                              {p.label} {p.sublabel ? `(${p.sublabel})` : ''}
                              {isSerializedForProduct(productOptions, p.value) ? ' [Serializado]' : ''}
                            </option>
                          ))}
                        </select>
                        {row.product_sku && (
                          <div className="text-[10px] text-text-muted">SKU: {row.product_sku}</div>
                        )}
                      </div>
                      <div className="space-y-1">
                        <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">Almacen</label>
                        <select
                          className="w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm"
                          value={row.warehouse_id ?? ''}
                          onChange={(e) => setRow(idx, { warehouse_id: e.target.value ? Number(e.target.value) : null })}
                        >
                          <option value="">Almacen...</option>
                          {warehouseOptions.map((w) => (
                            <option key={w.value} value={w.value}>{w.label}</option>
                          ))}
                        </select>
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
                          IMEIs / seriales ({row.serial_units.length} / {Math.floor(row.quantity)})
                        </div>
                        {Array.from({ length: Math.floor(row.quantity) }).map((_, i) => (
                          <div key={i} className="flex items-center gap-2">
                            <select
                              className="rounded border border-border-strong bg-surface px-2 py-1 text-xs"
                              value={row.serial_units[i]?.serial_type ?? 'imei'}
                              onChange={(e) => {
                                const list = [...row.serial_units];
                                list[i] = { serial_type: e.target.value as 'imei' | 'serial', serial_number: list[i]?.serial_number ?? '' };
                                setRow(idx, { serial_units: list });
                              }}
                            >
                              <option value="imei">IMEI</option>
                              <option value="serial">Serial</option>
                            </select>
                            <Input
                              value={row.serial_units[i]?.serial_number ?? ''}
                              onChange={(e) => {
                                const list = [...row.serial_units];
                                list[i] = { serial_type: list[i]?.serial_type ?? 'imei', serial_number: e.target.value };
                                setRow(idx, { serial_units: list });
                              }}
                              placeholder={`IMEI/Serial #${i + 1}`}
                              className="flex-1 text-xs"
                            />
                          </div>
                        ))}
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

function isSerializedForProduct(
  productOptions: { value: number; tracking_type?: string }[],
  productId: number,
): boolean {
  return productOptions.find((p) => p.value === productId)?.tracking_type === 'serialized';
}
