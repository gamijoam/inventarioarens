import { useEffect, useMemo } from 'react';
import { Boxes, ChevronDown, ChevronUp, Package, Trash2 } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useWarehouses } from '@/features/inventory-center/api';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import { cn } from '@/lib/cn';

import type { ImeiInput } from './ImeiListInput';
import { ImeiListInput } from './ImeiListInput';
import { ProductAutocomplete, type ProductAutocompleteOption } from './ProductAutocomplete';

export interface PurchaseItemRowValue {
  warehouse_id: number | null;
  product_id: number | null;
  product_variant_id: number | null;
  product_info: ProductAutocompleteOption | null;
  quantity: number;
  unit_cost: number;
  serial_units: ImeiInput[];
  error?: string;
}

interface PurchaseItemRowProps {
  value: PurchaseItemRowValue;
  onChange: (next: PurchaseItemRowValue) => void;
  onRemove: () => void;
  disabled?: boolean;
  canRemove: boolean;
  index: number;
  collapsed: boolean;
  onToggleCollapse: (index: number) => void;
}

export function PurchaseItemRow({
  value,
  onChange,
  onRemove,
  disabled,
  canRemove,
  index,
  collapsed,
  onToggleCollapse,
}: PurchaseItemRowProps) {
  const { data: warehouses = [] } = useWarehouses();
  const { data: variants = [], isLoading: variantsLoading } = useProductVariants(
    value.product_id ?? 0,
  );
  const activeVariants = useMemo(() => variants.filter((variant) => variant.is_active), [variants]);
  const hasVariantChoice = activeVariants.length > 1 || activeVariants.some((variant) => variant.color);
  const subtotal = useMemo(
    () => (Number.isFinite(value.quantity) ? value.quantity * value.unit_cost : 0),
    [value.quantity, value.unit_cost],
  );
  const isSerialized = value.product_info?.tracking_type === 'serialized';

  useEffect(() => {
    if (!value.product_id || !value.product_variant_id) return;
    if (!activeVariants.some((variant) => variant.id === value.product_variant_id)) {
      onChange({ ...value, product_variant_id: null });
    }
  }, [activeVariants, onChange, value]);

  return (
    <section
      className={cn(
        'border-border bg-surface overflow-visible rounded-md border',
        collapsed && 'border-border-strong',
      )}
      data-testid={`purchase-item-${index}`}
    >
      <header className="border-border bg-bg/50 flex min-h-14 items-center gap-3 border-b px-4 py-2.5">
        <Button
          type="button"
          size="icon-sm"
          variant="ghost"
          onClick={() => onToggleCollapse(index)}
          disabled={disabled}
          aria-label={collapsed ? `Expandir linea ${index + 1}` : `Colapsar linea ${index + 1}`}
          data-testid={`purchase-item-toggle-${index}`}
        >
          {collapsed ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </Button>
        <div className="bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-md text-sm font-bold">
          {index + 1}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-text-muted text-xs font-semibold uppercase">Producto de la compra</p>
          <p className="truncate text-sm font-semibold">
            {value.product_info?.name ?? 'Pendiente por seleccionar'}
          </p>
          {collapsed && (
            <p className="text-text-muted text-xs tabular-nums">
              {Number.isFinite(value.quantity) ? value.quantity : 0} x{' '}
              {(Number.isFinite(value.unit_cost) ? value.unit_cost : 0).toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
              })}{' '}
              ={' '}
              <strong className="text-text-primary">
                {subtotal.toLocaleString('es-VE', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })}
              </strong>
            </p>
          )}
        </div>
        {value.product_info?.tracking_type === 'serialized' && (
          <Badge variant="info" className="shrink-0">
            IMEI / serial
          </Badge>
        )}
        {canRemove && (
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            onClick={onRemove}
            disabled={disabled}
            aria-label={`Eliminar linea ${index + 1}`}
          >
            <Trash2 className="text-danger size-4" />
          </Button>
        )}
      </header>

      {!collapsed && (
        <div className="space-y-4 p-4">
        <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_240px]">
          <div className="min-w-0 space-y-1.5">
            <label className="text-text-secondary text-xs font-semibold uppercase">Producto</label>
            <ProductAutocomplete
              value={value.product_id}
              selectedProduct={value.product_info}
              invalid={!value.product_id}
              onChange={(id, product) =>
                onChange({
                  ...value,
                  product_id: id,
                  product_variant_id: null,
                  product_info: product ?? null,
                  serial_units: product?.tracking_type === 'serialized' ? [] : value.serial_units,
                })
              }
            />
            {value.product_info && (
              <div className="text-text-muted flex flex-wrap items-center gap-2 pt-1 text-xs">
                <Package className="size-3.5" />
                <span>Unidad: {value.product_info.unit_of_measure ?? 'unidad'}</span>
                {value.product_info.base_price != null && (
                  <span>
                    Precio base:{' '}
                    {Number(value.product_info.base_price).toLocaleString('es-VE', {
                      minimumFractionDigits: 2,
                    })}
                  </span>
                )}
              </div>
            )}
            {value.product_info && hasVariantChoice && (
              <div className="mt-3 space-y-1.5">
                <label className="text-text-secondary text-xs font-semibold uppercase">
                  Variante / color <span className="text-danger">*</span>
                </label>
                <Select
                  value={value.product_variant_id ? String(value.product_variant_id) : ''}
                  onChange={(event) =>
                    onChange({
                      ...value,
                      product_variant_id: event.target.value ? Number(event.target.value) : null,
                    })
                  }
                  disabled={disabled || variantsLoading}
                  className={cn('h-11', !value.product_variant_id && 'border-warning')}
                >
                  <option value="">
                    {variantsLoading ? 'Cargando variantes...' : 'Seleccionar variante / color'}
                  </option>
                  {activeVariants.map((variant) => (
                    <option key={variant.id} value={String(variant.id)}>
                      {variant.color ?? 'Variante general'}
                      {variant.sku_variant ? ` · ${variant.sku_variant}` : ''}
                    </option>
                  ))}
                </Select>
                {!value.product_variant_id && (
                  <p className="text-warning text-xs">Selecciona el color o variante que ingresará.</p>
                )}
              </div>
            )}
          </div>

          <div className="space-y-1.5">
            <label className="text-text-secondary flex items-center gap-1.5 text-xs font-semibold uppercase">
              <Boxes className="size-3.5" /> Almacen <span className="text-danger">*</span>
            </label>
            <Select
              value={value.warehouse_id ? String(value.warehouse_id) : ''}
              onChange={(event) =>
                onChange({
                  ...value,
                  warehouse_id: event.target.value ? Number(event.target.value) : null,
                })
              }
              disabled={disabled}
              className={cn('h-11', !value.warehouse_id && 'border-warning')}
            >
              <option value="">
                {warehouses.length === 0 ? 'No hay almacenes disponibles' : 'Seleccionar almacen'}
              </option>
              {warehouses.map((warehouse) => (
                <option key={warehouse.id} value={String(warehouse.id)}>
                  {warehouse.code}
                </option>
              ))}
            </Select>
            {!value.warehouse_id && (
              <p className="text-warning text-xs">Selecciona donde ingresara la mercancia.</p>
            )}
          </div>
        </div>

        <div className="border-border grid grid-cols-1 gap-3 border-t pt-4 sm:grid-cols-[160px_180px_minmax(180px,1fr)]">
          <div className="space-y-1.5">
            <label className="text-text-secondary text-xs font-semibold uppercase">Cantidad</label>
            <Input
              type="number"
              min={isSerialized ? 1 : 0.0001}
              step={isSerialized ? 1 : 0.0001}
              value={value.quantity ?? ''}
              onChange={(event) => onChange({ ...value, quantity: Number(event.target.value) })}
              disabled={disabled}
              placeholder="0"
              className="text-right tabular-nums"
              data-testid={`purchase-item-quantity-${index}`}
            />
            {isSerialized && (
              <p className="text-text-muted text-xs">Una unidad por cada IMEI o serial.</p>
            )}
          </div>

          <div className="space-y-1.5">
            <label className="text-text-secondary text-xs font-semibold uppercase">
              Costo unitario
            </label>
            <Input
              type="number"
              min={0.0001}
              step={0.0001}
              value={value.unit_cost ?? ''}
              onChange={(event) => onChange({ ...value, unit_cost: Number(event.target.value) })}
              disabled={disabled}
              placeholder="0.00"
              className="text-right tabular-nums"
            />
          </div>

          <div className="space-y-1.5">
            <label className="text-text-secondary text-xs font-semibold uppercase">Subtotal</label>
            <div className="bg-bg flex h-10 items-center justify-end rounded-md px-3 text-base font-bold tabular-nums">
              {subtotal.toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
              })}
            </div>
          </div>
        </div>

        {isSerialized && value.product_id && (
          <div className="border-info/30 bg-info/5 rounded-md border p-3">
            <ImeiListInput
              value={value.serial_units}
              onChange={(serial_units) => onChange({ ...value, serial_units })}
              expectedQuantity={value.quantity || 1}
              disabled={disabled}
            />
          </div>
        )}

        {value.error && <p className="text-danger text-xs font-medium">{value.error}</p>}
        </div>
      )}
    </section>
  );
}
