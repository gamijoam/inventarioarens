/**
 * ProductForm: formulario completo de producto estilo ERP (create + edit).
 * Diseño de alta productividad y densidad estilo ERP moderno:
 *  - Pestaña 1: Ficha Principal (Todo-en-Uno) [F1]
 *      Reúne en una sola vista de 2 columnas los datos esenciales:
 *      Columna Izquierda: Identificación & Códigos, Catálogos (Categorías/Tags) y Garantía.
 *      Columna Derecha: Precios & Tarifas (con desglose de utilidad e IVA), Control de Stock y Listas de Precios.
 *  - Pestañas especializadas para drilldown rápido:
 *      [F2] Tarifas y PVP (Precios base + Editor completo de Listas de Precios)
 *      [F3] Existencias y Medidas (Control de stock + Detalle por almacén)
 *      [F4] Clasificación (Categorías en árbol y etiquetas)
 *      [F5] Galería de Fotos (URL externa y multi-foto WebP)
 *      [F6] Garantías y Ficha (Garantía y ficha técnica)
 *      [F7 - F9] Variantes, Kardex y Auditoría
 */
import { type UseFormReturn, useController } from 'react-hook-form';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertTriangle,
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
  Sparkles,
  Star,
  Tag as TagIcon,
  Tags as TagsIcon,
  Trash2,
  UploadCloud,
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
import { toast } from '@/lib/toast';
import { putOne, postOne, api } from '@/api/client';
import { useQueryClient } from '@tanstack/react-query';
import { productKeys } from '@/features/inventory-center/queries';

