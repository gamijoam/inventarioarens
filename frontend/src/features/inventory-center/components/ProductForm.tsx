/**
 * ProductForm: formulario completo de producto estilo ERP (create + edit).
 * Renderiza todos los campos del backend organizados en pestañas de alta productividad:
 *  1. Identificacion [F1] (name, sku, barcode, brand, unit_of_measure personalizable con soporte PAR, descripción)
 *  2. Precios [F2] (modo automático/manual, costo, recargo %, precio base USD, IVA estimado, moneda)
 *  3. Control de stock [F3] (tracking_type, track_stock, min/max/reorder)
 *  4. Catálogos [F4] (categorías en árbol, tags con chips)
 *  5. Imágenes [F5] (url externa y galería multi-foto en edit)
 *  6. Garantía y estado [F6] (warranty_policy_id, long_description, is_active)
 *
 * Incluye atajos de teclado F1-F6 para cambiar de pestaña rápidamente y desglose en vivo.
 */
import { type UseFormReturn, useController } from 'react-hook-form';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Boxes,
  Check,
  ClipboardList,
  DollarSign,
  FileClock,
  History,
  Image as ImageIcon,
  Layers,
  Link2,
  ShieldCheck,
  Tag as TagIcon,
  Tags as TagsIcon,
} from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Combobox } from '@/components/ui/Combobox';
import { TreeSelect } from '@/components/ui/TreeSelect';
import { Label } from '@/components/ui/Label';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';

import {
  SALE_CURRENCIES,
  PRICING_MODES,
  TRACKING_TYPES,
  type StoreProductInput,
  type StoreProductValues,
} from '../schemas';
import {
  useBrands,
  useCategoriesTree,
  useExchangeRateTypes,
  useProductImages,
  useWarrantyPolicies,
} from '@/features/inventory-center/lookups';
import {
  type ProductFormVisibility,
  FULL_PRODUCT_FORM_VISIBILITY,
} from '../productFormConfig';
import { InlineCatalogCreate } from './InlineCatalogCreate';
import { InlineExchangeRateTypeCreate } from './InlineExchangeRateTypeCreate';
import { InlineWarrantyPolicyCreate } from './InlineWarrantyPolicyCreate';
import { ImageGallery } from './ImageGallery';
import { UnitOfMeasureSelector } from './UnitOfMeasureSelector';
import { PricesEditor } from './PricesEditor';
import { ProductStockDetailPanel } from './ProductStockDetailPanel';
import { ProductVariantsTab } from './ProductVariantsTab';
import { KardexTab } from './KardexTab';
import { AuditsTab } from './AuditsTab';

export type ProductFormTab =
  | 'general'
  | 'pricing'
  | 'stock'
  | 'catalogs'
  | 'images'
  | 'details'
  | 'variants'
  | 'kardex'
  | 'audits';

export interface ProductFormProps {
  form: UseFormReturn<StoreProductInput, unknown, StoreProductValues>;
  tagOptions: { value: number; label: string; color?: string }[];
  compact?: boolean;
  onCancel?: () => void;
  submitLabel?: string;
  onSubmit: () => void;
  isSubmitting: boolean;
  productId?: number;
  visibility?: Partial<ProductFormVisibility>;
  showAdvancedToggle?: boolean;
}

