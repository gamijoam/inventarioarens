import { useEffect, useMemo } from 'react';
import {
  AlertTriangle,
  ArrowDownRight,
  ArrowUpRight,
  Boxes,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Lightbulb,
  Package,
  PackagePlus,
  Pencil,
  RefreshCw,
  Sparkles,
  Trash2,
} from 'lucide-react';

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
  quantity: number | string;
  unit_cost: number | string;
  new_sale_price?: number | string;
  update_sale_price?: boolean;
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
  onEditProduct?: (productId: number) => void;
  onCreateProduct?: (initialName?: string) => void;
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
  onEditProduct,
  onCreateProduct,
}: PurchaseItemRowProps) {
  const { data: warehouses = [] } = useWarehouses();
  const { data: variants = [], isLoading: variantsLoading } = useProductVariants(
    value.product_id ?? 0,
  );
  const activeVariants = useMemo(() => variants.filter((variant) => variant.is_active), [variants]);
  const hasVariantChoice = activeVariants.length > 1 || activeVariants.some((variant) => variant.color);
  const subtotal = useMemo(
    () =>
      Number.isFinite(Number(value.quantity)) && Number.isFinite(Number(value.unit_cost))
        ? Number(value.quantity) * Number(value.unit_cost)
        : 0,
    [value.quantity, value.unit_cost],
  );
  const isSerialized = value.product_info?.tracking_type === 'serialized';

  const productInfo = value.product_info;

  // Costo anterior de referencia (prioriza last_purchase_cost, luego average_cost)
  const previousCost = useMemo(() => {
    if (!productInfo) return null;
    if (productInfo.last_purchase_cost != null && Number(productInfo.last_purchase_cost) > 0) {
      return Number(productInfo.last_purchase_cost);
    }
    if (productInfo.average_cost != null && Number(productInfo.average_cost) > 0) {
      return Number(productInfo.average_cost);
    }
    return null;
  }, [productInfo]);

  const currentPvp = useMemo(() => {
    return productInfo?.base_price != null ? Number(productInfo.base_price) : null;
  }, [productInfo]);

  const configuredMargin = useMemo(() => {
    return productInfo?.profit_margin != null ? Number(productInfo.profit_margin) : null;
  }, [productInfo]);

  const isAutomaticPricing = productInfo?.pricing_mode === 'automatic';

  // Costo numerico ingresado en este item
  const numericCost = Number(value.unit_cost);
  const hasValidCost = Number.isFinite(numericCost) && numericCost > 0;

  // Comparacion de costo (Variacion de costo nuevo vs costo anterior)
  const costDiff = useMemo(() => {
    if (!hasValidCost || previousCost == null || previousCost <= 0) return null;
    const diff = numericCost - previousCost;
    const diffPct = (diff / previousCost) * 100;
    return { diff, diffPct };
  }, [hasValidCost, numericCost, previousCost]);

  // Margen proyectado con el PVP actual
  const projectedAnalysis = useMemo(() => {
    if (!hasValidCost || currentPvp == null || currentPvp <= 0) return null;
    const profit = currentPvp - numericCost;
    const marginPct = (profit / numericCost) * 100;
    const isLoss = profit < 0;
    return { profit, marginPct, isLoss };
  }, [currentPvp, hasValidCost, numericCost]);

  // PVP Sugerido para mantener el margen configurado (o un 30% por defecto si no tiene)
  const effectiveMarginForSuggestion = configuredMargin ?? 30;
  const suggestedPvp = useMemo(() => {
    if (!hasValidCost) return null;
    return Math.round(numericCost * (1 + effectiveMarginForSuggestion / 100) * 100) / 100;
  }, [effectiveMarginForSuggestion, hasValidCost, numericCost]);

  /**
   * Acepta solo digitos y un separador decimal (punto o coma), sin forzar
   * `Number('')` a 0 para no perder el '.' al escribir.
   */
  const onDecimalInput = (
    raw: string,
    set: (next: number | string) => void,
  ) => {
    const cleaned = raw.replace(/,/g, '.').replace(/[^0-9.]/g, '');
    if (cleaned.split('.').length > 2) return;
    set(cleaned);
  };

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
          <div className="flex items-center gap-2">
            <span className="text-text-muted text-[10px] font-semibold uppercase">Línea {index + 1}</span>
            {value.product_info?.sku && (
              <code className="bg-bg text-text-secondary rounded px-1.5 py-0.5 text-[11px] font-mono font-bold">
                {value.product_info.sku}
              </code>
            )}
          </div>
          <p className="truncate text-sm font-semibold text-text-primary">
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

        {/* Indicador destacado de CANTIDAD agregada en esta tarjeta */}
        <div className="flex items-center gap-2">
          {Number(value.quantity) > 0 ? (
            <Badge variant="primary" className="text-xs px-2.5 py-1 font-bold flex items-center gap-1 shadow-xs">
              <Boxes className="size-3.5" />
              <span>{value.quantity} {value.product_info?.unit_of_measure ?? 'uds'}</span>
            </Badge>
          ) : (
            <Badge variant="warning" className="text-xs px-2 py-0.5 font-medium">
              ⚠️ Sin cantidad (0)
            </Badge>
          )}

          {hasValidCost && (
            <div className="hidden sm:flex items-center gap-1.5 text-xs text-text-secondary font-mono">
              <span>x ${numericCost.toFixed(2)}</span>
              <span>=</span>
              <strong className="text-sm font-black text-text-primary">
                ${subtotal.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </strong>
            </div>
          )}
        </div>

        {value.product_info?.tracking_type === 'serialized' && (
          <Badge variant="info" className="shrink-0 text-xs">
            IMEI ({value.serial_units.length}/{Number(value.quantity) || 0})
          </Badge>
        )}
        {canRemove && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={onRemove}
            disabled={disabled}
            className="h-8 gap-1.5 px-2.5 text-xs font-semibold text-text-secondary hover:border-danger/40 hover:bg-danger/10 hover:text-danger"
            aria-label={`Eliminar linea ${index + 1}`}
            data-testid={`purchase-item-remove-${index}`}
          >
            <Trash2 className="size-3.5" /> Eliminar
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
              onChange={(id, product) => {
                const prev = product?.last_purchase_cost != null && Number(product.last_purchase_cost) > 0
                  ? Number(product.last_purchase_cost)
                  : (product?.average_cost != null && Number(product.average_cost) > 0 ? Number(product.average_cost) : null);

                // Si no había costo ingresado previamente, auto-sugerir el costo anterior
                const nextCost = (value.unit_cost === '' || value.unit_cost == null) && prev != null
                  ? String(prev)
                  : value.unit_cost;

                onChange({
                  ...value,
                  product_id: id,
                  product_variant_id: null,
                  product_info: product ?? null,
                  unit_cost: nextCost,
                  serial_units: product?.tracking_type === 'serialized' ? [] : value.serial_units,
                });
              }}
              onProductNotFound={(query) => onCreateProduct?.(query)}
            />
            {(onEditProduct || onCreateProduct) && (
              <div className="flex flex-wrap items-center gap-2 pt-1">
                {value.product_id && onEditProduct && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="h-7 px-2 text-[11px] text-text-muted hover:text-primary"
                    onClick={() => onEditProduct(value.product_id!)}
                    data-testid={`purchase-item-edit-product-${index}`}
                  >
                    <Pencil className="size-3.5" /> Editar producto
                  </Button>
                )}
                {onCreateProduct && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="h-7 px-2 text-[11px] text-text-muted hover:text-primary"
                    onClick={() => onCreateProduct()}
                    data-testid={`purchase-item-create-product-${index}`}
                  >
                    <PackagePlus className="size-3.5" /> Nuevo producto
                  </Button>
                )}
              </div>
            )}
            {value.product_info && (
              <div className="flex flex-wrap items-center gap-2 pt-1 text-xs">
                <div className="text-text-muted flex items-center gap-1.5">
                  <Package className="size-3.5" />
                  <span>Unidad: {value.product_info.unit_of_measure ?? 'unidad'}</span>
                </div>

                <span className="text-border">|</span>
                <span className="inline-flex items-center gap-1">
                  <span className="text-text-secondary font-medium">Último costo:</span>
                  {previousCost != null ? (
                    <code className="bg-bg text-text-primary rounded px-1.5 py-0.5 font-semibold">
                      ${previousCost.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </code>
                  ) : (
                    <span className="text-text-muted italic">Sin costo previo</span>
                  )}
                </span>

                <span className="text-border">|</span>
                <span className="inline-flex items-center gap-1">
                  <span className="text-text-secondary font-medium">PVP actual:</span>
                  {currentPvp != null ? (
                    <code className="bg-primary/10 text-primary rounded px-1.5 py-0.5 font-semibold">
                      ${currentPvp.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </code>
                  ) : (
                    <span className="text-text-muted italic">Sin PVP</span>
                  )}
                </span>

                <span className="text-border">|</span>
                <span className="inline-flex items-center gap-1">
                  <span className="text-text-secondary font-medium">Margen:</span>
                  <Badge variant={isAutomaticPricing ? 'info' : 'default'} className="text-[11px] py-0">
                    {configuredMargin != null ? `${configuredMargin.toFixed(1)}%` : 'Manual'}
                    {isAutomaticPricing ? ' (Auto)' : ''}
                  </Badge>
                </span>

                {previousCost != null && String(value.unit_cost) !== String(previousCost) && (
                  <button
                    type="button"
                    onClick={() => onChange({ ...value, unit_cost: String(previousCost) })}
                    className="text-primary hover:underline ml-auto flex items-center gap-1 text-[11px] font-medium cursor-pointer"
                  >
                    <RefreshCw className="size-3" />
                    Cargar costo anterior (${previousCost.toFixed(2)})
                  </button>
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
              type="text"
              inputMode="decimal"
              value={value.quantity === '' || value.quantity == null ? '' : String(value.quantity)}
              onChange={(event) =>
                onDecimalInput(event.target.value, (next) =>
                  onChange({ ...value, quantity: next }),
                )
              }
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
              type="text"
              inputMode="decimal"
              value={value.unit_cost === '' || value.unit_cost == null ? '' : String(value.unit_cost)}
              onChange={(event) =>
                onDecimalInput(event.target.value, (next) =>
                  onChange({ ...value, unit_cost: next }),
                )
              }
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

        {value.product_info && hasValidCost && (
          <div className="bg-bg/60 border-border/80 rounded-md border p-3 text-xs space-y-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              {/* 1. Variacion de costo */}
              {costDiff && (
                <div className="flex items-center gap-1.5">
                  {costDiff.diff > 0.0001 ? (
                    <Badge variant="warning" className="flex items-center gap-1 font-medium">
                      <ArrowUpRight className="size-3.5 text-warning" />
                      Costo aumentó +{costDiff.diffPct.toFixed(1)}% (+${costDiff.diff.toFixed(2)})
                    </Badge>
                  ) : costDiff.diff < -0.0001 ? (
                    <Badge variant="success" className="flex items-center gap-1 font-medium">
                      <ArrowDownRight className="size-3.5 text-success" />
                      Costo disminuyó {costDiff.diffPct.toFixed(1)}% (-${Math.abs(costDiff.diff).toFixed(2)})
                    </Badge>
                  ) : (
                    <Badge variant="default" className="flex items-center gap-1">
                      <CheckCircle2 className="size-3.5 text-success" />
                      Mismo costo anterior (${previousCost?.toFixed(2)})
                    </Badge>
                  )}
                </div>
              )}

              {/* 2. Margen proyectado con PVP actual */}
              {projectedAnalysis && (
                <div className="flex items-center gap-1.5">
                  {projectedAnalysis.isLoss ? (
                    <div className="text-danger flex items-center gap-1 font-semibold">
                      <AlertTriangle className="size-3.5" />
                      ¡Venta a pérdida con PVP actual (${currentPvp?.toFixed(2)})! Margen: {projectedAnalysis.marginPct.toFixed(1)}%
                    </div>
                  ) : (
                    <div className="text-text-secondary flex items-center gap-1">
                      <span>Margen con PVP actual:</span>
                      <span className="text-text-primary font-semibold">
                        {projectedAnalysis.marginPct.toFixed(1)}%
                      </span>
                      <span className="text-text-muted">
                        (+${projectedAnalysis.profit.toFixed(2)}/ud)
                      </span>
                    </div>
                  )}
                </div>
              )}
            </div>

            {/* 3. Sugerencia y Actualizacion de PVP */}
            <div className="border-border/60 flex flex-wrap items-center justify-between gap-3 border-t pt-2">
              <div className="flex items-center gap-2">
                <Lightbulb className="text-warning size-4 shrink-0" />
                <span className="text-text-secondary">
                  PVP sugerido (margen {effectiveMarginForSuggestion}%):{' '}
                  <strong className="text-text-primary text-sm font-bold">
                    ${suggestedPvp?.toFixed(2)} USD
                  </strong>
                </span>
                {isAutomaticPricing && (
                  <Badge variant="info" className="text-[10px]">
                    Modo auto: se actualizará al recibir
                  </Badge>
                )}
              </div>

              {!isAutomaticPricing && (
                <div className="flex items-center gap-2">
                  <label className="flex items-center gap-1.5 cursor-pointer select-none text-xs">
                    <input
                      type="checkbox"
                      checked={Boolean(value.update_sale_price)}
                      onChange={(e) => {
                        const checked = e.target.checked;
                        onChange({
                          ...value,
                          update_sale_price: checked,
                          new_sale_price: checked
                            ? (value.new_sale_price || (suggestedPvp ? String(suggestedPvp) : ''))
                            : '',
                        });
                      }}
                      className="rounded border-border"
                    />
                    <span className="text-text-secondary font-medium">
                      Actualizar PVP al recibir:
                    </span>
                  </label>

                  {value.update_sale_price && (
                    <div className="flex items-center gap-1.5">
                      <Input
                        type="text"
                        inputMode="decimal"
                        value={value.new_sale_price ?? ''}
                        onChange={(e) =>
                          onDecimalInput(e.target.value, (next) =>
                            onChange({ ...value, new_sale_price: next }),
                          )
                        }
                        placeholder={suggestedPvp ? String(suggestedPvp) : '0.00'}
                        className="h-8 w-24 text-right text-xs font-semibold tabular-nums"
                      />
                      {suggestedPvp != null && String(value.new_sale_price) !== String(suggestedPvp) && (
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="h-8 px-2 text-[11px]"
                          onClick={() => onChange({ ...value, new_sale_price: String(suggestedPvp) })}
                        >
                          <Sparkles className="size-3 mr-1 text-warning" />
                          Usar sugerido
                        </Button>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        )}

        {isSerialized && value.product_id && (
          <div className="border-info/30 bg-info/5 rounded-md border p-3">
            <ImeiListInput
              value={value.serial_units}
              onChange={(serial_units) => onChange({ ...value, serial_units })}
              expectedQuantity={Number(value.quantity) || 1}
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
