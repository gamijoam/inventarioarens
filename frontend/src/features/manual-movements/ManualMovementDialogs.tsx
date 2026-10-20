import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { toast } from '@/lib/toast';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SingleSelectCombobox } from '@/components/ui/SingleSelectCombobox';
import { Textarea } from '@/components/ui/Textarea';
import { useProductsForTransfer } from '@/features/transfers/api';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import { useCreateManualMovement, useRejectManualMovement, useAvailableSerials } from './api';
import {
  CreateManualMovementSchema,
  MANUAL_MOVEMENT_TYPES,
  MANUAL_MOVEMENT_TYPE_LABELS,
  type ManualMovement,
  type ManualMovementType,
} from './schemas';

interface WarehouseOption {
  id: number;
  name: string;
}
interface CreateProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  warehouses: WarehouseOption[];
  onCreated: (movement: ManualMovement) => void;
}

export function CreateManualMovementDialog({
  open,
  onOpenChange,
  warehouses,
  onCreated,
}: CreateProps) {
  const [productSearch, setProductSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const { data: products = [] } = useProductsForTransfer(debouncedSearch);
  const [selectedProduct, setSelectedProduct] = useState<(typeof products)[0] | null>(null);

  useEffect(() => {
    const handle = window.setTimeout(() => setDebouncedSearch(productSearch), 120);
    return () => window.clearTimeout(handle);
  }, [productSearch]);

  const [warehouseId, setWarehouseId] = useState(0);
  const create = useCreateManualMovement();
  const [productId, setProductId] = useState<number | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [type, setType] = useState<ManualMovementType>('internal_consumption');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const { data: variants = [] } = useProductVariants(productId ?? 0);
  const hasVariants = variants.some((variant) => Boolean(variant.color) || Boolean(variant.sku_variant));

  // Manejo de productos serializados (teléfonos, laptops, etc.)
  const currentProduct = selectedProduct ?? products.find((p) => p.id === productId) ?? null;
  const isSerialized = currentProduct?.tracking_type === 'serialized';
  const isInput = type === 'adjustment_in' || type === 'return_internal' || type === 'found';

  // Seriales para entrada manual (texto o escaneo)
  const [entrySerialsText, setEntrySerialsText] = useState('');

  // Seriales disponibles para salida (consulta al backend filtrada por almacén y variante)
  const { data: availableUnits = [], isLoading: isLoadingUnits } = useAvailableSerials(
    isSerialized && !isInput ? productId : null,
    warehouseId,
    variantId || undefined,
  );
  const [selectedUnitIds, setSelectedUnitIds] = useState<number[]>([]);
  const [serialFilterSearch, setSerialFilterSearch] = useState('');

  useEffect(() => {
    if (open) {
      setWarehouseId(0);
      setProductId(null);
      setVariantId(null);
      setSelectedProduct(null);
      setProductSearch('');
      setDebouncedSearch('');
      setQuantity(1);
      setType('internal_consumption');
      setReason('');
      setNotes('');
      setErrors({});
      setEntrySerialsText('');
      setSelectedUnitIds([]);
      setSerialFilterSearch('');
    }
  }, [open]);

  // Al cambiar de producto o almacén o variante, resetear selección de seriales
  useEffect(() => {
    setVariantId(null);
    setSelectedUnitIds([]);
    setEntrySerialsText('');
  }, [productId]);

  useEffect(() => {
    setSelectedUnitIds([]);
  }, [warehouseId, variantId]);

  const enteredSerials = useMemo(() => {
    return entrySerialsText
      .split(/[\n,;]+/)
      .map((s) => s.trim())
      .filter(Boolean);
  }, [entrySerialsText]);

  // Si es entrada y el usuario escribe seriales, auto-sincronizar la cantidad
  useEffect(() => {
    if (isSerialized && isInput && enteredSerials.length > 0) {
      setQuantity(enteredSerials.length);
    }
  }, [isSerialized, isInput, enteredSerials.length]);

  const productOptions = useMemo(() => {
    const list = [...products];
    if (selectedProduct && !list.some((p) => p.id === selectedProduct.id)) {
      list.unshift(selectedProduct);
    }
    return list.map((product) => ({
      value: product.id,
      label: product.name,
      badge: product.is_active === false ? 'Inactivo' : undefined,
      hint: [
        product.tracking_type === 'serialized' ? '📱 Serializado/IMEI' : null,
        product.sku ? `SKU: ${product.sku}` : null,
        product.barcode ? `Código: ${product.barcode}` : null,
        product.available_stock !== undefined && product.available_stock !== null
          ? `Stock: ${product.available_stock}`
          : null,
      ]
        .filter((value): value is string => value !== null)
        .join(' · '),
      keywords: [product.description, product.brand?.name].filter(Boolean).join(' '),
    }));
  }, [products, selectedProduct]);

  const filteredAvailableUnits = useMemo(() => {
    if (!serialFilterSearch.trim()) return availableUnits;
    const q = serialFilterSearch.toLowerCase().trim();
    return availableUnits.filter((u) => u.serial_number.toLowerCase().includes(q));
  }, [availableUnits, serialFilterSearch]);

  function toggleUnitSelection(unit: { id: number; serial_number: string }) {
    setSelectedUnitIds((current) => {
      const exists = current.includes(unit.id);
      const next = exists ? current.filter((id) => id !== unit.id) : [...current, unit.id];
      if (next.length > quantity) {
        setQuantity(next.length);
      }
      return next;
    });
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const nextErrors: Record<string, string> = {};

    let serialUnitsPayload: { id?: number; serial_type: 'imei' | 'serial'; serial_number: string }[] | undefined;

    if (isSerialized) {
      if (isInput) {
        if (enteredSerials.length === 0) {
          nextErrors.serial_units = 'Debes ingresar al menos un IMEI/serial.';
        } else if (enteredSerials.length !== quantity) {
          nextErrors.serial_units = `Indicaste cantidad ${quantity}, pero ingresaste ${enteredSerials.length} IMEIs.`;
        }

        const duplicates = enteredSerials.filter(
          (item, index) => enteredSerials.indexOf(item) !== index,
        );
        if (duplicates.length > 0) {
          nextErrors.serial_units = `Hay IMEIs duplicados: ${duplicates.join(', ')}`;
        }

        serialUnitsPayload = enteredSerials.map((s) => ({
          serial_type: 'imei',
          serial_number: s,
        }));
      } else {
        if (selectedUnitIds.length === 0) {
          nextErrors.serial_units = 'Debes seleccionar los IMEIs que van a salir.';
        } else if (selectedUnitIds.length !== quantity) {
          nextErrors.serial_units = `La cantidad indicada es ${quantity}, pero tienes seleccionados ${selectedUnitIds.length} IMEIs.`;
        }

        serialUnitsPayload = availableUnits
          .filter((u) => selectedUnitIds.includes(u.id))
          .map((u) => ({
            id: u.id,
            serial_type: u.serial_type,
            serial_number: u.serial_number,
          }));
      }
    }

    if (Object.keys(nextErrors).length > 0) {
      setErrors(nextErrors);
      return;
    }

    const parsed = CreateManualMovementSchema.safeParse({
      warehouse_id: warehouseId,
      product_id: productId ?? 0,
      product_variant_id: hasVariants && variantId && variantId > 0 ? variantId : null,
      quantity,
      type,
      reason,
      notes: notes || null,
      serial_units: serialUnitsPayload,
    });

    if (!parsed.success) {
      const fieldErrors: Record<string, string> = {};
      parsed.error.issues.forEach((issue) => {
        fieldErrors[String(issue.path[0])] = issue.message;
      });
      setErrors(fieldErrors);
      return;
    }

    try {
      const movement = await create.mutateAsync(parsed.data);
      toast.success('Movimiento enviado para aprobación');
      onOpenChange(false);
      onCreated(movement);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear el movimiento.');
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Nuevo movimiento manual</DialogTitle>
          <DialogDescription>
            Registra la solicitud. El inventario cambiará solo después de su aprobación.
          </DialogDescription>
        </DialogHeader>
        <form className="grid gap-4 md:grid-cols-2" onSubmit={submit}>
          <Field label="Almacén" error={errors.warehouse_id}>
            <Select value={warehouseId} onChange={(e) => setWarehouseId(Number(e.target.value))}>
              <option value={0}>Seleccionar almacén</option>
              {warehouses.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Producto" error={errors.product_id}>
            <SingleSelectCombobox
              openOnFocus
              value={productId}
              onChange={(value) => {
                const nextId = value == null ? null : Number(value);
                setProductId(nextId);
                if (nextId != null) {
                  const found = products.find((p) => p.id === nextId) ?? null;
                  setSelectedProduct(found);
                } else {
                  setSelectedProduct(null);
                }
              }}
              options={productOptions}
              placeholder="Buscar por nombre, SKU o código…"
              onQueryChange={setProductSearch}
            />
          </Field>

          {/* Selector de Variante / Color */}
          {hasVariants && (
            <Field label="Variante / Color" error={errors.product_variant_id}>
              <Select
                value={variantId ?? 0}
                onChange={(e) => {
                  const val = Number(e.target.value);
                  setVariantId(val > 0 ? val : null);
                }}
              >
                <option value={0}>Todas las variantes / Sin variante</option>
                {variants
                  .filter((variant) => Boolean(variant.color) || Boolean(variant.sku_variant))
                  .map((variant) => (
                    <option key={variant.id} value={variant.id}>
                      {variant.color ?? variant.sku_variant ?? `Variante #${variant.id}`}
                    </option>
                  ))}
              </Select>
            </Field>
          )}

          <Field label="Tipo" error={errors.type}>
            <Select value={type} onChange={(e) => setType(e.target.value as ManualMovementType)}>
              {MANUAL_MOVEMENT_TYPES.map((item) => (
                <option key={item} value={item}>
                  {MANUAL_MOVEMENT_TYPE_LABELS[item]}
                </option>
              ))}
            </Select>
          </Field>

          <Field label="Cantidad" error={errors.quantity}>
            <Input
              type="number"
              min={isSerialized ? '1' : '0.01'}
              step={isSerialized ? '1' : '0.01'}
              value={quantity}
              onChange={(e) => setQuantity(Number(e.target.value))}
            />
            {isSerialized && (
              <p className="text-text-muted text-xs">
                Los productos serializados requieren cantidades enteras.
              </p>
            )}
          </Field>

          {/* ========================================================================= */}
          {/* SECCIÓN ESPECIAL PARA PRODUCTOS SERIALIZADOS (TELÉFONOS / IMEIs)          */}
          {/* ========================================================================= */}
          {isSerialized && (
            <div className="md:col-span-2 rounded-xl border border-primary/30 bg-primary/5 p-4 space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <span className="text-base font-bold text-primary">Control de IMEIs / Seriales</span>
                  <Badge variant="outline" className="text-xs font-mono font-bold">
                    {isInput ? 'Entrada al Inventario' : 'Salida / Baja'}
                  </Badge>
                </div>
                <Badge variant={errors.serial_units ? 'danger' : 'info'}>
                  {isInput
                    ? `${enteredSerials.length} de ${quantity} ingresados`
                    : `${selectedUnitIds.length} de ${quantity} seleccionados`}
                </Badge>
              </div>

              {isInput ? (
                /* ENTRADA: Inserción de nuevos IMEIs */
                <div className="space-y-1.5">
                  <Label htmlFor="entry-serials" className="text-xs">
                    Escribe o escanea los números de IMEI / serial (uno por línea o separados por coma):
                  </Label>
                  <Textarea
                    id="entry-serials"
                    rows={4}
                    value={entrySerialsText}
                    onChange={(e) => setEntrySerialsText(e.target.value)}
                    placeholder="Ejemplo:&#10;864201041234567&#10;864201041234568&#10;864201041234569"
                    className="font-mono text-xs"
                  />
                  <p className="text-text-muted text-xs">
                    Cada IMEI ingresado se guardará y estará disponible de inmediato para facturar en el POS.
                  </p>
                </div>
              ) : (
                /* SALIDA: Selección de IMEIs existentes disponibles */
                <div className="space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <Label className="text-xs">
                      Selecciona los IMEIs que van a salir ({selectedUnitIds.length}/{quantity}):
                    </Label>
                    <Input
                      type="text"
                      placeholder="Filtrar por IMEI..."
                      value={serialFilterSearch}
                      onChange={(e) => setSerialFilterSearch(e.target.value)}
                      className="h-8 max-w-[200px] text-xs font-mono"
                    />
                  </div>

                  {warehouseId <= 0 ? (
                    <div className="rounded border border-warning/40 bg-warning/10 p-3 text-xs text-warning">
                      Selecciona un almacén para consultar los IMEIs disponibles en stock.
                    </div>
                  ) : isLoadingUnits ? (
                    <div className="rounded border border-border bg-surface p-3 text-xs text-text-muted">
                      Consultando IMEIs en almacén...
                    </div>
                  ) : availableUnits.length === 0 ? (
                    <div className="rounded border border-danger/40 bg-danger/10 p-3 text-xs text-danger">
                      No hay IMEIs disponibles en este almacén{variantId ? ' para la variante seleccionada' : ''}.
                    </div>
                  ) : filteredAvailableUnits.length === 0 ? (
                    <div className="rounded border border-border bg-surface p-3 text-xs text-text-muted">
                      No se encontraron IMEIs con el término "{serialFilterSearch}".
                    </div>
                  ) : (
                    <div className="max-h-48 overflow-y-auto divide-y divide-border rounded-xl border border-border bg-surface">
                      {filteredAvailableUnits.map((u) => {
                        const isChecked = selectedUnitIds.includes(u.id);
                        return (
                          <div
                            key={u.id}
                            onClick={() => toggleUnitSelection(u)}
                            className={`flex items-center justify-between p-2.5 text-xs font-mono cursor-pointer transition-colors ${
                              isChecked ? 'bg-primary/10 font-bold' : 'hover:bg-surface-subtle'
                            }`}
                          >
                            <div className="flex items-center gap-2">
                              <input
                                type="checkbox"
                                checked={isChecked}
                                onChange={() => {}} // controlado por el click del div
                                className="size-4 rounded border-border text-primary cursor-pointer"
                              />
                              <span>{u.serial_number}</span>
                              {u.variant_label && (
                                <span className="rounded bg-surface-subtle px-1.5 py-0.5 text-[10px] font-sans font-medium text-text-muted">
                                  {u.variant_label}
                                </span>
                              )}
                            </div>
                            <span className="text-text-muted uppercase text-[10px]">
                              {u.serial_type}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}

              {errors.serial_units && (
                <p className="text-danger text-xs font-semibold">{errors.serial_units}</p>
              )}
            </div>
          )}

          <div className="md:col-span-2">
            <Field label="Motivo" error={errors.reason}>
              <Input
                maxLength={255}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Describe por qué se requiere el movimiento"
              />
            </Field>
          </div>
          <div className="md:col-span-2">
            <Field label="Notas opcionales">
              <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} />
            </Field>
          </div>
          <DialogFooter className="md:col-span-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" loading={create.isPending}>
              Crear solicitud
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <div className="space-y-2">
      <Label>{label}</Label>
      {children}
      {error && <p className="text-danger text-xs">{error}</p>}
    </div>
  );
}

export function RejectManualMovementDialog({
  movement,
  onOpenChange,
}: {
  movement: ManualMovement | null;
  onOpenChange: (open: boolean) => void;
}) {
  const reject = useRejectManualMovement();
  const [reason, setReason] = useState('');
  useEffect(() => {
    if (movement) setReason('');
  }, [movement]);
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!movement || reason.trim().length < 3) return;
    try {
      await reject.mutateAsync({ id: movement.id, reason: reason.trim() });
      toast.success('Movimiento rechazado');
      onOpenChange(false);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo rechazar.');
    }
  }
  return (
    <Dialog open={Boolean(movement)} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Rechazar movimiento #{movement?.id}</DialogTitle>
          <DialogDescription>
            Indica un motivo claro; quedará registrado en el historial de auditoría.
          </DialogDescription>
        </DialogHeader>
        <form className="space-y-4" onSubmit={submit}>
          <Field label="Motivo del rechazo">
            <Textarea
              autoFocus
              minLength={3}
              required
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={4}
            />
          </Field>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button
              type="submit"
              variant="danger"
              disabled={reason.trim().length < 3}
              loading={reject.isPending}
            >
              Confirmar rechazo
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