export function ProductForm({
  form,
  tagOptions,
  compact = false,
  onCancel,
  submitLabel = 'Guardar',
  onSubmit,
  isSubmitting,
  productId,
  visibility,
  showAdvancedToggle = false,
}: ProductFormProps) {
  const [activeTab, setActiveTab] = useState<ProductFormTab>('general');
  const [showAdvanced, setShowAdvanced] = useState(false);

  const activeVisibility = useMemo<ProductFormVisibility>(() => {
    const base = { ...FULL_PRODUCT_FORM_VISIBILITY, ...visibility } as ProductFormVisibility;
    if (showAdvanced) {
      return FULL_PRODUCT_FORM_VISIBILITY;
    }
    return base;
  }, [visibility, showAdvanced]);

  const hasHiddenFields = useMemo(() => {
    const base = { ...FULL_PRODUCT_FORM_VISIBILITY, ...visibility };
    return Object.values(base).some((v) => !v);
  }, [visibility]);

  const { data: brands = [] } = useBrands();
  const { data: categoryTree = [] } = useCategoriesTree();
  const { data: warrantyPolicies = [] } = useWarrantyPolicies();
  const { data: rateTypes = [] } = useExchangeRateTypes();
  const { data: galleryImages = [] } = useProductImages(productId ?? null);

  const pricingMode = form.watch('pricing_mode') ?? 'manual';
  const cost = Number(form.watch('last_purchase_cost'));
  const margin = Number(form.watch('profit_margin'));
  const currentBasePrice = Number(form.watch('base_price') ?? 0);

  // Si el producto existente ya tiene un base_price que concuerda con cost * (1 + margin/100),
  // significa que el margen guardado en el sistema ya incluye el precio final con IVA,
  // por lo que no debemos duplicar el IVA al abrir el formulario.
  const [applyIva, setApplyIva] = useState(() => {
    if (productId && Number.isFinite(cost) && cost > 0 && Number.isFinite(margin) && margin > 0 && currentBasePrice > 0) {
      const directCalc = Math.round(cost * (1 + margin / 100) * 100) / 100;
      if (Math.abs(directCalc - currentBasePrice) <= 0.05) {
        return false; // El margen ya lleva el precio directo al PVP final
      }
    }
    return true;
  });
  const [ivaRate, setIvaRate] = useState(16);
  const isInitialMount = useRef(true);

  const netSubtotal =
    Number.isFinite(cost) && cost > 0 && Number.isFinite(margin) && margin >= 0
      ? cost * (1 + margin / 100)
      : null;

  const calculatedSalePrice = useMemo(() => {
    if (netSubtotal === null) return '';
    const finalPrice = applyIva ? netSubtotal * (1 + ivaRate / 100) : netSubtotal;
    return (Math.round(finalPrice * 100) / 100).toFixed(2);
  }, [netSubtotal, applyIva, ivaRate]);

  useEffect(() => {
    if (productId && isInitialMount.current) {
      isInitialMount.current = false;
      return;
    }
    if (pricingMode === 'automatic' && calculatedSalePrice && Number(calculatedSalePrice) > 0) {
      const current = form.getValues('base_price');
      const next = Number(calculatedSalePrice);
      if (current !== next) {
        form.setValue('base_price', next, { shouldValidate: true });
      }
    }
  }, [pricingMode, calculatedSalePrice, form, productId]);

  // Atajos de teclado para pestañas ERP (F1 - F9)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F1') {
        e.preventDefault();
        setActiveTab('general');
      } else if (e.key === 'F2') {
        e.preventDefault();
        setActiveTab('pricing');
      } else if (e.key === 'F3') {
        e.preventDefault();
        setActiveTab('stock');
      } else if (e.key === 'F4') {
        e.preventDefault();
        setActiveTab('catalogs');
      } else if (e.key === 'F5') {
        e.preventDefault();
        setActiveTab('images');
      } else if (e.key === 'F6') {
        e.preventDefault();
        setActiveTab('details');
      } else if (e.key === 'F7' && productId) {
        e.preventDefault();
        setActiveTab('variants');
      } else if (e.key === 'F8' && productId) {
        e.preventDefault();
        setActiveTab('kardex');
      } else if (e.key === 'F9' && productId) {
        e.preventDefault();
        setActiveTab('audits');
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [productId]);

  const brandOptions = useMemo(
    () => [
      { value: '', label: '— Sin marca —' },
      ...brands.map((b) => ({ value: String(b.id), label: b.name })),
    ],
    [brands],
  );

  const warrantyOptions = useMemo(
    () => [
      { value: '', label: '— Sin garantía —' },
      ...warrantyPolicies.map((w) => ({ value: String(w.id), label: w.name })),
    ],
    [warrantyPolicies],
  );

  const rateTypeOptions = useMemo(
    () => [
      { value: '', label: '— Heredar del sistema —' },
      ...rateTypes.map((r) => ({ value: String(r.id), label: `${r.code} (${r.name})` })),
    ],
    [rateTypes],
  );

  const [categorySearch, setCategorySearch] = useState('');

  const filteredCategoryTree = useMemo(
    () => filterTreeByName(categoryTree as unknown as TreeLike[], categorySearch),
    [categoryTree, categorySearch],
  );

  // Cálculos financieros claros: Costo Compra -> Costo c/IVA -> Ganancia -> PVP Final
  const costBase = Number.isFinite(cost) && cost > 0 ? cost : 0;
  const costWithIva = applyIva && costBase > 0 ? Math.round(costBase * (1 + ivaRate / 100) * 100) / 100 : costBase;
  const effectivePrice =
    pricingMode === 'automatic' && calculatedSalePrice && (!productId || !isInitialMount.current)
      ? Number(calculatedSalePrice)
      : currentBasePrice;

  // Ganancia real estimada entre precio de venta y costo con IVA (o costo base si exento)
  const comparisonCost = applyIva && costWithIva > 0 ? costWithIva : costBase;
  const grossProfitUsd = effectivePrice > 0 && comparisonCost > 0 ? Math.round((effectivePrice - comparisonCost) * 100) / 100 : 0;
  const actualProfitMarginPercent =
    comparisonCost > 0 && effectivePrice > 0
      ? Math.round(((effectivePrice - comparisonCost) / comparisonCost) * 1000) / 10
      : margin > 0 ? margin : 0;

  // Detección de errores por pestaña
  const errors = form.formState.errors;
  const hasGeneralErrors = Boolean(errors.name || errors.sku || errors.barcode || errors.image_url || errors.description);
  const hasPricingErrors = Boolean(errors.base_price || errors.last_purchase_cost || errors.profit_margin || errors.sale_currency || errors.sale_exchange_rate_type_id);
  const hasStockErrors = Boolean(errors.min_stock || errors.max_stock || errors.reorder_quantity || errors.tracking_type || errors.unit_of_measure);
  const hasCatalogErrors = Boolean(errors.category_ids || errors.tag_ids || errors.brand_id);
  const hasDetailsErrors = Boolean(errors.warranty_policy_id || errors.long_description);

  const showDetailsSection = Boolean(
    activeVisibility.warranty_policy_id || activeVisibility.is_active || activeVisibility.long_description,
  );

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit();
      }}
      className="flex flex-col h-full overflow-hidden"
    >
      {/* ============================================================ */}
      {/* Barra de Pestañas ERP con Atajos F1 - F6                     */}
      {/* ============================================================ */}
      <div className="border-b border-border bg-surface-subtle/30 px-4 pt-2 flex items-center gap-1.5 overflow-x-auto shrink-0 select-none">
        <TabButton
          active={activeTab === 'general'}
          onClick={() => setActiveTab('general')}
          shortcut="F1"
          label="Datos Generales"
          icon={<ClipboardList className="size-3.5" />}
          hasError={hasGeneralErrors}
        />
        <TabButton
          active={activeTab === 'pricing'}
          onClick={() => setActiveTab('pricing')}
          shortcut="F2"
          label="Tarifas y PVP"
          icon={<DollarSign className="size-3.5" />}
          hasError={hasPricingErrors}
        />
        <TabButton
          active={activeTab === 'stock'}
          onClick={() => setActiveTab('stock')}
          shortcut="F3"
          label="Existencias y Medidas"
          icon={<Boxes className="size-3.5" />}
          hasError={hasStockErrors}
        />
        <TabButton
          active={activeTab === 'catalogs'}
          onClick={() => setActiveTab('catalogs')}
          shortcut="F4"
          label="Clasificación"
          icon={<TagsIcon className="size-3.5" />}
          hasError={hasCatalogErrors}
        />
        <TabButton
          active={activeTab === 'images'}
          onClick={() => setActiveTab('images')}
          shortcut="F5"
          label="Galería de Fotos"
          icon={<ImageIcon className="size-3.5" />}
        />
        {showDetailsSection && (
          <TabButton
            active={activeTab === 'details'}
            onClick={() => setActiveTab('details')}
            shortcut="F6"
            label="Garantías y Ficha"
            icon={<ShieldCheck className="size-3.5" />}
            hasError={hasDetailsErrors}
          />
        )}
        {productId && (
          <>
            <TabButton
              active={activeTab === 'variants'}
              onClick={() => setActiveTab('variants')}
              shortcut="F7"
              label="Variantes"
              icon={<Layers className="size-3.5" />}
            />
            <TabButton
              active={activeTab === 'kardex'}
              onClick={() => setActiveTab('kardex')}
              shortcut="F8"
              label="Kardex"
              icon={<History className="size-3.5" />}
            />
            <TabButton
              active={activeTab === 'audits'}
              onClick={() => setActiveTab('audits')}
              shortcut="F9"
              label="Auditoría"
              icon={<FileClock className="size-3.5" />}
            />
          </>
        )}
      </div>

      {/* ============================================================ */}
      {/* Contenedor de Paneles (Todos en DOM para evitar desmontaje)   */}
      {/* ============================================================ */}
      <div className="flex-1 overflow-y-auto p-5 sm:p-6 space-y-4">
        {/* Pestaña 1: Identificación [F1] */}
        <div className={cn('space-y-4', activeTab !== 'general' && 'hidden')}>
          <fieldset className="space-y-4">
            <SectionLegend>Identificacion</SectionLegend>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                name="name"
                label="Nombre"
                required
                error={form.formState.errors.name?.message}
                hint="Identificador principal en ventas y comprobantes"
              >
                <Input
                  {...form.register('name')}
                  placeholder="Ej. Nombre del producto"
                  className="font-semibold text-base h-11"
                  autoFocus
                />
              </Field>

              {activeVisibility.brand && (
                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="brand_id">Marca</Label>
                    <InlineCatalogCreate
                      kind="brand"
                      onCreated={(id) => form.setValue('brand_id', id, { shouldValidate: true })}
                    />
                  </div>
                  <Select
                    id="brand_id"
                    value={form.watch('brand_id') ? String(form.watch('brand_id')) : ''}
                    onChange={(e) => {
                      const v = e.target.value;
                      form.setValue('brand_id', v === '' ? undefined : Number(v), {
                        shouldValidate: true,
                      });
                    }}
                  >
                    {brandOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </div>
              )}

              {activeVisibility.sku && (
                <Field
                  name="sku"
                  label="SKU (Código interno)"
                  hint="Opcional, identificador único de inventario"
                  error={form.formState.errors.sku?.message}
                >
                  <Input
                    {...form.register('sku')}
                    placeholder="Ej. SKU-001"
                    className="font-mono text-sm uppercase"
                  />
                </Field>
              )}

              {activeVisibility.barcode && (
                <Field
                  name="barcode"
                  label="Código de barras"
                  hint="Escanéalo con la lectora o ingrésalo manualmente"
                >
                  <Input
                    {...form.register('barcode')}
                    placeholder="Ej. 7501234567890"
                    className="font-mono text-sm"
                  />
                </Field>
              )}

              {activeVisibility.unit_of_measure && (
                <Field
                  name="unit_of_measure"
                  label="Unidad de Medida / Tipo de Producto"
                  hint="Ej. Unidad, Par, Kilogramo o agrega una personalizada"
                >
                  <UnitOfMeasureField form={form} />
                </Field>
              )}

              {activeVisibility.is_active && (
                <div className="sm:col-span-2 rounded-lg border border-border bg-surface-subtle/40 p-3.5 flex items-center justify-between">
                  <div>
                    <Label htmlFor="is_active" className="font-semibold text-text-primary">
                      Producto Activo
                    </Label>
                    <p className="text-xs text-text-muted">
                      Controla si este producto está visible y habilitado para facturar en el POS y ventas.
                    </p>
                  </div>
                  <SwitchField form={form} name="is_active" label="" />
                </div>
              )}
            </div>

            {activeVisibility.description && (
              <Field
                name="description"
                label="Descripción corta"
                error={form.formState.errors.description?.message}
                hint="Resumen rápido para comprobantes y visualización compacta"
              >
                <Textarea
                  {...form.register('description')}
                  rows={2}
                  placeholder="Ej. Breve descripción o características principales..."
                />
              </Field>
            )}
          </fieldset>
        </div>

        {/* Pestaña 2: Precios y Costos [F2] */}
        <div className={cn('space-y-4', activeTab !== 'pricing' && 'hidden')}>
          <fieldset className="space-y-4">
            <SectionLegend>Precios</SectionLegend>

            {/* Tarjeta de Resumen Financiero: Costo Compra -> Costo con IVA -> Ganancia -> PVP Final */}
            <div className="rounded-xl border border-primary/20 bg-primary/5 p-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
              <div>
                <span className="text-[11px] uppercase font-bold text-text-muted">1. Costo Compra</span>
                <p className="text-base font-bold font-mono text-text-primary">
                  ${costBase > 0 ? costBase.toFixed(2) : '0.00'}
                </p>
                <span className="text-[10px] text-text-muted block">Precio proveedor</span>
              </div>
              <div>
                <span className="text-[11px] uppercase font-bold text-text-muted">
                  2. Costo {applyIva ? `con IVA (${ivaRate}%)` : '(Exento)'}
                </span>
                <p className="text-base font-bold font-mono text-text-secondary">
                  ${costWithIva > 0 ? costWithIva.toFixed(2) : '0.00'}
                </p>
                <span className="text-[10px] text-text-muted block">
                  {applyIva && costBase > 0 ? `IVA: +$${(costWithIva - costBase).toFixed(2)}` : 'Sin recargo IVA'}
                </span>
              </div>
              <div>
                <span className="text-[11px] uppercase font-bold text-text-muted">3. Ganancia Agregada</span>
                <p className="text-base font-bold font-mono text-primary">
                  {actualProfitMarginPercent > 0 ? `+${actualProfitMarginPercent.toFixed(1)}%` : '0%'}
                </p>
                <span className="text-[10px] text-text-muted block font-mono">
                  {grossProfitUsd > 0 ? `(+$${grossProfitUsd.toFixed(2)} utilidad)` : 'Sin utilidad'}
                </span>
              </div>
              <div className="border-l border-primary/20 pl-2">
                <span className="text-[11px] uppercase font-bold text-emerald-600 dark:text-emerald-400">
                  4. PVP Venta Final
                </span>
                <p className="text-xl font-black font-mono text-emerald-600 dark:text-emerald-400">
                  ${effectivePrice > 0 ? effectivePrice.toFixed(2) : '0.00'}
                </p>
                <span className="text-[10px] text-emerald-700/80 dark:text-emerald-300/80 block">
                  {pricingMode === 'automatic' ? 'Calculado automático' : 'Precio venta manual'}
                </span>
              </div>
            </div>

            {/* Casilla de IVA y Configuración de Cálculo */}
            <div className="rounded-xl border border-border bg-surface-subtle/40 p-3.5 flex flex-wrap items-center justify-between gap-3">
              <label className="flex items-center gap-2.5 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={applyIva}
                  onChange={(e) => setApplyIva(e.target.checked)}
                  className="size-4.5 rounded border-border text-primary focus:ring-primary/20"
                />
                <span className="text-sm font-bold text-text-primary">
                  Aplica IVA
                </span>
                <Badge variant={applyIva ? 'primary' : 'outline'} className="text-[11px] font-semibold">
                  {applyIva ? `${ivaRate}% IVA incluido en PVP` : 'Exento (Sin IVA)'}
                </Badge>
              </label>

              {applyIva && (
                <div className="flex items-center gap-2 text-xs">
                  <span className="text-text-secondary font-medium">Porcentaje IVA (%):</span>
                  <Input
                    type="number"
                    min="0"
                    max="100"
                    step="1"
                    value={ivaRate}
                    onChange={(e) => setIvaRate(Math.max(0, Number(e.target.value) || 0))}
                    className="w-18 h-8 text-center font-mono font-bold text-sm"
                  />
                </div>
              )}
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              {activeVisibility.pricing_mode && (
                <Field name="pricing_mode" label="Modo de precio" hint="Define cómo se calcula el PVP">
                  <Select {...form.register('pricing_mode')}>
                    <option value={PRICING_MODES[0]}>Automático por costo</option>
                    <option value={PRICING_MODES[1]}>Precio manual</option>
                  </Select>
                </Field>
              )}

              {activeVisibility.last_purchase_cost && (
                <Field
                  name="last_purchase_cost"
                  label="Costo unitario"
                  hint="Costo base de reposición en USD"
                  error={form.formState.errors.last_purchase_cost?.message}
                >
                  <Input
                    type="number"
                    min="0"
                    step="0.01"
                    {...form.register('last_purchase_cost', { valueAsNumber: true })}
                    className="font-mono"
                  />
                </Field>
              )}

              {activeVisibility.profit_margin && (
                <Field
                  name="profit_margin"
                  label="Recargo sobre costo (%)"
                  hint="Porcentaje de utilidad deseado"
                  error={form.formState.errors.profit_margin?.message}
                >
                  <Input
                    type="number"
                    min="0"
                    max="999.99"
                    step="0.01"
                    {...form.register('profit_margin', { valueAsNumber: true })}
                    className="font-mono"
                  />
                </Field>
              )}

              {activeVisibility.base_price && (
                <Field
                  name="base_price"
                  label={pricingMode === 'automatic' ? 'Precio de venta calculado' : 'Precio de venta manual'}
                  hint={applyIva ? `Precio base final en dólares (con ${ivaRate}% IVA)` : 'Precio base final en dólares (Exento)'}
                  error={form.formState.errors.base_price?.message}
                >
                  <div className="space-y-1.5">
                    {pricingMode === 'automatic' ? (
                      <Input
                        value={calculatedSalePrice || form.getValues('base_price')?.toString() || ''}
                        readOnly
                        className="font-mono font-bold text-emerald-600 bg-surface-subtle"
                      />
                    ) : (
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        {...form.register('base_price', { valueAsNumber: true })}
                        className="font-mono font-bold text-emerald-600"
                      />
                    )}
                    {calculatedSalePrice && pricingMode === 'manual' && (
                      <button
                        type="button"
                        onClick={() => form.setValue('base_price', Number(calculatedSalePrice), { shouldValidate: true })}
                        className="text-[11px] font-semibold text-primary hover:underline flex items-center gap-1 cursor-pointer"
                      >
                        ⚡ Aplicar cálculo automático ({applyIva ? `con ${ivaRate}% IVA` : 'sin IVA'}): ${calculatedSalePrice}
                      </button>
                    )}
                  </div>
                </Field>
              )}

              {activeVisibility.sale_currency && (
                <Field name="sale_currency" label="Moneda de venta" hint="Moneda de referencia de venta">
                  <Select {...form.register('sale_currency')}>
                    {SALE_CURRENCIES.map((c) => (
                      <option key={c} value={c}>
                        {c}
                      </option>
                    ))}
                  </Select>
                </Field>
              )}

              {activeVisibility.sale_exchange_rate_type_id && (
                <Field
                  name="sale_exchange_rate_type_id"
                  label="Tipo de tasa anclada"
                  hint="Tasa para conversión a VES"
                  error={form.formState.errors.sale_exchange_rate_type_id?.message}
                >
                  <div className="space-y-1.5">
                    <div className="flex items-center justify-between">
                      <span className="text-[11px] text-text-muted">Asignar a este producto</span>
                      <InlineExchangeRateTypeCreate
                        onCreated={(id) =>
                          form.setValue('sale_exchange_rate_type_id', id, { shouldValidate: true })
                        }
                      />
                    </div>
                    <Select
                      value={form.watch('sale_exchange_rate_type_id') ? String(form.watch('sale_exchange_rate_type_id')) : ''}
                      onChange={(e) => {
                        const v = e.target.value;
                        form.setValue('sale_exchange_rate_type_id', v === '' ? undefined : Number(v), {
                          shouldValidate: true,
                        });
                      }}
                    >
                      {rateTypeOptions.map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                </Field>
              )}
            </div>
          </fieldset>

          {productId && (
            <div className="pt-4 border-t border-border">
              <PricesEditor productId={productId} currentBasePrice={currentBasePrice} />
            </div>
          )}
        </div>

        {/* Pestaña 3: Control de Stock [F3] */}
        <div className={cn('space-y-4', activeTab !== 'stock' && 'hidden')}>
          <fieldset className="space-y-4">
            <SectionLegend>Control de stock</SectionLegend>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {activeVisibility.tracking_type && (
                <Field
                  name="tracking_type"
                  label="Tipo de control"
                  required
                  hint="Cantidad simple o control individual por serial/IMEI"
                >
                  <Select {...form.register('tracking_type')}>
                    {TRACKING_TYPES.map((t) => (
                      <option key={t} value={t}>
                        {t === 'quantity' ? 'Por cantidad (estándar)' : 'Serializado (IMEI / Serial individual)'}
                      </option>
                    ))}
                  </Select>
                </Field>
              )}

              {activeVisibility.unit_of_measure && (
                <Field
                  name="unit_of_measure"
                  label="Unidad de Medida / Presentación"
                  hint="Personalizable: PAR, Unidad, KG, etc."
                >
                  <UnitOfMeasureField form={form} />
                </Field>
              )}
            </div>

            {activeVisibility.track_stock && (
              <div className="rounded-lg border border-border bg-surface-subtle/30 p-3.5 flex items-center justify-between">
                <div>
                  <Label htmlFor="track_stock" className="font-semibold text-text-primary">
                    Trackear stock de este producto
                  </Label>
                  <p className="text-xs text-text-muted">
                    Si está desactivado, se comportará como un servicio o ítem sin límite de existencias.
                  </p>
                </div>
                <SwitchField form={form} name="track_stock" label="" />
              </div>
            )}

            {(activeVisibility.min_stock || activeVisibility.max_stock || activeVisibility.reorder_quantity) && (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 pt-2">
                {activeVisibility.min_stock && (
                  <Field
                    name="min_stock"
                    label="Stock mínimo"
                    hint="Alerta de reposición si baja de aquí"
                    error={form.formState.errors.min_stock?.message}
                  >
                    <Input
                      type="number"
                      min="0"
                      {...form.register('min_stock', {
                        setValueAs: (v) => (v === '' || v == null ? undefined : isNaN(Number(v)) ? undefined : Number(v)),
                      })}
                      className="font-mono"
                    />
                  </Field>
                )}

                {activeVisibility.max_stock && (
                  <Field
                    name="max_stock"
                    label="Stock máximo"
                    hint="Capacidad tope recomendada"
                    error={form.formState.errors.max_stock?.message}
                  >
                    <Input
                      type="number"
                      min="0"
                      {...form.register('max_stock', {
                        setValueAs: (v) => (v === '' || v == null ? undefined : isNaN(Number(v)) ? undefined : Number(v)),
                      })}
                      className="font-mono"
                    />
                  </Field>
                )}

                {activeVisibility.reorder_quantity && (
                  <Field
                    name="reorder_quantity"
                    label="Cantidad a reordenar"
                    hint="Lote sugerido en órdenes de compra"
                    error={form.formState.errors.reorder_quantity?.message}
                  >
                    <Input
                      type="number"
                      min="0"
                      {...form.register('reorder_quantity', {
                        setValueAs: (v) => (v === '' || v == null ? undefined : isNaN(Number(v)) ? undefined : Number(v)),
                      })}
                      className="font-mono"
                    />
                  </Field>
                )}
              </div>
            )}
          </fieldset>

          {productId && (
            <div className="pt-4 border-t border-border">
              <ProductStockDetailPanel
                productId={productId}
                trackingType={form.watch('tracking_type')}
              />
            </div>
          )}
        </div>

        {/* Pestaña 4: Catálogos y Etiquetas [F4] */}
        <div className={cn('space-y-4', activeTab !== 'catalogs' && 'hidden')}>
          <fieldset className="space-y-4">
            <SectionLegend>Catálogos</SectionLegend>

            {activeVisibility.categories && (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>Categorías</Label>
                  <InlineCatalogCreate kind="category" onCreated={() => undefined} />
                </div>
                <Input
                  type="text"
                  placeholder="Buscar categoría..."
                  value={categorySearch}
                  onChange={(e) => setCategorySearch(e.target.value)}
                  className="mb-2 text-sm"
                />
                <div className="max-h-60 overflow-y-auto rounded-lg border border-border p-3 bg-surface-subtle/20">
                  <TreeSelect
                    nodes={filteredCategoryTree.map(toNode)}
                    value={form.watch('category_ids') ?? []}
                    onChange={(ids) =>
                      form.setValue('category_ids', ids.map(Number), { shouldValidate: true })
                    }
                  />
                </div>
              </div>
            )}

            {activeVisibility.tags && (
              <div className="space-y-2 pt-2">
                <div className="flex items-center justify-between">
                  <Label>Tags</Label>
                  <InlineCatalogCreate kind="tag" onCreated={() => undefined} />
                </div>
                <Combobox
                  options={tagOptions}
                  value={form.watch('tag_ids') ?? []}
                  onChange={(ids) =>
                    form.setValue('tag_ids', ids.map(Number), { shouldValidate: true })
                  }
                  placeholder="Selecciona etiquetas..."
                />
              </div>
            )}
          </fieldset>
        </div>

        {/* Pestaña 5: Imágenes [F5] */}
        <div className={cn('space-y-4', activeTab !== 'images' && 'hidden')}>
          <fieldset className="space-y-4">
            <SectionLegend>Imágenes del producto</SectionLegend>
            {activeVisibility.image_url && (
              <Field
                name="image_url"
                label="URL externa de imagen (opcional)"
                hint="Enlace directo a una imagen del fabricante o web externa"
                error={form.formState.errors.image_url?.message}
              >
                <Input {...form.register('image_url')} placeholder="https://..." />
              </Field>
            )}

            {productId !== undefined ? (
              <div className="space-y-2 rounded-xl border border-border bg-surface-subtle/30 p-4">
                <div className="flex items-center justify-between">
                  <p className="text-xs font-semibold uppercase text-text-primary">
                    Galería de imágenes
                  </p>
                  <p className="text-[11px] text-text-muted">
                    Sube fotos en alta resolución. Formato WebP automático con miniaturas.
                  </p>
                </div>
                <ImageGallery productId={productId} images={galleryImages} canEdit />
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-border p-6 text-center text-text-muted">
                <ImageIcon className="size-8 mx-auto mb-2 text-text-muted/60" />
                <p className="text-sm font-medium">Subida de fotos disponible tras crear el producto</p>
                <p className="text-xs text-text-muted mt-1">
                  Puedes especificar una URL de imagen externa arriba o guardar el producto y adjuntar imágenes inmediatamente.
                </p>
              </div>
            )}
          </fieldset>
        </div>

        {/* Pestaña 6: Garantía y Ficha Técnica [F6] */}
        {showDetailsSection && (
          <div className={cn('space-y-4', activeTab !== 'details' && 'hidden')}>
            <fieldset className="space-y-4">
              <SectionLegend>Garantía y estado</SectionLegend>
            {activeVisibility.warranty_policy_id && (
              <Field
                name="warranty_policy_id"
                label="Política de garantía"
                error={form.formState.errors.warranty_policy_id?.message}
                hint="Garantía aplicable al emitir la venta o comprobante"
              >
                <div className="flex items-start gap-2">
                  <Select
                    value={form.watch('warranty_policy_id') ? String(form.watch('warranty_policy_id')) : ''}
                    onChange={(e) => {
                      const v = e.target.value;
                      form.setValue('warranty_policy_id', v === '' ? undefined : Number(v), {
                        shouldValidate: true,
                      });
                    }}
                    className="flex-1"
                  >
                    {warrantyOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                  <InlineWarrantyPolicyCreate
                    onCreated={(id) =>
                      form.setValue('warranty_policy_id', id, { shouldValidate: true })
                    }
                  />
                </div>
              </Field>
            )}

            {!compact && activeVisibility.long_description && (
              <Field
                name="long_description"
                label="Descripción larga / Ficha técnica"
                hint="Especificaciones detalladas, componentes o ficha del producto"
              >
                <Textarea
                  {...form.register('long_description')}
                  rows={5}
                  placeholder="Especificaciones completas, características técnicas..."
                />
              </Field>
            )}
          </fieldset>
        </div>
        )}

        {productId && (
          <>
            <div className={cn('space-y-4', activeTab !== 'variants' && 'hidden')}>
              <ProductVariantsTab productId={productId} />
            </div>

            <div className={cn('space-y-4', activeTab !== 'kardex' && 'hidden')}>
              <KardexTab productId={productId} />
            </div>

            <div className={cn('space-y-4', activeTab !== 'audits' && 'hidden')}>
              <AuditsTab productId={productId} />
            </div>
          </>
        )}

        {/* Botón para desplegar campos adicionales */}
        {showAdvancedToggle && hasHiddenFields && (
          <div className="flex justify-center border-t border-dashed border-border pt-3">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => setShowAdvanced((prev) => !prev)}
              className="text-xs text-text-muted hover:text-text-primary"
              data-testid="toggle-advanced-product-fields"
            >
              {showAdvanced ? '− Ocultar campos adicionales' : '+ Mostrar más campos (avanzado)'}
            </Button>
          </div>
        )}
      </div>

      {/* ============================================================ */}
      {/* Barra Inferior ERP con Atajos y Acciones                     */}
      {/* ============================================================ */}
      <div className="border-t border-border bg-surface-subtle/50 px-4 py-3 flex items-center justify-between gap-3 shrink-0 flex-wrap">
        <div className="flex items-center gap-2 text-[11px] text-text-muted font-mono hidden sm:flex">
          <span className="inline-flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">{productId ? 'F1-F9' : 'F1-F6'}</kbd>
            <span>Pestañas</span>
          </span>
          <span>•</span>
          <span className="inline-flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">Enter</kbd>
            <span>Guardar</span>
          </span>
          <span>•</span>
          <span className="inline-flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">Esc</kbd>
            <span>Cancelar</span>
          </span>
        </div>

        <div className="flex items-center gap-2 ml-auto">
          {onCancel && (
            <Button
              type="button"
              variant="outline"
              onClick={onCancel}
              disabled={isSubmitting}
            >
              Cancelar
            </Button>
          )}
          <Button type="submit" loading={isSubmitting} className="gap-1.5 font-semibold">
            <Check className="size-4" />
            <span>{submitLabel}</span>
          </Button>
        </div>
      </div>
    </form>
  );
}