import {
  SALE_CURRENCIES,
  PRICING_MODES,
  TRACKING_TYPES,
  type Product,
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
import { ProductVariantsTab, type PendingVariant } from './ProductVariantsTab';
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

export interface StagedImageItem {
  id: string;
  file: File;
  previewUrl: string;
  name: string;
  sizeStr: string;
}

export interface ProductFormProps {
  form: UseFormReturn<StoreProductInput, unknown, StoreProductValues>;
  tagOptions: { value: number; label: string; color?: string }[];
  compact?: boolean;
  onCancel?: () => void;
  submitLabel?: string;
  onSubmit: (onSaveExtras?: (product: Product) => Promise<void>) => Promise<unknown> | void;
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
  const [visitedTabs, setVisitedTabs] = useState<Set<ProductFormTab>>(() => new Set(['general']));
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [isSyncingPrices, setIsSyncingPrices] = useState(false);
  const qc = useQueryClient();

  // Precios pendientes modificados en el editor de listas
  const [pendingPrices, setPendingPrices] = useState<
    Array<{
      price_list_id: number;
      price: number | null;
      margin_percentage?: number | null;
      currency: 'USD' | 'VES';
      remove: boolean;
    }>
  >([]);

  const handlePricesChange = useCallback(
    (
      prices: Array<{
        price_list_id: number;
        price: number | null;
        margin_percentage?: number | null;
        currency: 'USD' | 'VES';
        remove: boolean;
      }>,
    ) => {
      setPendingPrices(prices);
    },
    [],
  );

  // Variantes pendientes para producto nuevo
  const [pendingVariants, setPendingVariants] = useState<PendingVariant[]>([]);

  // Modo de fotos en la pestaña F5: 'upload' (archivos de PC / portapapeles) o 'url' (enlace web)
  const [photoMode, setPhotoMode] = useState<'upload' | 'url'>('upload');
  const [isResolvingUrl, setIsResolvingUrl] = useState(false);
  const [urlImageError, setUrlImageError] = useState(false);

  const handleResolveUrl = async () => {
    const rawUrl = form.getValues('image_url')?.trim();
    if (!rawUrl) {
      toast.error('Ingresa un enlace web primero.');
      return;
    }
    try {
      setIsResolvingUrl(true);
      const response = await api.post<{ image_url: string; source: string }>(
        '/products/resolve-image-url',
        { url: rawUrl },
      );
      const res = response.data;
      if (res && res.image_url) {
        form.setValue('image_url', res.image_url, { shouldDirty: true, shouldValidate: true });
        setUrlImageError(false);
        if (res.source === 'og_image' || res.source === 'twitter_image') {
          toast.success('¡Foto del producto extraída exitosamente de la página web!');
        } else {
          toast.success('Enlace de imagen verificado correctamente.');
        }
      }
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : 'No se pudo extraer la imagen del enlace ingresado.',
      );
    } finally {
      setIsResolvingUrl(false);
    }
  };

  // Imágenes seleccionadas listas para subida
  const [stagedImages, setStagedImages] = useState<StagedImageItem[]>([]);
  const [isDraggingPhotos, setIsDraggingPhotos] = useState(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const handleFilesSelected = useCallback((fileList: FileList | File[] | null) => {
    if (!fileList || fileList.length === 0) return;
    const files = Array.from(fileList);
    const validItems: StagedImageItem[] = [];

    for (const file of files) {
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        toast.error(`"${file.name}" no es válido. Solo se admiten formatos JPG, PNG o WebP.`);
        continue;
      }
      if (file.size > 5 * 1024 * 1024) {
        toast.error(`"${file.name}" supera el tamaño máximo permitido (5 MB).`);
        continue;
      }
      validItems.push({
        id: `staged-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
        file,
        previewUrl: URL.createObjectURL(file),
        name: file.name,
        sizeStr:
          file.size < 1024 * 1024
            ? `${(file.size / 1024).toFixed(1)} KB`
            : `${(file.size / (1024 * 1024)).toFixed(1)} MB`,
      });
    }

    if (validItems.length > 0) {
      setStagedImages((prev) => [...prev, ...validItems]);
      toast.success(`${validItems.length} foto(s) lista(s) para guardar.`);
    }
  }, []);

  const removeStagedImage = useCallback((id: string) => {
    setStagedImages((prev) => {
      const target = prev.find((i) => i.id === id);
      if (target) URL.revokeObjectURL(target.previewUrl);
      return prev.filter((i) => i.id !== id);
    });
  }, []);

  const setPrimaryStagedImage = useCallback((index: number) => {
    if (index === 0) return;
    setStagedImages((prev) => {
      const item = prev[index];
      if (!item) return prev;
      const rest = prev.filter((_, i) => i !== index);
      return [item, ...rest];
    });
    toast.success('Foto marcada como portada principal.');
  }, []);

  // Soporte para pegar imágenes directamente desde el portapapeles con Ctrl+V
  useEffect(() => {
    function onPaste(e: ClipboardEvent) {
      const items = e.clipboardData?.items;
      if (!items) return;
      const files: File[] = [];
      for (const item of items) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
          const f = item.getAsFile();
          if (f) files.push(f);
        }
      }
      if (files.length > 0) {
        e.preventDefault();
        handleFilesSelected(files);
      }
    }
    window.addEventListener('paste', onPaste);
    return () => window.removeEventListener('paste', onPaste);
  }, [handleFilesSelected]);

  const handleTabChange = (tab: ProductFormTab) => {
    setActiveTab(tab);
    setVisitedTabs((prev) => {
      if (prev.has(tab)) return prev;
      const next = new Set(prev);
      next.add(tab);
      return next;
    });
  };

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
  const { data: galleryImages = [] } = useProductImages(
    productId && visitedTabs.has('images') ? productId : null,
  );

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
    Number.isFinite(cost) && cost > 0 && Number.isFinite(margin)
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
        handleTabChange('general');
      } else if (e.key === 'F2') {
        e.preventDefault();
        handleTabChange('pricing');
      } else if (e.key === 'F3') {
        e.preventDefault();
        handleTabChange('stock');
      } else if (e.key === 'F4') {
        e.preventDefault();
        handleTabChange('catalogs');
      } else if (e.key === 'F5') {
        e.preventDefault();
        handleTabChange('images');
      } else if (e.key === 'F6') {
        e.preventDefault();
        handleTabChange('details');
      } else if (e.key === 'F7') {
        e.preventDefault();
        handleTabChange('variants');
      } else if (e.key === 'F8' && productId) {
        e.preventDefault();
        handleTabChange('kardex');
      } else if (e.key === 'F9' && productId) {
        e.preventDefault();
        handleTabChange('audits');
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

  // Cálculos financieros: Costo Compra -> Costo c/IVA -> Ganancia -> PVP Final
  const costBase = Number.isFinite(cost) && cost > 0 ? cost : 0;
  const costWithIva = applyIva && costBase > 0 ? Math.round(costBase * (1 + ivaRate / 100) * 100) / 100 : costBase;
  const effectivePrice =
    pricingMode === 'automatic' && calculatedSalePrice && (!productId || !isInitialMount.current)
      ? Number(calculatedSalePrice)
      : currentBasePrice;

  const comparisonCost = applyIva && costWithIva > 0 ? costWithIva : costBase;
  const grossProfitUsd = effectivePrice > 0 && comparisonCost > 0 ? Math.round((effectivePrice - comparisonCost) * 100) / 100 : 0;
  const actualProfitMarginPercent =
    comparisonCost > 0 && effectivePrice > 0
      ? Math.round(((effectivePrice - comparisonCost) / comparisonCost) * 1000) / 10
      : margin > 0 ? margin : 0;

  // Detección de errores por sección
  const errors = form.formState.errors;
  const hasGeneralErrors = Boolean(errors.name || errors.sku || errors.barcode || errors.image_url || errors.description);
  const hasPricingErrors = Boolean(errors.base_price || errors.last_purchase_cost || errors.profit_margin || errors.sale_currency || errors.sale_exchange_rate_type_id);
  const hasStockErrors = Boolean(errors.min_stock || errors.max_stock || errors.reorder_quantity || errors.tracking_type || errors.unit_of_measure);
  const hasCatalogErrors = Boolean(errors.category_ids || errors.tag_ids || errors.brand_id);
  const hasDetailsErrors = Boolean(errors.warranty_policy_id || errors.long_description);

  const showDetailsSection = Boolean(
    activeVisibility.warranty_policy_id || activeVisibility.is_active || activeVisibility.long_description,
  );

  // Guardado unificado de producto + listas de precios + variantes + fotos
  const handleFormSubmit = async () => {
    try {
      setIsSyncingPrices(true);
      if (productId) {
        // 1. Guardar primero los precios modificados de las listas
        if (pendingPrices.length > 0) {
          await putOne(`/products/${productId}/prices`, { prices: pendingPrices });
          setPendingPrices([]);
        }
        // 2. Subir imágenes nuevas seleccionadas si las hay
        if (stagedImages.length > 0) {
          for (const item of stagedImages) {
            const formData = new FormData();
            formData.append('image', item.file);
            await api.post(`/products/${productId}/images`, formData);
          }
          setStagedImages([]);
        }
        // 3. Guardar datos principales del producto (dispara onSuccess)
        await onSubmit();
        void qc.invalidateQueries({ queryKey: productKeys.prices(productId) });
        void qc.invalidateQueries({ queryKey: productKeys.detail(productId) });
        void qc.invalidateQueries({ queryKey: productKeys.lists() });
      } else {
        // En modo creación, guardar producto y sus extras asociados
        await onSubmit(async (newProduct: Product) => {
          // A. Guardar precios de listas configurados
          if (pendingPrices.length > 0) {
            await putOne(`/products/${newProduct.id}/prices`, { prices: pendingPrices });
          }
          // B. Guardar variantes configuradas
          if (pendingVariants.length > 0) {
            for (const v of pendingVariants) {
              await postOne(`/products/${newProduct.id}/variants`, {
                color: v.color,
                color_hex: v.color_hex || '#888888',
                sku_variant: v.sku_variant || undefined,
                barcode_variant: v.barcode_variant || undefined,
                price_override: v.price_override ? Number(v.price_override) : null,
                position: Number(v.position) || 0,
                is_active: v.is_active,
              });
            }
          }
          // C. Subir imágenes seleccionadas
          if (stagedImages.length > 0) {
            for (const item of stagedImages) {
              const formData = new FormData();
              formData.append('image', item.file);
              await api.post(`/products/${newProduct.id}/images`, formData);
            }
          }
          void qc.invalidateQueries({ queryKey: productKeys.all });
          void qc.invalidateQueries({ queryKey: productKeys.detail(newProduct.id) });
          void qc.invalidateQueries({ queryKey: productKeys.lists() });
        });
      }
    } catch (err) {
      console.error('Error al guardar producto:', err);
      toast.error(err instanceof Error ? err.message : 'Error al guardar producto.');
    } finally {
      setIsSyncingPrices(false);
    }
  };

  return (
    <form
      noValidate
      onSubmit={(e) => {
        e.preventDefault();
        void handleFormSubmit();
      }}
      className="flex flex-col h-full overflow-hidden"
    >
      {/* ============================================================ */}
      {/* Barra Superior de Pestañas ERP con Atajos F1 - F9           */}
      {/* ============================================================ */}
      <div className="border-b border-border bg-surface-subtle/30 px-4 pt-1.5 flex items-center gap-1.5 overflow-x-auto shrink-0 select-none">
        <TabButton
          active={activeTab === 'general'}
          onClick={() => handleTabChange('general')}
          shortcut="F1"
          label="Ficha Principal"
          icon={<ClipboardList className="size-3.5" />}
          hasError={hasGeneralErrors || hasPricingErrors || hasStockErrors || hasCatalogErrors}
        />
        <TabButton
          active={activeTab === 'pricing'}
          onClick={() => handleTabChange('pricing')}
          shortcut="F2"
          label="Tarifas y PVP"
          icon={<DollarSign className="size-3.5" />}
          hasError={hasPricingErrors}
        />
        <TabButton
          active={activeTab === 'stock'}
          onClick={() => handleTabChange('stock')}
          shortcut="F3"
          label="Existencias y Medidas"
          icon={<Boxes className="size-3.5" />}
          hasError={hasStockErrors}
        />
        <TabButton
          active={activeTab === 'catalogs'}
          onClick={() => handleTabChange('catalogs')}
          shortcut="F4"
          label="Clasificación"
          icon={<TagsIcon className="size-3.5" />}
          hasError={hasCatalogErrors}
        />
        <TabButton
          active={activeTab === 'images'}
          onClick={() => handleTabChange('images')}
          shortcut="F5"
          label={
            stagedImages.length > 0
              ? `Fotos (${stagedImages.length})`
              : 'Galería de Fotos'
          }
          icon={<ImageIcon className="size-3.5" />}
        />
        {showDetailsSection && (
          <TabButton
            active={activeTab === 'details'}
            onClick={() => handleTabChange('details')}
            shortcut="F6"
            label="Garantías y Ficha"
            icon={<ShieldCheck className="size-3.5" />}
            hasError={hasDetailsErrors}
          />
        )}
        <TabButton
          active={activeTab === 'variants'}
          onClick={() => handleTabChange('variants')}
          shortcut="F7"
          label={
            pendingVariants.length > 0
              ? `Variantes (${pendingVariants.length})`
              : 'Variantes'
          }
          icon={<Layers className="size-3.5" />}
        />
        {productId && (
          <>
            <TabButton
              active={activeTab === 'kardex'}
              onClick={() => handleTabChange('kardex')}
              shortcut="F8"
              label="Kardex"
              icon={<History className="size-3.5" />}
            />
            <TabButton
              active={activeTab === 'audits'}
              onClick={() => handleTabChange('audits')}
              shortcut="F9"
              label="Auditoría"
              icon={<FileClock className="size-3.5" />}
            />
          </>
        )}
      </div>

      {/* ============================================================ */}
      {/* Contenedor Principal ERP con Scroll y Densidad Óptima        */}
      {/* ============================================================ */}
      <div className="flex-1 overflow-y-auto p-4 sm:p-5">
        <div className={cn(activeTab === 'general' ? 'grid grid-cols-1 lg:grid-cols-2 gap-4.5 items-start' : 'space-y-4')}>
          {/* ============================================================ */}
          {/* COLUMNA 1 (IZQUIERDA): Identificación, Catálogos, Garantía   */}
          {/* ============================================================ */}
          <div
            className={cn(
              'space-y-4',
              activeTab !== 'general' && activeTab !== 'catalogs' && activeTab !== 'details' && 'hidden',
            )}
          >
            {/* 1. SECCIÓN IDENTIFICACIÓN */}
            <div className={cn(activeTab !== 'general' && 'hidden')}>
              <div className="rounded-xl border border-border/80 bg-surface shadow-xs p-4">
                <fieldset className="space-y-3.5">
                  <SectionLegend>Identificacion</SectionLegend>
                  <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                      <Field
                        name="name"
                        label="Nombre del Producto"
                        required
                        error={form.formState.errors.name?.message}
                        hint="Identificador principal para ventas, POS y comprobantes"
                      >
                        <Input
                          {...form.register('name')}
                          placeholder="Ej. Nombre del producto"
                          className="font-semibold text-base h-10"
                          autoFocus
                        />
                      </Field>
                    </div>

                    {/* Indicador sutil de foto en Identificación */}
                    <div className="sm:col-span-2 rounded-lg border border-border/80 bg-surface-subtle/30 px-3 py-2 flex items-center justify-between gap-3">
                      <div className="flex items-center gap-2.5 min-w-0">
                        {stagedImages.length > 0 && stagedImages[0] ? (
                          <div className="relative size-9 rounded-md border border-primary/50 overflow-hidden shrink-0 bg-surface">
                            <img src={stagedImages[0].previewUrl} alt="Portada" className="size-full object-cover" />
                          </div>
                        ) : form.watch('image_url') ? (
                          <div className="size-9 rounded-md border border-border overflow-hidden shrink-0 bg-surface">
                            <img
                              src={form.watch('image_url') || ''}
                              alt="URL"
                              className="size-full object-cover"
                              onError={(e) => { e.currentTarget.style.display = 'none'; }}
                            />
                          </div>
                        ) : galleryImages.length > 0 && galleryImages[0] ? (
                          <div className="size-9 rounded-md border border-border overflow-hidden shrink-0 bg-surface">
                            <img
                              src={galleryImages[0].thumb_url || galleryImages[0].url}
                              alt="Foto"
                              className="size-full object-cover"
                            />
                          </div>
                        ) : (
                          <div className="size-9 rounded-md border border-dashed border-border flex items-center justify-center shrink-0 bg-surface text-text-muted">
                            <ImageIcon className="size-4 text-text-muted/60" />
                          </div>
                        )}
                        <div className="min-w-0">
                          <p className="text-xs font-medium text-text-primary truncate">
                            {stagedImages.length > 0
                              ? `${stagedImages.length} foto(s) lista(s) para guardar`
                              : galleryImages.length > 0
                              ? `${galleryImages.length} foto(s) guardada(s)`
                              : form.watch('image_url')
                              ? 'Foto vinculada por enlace'
                              : 'Sin fotografías todavía'}
                          </p>
                        </div>
                      </div>

                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => handleTabChange('images')}
                        className="h-7 px-2 text-xs text-primary hover:bg-primary/10 font-semibold gap-1 shrink-0"
                      >
                        <span>Gestionar fotos [F5]</span>
                        <span>→</span>
                      </Button>
                    </div>

                    {activeVisibility.sku && (
                      <Field
                        name="sku"
                        label="SKU (Código interno)"
                        hint="Código único de inventario"
                        error={form.formState.errors.sku?.message}
                      >
                        <Input
                          {...form.register('sku')}
                          placeholder="Ej. SKU-001"
                          className="font-mono text-xs uppercase h-9"
                        />
                      </Field>
                    )}

                    {activeVisibility.barcode && (
                      <Field
                        name="barcode"
                        label="Código de barras"
                        hint="Escanéalo o ingrésalo manual"
                      >
                        <Input
                          {...form.register('barcode')}
                          placeholder="Ej. 7501234567890"
                          className="font-mono text-xs h-9"
                        />
                      </Field>
                    )}

                    {activeVisibility.brand && (
                      <div className="space-y-1">
                        <div className="flex items-center justify-between">
                          <Label htmlFor="brand_id" className="font-bold text-xs text-text-primary">
                            Marca
                          </Label>
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
                          className="h-9 text-xs"
                        >
                          {brandOptions.map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </Select>
                      </div>
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

                    {activeVisibility.is_active && (
                      <div className="sm:col-span-2 rounded-lg border border-border/80 bg-surface-subtle/30 px-3.5 py-2.5 flex items-center justify-between">
                        <div>
                          <Label htmlFor="is_active" className="font-semibold text-xs text-text-primary">
                            Producto Activo en Ventas
                          </Label>
                          <p className="text-[11px] text-text-muted">
                            Habilitado para facturación en POS y búsqueda de inventario.
                          </p>
                        </div>
                        <SwitchField form={form} name="is_active" label="" />
                      </div>
                    )}

                    {activeVisibility.description && (
                      <div className="sm:col-span-2">
                        <Field
                          name="description"
                          label="Descripción corta"
                          error={form.formState.errors.description?.message}
                          hint="Resumen rápido para comprobantes y tickets"
                        >
                          <Textarea
                            {...form.register('description')}
                            rows={2}
                            placeholder="Ej. Breve descripción o características principales..."
                            className="text-xs"
                          />
                        </Field>
                      </div>
                    )}
                  </div>
                </fieldset>
              </div>
            </div>

            {/* 2. SECCIÓN CATÁLOGOS */}
            <div className={cn(activeTab !== 'general' && activeTab !== 'catalogs' && 'hidden')}>
              <div className="rounded-xl border border-border/80 bg-surface shadow-xs p-4">
                <fieldset className="space-y-3.5">
                  <SectionLegend>Catálogos</SectionLegend>

                  {activeVisibility.categories && (
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between">
                        <Label className="font-bold text-xs text-text-primary">Categorías</Label>
                        <InlineCatalogCreate kind="category" onCreated={() => undefined} />
                      </div>
                      <Input
                        type="text"
                        placeholder="Buscar categoría..."
                        value={categorySearch}
                        onChange={(e) => setCategorySearch(e.target.value)}
                        className="h-8 text-xs mb-1.5"
                      />
                      <div className="max-h-44 overflow-y-auto rounded-lg border border-border/80 p-2.5 bg-surface-subtle/20 text-xs">
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
                    <div className="space-y-1.5 pt-1">
                      <div className="flex items-center justify-between">
                        <Label className="font-bold text-xs text-text-primary">Tags</Label>
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
            </div>

            {/* 3. SECCIÓN GARANTÍA Y ESTADO */}
            {showDetailsSection && (
              <div className={cn(activeTab !== 'general' && activeTab !== 'details' && 'hidden')}>
                <div className="rounded-xl border border-border/80 bg-surface shadow-xs p-4">
                  <fieldset className="space-y-3.5">
                    <SectionLegend>Garantía y estado</SectionLegend>
                    {activeVisibility.warranty_policy_id && (
                      <Field
                        name="warranty_policy_id"
                        label="Política de garantía"
                        error={form.formState.errors.warranty_policy_id?.message}
                        hint="Garantía predeterminada para el cliente"
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
                            className="flex-1 h-9 text-xs"
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
                        hint="Especificaciones completas y ficha del fabricante"
                      >
                        <Textarea
                          {...form.register('long_description')}
                          rows={4}
                          placeholder="Especificaciones completas, características técnicas..."
                          className="text-xs"
                        />
                      </Field>
                    )}
                  </fieldset>
                </div>
              </div>
            )}
          </div>

          {/* ============================================================ */}
          {/* COLUMNA 2 (DERECHA): Precios, Stock, Listas de Precios       */}
          {/* ============================================================ */}
          <div
            className={cn(
              'space-y-4',
              activeTab !== 'general' && activeTab !== 'pricing' && activeTab !== 'stock' && 'hidden',
            )}
          >
            {/* 4. SECCIÓN PRECIOS Y TARIFAS */}
            <div className={cn(activeTab !== 'general' && activeTab !== 'pricing' && 'hidden')}>
              <div className="rounded-xl border border-border/80 bg-surface shadow-xs p-4">
                <fieldset className="space-y-3.5">
                  <SectionLegend>Precios</SectionLegend>

                  {/* Resumen Financiero Compacto Estilo KPI */}
                  <div className="rounded-xl border border-primary/20 bg-primary/5 p-3 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                    <div>
                      <span className="text-[10px] uppercase font-bold text-text-muted">1. Costo Compra</span>
                      <p className="text-sm font-bold font-mono text-text-primary">
                        ${costBase > 0 ? costBase.toFixed(2) : '0.00'}
                      </p>
                      <span className="text-[10px] text-text-muted block">Proveedor</span>
                    </div>
                    <div>
                      <span className="text-[10px] uppercase font-bold text-text-muted">
                        2. Costo {applyIva ? `c/IVA (${ivaRate}%)` : '(Exento)'}
                      </span>
                      <p className="text-sm font-bold font-mono text-text-secondary">
                        ${costWithIva > 0 ? costWithIva.toFixed(2) : '0.00'}
                      </p>
                      <span className="text-[10px] text-text-muted block font-mono">
                        {applyIva && costBase > 0 ? `+$${(costWithIva - costBase).toFixed(2)}` : 'Sin IVA'}
                      </span>
                    </div>
                    <div>
                      <span className="text-[10px] uppercase font-bold text-text-muted">3. Ganancia</span>
                      <p className="text-sm font-bold font-mono text-primary">
                        {actualProfitMarginPercent > 0 ? `+${actualProfitMarginPercent.toFixed(1)}%` : '0%'}
                      </p>
                      <span className="text-[10px] text-text-muted block font-mono">
                        {grossProfitUsd > 0 ? `+$${grossProfitUsd.toFixed(2)}` : '$0.00'}
                      </span>
                    </div>
                    <div className="border-l border-primary/20 pl-2">
                      <span className="text-[10px] uppercase font-bold text-emerald-600 dark:text-emerald-400">
                        4. PVP Venta Final
                      </span>
                      <p className="text-lg font-black font-mono text-emerald-600 dark:text-emerald-400">
                        ${effectivePrice > 0 ? effectivePrice.toFixed(2) : '0.00'}
                      </p>
                      <span className="text-[10px] text-emerald-700/80 dark:text-emerald-300/80 block">
                        {pricingMode === 'automatic' ? 'Automático' : 'Manual'}
                      </span>
                    </div>
                  </div>

                  {/* Fila de IVA y Configuración */}
                  <div className="rounded-lg border border-border/80 bg-surface-subtle/30 px-3 py-2 flex flex-wrap items-center justify-between gap-2.5">
                    <label className="flex items-center gap-2 cursor-pointer select-none">
                      <input
                        type="checkbox"
                        checked={applyIva}
                        onChange={(e) => setApplyIva(e.target.checked)}
                        className="size-4 rounded border-border text-primary focus:ring-primary/20"
                      />
                      <span className="text-xs font-bold text-text-primary">Aplica IVA</span>
                      <Badge variant={applyIva ? 'primary' : 'outline'} className="text-[10px] font-semibold py-0">
                        {applyIva ? `${ivaRate}% IVA incluido` : 'Exento'}
                      </Badge>
                    </label>

                    {applyIva && (
                      <div className="flex items-center gap-1.5 text-xs ml-auto">
                        <span className="text-text-muted text-[11px] font-medium">% IVA:</span>
                        <Input
                          type="number"
                          min="0"
                          max="100"
                          step="1"
                          value={ivaRate}
                          onChange={(e) => setIvaRate(Math.max(0, Number(e.target.value) || 0))}
                          className="w-16 h-7 text-center font-mono font-bold text-xs"
                        />
                      </div>
                    )}
                  </div>

                  {/* Campos de Costo, Margen y PVP */}
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {activeVisibility.pricing_mode && (
                      <Field name="pricing_mode" label="Modo de precio" hint="Cálculo del PVP">
                        <Select {...form.register('pricing_mode')} className="h-9 text-xs">
                          <option value={PRICING_MODES[0]}>Automático por costo</option>
                          <option value={PRICING_MODES[1]}>Precio manual</option>
                        </Select>
                      </Field>
                    )}

                    {activeVisibility.last_purchase_cost && (
                      <Field
                        name="last_purchase_cost"
                        label="Costo unitario ($)"
                        hint="Reposición en USD"
                        error={form.formState.errors.last_purchase_cost?.message}
                      >
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          {...form.register('last_purchase_cost', { valueAsNumber: true })}
                          className="font-mono text-xs h-9"
                          placeholder="0.00"
                        />
                      </Field>
                    )}

                    {activeVisibility.profit_margin && (
                      <Field
                        name="profit_margin"
                        label="Margen de Ganancia (%)"
                        hint="% utilidad deseado"
                        error={form.formState.errors.profit_margin?.message}
                      >
                        <Input
                          type="number"
                          step="0.01"
                          {...form.register('profit_margin', { valueAsNumber: true })}
                          className="font-mono text-xs h-9"
                          placeholder="0.0"
                        />
                      </Field>
                    )}

                    {activeVisibility.base_price && (
                      <div className="sm:col-span-3">
                        <Field
                          name="base_price"
                          label={pricingMode === 'automatic' ? 'Precio de venta calculado' : 'Precio de venta manual'}
                          hint={applyIva ? `Precio base final con ${ivaRate}% IVA` : 'Precio base final en USD (Exento)'}
                          error={form.formState.errors.base_price?.message}
                        >
                          <div className="space-y-1">
                            {pricingMode === 'automatic' ? (
                              <Input
                                value={calculatedSalePrice || form.getValues('base_price')?.toString() || ''}
                                readOnly
                                className="font-mono font-bold text-sm text-emerald-600 bg-surface-subtle h-9"
                              />
                            ) : (
                              <Input
                                type="number"
                                min="0"
                                step="0.01"
                                {...form.register('base_price', { valueAsNumber: true })}
                                className="font-mono font-bold text-sm text-emerald-600 h-9"
                                placeholder="0.00"
                              />
                            )}
                            {calculatedSalePrice && pricingMode === 'manual' && (
                              <button
                                type="button"
                                onClick={() => form.setValue('base_price', Number(calculatedSalePrice), { shouldValidate: true })}
                                className="text-[11px] font-semibold text-primary hover:underline flex items-center gap-1 cursor-pointer pt-0.5"
                              >
                                ⚡ Aplicar cálculo ({applyIva ? `con ${ivaRate}% IVA` : 'sin IVA'}): ${calculatedSalePrice}
                              </button>
                            )}
                          </div>
                        </Field>
                      </div>
                    )}

                    {activeVisibility.sale_currency && (
                      <Field name="sale_currency" label="Moneda de venta" hint="Moneda de referencia">
                        <Select {...form.register('sale_currency')} className="h-9 text-xs">
                          {SALE_CURRENCIES.map((c) => (
                            <option key={c} value={c}>
                              {c}
                            </option>
                          ))}
                        </Select>
                      </Field>
                    )}

                    {activeVisibility.sale_exchange_rate_type_id && (
                      <div className="sm:col-span-2">
                        <Field
                          name="sale_exchange_rate_type_id"
                          label="Tipo de tasa anclada"
                          hint="Tasa para conversión a VES"
                          error={form.formState.errors.sale_exchange_rate_type_id?.message}
                        >
                          <div className="space-y-1">
                            <div className="flex items-center justify-between">
                              <span className="text-[10px] text-text-muted">Asignar a este producto</span>
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
                              className="h-9 text-xs"
                            >
                              {rateTypeOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                  {o.label}
                                </option>
                              ))}
                            </Select>
                          </div>
                        </Field>
                      </div>
                    )}
                  </div>
                </fieldset>
              </div>

              {/* Editor de Precios por Lista vinculado al formulario */}
              <div className="pt-3">
                <PricesEditor
                  productId={productId}
                  currentBasePrice={effectivePrice}
                  onPricesChange={handlePricesChange}
                  showSaveButton={Boolean(productId)}
                />
              </div>
            </div>

            {/* 5. SECCIÓN CONTROL DE STOCK */}
            <div className={cn(activeTab !== 'general' && activeTab !== 'stock' && 'hidden')}>
              <div className="rounded-xl border border-border/80 bg-surface shadow-xs p-4">
                <fieldset className="space-y-3.5">
                  <SectionLegend>Control de stock</SectionLegend>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {activeVisibility.tracking_type && (
                      <Field
                        name="tracking_type"
                        label="Tipo de control"
                        required
                        hint="Cantidad estándar o serializado con IMEI"
                      >
                        <Select {...form.register('tracking_type')} className="h-9 text-xs">
                          {TRACKING_TYPES.map((t) => (
                            <option key={t} value={t}>
                              {t === 'quantity' ? 'Por cantidad (estándar)' : 'Serializado (IMEI / Serial individual)'}
                            </option>
                          ))}
                        </Select>
                      </Field>
                    )}

                    {activeVisibility.track_stock && (
                      <div className="rounded-lg border border-border/80 bg-surface-subtle/30 px-3.5 py-2 flex items-center justify-between">
                        <div>
                          <Label htmlFor="track_stock" className="font-semibold text-xs text-text-primary">
                            Trackear stock
                          </Label>
                          <p className="text-[11px] text-text-muted">
                            Desactívalo si es un servicio.
                          </p>
                        </div>
                        <SwitchField form={form} name="track_stock" label="" />
                      </div>
                    )}
                  </div>

                  {(activeVisibility.min_stock || activeVisibility.max_stock || activeVisibility.reorder_quantity) && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3 pt-1">
                      {activeVisibility.min_stock && (
                        <Field
                          name="min_stock"
                          label="Stock mínimo"
                          hint="Alerta de reposición"
                          error={form.formState.errors.min_stock?.message}
                        >
                          <Input
                            type="number"
                            min="0"
                            {...form.register('min_stock', {
                              setValueAs: (v) => (v === '' || v == null ? undefined : isNaN(Number(v)) ? undefined : Number(v)),
                            })}
                            className="font-mono text-xs h-9"
                            placeholder="0"
                          />
                        </Field>
                      )}

                      {activeVisibility.max_stock && (
                        <Field
                          name="max_stock"
                          label="Stock máximo"
                          hint="Capacidad tope"
                          error={form.formState.errors.max_stock?.message}
                        >
                          <Input
                            type="number"
                            min="0"
                            {...form.register('max_stock', {
                              setValueAs: (v) => (v === '' || v == null ? undefined : isNaN(Number(v)) ? undefined : Number(v)),
                            })}
                            className="font-mono text-xs h-9"
                            placeholder="0"
                          />
                        </Field>
                      )}

                      {activeVisibility.reorder_quantity && (
                        <Field
                          name="reorder_quantity"
                          label="Reorden sugerido"
                          hint="Lote de compra"
                          error={form.formState.errors.reorder_quantity?.message}
                        >
                          <Input
                            type="number"
                            min="0"
                            {...form.register('reorder_quantity', {
                              setValueAs: (v) => (v === '' || v == null ? undefined : isNaN(Number(v)) ? undefined : Number(v)),
                            })}
                            className="font-mono text-xs h-9"
                            placeholder="0"
                          />
                        </Field>
                      )}
                    </div>
                  )}
                </fieldset>
              </div>

              {productId && (
                <div className="pt-3">
                  <ProductStockDetailPanel
                    productId={productId}
                    trackingType={form.watch('tracking_type')}
                  />
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Pestaña 5: Imágenes [F5] */}
        <div className={cn('space-y-4', activeTab !== 'images' && 'hidden')}>
          <fieldset className="space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-border/80 pb-3">
              <div>
                <SectionLegend>Fotografías del producto</SectionLegend>
                <p className="text-xs text-text-muted mt-0.5">
                  Elige si prefieres subir archivos desde tu equipo o vincular un enlace web.
                </p>
              </div>

              {/* Selector de modo claro y profesional */}
              <div className="inline-flex rounded-lg border border-border bg-surface-subtle/50 p-1 self-start sm:self-auto">
                <button
                  type="button"
                  onClick={() => setPhotoMode('upload')}
                  className={cn(
                    'px-3 py-1.5 text-xs font-semibold rounded-md transition-all flex items-center gap-1.5 cursor-pointer',
                    photoMode === 'upload'
                      ? 'bg-primary text-white shadow-xs'
                      : 'text-text-muted hover:text-text-primary',
                  )}
                >
                  <UploadCloud className="size-3.5" />
                  <span>Subir desde mi equipo</span>
                  {stagedImages.length > 0 && (
                    <span className="px-1.5 py-0.2 rounded-full bg-white/20 text-white text-[10px] font-bold">
                      {stagedImages.length}
                    </span>
                  )}
                </button>
                <button
                  type="button"
                  onClick={() => setPhotoMode('url')}
                  className={cn(
                    'px-3 py-1.5 text-xs font-semibold rounded-md transition-all flex items-center gap-1.5 cursor-pointer',
                    photoMode === 'url'
                      ? 'bg-primary text-white shadow-xs'
                      : 'text-text-muted hover:text-text-primary',
                  )}
                >
                  <Link2 className="size-3.5" />
                  <span>Enlace Web (URL)</span>
                  {Boolean(form.watch('image_url')) && (
                    <span className="px-1.5 py-0.2 rounded-full bg-white/20 text-white text-[10px] font-bold">
                      1
                    </span>
                  )}
                </button>
              </div>
            </div>

            {/* MODO 1: SUBIDA DESDE LA PC / DRAG & DROP / CTRL+V */}
            {photoMode === 'upload' && (
              <div className="space-y-4">
                {/* Input oculto para selección de archivos */}
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  multiple
                  className="hidden"
                  onChange={(e) => {
                    handleFilesSelected(e.target.files);
                    e.target.value = '';
                  }}
                />

                {/* Zona de subida Drag & Drop */}
                <div
                  onDragOver={(e) => {
                    e.preventDefault();
                    setIsDraggingPhotos(true);
                  }}
                  onDragLeave={() => setIsDraggingPhotos(false)}
                  onDrop={(e) => {
                    e.preventDefault();
                    setIsDraggingPhotos(false);
                    handleFilesSelected(e.dataTransfer.files);
                  }}
                  className={cn(
                    'rounded-xl border-2 border-dashed p-7 transition-all text-center cursor-pointer',
                    isDraggingPhotos
                      ? 'border-primary bg-primary/10 scale-[1.01]'
                      : 'border-border/80 bg-surface hover:border-primary/50 hover:bg-surface-subtle/30',
                  )}
                  onClick={() => fileInputRef.current?.click()}
                >
                  <div className="flex flex-col items-center justify-center gap-2">
                    <div className="size-12 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                      <UploadCloud className="size-6" />
                    </div>
                    <div>
                      <p className="text-sm font-semibold text-text-primary">
                        Haz clic aquí para seleccionar fotos o arrástralas a esta zona
                      </p>
                      <p className="text-xs text-text-muted mt-0.5">
                        Formatos JPG, PNG o WebP (hasta 5 MB) • Selección múltiple • Puedes pegar con <kbd className="px-1.5 py-0.5 rounded bg-surface border border-border text-[10px] font-mono font-semibold">Ctrl+V</kbd>
                      </p>
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-2 h-8 text-xs font-semibold gap-1.5"
                      onClick={(e) => {
                        e.stopPropagation();
                        fileInputRef.current?.click();
                      }}
                    >
                      <ImageIcon className="size-3.5" />
                      <span>Explorar archivos de mi computadora</span>
                    </Button>
                  </div>
                </div>

                {/* Cuadrícula de fotos en cola / staged */}
                {stagedImages.length > 0 && (
                  <div className="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-primary animate-pulse" />
                        <p className="text-xs font-bold uppercase tracking-wider text-primary">
                          {stagedImages.length} foto(s) lista(s) para guardar al crear el producto
                        </p>
                      </div>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          stagedImages.forEach((i) => URL.revokeObjectURL(i.previewUrl));
                          setStagedImages([]);
                        }}
                        className="h-7 text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-100/50"
                      >
                        Quitar todas
                      </Button>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                      {stagedImages.map((item, index) => {
                        const isPrimary = index === 0;
                        return (
                          <div
                            key={item.id}
                            className={cn(
                              'group relative rounded-xl border overflow-hidden bg-surface shadow-xs transition-all flex flex-col',
                              isPrimary
                                ? 'border-primary ring-2 ring-primary/20 shadow-sm'
                                : 'border-border hover:border-border-strong',
                            )}
                          >
                            <div className="relative aspect-square w-full bg-slate-50 dark:bg-zinc-900 flex items-center justify-center overflow-hidden">
                              <img
                                src={item.previewUrl}
                                alt={item.name}
                                className="size-full object-contain p-1"
                              />

                              {/* Badge de Portada */}
                              {isPrimary ? (
                                <div className="absolute top-1.5 left-1.5 px-1.5 py-0.5 rounded bg-primary text-white text-[10px] font-bold flex items-center gap-1 shadow-sm">
                                  <Star className="size-3 fill-white" />
                                  <span>Principal</span>
                                </div>
                              ) : (
                                <button
                                  type="button"
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    setPrimaryStagedImage(index);
                                  }}
                                  className="absolute top-1.5 left-1.5 opacity-0 group-hover:opacity-100 transition-opacity px-1.5 py-0.5 rounded bg-black/75 hover:bg-primary text-white text-[10px] font-medium flex items-center gap-1 shadow-sm cursor-pointer"
                                  title="Fijar como foto principal de portada"
                                >
                                  <Star className="size-3" />
                                  <span>Portada</span>
                                </button>
                              )}

                              {/* Botón eliminar */}
                              <button
                                type="button"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  removeStagedImage(item.id);
                                }}
                                className="absolute top-1.5 right-1.5 size-6 rounded-full bg-rose-600/90 hover:bg-rose-700 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-sm cursor-pointer"
                                title="Quitar foto"
                              >
                                <Trash2 className="size-3" />
                              </button>
                            </div>

                            <div className="p-2 text-[11px] bg-surface border-t border-border/60">
                              <p className="truncate font-medium text-text-primary" title={item.name}>
                                {item.name}
                              </p>
                              <span className="text-[10px] text-text-muted">{item.sizeStr}</span>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Galería de fotos existentes en el servidor si ya está creado */}
                {productId !== undefined && (
                  <div className="space-y-2 rounded-xl border border-border bg-surface-subtle/30 p-4">
                    <div className="flex items-center justify-between">
                      <p className="text-xs font-semibold uppercase text-text-primary">
                        Fotos ya guardadas en el producto ({galleryImages.length})
                      </p>
                      <p className="text-xs text-text-muted">
                        Formatos WebP con miniaturas automáticas.
                      </p>
                    </div>
                    <ImageGallery productId={productId} images={galleryImages} canEdit />
                  </div>
                )}
              </div>
            )}

            {/* MODO 2: ENLACE WEB (URL) */}
            {photoMode === 'url' && (
              <div className="space-y-4">
                <div className="rounded-xl border border-border/80 bg-surface shadow-xs p-4 space-y-4">
                  <div className="space-y-1">
                    <Label htmlFor="image_url_input" className="text-xs font-bold text-text-primary">
                      Dirección Web de la Imagen (URL) o Enlace del Producto
                    </Label>
                    <p className="text-xs text-text-muted">
                      Puedes pegar el enlace directo a una foto (.jpg, .png) o el enlace de la página web del fabricante (smart-gsm, GSMArena, MercadoLibre, etc.) y nuestro sistema extraerá la foto por ti.
                    </p>
                  </div>

                  <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                    <Input
                      id="image_url_input"
                      {...form.register('image_url')}
                      placeholder="https://ejemplo.com/foto.jpg o enlace de la página..."
                      className="flex-1 h-9.5 text-xs font-mono"
                      onChange={(e) => {
                        form.setValue('image_url', e.target.value, { shouldDirty: true, shouldValidate: true });
                        setUrlImageError(false);
                      }}
                    />
                    <Button
                      type="button"
                      variant="primary"
                      size="sm"
                      onClick={handleResolveUrl}
                      loading={isResolvingUrl}
                      className="h-9.5 px-3.5 text-xs font-semibold gap-1.5 shrink-0"
                    >
                      <Sparkles className="size-3.5" />
                      <span>Extraer foto del enlace</span>
                    </Button>
                    {form.watch('image_url') ? (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                          form.setValue('image_url', '', { shouldDirty: true, shouldValidate: true });
                          setUrlImageError(false);
                        }}
                        className="h-9.5 px-3 text-xs text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/30 border-rose-200 dark:border-rose-900/50 shrink-0 font-medium"
                      >
                        <Trash2 className="size-3.5" />
                        <span>Quitar</span>
                      </Button>
                    ) : null}
                  </div>

                  {/* Vista Previa o Diagnóstico de Error */}
                  {form.watch('image_url') && (
                    <div className="pt-2">
                      {urlImageError ? (
                        <div className="rounded-xl border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4 text-xs text-amber-900 dark:text-amber-200 space-y-2.5">
                          <div className="flex items-center gap-2 font-bold text-amber-800 dark:text-amber-300">
                            <AlertTriangle className="size-4 shrink-0 text-amber-600" />
                            <span>No se pudo cargar la imagen desde este enlace</span>
                          </div>
                          <p className="text-[11px] leading-relaxed">
                            El enlace ingresado parece ser una página web completa (HTML) y no un archivo de imagen directo (.jpg, .png, .webp).
                          </p>
                          <div className="flex flex-wrap items-center gap-2 pt-1">
                            <Button
                              type="button"
                              size="sm"
                              onClick={handleResolveUrl}
                              loading={isResolvingUrl}
                              className="h-7 text-xs bg-amber-600 hover:bg-amber-700 text-white gap-1.5"
                            >
                              <Sparkles className="size-3.5" />
                              <span>Extraer foto automáticamente de la página</span>
                            </Button>
                          </div>
                          <div className="border-t border-amber-200 dark:border-amber-900/60 pt-2 text-[11px] text-amber-800 dark:text-amber-300">
                            <p className="font-semibold mb-0.5">Cómo copiar la foto manualmente si lo prefieres:</p>
                            <p>1. Ve a la página web donde ves la foto.</p>
                            <p>2. Haz <strong>clic derecho</strong> directamente sobre la foto del celular/producto.</p>
                            <p>3. Selecciona <strong>"Copiar dirección de imagen"</strong> (o "Copy image address").</p>
                            <p>4. Vuelve aquí y pega ese enlace directo.</p>
                          </div>
                        </div>
                      ) : (
                        <div className="flex flex-col sm:flex-row items-center gap-4 p-3 bg-surface-subtle/50 rounded-xl border border-border/70">
                          <div className="size-36 sm:size-44 rounded-lg border border-border bg-slate-50 dark:bg-zinc-900 overflow-hidden flex items-center justify-center shrink-0 p-1 shadow-inner">
                            <img
                              src={form.watch('image_url') || ''}
                              alt="Vista previa URL"
                              className="size-full object-contain"
                              onError={() => setUrlImageError(true)}
                              onLoad={() => setUrlImageError(false)}
                            />
                          </div>
                          <div className="flex-1 min-w-0 space-y-1.5 text-center sm:text-left">
                            <div className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold">
                              <Check className="size-3" />
                              <span>Foto vinculada y lista</span>
                            </div>
                            <p className="text-xs font-semibold text-text-primary">Vista previa del enlace</p>
                            <p className="text-xs text-text-muted break-all font-mono text-[11px] bg-surface p-1.5 rounded border border-border/50">
                              {form.watch('image_url')}
                            </p>
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            )}
          </fieldset>
        </div>

        {/* Pestaña Variantes [F7]: tanto en creación como en edición */}
        <div className={cn('space-y-4', activeTab !== 'variants' && 'hidden')}>
          {visitedTabs.has('variants') && (
            <ProductVariantsTab
              productId={productId}
              pendingVariants={pendingVariants}
              onPendingVariantsChange={setPendingVariants}
              baseSku={form.watch('sku') || ''}
            />
          )}
        </div>

        {/* Pestañas avanzadas exclusivas para producto existente */}
        {productId && (
          <>
            <div className={cn('space-y-4', activeTab !== 'kardex' && 'hidden')}>
              {visitedTabs.has('kardex') && <KardexTab productId={productId} />}
            </div>

            <div className={cn('space-y-4', activeTab !== 'audits' && 'hidden')}>
              {visitedTabs.has('audits') && <AuditsTab productId={productId} />}
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
      <div className="border-t border-border bg-surface-subtle/50 px-4 py-2.5 flex items-center justify-between gap-3 shrink-0 flex-wrap">
        <div className="flex items-center gap-2 text-xs text-text-muted font-mono hidden sm:flex">
          <span className="inline-flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">{productId ? 'F1-F9' : 'F1-F7'}</kbd>
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
          {pendingPrices.length > 0 && (
            <>
              <span>•</span>
              <span className="text-amber-600 dark:text-amber-400 font-semibold">
                {pendingPrices.length} precio(s) modificado(s)
              </span>
            </>
          )}
          {pendingVariants.length > 0 && (
            <>
              <span>•</span>
              <span className="text-indigo-600 dark:text-indigo-400 font-semibold">
                {pendingVariants.length} variante(s)
              </span>
            </>
          )}
          {stagedImages.length > 0 && (
            <>
              <span>•</span>
              <span className="text-emerald-600 dark:text-emerald-400 font-semibold">
                {stagedImages.length} foto(s)
              </span>
            </>
          )}
        </div>

        <div className="flex items-center gap-2 ml-auto">
          {onCancel && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onCancel}
              disabled={isSubmitting || isSyncingPrices}
              className="h-8 text-xs"
            >
              Cancelar
            </Button>
          )}
          <Button
            type="submit"
            size="sm"
            loading={isSubmitting || isSyncingPrices}
            className="gap-1.5 font-semibold h-8 text-xs px-4"
          >
            <Check className="size-3.5" />
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
        'group flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-t-lg transition-colors border-b-2 relative -mb-px whitespace-nowrap',
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
          'text-[10px] font-mono px-1 py-0.2 rounded bg-bg/80 border border-border text-text-muted',
          active && 'border-primary/40 text-primary font-bold bg-primary/5',
        )}
      >
        {shortcut}
      </span>
      {hasError && (
        <span
          className="size-1.5 rounded-full bg-danger absolute top-1.5 right-1.5 ring-2 ring-surface animate-pulse"
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
    <legend className="text-xs font-black uppercase tracking-widest text-primary border-b border-primary/20 pb-1.5 mb-3 flex items-center gap-2">
      <span className="w-1 h-3 rounded-full bg-primary inline-block" />
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
    <div className="space-y-1">
      <Label htmlFor={name} className="flex items-center gap-1 font-bold text-xs text-text-primary">
        {label}
        {required && <span className="text-danger font-black">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-[11px] text-text-secondary leading-tight">{hint}</p>}
      {error && <p className="text-xs text-danger font-semibold">{error}</p>}
    </div>
  );
}

export { Link2, TagIcon, TagsIcon };
