import { useEffect } from 'react';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import { Label } from '@/components/ui/Label';
import { cn } from '@/lib/cn';

interface VariantSelectProps {
  productId: number;
  warehouseId?: number | null;
  value: number | null;
  onChange: (variantId: number | null) => void;
  disabled?: boolean;
  testIdPrefix?: string;
  required?: boolean;
  invalid?: boolean;
  onHasVariantsChange?: (hasVariants: boolean) => void;
}

/**
 * Selector de variante por linea de traslado. Solo se muestra cuando el
 * producto tiene variantes activas.
 */
export function VariantSelect({
  productId,
  warehouseId,
  value,
  onChange,
  disabled,
  testIdPrefix = 'variant',
  required = false,
  invalid = false,
  onHasVariantsChange,
}: VariantSelectProps) {
  const { data: variants = [] } = useProductVariants(productId, warehouseId);
  const realVariants = variants.filter((v) => Boolean(v.color || v.sku_variant));
  const hasVariants = realVariants.length > 0 || variants.length > 1;

  useEffect(() => {
    onHasVariantsChange?.(hasVariants);
  }, [hasVariants, onHasVariantsChange]);

  if (!hasVariants) {
    return null;
  }

  const listToDisplay = realVariants.length > 0 ? realVariants : variants;

  return (
    <div className="space-y-1">
      <Label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
        Variante {required && <span className="text-danger">*</span>}
      </Label>
      <select
        data-testid={`${testIdPrefix}-select`}
        className={cn(
          'bg-surface w-full rounded border px-3 py-2 text-sm transition-colors',
          invalid ? 'border-danger ring-1 ring-danger' : 'border-border-strong',
        )}
        value={value ?? ''}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
      >
        <option value="">{required ? '-- Seleccionar variante obligatoria --' : 'Sin variante'}</option>
        {listToDisplay.map((v) => (
          <option key={v.id} value={v.id}>
            {v.color ?? v.sku_variant ?? `Variante #${v.id}`}
            {typeof v.stock_available === 'number' ? ` (${v.stock_available} disp.)` : ''}
          </option>
        ))}
      </select>
    </div>
  );
}