function TabButton({
  active,
  onClick,
  shortcut,
  label,
  icon,
  hasError,
}: {
  active: boolean;
  onClick: () => void;
  shortcut: string;
  label: string;
  icon: React.ReactNode;
  hasError?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'group flex items-center gap-2 px-3.5 py-2.5 text-sm font-semibold rounded-t-lg transition-all border-b-2 relative -mb-px whitespace-nowrap',
        active
          ? 'bg-surface border-primary text-primary shadow-xs'
          : 'border-transparent text-text-secondary hover:text-text-primary hover:bg-surface/50',
      )}
    >
      <span className={cn('transition-colors', active ? 'text-primary' : 'text-text-muted')}>
        {icon}
      </span>
      <span>{label}</span>
      <span
        className={cn(
          'text-[10px] font-mono px-1.5 py-0.5 rounded bg-bg/80 border border-border text-text-muted',
          active && 'border-primary/40 text-primary font-bold bg-primary/5',
        )}
      >
        {shortcut}
      </span>
      {hasError && (
        <span
          className="size-2 rounded-full bg-danger absolute top-1.5 right-1.5 ring-2 ring-surface animate-pulse"
          title="Contiene errores de validación"
        />
      )}
    </button>
  );
}

function UnitOfMeasureField({
  form,
}: {
  form: UseFormReturn<StoreProductInput, unknown, StoreProductValues>;
}) {
  const { field } = useController({ name: 'unit_of_measure', control: form.control });
  return (
    <UnitOfMeasureSelector
      value={field.value ?? 'unit'}
      onChange={field.onChange}
    />
  );
}

