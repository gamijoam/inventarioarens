/**
 * QuickCreateProductDialog.tsx — Modal de Creación Rápida de Productos.
 *
 * Diseñado para alta velocidad operativa (mostrador, partes, abarrotes):
 *  - En una sola pantalla limpia permite ingresar:
 *      * Nombre, SKU, Código de barras, Marca, Categoría, Unidad de medida y Foto.
 *      * Costo de compra (USD).
 *      * Todos los precios de venta por lista (P1 Detal, P2 Mayor, P3 Taller, etc.)
 *        con conversión en tiempo real a Bs (VES) y cálculo de margen de ganancia.
 *      * Pestaña secundaria opcional para parámetros avanzados (garantía, límites de stock, notas).
 *  - Guarda el producto y sus precios en 1 solo clic.
 */
import { useMemo, useState } from 'react';
import {
  DollarSign,
  Package,
  Save,
  Sliders,
  Sparkles,
  ExternalLink,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Spinner } from '@/components/ui/Spinner';
import {
  useBrands,
  useCategories,
  useCreateProduct,
  useWarrantyPolicies,
} from '@/features/inventory-center/api';
import { putOne } from '@/api/client';
import { productKeys } from '@/features/inventory-center/queries';
import {
  STANDARD_UNITS_OF_MEASURE,
  type PriceList,
  type Product,
} from '@/features/inventory-center/schemas';
import { cn } from '@/lib/cn';

export interface QuickCreateProductDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  priceLists: PriceList[];
  activeRate: { id?: number; name?: string; code?: string; exchange_rate_type_code?: string | null; rate: number } | null;
  onSuccess?: (product?: Product) => void;
  onOpenFullForm?: () => void;
}

