import { useProductVariants } from '@/features/inventory-center/variantApi';
import { Label } from '@/components/ui/Label';

interface VariantSelectProps {
  productId: number;
  warehouseId?: number | null;
  value: number | null;
  onChange: (variantId: number | null) => void;
  disabled?: boolean;
  testIdPrefix?: string;
}

/**
 * Selector de variante por linea de traslado. Solo se muestra cuando el
 * producto tiene variantes activas. La primera opcion es "Sin variante"
 * (comportamiento legacy a nivel de producto).
 */
export function VariantSelect({
  productId,
  warehouseId,
  value,
  onChange,
  disabled,
  testIdPrefix = 'variant',
}: VariantSelectProps) {
  const { data: variants = [] } = useProductVariants(productId, warehouseId);

  if (variants.length === 0) {
    return null;
  }

  return (
    <div className="space-y-1">
      <Label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
        Variante
      </Label>
      <select
        data-testid={`${testIdPrefix}-select`}
        className="border-border-strong bg-surface w-full rounded border px-3 py-2 text-sm"
        value={value ?? ''}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
      >
        <option value="">Sin variante</option>
        {variants.map((v) => (
          <option key={v.id} value={v.id}>
            {v.color ?? v.sku_variant ?? `Variante #${v.id}`}
            {typeof v.stock_available === 'number' ? ` (${v.stock_available})` : ''}
          </option>
        ))}
      </select>
    </div>
  );
}
