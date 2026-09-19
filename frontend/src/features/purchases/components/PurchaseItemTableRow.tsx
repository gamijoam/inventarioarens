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
  PackagePlus,
  Pencil,
  RefreshCw,
  Trash2,
} from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useWarehouses } from '@/features/inventory-center/api';
import { useProductVariants } from '@/features/inventory-center/variantApi';
import { cn } from '@/lib/cn';

import { ImeiListInput } from './ImeiListInput';
import { ProductAutocomplete } from './ProductAutocomplete';
import type { PurchaseItemRowValue } from './PurchaseItemRow';

interface PurchaseItemTableRowProps {
  value: PurchaseItemRowValue;
  onChange: (next: PurchaseItemRowValue) => void;
  onRemove: () => void;
  disabled?: boolean;
  canRemove: boolean;
  index: number;
  isExpanded: boolean;
  onToggleExpand: (index: number) => void;
  onEditProduct?: (productId: number) => void;
  onCreateProduct?: (initialName?: string) => void;
}

export function PurchaseItemTableRow({
  value,
  onChange,
  onRemove,
  disabled,
  canRemove,
  index,
  isExpanded,
  onToggleExpand,
  onEditProduct,
  onCreateProduct,
}: PurchaseItemTableRowProps) {
  const { data: warehouses = [] } = useWarehouses();
  const { data: variants = [], isLoading: variantsLoading } = useProductVariants(
    value.product_id ?? 0,
  );
  const activeVariants = useMemo(() => variants.filter((variant) => variant.is_active), [variants]);
  const hasVariantChoice = activeVariants.length > 1 || activeVariants.some((variant) => variant.color);

  const quantityNum = Number(value.quantity) || 0;
  const unitCostNum = Number(value.unit_cost) || 0;
  const subtotal = useMemo(() => {
    return Number.isFinite(quantityNum) && Number.isFinite(unitCostNum)
      ? quantityNum * unitCostNum
      : 0;
  }, [quantityNum, unitCostNum]);

  const isSerialized = value.product_info?.tracking_type === 'serialized';
  const productInfo = value.product_info;

  // Costo anterior de referencia
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
  const hasValidCost = Number.isFinite(unitCostNum) && unitCostNum > 0;

  // Variación de costo
  const costDiff = useMemo(() => {
    if (!hasValidCost || previousCost == null || previousCost <= 0) return null;
    const diff = unitCostNum - previousCost;
    const diffPct = (diff / previousCost) * 100;
    return { diff, diffPct };
  }, [hasValidCost, unitCostNum, previousCost]);

  // Margen proyectado con PVP actual
  const projectedAnalysis = useMemo(() => {
    if (!hasValidCost || currentPvp == null || currentPvp <= 0) return null;
    const profit = currentPvp - unitCostNum;
    const marginPct = (profit / unitCostNum) * 100;
    const isLoss = profit < 0;
    return { profit, marginPct, isLoss };
  }, [currentPvp, hasValidCost, unitCostNum]);

  // PVP Sugerido
  const effectiveMarginForSuggestion = configuredMargin ?? 30;
  const suggestedPvp = useMemo(() => {
    if (!hasValidCost) return null;
    return Math.round(unitCostNum * (1 + effectiveMarginForSuggestion / 100) * 100) / 100;
  }, [effectiveMarginForSuggestion, hasValidCost, unitCostNum]);

  const onDecimalInput = (raw: string, set: (next: number | string) => void) => {
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

  const serialsCount = value.serial_units.length;
  const serialsComplete = isSerialized && serialsCount === quantityNum && quantityNum > 0;

  return (
    <>
      <tr
        className={cn(
          'border-b border-border/80 transition-colors hover:bg-surface-subtle/50',
          index % 2 === 0 ? 'bg-surface' : 'bg-surface-subtle/20',
          isExpanded && 'bg-primary/5 hover:bg-primary/5',
        )}
        data-testid={`purchase-table-row-${index}`}
      >
        {/* 1. Correlativo + Toggle Expandir */}
        <td className="p-3 text-center align-middle whitespace-nowrap">
          <div className="flex items-center justify-center gap-1">
            <Button
              type="button"
              size="icon-sm"
              variant="ghost"
              onClick={() => onToggleExpand(index)}
              disabled={disabled}
              className="size-7"
              aria-label={isExpanded ? 'Contraer fila' : 'Expandir fila'}
            >
              {isExpanded ? <ChevronUp className="size-3.5" /> : <ChevronDown className="size-3.5" />}
            </Button>
            <span className="bg-primary/10 text-primary font-mono text-xs font-bold rounded size-6 flex items-center justify-center">
              {index + 1}
            </span>
          </div>
        </td>

        {/* 2. Producto (Nombre, SKU, Variantes) */}
        <td className="p-3 align-middle min-w-[280px]">
          {value.product_id ? (
            <div className="space-y-1">
              <div className="flex items-center gap-1.5 flex-wrap">
                <span className="font-bold text-sm text-text-primary">
                  {value.product_info?.name}
                </span>
                {value.product_info?.sku && (
                  <code className="bg-bg text-text-muted rounded px-1.5 py-0.5 text-[11px] font-mono font-semibold">
                    {value.product_info.sku}
                  </code>
                )}
                {isSerialized && (
                  <Badge variant={serialsComplete ? 'success' : 'warning'} className="text-[10px] py-0 px-1.5 font-bold">
                    IMEI ({serialsCount}/{quantityNum})
                  </Badge>
                )}
              </div>

              <div className="flex items-center gap-2 text-xs text-text-muted">
                <span>Unidad: <strong className="text-text-secondary">{value.product_info?.unit_of_measure ?? 'unidad'}</strong></span>
                {previousCost != null && (
                  <span>· Último costo: <strong className="text-text-secondary">${previousCost.toFixed(2)}</strong></span>
                )}
                {currentPvp != null && (
                  <span>· PVP: <strong className="text-primary">${currentPvp.toFixed(2)}</strong></span>
                )}
              </div>

              {/* Selector de variante si aplica */}
              {hasVariantChoice && (
                <div className="pt-1">
                  <Select
                    value={value.product_variant_id ? String(value.product_variant_id) : ''}
                    onChange={(e) =>
                      onChange({
                        ...value,
                        product_variant_id: e.target.value ? Number(e.target.value) : null,
                      })
                    }
                    disabled={disabled || variantsLoading}
                    className={cn('h-8 text-xs', !value.product_variant_id && 'border-warning')}
                  >
                    <option value="">
                      {variantsLoading ? 'Cargando variantes...' : '— Seleccionar variante / color —'}
                    </option>
                    {activeVariants.map((variant) => (
                      <option key={variant.id} value={String(variant.id)}>
                        {variant.color ?? 'Variante general'}{variant.sku_variant ? ` (${variant.sku_variant})` : ''}
                      </option>
                    ))}
                  </Select>
                </div>
              )}
            </div>
          ) : (
            <ProductAutocomplete
              value={value.product_id}
              selectedProduct={value.product_info}
              invalid={!value.product_id}
              placeholder="Buscar producto a agregar..."
              onChange={(id, product) => {
                const prev = product?.last_purchase_cost != null && Number(product.last_purchase_cost) > 0
                  ? Number(product.last_purchase_cost)
                  : (product?.average_cost != null && Number(product.average_cost) > 0 ? Number(product.average_cost) : null);

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
          )}
        </td>

        {/* 3. Almacén */}
        <td className="p-3 align-middle w-[150px]">
          <Select
            value={value.warehouse_id ? String(value.warehouse_id) : ''}
            onChange={(e) =>
              onChange({
                ...value,
                warehouse_id: e.target.value ? Number(e.target.value) : null,
              })
            }
            disabled={disabled}
            className={cn('h-9 text-xs', !value.warehouse_id && 'border-warning')}
          >
            <option value="">— Almacén —</option>
            {warehouses.map((wh) => (
              <option key={wh.id} value={String(wh.id)}>
                {wh.code}
              </option>
            ))}
          </Select>
          {!value.warehouse_id && (
            <span className="text-[10px] text-warning block mt-0.5">Requerido</span>
          )}
        </td>

        {/* 4. Cantidad (Input con badge de cantidad y unidad) */}
        <td className="p-3 align-middle w-[130px] text-right">
          <div className="space-y-1">
            <Input
              type="text"
              inputMode="decimal"
              value={value.quantity === '' || value.quantity == null ? '' : String(value.quantity)}
              onChange={(e) =>
                onDecimalInput(e.target.value, (next) =>
                  onChange({ ...value, quantity: next }),
                )
              }
              disabled={disabled}
              placeholder="0"
              className={cn(
                'h-9 text-right font-mono font-bold tabular-nums text-sm',
                quantityNum <= 0 && 'border-warning bg-warning/5',
              )}
            />
            <div className="flex justify-end">
              <Badge
                variant={quantityNum > 0 ? 'primary' : 'outline'}
                className="text-[10px] py-0 px-1.5 font-bold"
              >
                {quantityNum > 0 ? `${quantityNum} ${value.product_info?.unit_of_measure ?? 'uds'}` : 'Sin cant.'}
              </Badge>
            </div>
          </div>
        </td>

        {/* 5. Costo Unitario */}
        <td className="p-3 align-middle w-[140px] text-right">
          <div className="space-y-1">
            <div className="relative">
              <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-text-muted">$</span>
              <Input
                type="text"
                inputMode="decimal"
                value={value.unit_cost === '' || value.unit_cost == null ? '' : String(value.unit_cost)}
                onChange={(e) =>
                  onDecimalInput(e.target.value, (next) =>
                    onChange({ ...value, unit_cost: next }),
                  )
                }
                disabled={disabled}
                placeholder="0.00"
                className="h-9 pl-6 text-right font-mono font-bold tabular-nums text-sm"
              />
            </div>
            {previousCost != null && String(value.unit_cost) !== String(previousCost) && (
              <button
                type="button"
                onClick={() => onChange({ ...value, unit_cost: String(previousCost) })}
                className="text-[10px] text-primary hover:underline flex items-center justify-end gap-1 ml-auto cursor-pointer"
                title="Cargar costo anterior"
              >
                <RefreshCw className="size-2.5" />
                Ant: ${previousCost.toFixed(2)}
              </button>
            )}
          </div>
        </td>

        {/* 6. Subtotal Línea */}
        <td className="p-3 align-middle w-[140px] text-right">
          <span className="font-mono font-black text-sm text-text-primary block">
            ${subtotal.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </span>
          {costDiff && (
            <span
              className={cn(
                'text-[10px] font-semibold block',
                costDiff.diff > 0 ? 'text-warning' : 'text-emerald-600 dark:text-emerald-400',
              )}
            >
              {costDiff.diff > 0 ? `+${costDiff.diffPct.toFixed(0)}%` : `${costDiff.diffPct.toFixed(0)}%`}
            </span>
          )}
        </td>

        {/* 7. Acciones */}
        <td className="p-3 align-middle w-[90px] text-center">
          <div className="flex items-center justify-center gap-1">
            {value.product_id && onEditProduct && (
              <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                onClick={() => onEditProduct(value.product_id!)}
                title="Editar producto"
                className="size-8 text-text-muted hover:text-primary hover:bg-primary/10"
                data-testid={`purchase-table-edit-product-${index}`}
              >
                <Pencil className="size-4" />
              </Button>
            )}
            {onCreateProduct && (
              <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                onClick={() => onCreateProduct()}
                title="Crear producto"
                className="size-8 text-text-muted hover:text-primary hover:bg-primary/10"
                data-testid={`purchase-table-create-product-${index}`}
              >
                <PackagePlus className="size-4" />
              </Button>
            )}
            <Button
              type="button"
              size="icon-sm"
              variant="ghost"
              onClick={() => onToggleExpand(index)}
              title={isExpanded ? 'Ocultar detalles' : 'Ver análisis y seriales'}
              className={cn('size-8', isExpanded && 'text-primary bg-primary/10')}
            >
              <Lightbulb className="size-4" />
            </Button>
            {canRemove && (
              <Button
                type="button"
                size="icon-sm"
                variant="ghost"
                onClick={onRemove}
                disabled={disabled}
                className="size-8 text-text-muted hover:text-danger hover:bg-danger/10"
                title="Eliminar producto"
              >
                <Trash2 className="size-4" />
              </Button>
            )}
          </div>
        </td>
      </tr>

      {/* Sub-fila expandida para Análisis Financiero y Captura de IMEIs */}
      {isExpanded && (
        <tr className="bg-primary/5 border-b border-border/80">
          <td colSpan={7} className="p-4">
            <div className="space-y-3 bg-surface rounded-lg p-3.5 border border-primary/20 shadow-xs">
              <div className="flex flex-wrap items-center justify-between gap-3 text-xs">
                {/* 1. Variación de costo */}
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

                {/* 2. Margen proyectado */}
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

                {/* 3. PVP Sugerido */}
                <div className="flex items-center gap-2">
                  <Lightbulb className="text-warning size-4 shrink-0" />
                  <span className="text-text-secondary">
                    PVP sugerido (margen {effectiveMarginForSuggestion}%):{' '}
                    <strong className="text-text-primary font-bold">
                      ${suggestedPvp?.toFixed(2)} USD
                    </strong>
                  </span>
                  {isAutomaticPricing && (
                    <Badge variant="info" className="text-[10px]">
                      Modo auto: se actualizará al recibir
                    </Badge>
                  )}
                </div>
              </div>

              {/* Actualizar PVP manual si no es automático */}
              {!isAutomaticPricing && hasValidCost && (
                <div className="border-t border-border pt-2.5 flex items-center justify-between gap-3 text-xs">
                  <label className="flex items-center gap-2 cursor-pointer select-none">
                    <input
                      type="checkbox"
                      checked={Boolean(value.update_sale_price)}
                      onChange={(e) =>
                        onChange({
                          ...value,
                          update_sale_price: e.target.checked,
                          new_sale_price:
                            e.target.checked && suggestedPvp != null
                              ? String(suggestedPvp)
                              : value.new_sale_price,
                        })
                      }
                      className="size-4 rounded border-border text-primary focus:ring-primary/20"
                    />
                    <span className="font-semibold text-text-primary">
                      Actualizar PVP al recibir la compra
                    </span>
                  </label>

                  {value.update_sale_price && (
                    <div className="flex items-center gap-2">
                      <span className="text-text-muted">Nuevo PVP ($):</span>
                      <Input
                        type="text"
                        inputMode="decimal"
                        value={value.new_sale_price ?? ''}
                        onChange={(e) =>
                          onDecimalInput(e.target.value, (next) =>
                            onChange({ ...value, new_sale_price: next }),
                          )
                        }
                        className="w-24 h-8 text-right font-mono font-bold text-xs"
                      />
                    </div>
                  )}
                </div>
              )}

              {/* Captura de IMEIs / Seriales para productos serializados */}
              {isSerialized && (
                <div className="border-t border-border pt-3">
                  <div className="flex items-center justify-between mb-2">
                    <h4 className="text-xs font-bold uppercase text-text-secondary flex items-center gap-1.5">
                      <Boxes className="size-3.5 text-primary" />
                      Captura de Seriales / IMEIs ({serialsCount} de {quantityNum} ingresados)
                    </h4>
                    {serialsComplete ? (
                      <Badge variant="success" className="text-[10px]">
                        ✓ Completos
                      </Badge>
                    ) : (
                      <Badge variant="warning" className="text-[10px]">
                        Faltan {Math.max(0, quantityNum - serialsCount)} seriales
                      </Badge>
                    )}
                  </div>
                  <ImeiListInput
                    expectedQuantity={quantityNum}
                    value={value.serial_units}
                    onChange={(serial_units) => onChange({ ...value, serial_units })}
                    disabled={disabled}
                  />
                </div>
              )}
            </div>
          </td>
        </tr>
      )}
    </>
  );
}
