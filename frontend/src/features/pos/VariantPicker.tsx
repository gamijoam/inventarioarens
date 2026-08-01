import { useEffect, useMemo, useState } from 'react';
import { Loader2, X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import type { ProductVariant } from '@/features/inventory-center/variantSchemas';
import { cn } from '@/lib/cn';

export interface VariantPickerValue {
  variant: ProductVariant;
  quantity: number;
}

interface VariantPickerProps {
  productId: number;
  productName: string;
  warehouseId?: number | null;
  open: boolean;
  initialVariantId?: number | null;
  initialQuantity?: number;
  onClose: () => void;
  onSelect: (value: VariantPickerValue) => void;
}

/**
 * Modal para que el cajero elija color/variante antes de agregar al carrito.
 * Solo se muestra cuando el producto tiene mas de una variante activa.
 * Si hay stock por almacen disponible (warehouseId), la columna "Disponible"
 * muestra el numero real por color en ese almacen.
 */
export function VariantPicker({
  productId,
  productName,
  warehouseId,
  open,
  initialVariantId,
  initialQuantity = 1,
  onClose,
  onSelect,
}: VariantPickerProps) {
  const { data: variants = [], isLoading } = useProductVariants(productId, warehouseId);

  const [selectedId, setSelectedId] = useState<number | null>(initialVariantId ?? null);
  const [quantity, setQuantity] = useState<number>(initialQuantity);

  useEffect(() => {
    if (!open) return;
    setSelectedId(initialVariantId ?? null);
    setQuantity(Math.max(1, initialQuantity));
  }, [open, initialVariantId, initialQuantity]);

  const sorted = useMemo(() => [...variants].sort((a, b) => a.position - b.position), [variants]);

  const selected = sorted.find((variant) => variant.id === selectedId) ?? null;
  const available = selected?.stock_available ?? 0;
  const exceedsStock = quantity > available && available > 0;

  if (!open) return null;

  function handleConfirm() {
    if (!selected) return;
    onSelect({ variant: selected, quantity });
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Elegir color: {productName}</DialogTitle>
        </DialogHeader>
        <div className="space-y-4">
          <p className="text-text-muted text-sm">
            Selecciona el color que vas a vender. La cantidad se descuenta del almacen activo.
          </p>

          {isLoading ? (
            <div className="text-text-muted flex items-center justify-center py-8 text-sm">
              <Loader2 className="size-4 animate-spin" /> Cargando variantes...
            </div>
          ) : sorted.length === 0 ? (
            <div className="border-border bg-bg/40 text-text-muted rounded border p-4 text-sm">
              Este producto no tiene variantes activas.
              <DialogFooter className="mt-3">
                <Button type="button" onClick={onClose}>
                  Cerrar
                </Button>
              </DialogFooter>
            </div>
          ) : (
            <>
              <ul
                className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                data-testid="variant-picker-list"
              >
                {sorted.map((variant) => {
                  const isSelected = variant.id === selectedId;
                  const stock = variant.stock_available ?? 0;
                  const disabled = stock <= 0;
                  return (
                    <li key={variant.id}>
                      <button
                        type="button"
                        onClick={() => setSelectedId(variant.id)}
                        disabled={disabled}
                        aria-pressed={isSelected}
                        className={cn(
                          'flex w-full items-center gap-3 rounded-md border px-3 py-2 text-left transition-colors',
                          isSelected ? 'border-primary bg-primary/10' : 'border-border hover:bg-bg',
                          disabled && 'cursor-not-allowed opacity-50',
                        )}
                        data-testid={`variant-option-${variant.id}`}
                      >
                        <span
                          className="border-border inline-block size-6 shrink-0 rounded-full border"
                          style={{ backgroundColor: variant.color_hex ?? '#888888' }}
                          aria-hidden="true"
                        />
                        <span className="flex-1">
                          <span className="block text-sm font-medium">
                            {variant.color ?? 'Default'}
                          </span>
                          <span className="text-text-muted block text-xs">
                            {variant.sku_variant ??
                              variant.barcode_variant ??
                              `Variante #${variant.id}`}
                          </span>
                        </span>
                        <span
                          className={cn(
                            'shrink-0 rounded px-2 py-0.5 text-xs tabular-nums',
                            stock > 0 ? 'bg-success/15 text-success' : 'bg-bg text-text-muted',
                          )}
                        >
                          {stock > 0 ? `${stock} disp.` : 'Sin stock'}
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>

              <div className="space-y-2">
                <label htmlFor="variant-quantity" className="text-sm font-medium">
                  Cantidad
                </label>
                <input
                  id="variant-quantity"
                  type="number"
                  min={1}
                  max={available || undefined}
                  step="0.0001"
                  value={quantity}
                  onChange={(event) => setQuantity(Math.max(1, Number(event.target.value)))}
                  className="border-border-strong bg-surface w-32 rounded border px-2 py-1 text-sm"
                />
                {exceedsStock && (
                  <p className="text-danger text-xs">
                    La cantidad supera el stock disponible ({available}).
                  </p>
                )}
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                  <X className="size-4" /> Cancelar
                </Button>
                <Button type="button" onClick={handleConfirm} disabled={!selected || exceedsStock}>
                  Agregar al carrito
                </Button>
              </DialogFooter>
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