export function QuickCreateProductDialog({
  open,
  onOpenChange,
  priceLists,
  activeRate,
  onSuccess,
  onOpenFullForm,
}: QuickCreateProductDialogProps) {
  const qc = useQueryClient();
  const createProduct = useCreateProduct();

  const { data: brands = [] } = useBrands();
  const { data: categories = [] } = useCategories();
  const { data: warranties = [] } = useWarrantyPolicies();

  const [activeTab, setActiveTab] = useState<'main' | 'advanced'>('main');

  // Datos principales
  const [name, setName] = useState('');
  const [sku, setSku] = useState('');
  const [barcode, setBarcode] = useState('');
  const [brandId, setBrandId] = useState<number | undefined>(undefined);
  const [categoryId, setCategoryId] = useState<number | undefined>(undefined);
  const [unitOfMeasure, setUnitOfMeasure] = useState('unit');
  const [imageUrl, setImageUrl] = useState('');
  const [isActive, setIsActive] = useState(true);

  // Costo y Precios
  const [cost, setCost] = useState('');
  const [basePrice, setBasePrice] = useState('');
  const [priceMap, setPriceMap] = useState<Record<number, string>>({});

  // Parámetros avanzados
  const [minStock, setMinStock] = useState('');
  const [maxStock, setMaxStock] = useState('');
  const [reorderQuantity, setReorderQuantity] = useState('');
  const [longDescription, setLongDescription] = useState('');
  const [trackingType, setTrackingType] = useState<'quantity' | 'serialized'>('quantity');
  const [trackStock, setTrackStock] = useState(true);
  const [warrantyPolicyId, setWarrantyPolicyId] = useState<number | undefined>(undefined);

  const [isSubmitting, setIsSubmitting] = useState(false);

  // Listas activas ordenadas (la predeterminada primero)
  const activeLists = useMemo(() => {
    const list = priceLists.filter((l) => l.is_active);
    return list.sort((a, b) => (b.is_default ? 1 : 0) - (a.is_default ? 1 : 0));
  }, [priceLists]);

  const handlePriceChange = (listId: number, val: string, isDefault: boolean) => {
    setPriceMap((prev) => ({
      ...prev,
      [listId]: val,
    }));
    if (isDefault) {
      setBasePrice(val);
    }
  };

  const handleReset = () => {
    setName('');
    setSku('');
    setBarcode('');
    setBrandId(undefined);
    setCategoryId(undefined);
    setUnitOfMeasure('unit');
    setImageUrl('');
    setIsActive(true);
    setCost('');
    setBasePrice('');
    setPriceMap({});
    setMinStock('');
    setMaxStock('');
    setReorderQuantity('');
    setLongDescription('');
    setTrackingType('quantity');
    setTrackStock(true);
    setWarrantyPolicyId(undefined);
    setActiveTab('main');
  };

  const handleSubmit = async () => {
    if (!name.trim()) {
      toast.error('El nombre del producto es obligatorio.');
      return;
    }

    setIsSubmitting(true);
    try {
      const costNum = cost.trim() !== '' ? parseFloat(cost) : null;

      // Precio base
      let basePriceNum = basePrice.trim() !== '' ? parseFloat(basePrice) : null;
      if (basePriceNum == null || basePriceNum <= 0) {
        const firstWithVal = Object.values(priceMap).find((v) => v.trim() !== '');
        if (firstWithVal) basePriceNum = parseFloat(firstWithVal);
      }

      let marginNum: number | null = null;
      if (costNum != null && costNum > 0 && basePriceNum != null && basePriceNum > 0) {
        marginNum = Number((((basePriceNum - costNum) / costNum) * 100).toFixed(2));
      }

      // 1. Crear producto base
      const res = await createProduct.mutateAsync({
        name: name.trim(),
        sku: sku.trim() || null,
        barcode: barcode.trim() || null,
        brand_id: brandId || null,
        category_ids: categoryId ? [categoryId] : [],
        unit_of_measure: unitOfMeasure,
        is_active: isActive,
        image_url: imageUrl.trim() || null,
        last_purchase_cost: costNum,
        base_price: basePriceNum,
        profit_margin: marginNum,
        min_stock: minStock.trim() !== '' ? parseFloat(minStock) : null,
        max_stock: maxStock.trim() !== '' ? parseFloat(maxStock) : null,
        reorder_quantity: reorderQuantity.trim() !== '' ? parseFloat(reorderQuantity) : null,
        long_description: longDescription.trim() || null,
        description: longDescription.trim() || null,
        tracking_type: trackingType,
        track_stock: trackStock,
        warranty_policy_id: warrantyPolicyId || null,
      });

      const created = (res as { data?: Product })?.data ?? (res as Product);
      const createdId = created?.id;

      // 2. Guardar precios por lista si fueron colocados
      const dirtyPrices = Object.entries(priceMap)
        .filter(([, v]) => v.trim() !== '' && !isNaN(parseFloat(v)))
        .map(([listIdStr, v]) => ({
          price_list_id: Number(listIdStr),
          price: parseFloat(v),
          currency: 'USD' as const,
        }));

      if (dirtyPrices.length > 0 && createdId) {
        await putOne(`/products/${createdId}/prices`, { prices: dirtyPrices });
      }

      toast.success('¡Producto creado exitosamente con sus tarifas de precios!');
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      if (createdId) {
        void qc.invalidateQueries({ queryKey: productKeys.detail(createdId) });
        void qc.invalidateQueries({ queryKey: productKeys.prices(createdId) });
      }
      handleReset();
      onSuccess?.(created);
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al crear el producto.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl w-[96vw] max-h-[92vh] overflow-hidden p-0 flex flex-col rounded-2xl shadow-2xl border-border">
        {/* Cabecera */}
        <DialogHeader className="p-5 pb-3 bg-surface-subtle/50 border-b border-border/80">
          <div className="flex items-start justify-between gap-3">
            <div>
              <div className="flex items-center gap-2">
                <span className="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                  <Sparkles className="size-3" /> Creación Rápida
                </span>
                <DialogTitle className="text-lg sm:text-xl font-bold text-text-primary">
                  Crear Producto
                </DialogTitle>
              </div>
              <DialogDescription className="text-xs text-text-muted mt-1">
                Ingresa datos clave, costo y tarifas de precios en una sola pantalla.
              </DialogDescription>
            </div>

            {onOpenFullForm && (
              <button
                type="button"
                onClick={() => {
                  onOpenChange(false);
                  onOpenFullForm();
                }}
                className="text-xs text-text-muted hover:text-primary hover:underline inline-flex items-center gap-1"
              >
                <ExternalLink className="size-3" />
                Formulario ERP completo (F1-F9)
              </button>
            )}
          </div>
        </DialogHeader>

        {/* Pestañas de Navegación */}
        <div className="flex items-center gap-2 px-6 border-b border-border bg-surface-subtle/30 shrink-0">
          <button
            type="button"
            onClick={() => setActiveTab('main')}
            className={cn(
              'px-4 py-2.5 text-xs font-semibold border-b-2 transition-all flex items-center gap-1.5',
              activeTab === 'main'
                ? 'border-primary text-primary bg-surface/60'
                : 'border-transparent text-text-muted hover:text-text-primary',
            )}
          >
            <Sparkles className="size-3.5" />
            Datos, Costo y Precios
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('advanced')}
            className={cn(
              'px-4 py-2.5 text-xs font-semibold border-b-2 transition-all flex items-center gap-1.5',
              activeTab === 'advanced'
                ? 'border-primary text-primary bg-surface/60'
                : 'border-transparent text-text-muted hover:text-text-primary',
            )}
          >
            <Sliders className="size-3.5" />
            Parámetros Avanzados (Garantía y Límites)
          </button>
        </div>

        {/* Cuerpo del Formulario */}
        <div className="p-5 sm:p-6 flex-1 overflow-y-auto">
          {activeTab === 'main' ? (
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
              {/* Columna Izquierda: Datos Básicos & Foto (5 cols) */}
              <div className="lg:col-span-5 flex flex-col gap-4">
                {/* Nombre */}
                <div>
                  <Label className="text-xs font-semibold text-text-primary">
                    Nombre del Producto <span className="text-rose-500">*</span>
                  </Label>
                  <Input
                    placeholder="Ej. Bujía Denso K20PR-U / Harina PAN 1kg"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    autoFocus
                    className="mt-1 font-medium"
                  />
                </div>

                {/* SKU y Código de Barras */}
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label className="text-xs text-text-secondary">Código / SKU</Label>
                    <Input
                      placeholder="SKU-1234"
                      value={sku}
                      onChange={(e) => setSku(e.target.value)}
                      className="mt-1 font-mono text-xs"
                    />
                  </div>
                  <div>
                    <Label className="text-xs text-text-secondary">Código de barras</Label>
                    <Input
                      placeholder="759123456789"
                      value={barcode}
                      onChange={(e) => setBarcode(e.target.value)}
                      className="mt-1 font-mono text-xs"
                    />
                  </div>
                </div>

                {/* Categoría y Marca */}
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label className="text-xs text-text-secondary">Categoría</Label>
                    <Select
                      value={categoryId ? String(categoryId) : ''}
                      onChange={(e) =>
                        setCategoryId(e.target.value ? Number(e.target.value) : undefined)
                      }
                      className="mt-1 text-xs"
                    >
                      <option value="">(Sin categoría)</option>
                      {categories.map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div>
                    <Label className="text-xs text-text-secondary">Marca</Label>
                    <Select
                      value={brandId ? String(brandId) : ''}
                      onChange={(e) =>
                        setBrandId(e.target.value ? Number(e.target.value) : undefined)
                      }
                      className="mt-1 text-xs"
                    >
                      <option value="">(Sin marca)</option>
                      {brands.map((b) => (
                        <option key={b.id} value={b.id}>
                          {b.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                </div>

                {/* Unidad de Medida y Switch Activo */}
                <div className="grid grid-cols-2 gap-2 items-center">
                  <div>
                    <Label className="text-xs text-text-secondary">Unidad de Medida</Label>
                    <Select
                      value={unitOfMeasure}
                      onChange={(e) => setUnitOfMeasure(e.target.value)}
                      className="mt-1 text-xs"
                    >
                      {STANDARD_UNITS_OF_MEASURE.map((u) => (
                        <option key={u.value} value={u.value}>
                          {u.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="flex items-center justify-between bg-surface-subtle/50 border border-border/80 rounded-lg p-2.5 mt-3.5">
                    <Label className="text-xs cursor-pointer" onClick={() => setIsActive(!isActive)}>
                      Activo para venta
                    </Label>
                    <Switch checked={isActive} onCheckedChange={setIsActive} />
                  </div>
                </div>

                {/* Foto / URL de imagen con vista previa */}
                <div className="flex flex-col gap-1.5 pt-1">
                  <Label className="text-xs text-text-secondary">URL de Imagen (Opcional)</Label>
                  <div className="flex items-center gap-2">
                    <div className="size-10 rounded-lg border border-border bg-slate-50 dark:bg-zinc-900 overflow-hidden flex items-center justify-center shrink-0">
                      {imageUrl ? (
                        <img
                          src={imageUrl}
                          alt="Preview"
                          className="size-full object-contain"
                          onError={(e) => {
                            e.currentTarget.style.display = 'none';
                          }}
                        />
                      ) : (
                        <Package className="size-5 text-text-muted/60" />
                      )}
                    </div>
                    <Input
                      placeholder="https://ejemplo.com/foto.jpg"
                      value={imageUrl}
                      onChange={(e) => setImageUrl(e.target.value)}
                      className="text-xs flex-1"
                    />
                  </div>
                </div>
              </div>

              {/* Columna Derecha: Costo y Precios de Venta desde el inicio (7 cols) */}
              <div className="lg:col-span-7 flex flex-col gap-4">
                <div className="bg-surface border border-border/80 rounded-xl p-4 shadow-2xs flex flex-col gap-3">
                  <div className="flex items-center justify-between border-b border-border/60 pb-2">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-text-secondary flex items-center gap-1.5">
                      <DollarSign className="size-4 text-primary" />
                      Costo y Tarifas de Venta
                    </h4>
                    {activeRate && (
                      <span className="text-[11px] font-medium text-text-muted bg-surface-subtle px-2 py-0.5 rounded border border-border/60">
                        Tasa: 1 USD = {activeRate.rate.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES
                      </span>
                    )}
                  </div>

                  {/* Campo de Costo de Compra */}
                  <div className="bg-amber-500/5 border border-amber-500/20 rounded-lg p-2.5 flex items-center justify-between gap-3">
                    <div className="flex flex-col">
                      <Label className="text-xs font-semibold text-amber-700 dark:text-amber-400">
                        Costo de compra (USD)
                      </Label>
                      <span className="text-[10px] text-text-muted">
                        Permite calcular en vivo tu ganancia en cada tarifa
                      </span>
                    </div>
                    <div className="w-32">
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0.00"
                        value={cost}
                        onChange={(e) => setCost(e.target.value)}
                        className="text-right font-bold h-8 text-sm"
                      />
                    </div>
                  </div>

                  {/* Tarifas de Precios de Venta */}
                  <div className="flex flex-col gap-2 pt-1">
                    {activeLists.length === 0 ? (
                      <div className="text-xs text-text-muted text-center p-3 border border-dashed rounded-lg">
                        No hay listas de precios activas registradas.
                      </div>
                    ) : (
                      activeLists.map((list) => {
                        const currentVal = priceMap[list.id] ?? '';
                        const numVal = parseFloat(currentVal) || 0;
                        const vesVal = activeRate && numVal > 0 ? numVal * activeRate.rate : null;
                        const costNum = parseFloat(cost) || 0;
                        const margin =
                          costNum > 0 && numVal > 0
                            ? (((numVal - costNum) / costNum) * 100).toFixed(0)
                            : null;
                        const profit =
                          costNum > 0 && numVal > 0 ? (numVal - costNum).toFixed(2) : null;
                        const isDefault = Boolean(list.is_default);

                        return (
                          <div
                            key={list.id}
                            className={cn(
                              'p-2.5 rounded-lg border flex flex-col gap-1.5 transition-colors',
                              isDefault
                                ? 'bg-primary/5 border-primary/30'
                                : 'bg-surface-subtle/40 border-border/70',
                            )}
                          >
                            <div className="flex items-center justify-between gap-2">
                              <div className="flex items-center gap-1.5">
                                <span className="text-xs font-bold text-text-primary">
                                  {list.name}
                                </span>
                                {isDefault && (
                                  <span className="text-[9px] font-semibold text-primary bg-primary/10 px-1.5 py-0.5 rounded">
                                    Predeterminada
                                  </span>
                                )}
                                {list.code && (
                                  <span className="text-[10px] font-mono text-text-muted">
                                    ({list.code})
                                  </span>
                                )}
                              </div>
                              <div className="flex items-center gap-1.5">
                                <span className="text-xs font-semibold text-text-muted">$</span>
                                <Input
                                  type="number"
                                  step="any"
                                  min="0"
                                  placeholder="0.00"
                                  value={currentVal}
                                  onChange={(e) =>
                                    handlePriceChange(list.id, e.target.value, isDefault)
                                  }
                                  className="w-28 text-right font-bold text-sm h-8"
                                />
                              </div>
                            </div>

                            {/* Conversión en Bs y Margen */}
                            <div className="flex items-center justify-between text-[11px] text-text-muted pt-1 border-t border-border/30">
                              <span>
                                {vesVal != null ? (
                                  <span className="font-semibold text-text-secondary">
                                    Bs{' '}
                                    {vesVal.toLocaleString('es-VE', {
                                      minimumFractionDigits: 2,
                                      maximumFractionDigits: 2,
                                    })}
                                  </span>
                                ) : (
                                  <span>Bs 0,00</span>
                                )}
                              </span>
                              {margin != null && (
                                <span
                                  className={cn(
                                    'font-medium text-[10px]',
                                    Number(margin) >= 0
                                      ? 'text-emerald-600 dark:text-emerald-400'
                                      : 'text-rose-600 dark:text-rose-400',
                                  )}
                                >
                                  Margen: +{margin}% (+${profit} ganancia)
                                </span>
                              )}
                            </div>
                          </div>
                        );
                      })
                    )}
                  </div>
                </div>
              </div>
            </div>
          ) : (
            /* Pestaña Secundaria: Parámetros Avanzados */
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="flex flex-col gap-4">
                <div>
                  <Label className="text-xs font-semibold text-text-secondary">
                    Descripción / Especificaciones
                  </Label>
                  <Textarea
                    rows={6}
                    placeholder="Detalles técnicos, compatibilidad o notas..."
                    value={longDescription}
                    onChange={(e) => setLongDescription(e.target.value)}
                    className="mt-1"
                  />
                </div>
                <div>
                  <Label className="text-xs font-semibold text-text-secondary">
                    Política de garantía
                  </Label>
                  <Select
                    value={warrantyPolicyId ? String(warrantyPolicyId) : ''}
                    onChange={(e) =>
                      setWarrantyPolicyId(e.target.value ? Number(e.target.value) : undefined)
                    }
                    className="mt-1 text-xs"
                  >
                    <option value="">(Sin política de garantía)</option>
                    {warranties.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name} ({w.duration_days} días)
                      </option>
                    ))}
                  </Select>
                </div>
              </div>

              <div className="flex flex-col gap-4">
                {/* Control de Inventario */}
                <div className="p-4 rounded-xl border border-border bg-surface flex flex-col gap-3">
                  <h5 className="text-xs font-bold uppercase tracking-wider text-text-secondary">
                    Control de Inventario
                  </h5>
                  <div className="flex items-center justify-between">
                    <div>
                      <Label className="text-xs">Rastrear existencias</Label>
                      <p className="text-[11px] text-text-muted">
                        Afectar inventario en ventas y compras
                      </p>
                    </div>
                    <Switch checked={trackStock} onCheckedChange={setTrackStock} />
                  </div>
                  <div className="pt-2 border-t border-border/50">
                    <Label className="text-xs">Modalidad de rastreo</Label>
                    <Select
                      value={trackingType}
                      onChange={(e) =>
                        setTrackingType(e.target.value as 'quantity' | 'serialized')
                      }
                      className="mt-1 text-xs"
                    >
                      <option value="quantity">Por cantidad (estándar)</option>
                      <option value="serialized">
                        Serializado (IMEI / Números de serie individuales)
                      </option>
                    </Select>
                  </div>
                </div>

                {/* Umbrales de Stock */}
                <div className="p-4 rounded-xl border border-border bg-surface flex flex-col gap-3">
                  <h5 className="text-xs font-bold uppercase tracking-wider text-text-secondary">
                    Umbrales de Stock
                  </h5>
                  <div className="grid grid-cols-3 gap-2">
                    <div>
                      <Label className="text-[11px] text-text-secondary">Mínimo</Label>
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0"
                        value={minStock}
                        onChange={(e) => setMinStock(e.target.value)}
                        className="mt-1 text-xs"
                      />
                    </div>
                    <div>
                      <Label className="text-[11px] text-text-secondary">Máximo</Label>
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0"
                        value={maxStock}
                        onChange={(e) => setMaxStock(e.target.value)}
                        className="mt-1 text-xs"
                      />
                    </div>
                    <div>
                      <Label className="text-[11px] text-text-secondary">Punto Reorden</Label>
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0"
                        value={reorderQuantity}
                        onChange={(e) => setReorderQuantity(e.target.value)}
                        className="mt-1 text-xs"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <DialogFooter className="border-t border-border p-4 bg-surface-subtle/40 flex items-center justify-between sm:justify-between w-full shrink-0">
          <Button
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={isSubmitting}
          >
            Cancelar
          </Button>

          <Button
            size="sm"
            onClick={handleSubmit}
            disabled={isSubmitting}
            className="gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm font-semibold"
          >
            {isSubmitting ? (
              <Spinner size="sm" className="text-white" />
            ) : (
              <Save className="size-3.5" />
            )}
            {isSubmitting ? 'Creando producto...' : 'Crear Producto'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
