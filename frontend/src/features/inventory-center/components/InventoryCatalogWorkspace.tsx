/**
 * InventoryCatalogWorkspace.tsx — Vista Catálogo Visual para el Centro de Inventario.
 *
 * Proporciona una experiencia tipo vitrina e-commerce / catálogo digital (estilo Treinta):
 *  - Cuadrícula responsiva de tarjetas con foto destacada, nombre y precios de venta (USD + VES).
 *  - Badges de disponibilidad y stock en tiempo real sobre la imagen.
 *  - Barra superior con buscador instantáneo, filtro rápido por chips de categorías,
 *    selector de almacén, filtro de rastreo y filtro de disponibilidad de stock.
 *  - Modal interactivo de Gestión Rápida de Inventario al hacer clic en cualquier producto:
 *      * Ficha visual con fotos ampliadas.
 *      * Comparativa de tarifas de precios en todas las listas con equivalencia en Bs.
 *      * Desglose de existencias por almacén (disponible, físico/reservado, dañado).
 *      * Botón de Edición Rápida directa del producto (EditProductDialog).
 *      * Acceso a la ficha completa con Kardex e historial (/inventory/$productId).
 */
import { useMemo, useState, type ChangeEvent } from 'react';
import { Link } from '@tanstack/react-router';
import {
  AlertTriangle,
  Boxes,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  DollarSign,
  Edit,
  ExternalLink,
  Package,
  Plus,
  Search,
  X,
} from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import {
  useCategories,
  useProductStockByWarehouse,
} from '@/features/inventory-center/api';
import { EditProductDialog } from '@/features/inventory-center/dialogs/EditProductDialog';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';

export interface InventoryCatalogWorkspaceProps {
  products: Product[];
  totalProducts: number;
  totalPages: number;
  currentPage: number;
  isLoading: boolean;
  search: string;
  onSearchChange: (search: string) => void;
  categoryId?: number;
  onCategoryChange?: (categoryId?: number) => void;
  warehouseId?: number;
  onWarehouseChange: (warehouseId?: number) => void;
  tracking: 'all' | 'quantity' | 'serialized';
  onTrackingChange: (tracking: 'all' | 'quantity' | 'serialized') => void;
  stock: 'all' | 'available' | 'low' | 'critical' | 'out' | 'overstock';
  onStockChange: (stock: 'all' | 'available' | 'low' | 'critical' | 'out' | 'overstock') => void;
  status: 'all' | 'active' | 'inactive';
  onStatusChange: (status: 'all' | 'active' | 'inactive') => void;
  onPageChange: (page: number) => void;
  warehouses: { id: number; code: string; name: string }[];
  priceLists: PriceList[];
  activeRate: { id?: number; name?: string; code?: string; exchange_rate_type_code?: string | null; rate: number } | null;
  onNewProduct: () => void;
}

interface ComputedPrice {
  listId: number;
  listName: string;
  isDefault: boolean;
  price: number;
  currency: string;
}

/**
 * Calcula los precios de venta efectivos para un producto en base a las listas de precios
 * activas y manuales/automáticas.
 */
function computeProductPrices(product: Product, priceLists: PriceList[]): ComputedPrice[] {
  const manualPrices = (product.prices ?? []).filter((p) => p.is_active !== false);
  const manualById = new Map(manualPrices.map((p) => [p.price_list_id, p]));

  const effectivePrice = (list: PriceList, seen = new Set<number>()): number | null => {
    if (seen.has(list.id)) return product.base_price != null ? Number(product.base_price) : null;
    seen.add(list.id);

    const manual = manualById.get(list.id);
    if (manual) return Number(manual.price);

    let base: number | null = product.base_price != null ? Number(product.base_price) : null;
    if (list.base_price_list_id) {
      const baseList = priceLists.find((l) => l.id === list.base_price_list_id);
      if (baseList) base = effectivePrice(baseList, seen);
    }

    if (base == null) return null;
    const markup = Number(list.markup_percentage ?? 0);
    return Number((base * (1 + markup / 100)).toFixed(2));
  };

  const results: ComputedPrice[] = [];

  // 1. Agregar listas configuradas en el sistema
  for (const list of priceLists.filter((l) => l.is_active)) {
    const calc = effectivePrice(list);
    if (calc != null) {
      results.push({
        listId: list.id,
        listName: list.name,
        isDefault: Boolean(list.is_default),
        price: calc,
        currency: 'USD',
      });
    }
  }

  // Si no hubo listas calculadas pero tiene base_price
  if (results.length === 0 && product.base_price != null) {
    results.push({
      listId: 0,
      listName: 'Precio Base',
      isDefault: true,
      price: Number(product.base_price),
      currency: product.sale_currency || 'USD',
    });
  }

  return results;
}