function SwitchField({
  form,
  name,
  label,
}: {
  form: UseFormReturn<StoreProductInput, unknown, StoreProductValues>;
  name: 'is_active' | 'track_stock';
  label: string;
}) {
  const { field } = useController({ name, control: form.control });
  return (
    <div className="flex items-center gap-2">
      <Switch
        id={name}
        checked={Boolean(field.value)}
        onCheckedChange={field.onChange}
      />
      {label && <Label htmlFor={name}>{label}</Label>}
    </div>
  );
}

// Helpers internos
interface TreeLike { id: number; name: string; children?: unknown[] }
interface TreeNode { id: number; label: string; children?: TreeNode[] }

function filterTreeByName(nodes: TreeLike[], query: string): TreeLike[] {
  const q = query.trim().toLowerCase();
  if (!q) return nodes;

  const visit = (node: TreeLike): TreeLike | null => {
    const matches = node.name.toLowerCase().includes(q);
    const children = (node.children as TreeLike[] | undefined)
      ?.map((child) => visit(child))
      .filter((child): child is TreeLike => child !== null) ?? [];

    if (!matches && children.length === 0) return null;

    return {
      ...node,
      children: children.length > 0 ? children : undefined,
    };
  };

  return nodes
    .map((node) => visit(node))
    .filter((node): node is TreeLike => node !== null);
}

const toNode = (c: TreeLike): TreeNode => ({
  id: c.id,
  label: c.name,
  children: (c.children as TreeLike[] | undefined)?.map(toNode),
});

function SectionLegend({ children }: { children: React.ReactNode }) {
  return (
    <legend className="text-xs font-black uppercase tracking-widest text-primary border-b border-primary/20 pb-2 mb-4 flex items-center gap-2">
      <span className="w-1 h-3.5 rounded-full bg-primary inline-block" />
      <span>{children}</span>
    </legend>
  );
}

interface FieldProps {
  name: string;
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}

function Field({ name, label, required, hint, error, children }: FieldProps) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={name} className="flex items-center gap-1 font-bold text-sm text-text-primary">
        {label}
        {required && <span className="text-danger font-black">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-xs text-text-secondary leading-tight">{hint}</p>}
      {error && <p className="text-sm text-danger font-semibold">{error}</p>}
    </div>
  );
}

export { Link2, TagIcon, TagsIcon };
