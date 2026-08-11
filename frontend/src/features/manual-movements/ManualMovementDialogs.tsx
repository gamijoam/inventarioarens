import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
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
import { useCreateManualMovement, useRejectManualMovement } from './api';
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
  const { data: products = [] } = useProductsForTransfer();
  const create = useCreateManualMovement();
  const [warehouseId, setWarehouseId] = useState(0);
  const [productId, setProductId] = useState<number | null>(null);
  const [variantId, setVariantId] = useState<number | null>(null);
  const [quantity, setQuantity] = useState(1);
  const [type, setType] = useState<ManualMovementType>('internal_consumption');
  const [reason, setReason] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const { data: variants = [] } = useProductVariants(productId ?? 0);
  const hasVariants = variants.some((variant) => Boolean(variant.color) || Boolean(variant.sku_variant));
  useEffect(() => {
    if (open) {
      setWarehouseId(0);
      setProductId(null);
      setVariantId(null);
      setQuantity(1);
      setType('internal_consumption');
      setReason('');
      setNotes('');
      setErrors({});
    }
  }, [open]);
  // Al cambiar de producto, resetear la variante seleccionada.
  useEffect(() => {
    setVariantId(null);
  }, [productId]);
  const productOptions = useMemo(
    () =>
      products.map((product) => ({
        value: product.id,
        label: product.name,
        hint: product.sku ? `SKU: ${product.sku}` : undefined,
      })),
    [products],
  );
  async function submit(event: FormEvent) {
    event.preventDefault();
    const parsed = CreateManualMovementSchema.safeParse({
      warehouse_id: warehouseId,
      product_id: productId ?? 0,
      product_variant_id: hasVariants ? variantId : null,
      quantity,
      type,
      reason,
      notes: notes || null,
    });
    if (!parsed.success) {
      const next: Record<string, string> = {};
      parsed.error.issues.forEach((issue) => {
        next[String(issue.path[0])] = issue.message;
      });
      setErrors(next);
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
      <DialogContent className="max-w-2xl">
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
              value={productId}
              onChange={(value) => setProductId(value == null ? null : Number(value))}
              options={productOptions}
              placeholder="Buscar producto…"
            />
          </Field>
          {hasVariants && (
            <Field label="Variante / Color" error={errors.product_variant_id}>
              <Select
                value={variantId ?? 0}
                onChange={(e) => setVariantId(e.target.value ? Number(e.target.value) : null)}
              >
                <option value={0}>Seleccionar variante</option>
                {variants
                  .filter((variant) => Boolean(variant.color) || Boolean(variant.sku_variant))
                  .map((variant) => (
                    <option key={variant.id} value={variant.id}>
                      {variant.color ?? variant.sku_variant ?? `Variante ${variant.id}`}
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
              min="0.01"
              step="0.01"
              value={quantity}
              onChange={(e) => setQuantity(Number(e.target.value))}
            />
          </Field>
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