export function InventoryCatalogWorkspace({
  products,
  totalProducts,
  totalPages,
  currentPage,
  isLoading,
  search,
  onSearchChange,
  categoryId,
  onCategoryChange,
  warehouseId,
  onWarehouseChange,
  tracking,
  onTrackingChange,
  stock,
  onStockChange,
  status,
  onStatusChange,
  onPageChange,
  warehouses,
  priceLists,
  activeRate,
  onNewProduct,
}: InventoryCatalogWorkspaceProps) {
  const [searchInput, setSearchInput] = useState(search);
  const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);

  // Categorías para chips horizontales tipo catálogo
  const { data: categories = [] } = useCategories();

  const handleSearchSubmit = (val: string) => {
    onSearchChange(val);
  };

  return (
    <div className="flex flex-col gap-4">
      {/* 1. Barra de Control Superior (Estilo Catálogo Digital Treinta) */}
      <Card className="border-border shadow-2xs">
        <CardContent className="p-3 sm:p-4 flex flex-col gap-3">
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            {/* Buscador de productos */}
            <div className="relative flex-1">
              <Search
                className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                aria-hidden="true"
              />
              <Input
                value={searchInput}
                onChange={(e: ChangeEvent<HTMLInputElement>) => {
                  setSearchInput(e.target.value);
                  handleSearchSubmit(e.target.value);
                }}
                placeholder="Buscar por nombre, SKU o código de barras..."
                className={cn('pl-9 pr-8 text-sm h-10', searchInput && 'pr-8')}
                data-testid="catalog-search-input"
              />
              {searchInput ? (
                <button
                  type="button"
                  onClick={() => {
                    setSearchInput('');
                    handleSearchSubmit('');
                  }}
                  className="text-text-muted hover:text-text-primary absolute top-1/2 right-2.5 -translate-y-1/2 rounded p-0.5 transition-colors"
                  aria-label="Limpiar búsqueda"
                >
                  <X className="size-4" />
                </button>
              ) : null}
            </div>

            {/* Filtros compactos: Almacén, Tipo y Stock */}
            <div className="flex flex-wrap items-center gap-2">
              <select
                className="h-10 rounded-md border border-border bg-surface px-3 text-xs sm:text-sm font-medium text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary shadow-2xs"
                value={warehouseId ? String(warehouseId) : ''}
                onChange={(e) => onWarehouseChange(e.target.value ? Number(e.target.value) : undefined)}
                data-testid="catalog-warehouse-filter"
                title="Filtrar por almacén"
              >
                <option value="">🏢 Todos los almacenes</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={String(w.id)}>
                    {w.name || w.code}
                  </option>
                ))}
              </select>

              <select
                className="h-10 rounded-md border border-border bg-surface px-3 text-xs sm:text-sm font-medium text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary shadow-2xs"
                value={tracking}
                onChange={(e) => onTrackingChange(e.target.value as 'all' | 'quantity' | 'serialized')}
                data-testid="catalog-tracking-filter"
                title="Filtrar por tipo de rastreo"
              >
                <option value="all">🔍 Todos los tipos</option>
                <option value="quantity">Por cantidad</option>
                <option value="serialized">Serializados (IMEI)</option>
              </select>

              <select
                className="h-10 rounded-md border border-border bg-surface px-3 text-xs sm:text-sm font-medium text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary shadow-2xs"
                value={stock}
                onChange={(e) => onStockChange(e.target.value as any)}
                data-testid="catalog-stock-filter"
                title="Filtrar por disponibilidad"
              >
                <option value="all">📦 Todo el stock</option>
                <option value="available">✅ Con existencia</option>
                <option value="low">⚠️ Stock bajo</option>
                <option value="out">❌ Agotados</option>
              </select>

              <select
                className="h-10 rounded-md border border-border bg-surface px-3 text-xs sm:text-sm font-medium text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary shadow-2xs"
                value={status}
                onChange={(e) => onStatusChange(e.target.value as any)}
                data-testid="catalog-status-filter"
                title="Filtrar por estado activo/inactivo"
              >
                <option value="all">🔘 Todos</option>
                <option value="active">Activos</option>
                <option value="inactive">Inactivos</option>
              </select>

              <Can I={PERMISSIONS.PRODUCTS_CREATE}>
                <Button
                  onClick={onNewProduct}
                  size="sm"
                  className="h-10 text-xs sm:text-sm font-semibold gap-1.5"
                  data-testid="catalog-new-product-btn"
                >
                  <Plus className="size-4" />
                  <span>Nuevo producto</span>
                </Button>
              </Can>
            </div>
          </div>

          {/* Chips horizontales de categorías estilo Tienda / Catálogo */}
          {categories.length > 0 && onCategoryChange && (
            <div className="flex items-center gap-1.5 overflow-x-auto pb-1 pt-1 scrollbar-none no-scrollbar">
              <button
                type="button"
                onClick={() => onCategoryChange(undefined)}
                className={cn(
                  'px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-all border',
                  !categoryId
                    ? 'bg-primary text-primary-foreground border-primary shadow-xs'
                    : 'bg-surface text-text-muted border-border hover:border-text-secondary hover:text-text-primary',
                )}
              >
                Todas las categorías ({totalProducts})
              </button>
              {categories.map((cat) => {
                const isSelected = categoryId === cat.id;
                return (
                  <button
                    key={cat.id}
                    type="button"
                    onClick={() => onCategoryChange(isSelected ? undefined : cat.id)}
                    className={cn(
                      'px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-all border',
                      isSelected
                        ? 'bg-primary text-primary-foreground border-primary shadow-xs'
                        : 'bg-surface text-text-muted border-border hover:border-text-secondary hover:text-text-primary',
                    )}
                  >
                    {cat.name}
                  </button>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* 2. Cuadrícula de Productos (Grid Catálogo) */}
      {isLoading ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4">
          {Array.from({ length: 12 }).map((_, idx) => (
            <div
              key={idx}
              className="bg-surface rounded-xl border border-border p-3 flex flex-col gap-2 animate-pulse"
            >
              <div className="w-full aspect-square bg-surface-subtle rounded-lg" />
              <div className="h-4 bg-surface-subtle rounded w-3/4" />
              <div className="h-3 bg-surface-subtle rounded w-1/2" />
              <div className="h-5 bg-surface-subtle rounded w-2/3 mt-2" />
            </div>
          ))}
        </div>
      ) : products.length === 0 ? (
        <Card className="border-border shadow-2xs">
          <CardContent className="p-8">
            <EmptyState
              icon={<Package className="size-10 text-text-muted" />}
              title="No se encontraron productos"
              description="Intenta ajustando el término de búsqueda o cambiando los filtros de categoría y stock."
            />
          </CardContent>
        </Card>
      ) : (
        <div
          className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4"
          data-testid="inventory-catalog-grid"
        >
          {products.map((product) => {
            const prices = computeProductPrices(product, priceLists);
            const defaultPrice = prices.find((p) => p.isDefault) ?? prices[0];
            const priceVal = defaultPrice?.price ?? (product.base_price != null ? Number(product.base_price) : 0);
            const priceVes = activeRate && priceVal ? priceVal * activeRate.rate : null;

            // Stock numérico
            const rawStock = product.available_stock;
            const stockNum = rawStock == null ? 0 : typeof rawStock === 'string' ? parseFloat(rawStock) : rawStock;
            const isOutOfStock = stockNum <= 0;

            // Imagen principal o fallback
            const imageUrl =
              product.primary_image_url ||
              product.images?.[0]?.url ||
              product.images?.[0]?.thumb_url ||
              product.image_url;

            const categoryName = product.categories?.[0]?.name;

            return (
              <div
                key={product.id}
                onClick={() => setSelectedProduct(product)}
                className="group relative bg-surface border border-border/80 hover:border-primary/50 rounded-xl overflow-hidden shadow-2xs hover:shadow-md transition-all duration-200 flex flex-col cursor-pointer"
                data-testid={`catalog-card-${product.id}`}
              >
                {/* Contenedor de Imagen 1:1 Cuadrado */}
                <div className="relative w-full aspect-square bg-slate-50 dark:bg-zinc-900/60 overflow-hidden flex items-center justify-center border-b border-border/40">
                  {imageUrl ? (
                    <img
                      src={imageUrl}
                      alt={product.name}
                      className="size-full object-contain p-2 group-hover:scale-105 transition-transform duration-300"
                      loading="lazy"
                      onError={(e) => {
                        e.currentTarget.style.display = 'none';
                        const parent = e.currentTarget.parentElement;
                        if (parent) {
                          const placeholder = parent.querySelector('.fallback-placeholder');
                          if (placeholder) (placeholder as HTMLElement).style.display = 'flex';
                        }
                      }}
                    />
                  ) : null}

                  {/* Fallback SVG elegante cuando no hay imagen o falla */}
                  <div
                    className={cn(
                      'fallback-placeholder size-full flex flex-col items-center justify-center gap-1.5 text-text-muted',
                      imageUrl ? 'hidden' : 'flex',
                    )}
                  >
                    <Package className="size-10 stroke-[1.25] text-text-muted/60" />
                    <span className="text-[10px] font-medium tracking-wide uppercase text-text-muted/60">
                      Sin foto
                    </span>
                  </div>

                  {/* Badge de Stock en esquina superior izquierda */}
                  <div className="absolute top-2 left-2 flex flex-col gap-1">
                    {isOutOfStock ? (
                      <span className="bg-rose-600/90 backdrop-blur-xs text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-xs flex items-center gap-1">
                        <AlertTriangle className="size-2.5" />
                        Agotado
                      </span>
                    ) : (
                      <span className="bg-emerald-600/90 backdrop-blur-xs text-white text-[10px] font-semibold px-2 py-0.5 rounded-full shadow-xs flex items-center gap-1">
                        <CheckCircle2 className="size-2.5" />
                        {Number.isFinite(stockNum) ? stockNum.toFixed(0) : '0'} disp.
                      </span>
                    )}
                  </div>

                  {/* Badge de Categoría en esquina superior derecha */}
                  {categoryName && (
                    <span
                      className="absolute top-2 right-2 bg-surface/90 backdrop-blur-xs text-text-secondary text-[10px] font-medium px-2 py-0.5 rounded-full border border-border/60 shadow-2xs truncate max-w-[90px]"
                      title={categoryName}
                    >
                      {categoryName}
                    </span>
                  )}

                  {/* Botón flotante de Edición Rápida (visible al hover o touch) */}
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation();
                      setEditingProduct(product);
                    }}
                    title="Editar producto"
                    className="absolute bottom-2 right-2 p-1.5 rounded-lg bg-surface/95 text-text-primary border border-border/80 hover:bg-primary hover:text-white shadow-sm opacity-90 sm:opacity-0 sm:group-hover:opacity-100 transition-all duration-150"
                    data-testid={`catalog-edit-btn-${product.id}`}
                  >
                    <Edit className="size-3.5" />
                  </button>
                </div>

                {/* Contenido de la Tarjeta (Nombre + Precios de Venta) */}
                <div className="p-3 flex flex-col flex-1 justify-between gap-2">
                  <div className="flex flex-col gap-1">
                    {product.sku && (
                      <span className="text-[10px] font-mono text-text-muted truncate">
                        SKU: {product.sku}
                      </span>
                    )}
                    <h3
                      className="font-semibold text-xs sm:text-sm text-text-primary line-clamp-2 leading-tight group-hover:text-primary transition-colors"
                      title={product.name}
                    >
                      {product.name}
                    </h3>
                  </div>

                  {/* Precios de Venta (Destacados estilo Treinta) */}
                  <div className="pt-2 mt-auto border-t border-border/40 flex flex-col">
                    <div className="flex items-baseline justify-between gap-1">
                      <span className="text-base sm:text-lg font-bold text-text-primary tracking-tight tabular-nums">
                        {formatMoney(priceVal)}
                      </span>
                      {prices.length > 1 && (
                        <span className="text-[10px] text-primary font-medium bg-primary/10 px-1.5 py-0.5 rounded">
                          +{prices.length - 1} tarifas
                        </span>
                      )}
                    </div>

                    {priceVes != null && (
                      <span className="text-[11px] font-semibold text-text-muted tabular-nums">
                        Bs{' '}
                        {priceVes.toLocaleString('es-VE', {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                        })}
                      </span>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* 3. Paginación Inferior */}
      {totalProducts > 0 && totalPages > 1 && (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-2 pt-2 text-sm text-text-muted">
          <p className="text-xs sm:text-sm">
            Mostrando <strong className="text-text-primary">{products.length}</strong> de{' '}
            <strong className="text-text-primary">{totalProducts}</strong> productos
          </p>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={currentPage <= 1}
              onClick={() => onPageChange(Math.max(1, currentPage - 1))}
              className="gap-1 text-xs"
            >
              <ChevronLeft className="size-3.5" />
              Anterior
            </Button>
            <span className="text-xs px-2 font-medium">
              Página {currentPage} de {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={currentPage >= totalPages}
              onClick={() => onPageChange(currentPage + 1)}
              className="gap-1 text-xs"
            >
              Siguiente
              <ChevronRight className="size-3.5" />
            </Button>
          </div>
        </div>
      )}

      {/* 4. Modal de Detalle y Gestión Rápida del Producto */}
      {selectedProduct && (
        <ProductCatalogDetailModal
          product={selectedProduct}
          priceLists={priceLists}
          activeRate={activeRate}
          open={Boolean(selectedProduct)}
          onOpenChange={(open) => {
            if (!open) setSelectedProduct(null);
          }}
          onEdit={() => {
            const p = selectedProduct;
            setSelectedProduct(null);
            setEditingProduct(p);
          }}
        />
      )}

      {/* 5. Dialog para Editar el Producto */}
      {editingProduct && (
        <EditProductDialog
          product={editingProduct}
          open={Boolean(editingProduct)}
          onOpenChange={(open) => {
            if (!open) setEditingProduct(null);
          }}
        />
      )}
    </div>
  );
}

/**
 * Modal de Inspección y Gestión de Inventario para la vista Catálogo.
 * Permite al comerciante ver fotos, precios en todas las listas, stock en todos los almacenes,
 * y saltar a editar el producto o ver su ficha de kardex completa.
 */
interface ProductCatalogDetailModalProps {
  product: Product;
  priceLists: PriceList[];
  activeRate: { id?: number; name?: string; code?: string; exchange_rate_type_code?: string | null; rate: number } | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onEdit: () => void;
}

function ProductCatalogDetailModal({
  product,
  priceLists,
  activeRate,
  open,
  onOpenChange,
  onEdit,
}: ProductCatalogDetailModalProps) {
  const prices = useMemo(() => computeProductPrices(product, priceLists), [product, priceLists]);
  const { data: stockByWarehouse = [], isLoading: isLoadingStock } = useProductStockByWarehouse(product.id);

  // Galería de imágenes si existen
  const allImages = useMemo(() => {
    const list: string[] = [];
    if (product.primary_image_url) list.push(product.primary_image_url);
    if (product.images) {
      for (const img of product.images) {
        if (img.url && !list.includes(img.url)) list.push(img.url);
      }
    }
    if (product.image_url && !list.includes(product.image_url)) list.push(product.image_url);
    return list;
  }, [product]);

  const [activeImageIndex, setActiveImageIndex] = useState(0);
  const currentImage = allImages[activeImageIndex] || null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto p-0">
        <DialogHeader className="p-5 pb-0">
          <div className="flex items-start justify-between gap-3">
            <div>
              <span className="text-xs font-mono text-text-muted">
                SKU: {product.sku || 'Sin SKU'} • {product.barcode ? `Código: ${product.barcode}` : 'Sin código de barras'}
              </span>
              <DialogTitle className="text-lg sm:text-xl font-bold text-text-primary mt-0.5">
                {product.name}
              </DialogTitle>
              <DialogDescription className="text-xs text-text-muted mt-0.5">
                Consulta y gestión de tarifas de venta y existencias por almacén.
              </DialogDescription>
            </div>
            <Badge variant={product.is_active ? 'success' : 'default'}>
              {product.is_active ? 'Activo' : 'Inactivo'}
            </Badge>
          </div>

          {/* Clasificación */}
          <div className="flex flex-wrap items-center gap-2 pt-2">
            {product.brand && (
              <Badge variant="outline" className="text-xs font-medium">
                Marca: {product.brand.name}
              </Badge>
            )}
            {product.categories?.map((cat) => (
              <Badge key={cat.id} variant="default" className="text-xs font-medium">
                {cat.name}
              </Badge>
            ))}
            <Badge variant="outline" className="text-xs">
              Unidad: {product.unit_of_measure || 'Unidad'}
            </Badge>
          </div>
        </DialogHeader>

        <div className="p-5 sm:p-6 pt-3 flex flex-col gap-5">
          {/* Cuerpo: Imagen + Resumen de Precios */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {/* Foto del producto con selector de miniaturas */}
            <div className="flex flex-col gap-2">
              <div className="w-full aspect-square bg-slate-50 dark:bg-zinc-900 rounded-xl border border-border overflow-hidden flex items-center justify-center p-3 relative">
                {currentImage ? (
                  <img
                    src={currentImage}
                    alt={product.name}
                    className="size-full object-contain"
                  />
                ) : (
                  <div className="flex flex-col items-center justify-center gap-2 text-text-muted">
                    <Package className="size-16 stroke-[1.25] text-text-muted/50" />
                    <span className="text-xs font-medium text-text-muted/60">Sin imagen registrada</span>
                  </div>
                )}
              </div>

              {/* Miniaturas de la galería */}
              {allImages.length > 1 && (
                <div className="flex items-center gap-2 overflow-x-auto pb-1">
                  {allImages.map((img, idx) => (
                    <button
                      key={idx}
                      type="button"
                      onClick={() => setActiveImageIndex(idx)}
                      className={cn(
                        'size-12 rounded-lg border overflow-hidden p-0.5 bg-surface transition-all flex-shrink-0',
                        activeImageIndex === idx
                          ? 'border-primary ring-2 ring-primary/20'
                          : 'border-border hover:border-text-secondary',
                      )}
                    >
                      <img src={img} alt={`Thumb ${idx}`} className="size-full object-cover rounded-md" />
                    </button>
                  ))}
                </div>
              )}
            </div>

            {/* Listas de Precios de Venta */}
            <div className="flex flex-col gap-3">
              <h4 className="text-xs font-bold uppercase tracking-wider text-text-secondary flex items-center gap-1.5">
                <DollarSign className="size-4 text-primary" />
                Precios de Venta
              </h4>

              <div className="flex flex-col gap-2 bg-surface-subtle/50 rounded-xl border border-border p-3">
                {prices.map((p) => {
                  const pVes = activeRate ? p.price * activeRate.rate : null;
                  return (
                    <div
                      key={p.listId}
                      className={cn(
                        'flex items-center justify-between p-2 rounded-lg border',
                        p.isDefault
                          ? 'bg-primary/5 border-primary/30'
                          : 'bg-surface border-border/60',
                      )}
                    >
                      <div className="flex flex-col">
                        <span className="text-xs font-semibold text-text-primary flex items-center gap-1">
                          {p.listName}
                          {p.isDefault && (
                            <span className="text-[10px] text-primary font-bold">(Predeterminada)</span>
                          )}
                        </span>
                        {pVes != null && (
                          <span className="text-[11px] font-medium text-text-muted tabular-nums">
                            Bs{' '}
                            {pVes.toLocaleString('es-VE', {
                              minimumFractionDigits: 2,
                              maximumFractionDigits: 2,
                            })}
                          </span>
                        )}
                      </div>
                      <span className="text-sm font-bold text-text-primary tabular-nums">
                        {formatMoney(p.price)}
                      </span>
                    </div>
                  );
                })}
              </div>

              {/* Tasa activa */}
              {activeRate && (
                <div className="text-[11px] text-text-muted bg-surface rounded-lg border border-border/60 px-3 py-1.5 flex items-center justify-between">
                  <span>Tasa de cambio:</span>
                  <span className="font-semibold text-text-primary">
                    1 USD = {activeRate.rate.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES
                  </span>
                </div>
              )}
            </div>
          </div>

          {/* Existencias por Almacén */}
          <div className="flex flex-col gap-2">
            <h4 className="text-xs font-bold uppercase tracking-wider text-text-secondary flex items-center gap-1.5">
              <Boxes className="size-4 text-primary" />
              Existencias por Almacén
            </h4>

            {isLoadingStock ? (
              <div className="p-4 text-center text-xs text-text-muted">Consultando existencias...</div>
            ) : stockByWarehouse.length === 0 ? (
              <div className="p-3 bg-surface-subtle/50 rounded-xl border border-border text-xs text-text-muted text-center">
                Este producto no posee existencias registradas en ningún almacén.
              </div>
            ) : (
              <div className="overflow-x-auto rounded-xl border border-border">
                <table className="w-full text-xs text-left">
                  <thead className="bg-surface-subtle/80 border-b border-border text-text-secondary font-semibold">
                    <tr>
                      <th className="px-3 py-2">Almacén</th>
                      <th className="px-3 py-2 text-right">Disponible</th>
                      <th className="px-3 py-2 text-right">Reservado</th>
                      <th className="px-3 py-2 text-right">Dañado</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {stockByWarehouse.map((sw) => {
                      const avail = typeof sw.available === 'string' ? parseFloat(sw.available) : sw.available;
                      return (
                        <tr key={sw.warehouse_id} className="hover:bg-surface-subtle/30">
                          <td className="px-3 py-2 font-medium text-text-primary">
                            {sw.warehouse_name || sw.warehouse_code}
                          </td>
                          <td className="px-3 py-2 text-right font-bold text-emerald-600 dark:text-emerald-400">
                            {Number.isFinite(avail) ? avail : 0}
                          </td>
                          <td className="px-3 py-2 text-right text-text-muted">{sw.reserved ?? 0}</td>
                          <td className="px-3 py-2 text-right text-text-muted">{sw.damaged ?? 0}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>

        {/* Footer con Acciones de Manejo de Inventario */}
        <DialogFooter className="border-t border-border p-4 bg-surface-subtle/30 flex items-center justify-between sm:justify-between w-full">
          <Link
            to="/inventory/$productId"
            params={{ productId: String(product.id) }}
            className="text-xs text-primary font-medium hover:underline inline-flex items-center gap-1"
          >
            <ExternalLink className="size-3.5" />
            Ficha completa / Kardex
          </Link>

          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cerrar
            </Button>
            <Can I={PERMISSIONS.PRODUCTS_UPDATE}>
              <Button size="sm" onClick={onEdit} className="gap-1.5">
                <Edit className="size-3.5" />
                Editar producto
              </Button>
            </Can>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
