import { useMemo, useState, useEffect } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Info,
  Package,
  Plus,
  Search,
  X,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { useCategories, useProducts } from '@/features/inventory-center/api';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { formatLocalNumber, resolvePosProductPrice } from './posLogic';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';

interface PosCatalogGridProps {
  warehouseId: number | null;
  selectedPriceList: PriceList | null;
  activeRate: { id?: number; name?: string; code?: string; rate: number } | null;
  onSelectProduct: (product: Product) => Promise<boolean> | void;
  onDetailProduct?: (product: Product) => void;
  className?: string;
}

export function PosCatalogGrid({
  warehouseId,
  selectedPriceList,
  activeRate,
  onSelectProduct,
  onDetailProduct,
  className,
}: PosCatalogGridProps) {
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | undefined>(undefined);
  const [onlyAvailable, setOnlyAvailable] = useState(false);
  const [page, setPage] = useState(1);
  const perPage = 12;

  // Debounce búsqueda para respuesta instantánea
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search.trim());
      setPage(1);
    }, 250);
    return () => clearTimeout(timer);
  }, [search]);

  // Reset page when category or stock filter changes
  useEffect(() => {
    setPage(1);
  }, [selectedCategoryId, onlyAvailable, warehouseId]);

  // Categorías
  const { data: categories = [] } = useCategories();

  // Consulta de productos
  const { data: paginatedData, isLoading } = useProducts({
    search: debouncedSearch,
    category_id: selectedCategoryId,
    warehouse_id: warehouseId ?? undefined,
    tracking_type: 'all',
    active_status: 'active',
    stock_status: onlyAvailable ? 'available' : 'all',
    page,
    per_page: perPage,
  });

  const products = useMemo(() => paginatedData?.data ?? [], [paginatedData?.data]);
  const meta = paginatedData?.meta;
  const totalProducts = meta?.total ?? products.length;
  const totalPages = meta?.last_page ?? 1;

  const handleProductClick = async (product: Product) => {
    try {
      const added = await onSelectProduct(product);
      if (added) {
        toast.success(`"${product.name}" agregado al ticket`, {
          duration: 1500,
          position: 'bottom-left',
        });
      }
    } catch {
      // Manejado por addProduct
    }
  };

  return (
    <section
      className={cn(
        'border-border/80 bg-surface flex min-h-0 flex-col overflow-hidden rounded-2xl border shadow-sm',
        className,
      )}
      data-testid="pos-catalog-grid-section"
    >
      {/* ============================================================ */}
      {/* 1. Barra Superior: Buscador + Filtros de Categorías          */}
      {/* ============================================================ */}
      <div className="border-border from-surface to-bg/80 flex flex-col gap-2.5 border-b bg-gradient-to-r p-3 sm:px-4 sm:py-3">
        {/* Fila de Buscador y Switch de Stock */}
        <div className="flex items-center gap-2.5">
          <div className="relative flex-1">
            <Search className="text-primary/70 pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar por nombre, código o marca en catálogo..."
              className="h-10 pl-9 pr-8 text-sm rounded-xl bg-surface border-border/80 focus:border-primary"
            />
            {search && (
              <button
                type="button"
                onClick={() => setSearch('')}
                className="text-text-muted hover:text-text-primary absolute top-1/2 right-2.5 -translate-y-1/2 p-1 rounded-md"
                title="Limpiar búsqueda"
              >
                <X className="size-4" />
              </button>
            )}
          </div>

          {/* Toggle Solo Disponibles */}
          <button
            type="button"
            onClick={() => setOnlyAvailable(!onlyAvailable)}
            className={cn(
              'flex items-center gap-1.5 h-10 px-3 rounded-xl border text-xs font-semibold transition-all shrink-0',
              onlyAvailable
                ? 'bg-emerald-600 text-white border-emerald-600 shadow-xs'
                : 'border-border bg-surface text-text-secondary hover:text-text-primary hover:border-primary/50',
            )}
            title="Mostrar solo productos con stock disponible"
          >
            <CheckCircle2 className="size-4" />
            <span className="hidden sm:inline">Con stock</span>
          </button>
        </div>

        {/* Fila de Chips de Categorías deslizables */}
        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none overscroll-contain">
          <button
            type="button"
            onClick={() => setSelectedCategoryId(undefined)}
            className={cn(
              'px-3 py-1 text-xs font-bold rounded-lg border transition-all shrink-0',
              selectedCategoryId === undefined
                ? 'bg-primary text-white border-primary shadow-xs'
                : 'bg-surface border-border text-text-secondary hover:text-text-primary hover:border-primary/40',
            )}
          >
            Todos ({totalProducts})
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              type="button"
              onClick={() => setSelectedCategoryId(cat.id === selectedCategoryId ? undefined : cat.id)}
              className={cn(
                'px-3 py-1 text-xs font-medium rounded-lg border transition-all shrink-0',
                cat.id === selectedCategoryId
                  ? 'bg-primary text-white border-primary font-bold shadow-xs'
                  : 'bg-surface border-border text-text-secondary hover:text-text-primary hover:border-primary/40',
              )}
            >
              {cat.name}
            </button>
          ))}
        </div>
      </div>

      {/* ============================================================ */}
      {/* 2. Cuadrícula de Tarjetas de Productos                       */}
      {/* ============================================================ */}
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-[#f8fafc] dark:bg-zinc-950/40 p-3 sm:p-4">
        {isLoading ? (
          <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-3 2xl:grid-cols-4 gap-3 sm:gap-4">
            {Array.from({ length: 8 }).map((_, i) => (
              <div
                key={i}
                className="bg-surface rounded-2xl border border-border/60 p-3 animate-pulse flex flex-col gap-2.5 h-64"
              >
                <div className="w-full h-32 bg-slate-200 dark:bg-zinc-800 rounded-xl" />
                <div className="h-4 bg-slate-200 dark:bg-zinc-800 rounded w-3/4" />
                <div className="h-3 bg-slate-200 dark:bg-zinc-800 rounded w-1/2" />
                <div className="h-6 bg-slate-200 dark:bg-zinc-800 rounded mt-auto" />
              </div>
            ))}
          </div>
        ) : products.length === 0 ? (
          <div className="border-border bg-surface text-text-muted flex h-full min-h-[300px] items-center justify-center rounded-2xl border border-dashed p-6 text-center text-sm">
            <div className="max-w-xs">
              <Package className="text-primary/50 mx-auto mb-3 size-10 stroke-[1.5]" />
              <p className="text-text-primary font-bold text-base">No hay productos disponibles</p>
              <p className="mt-1 text-xs text-text-secondary">
                {search || selectedCategoryId !== undefined
                  ? 'No se encontraron resultados para los filtros seleccionados.'
                  : 'No hay productos registrados en este almacén.'}
              </p>
              {(search || selectedCategoryId !== undefined || onlyAvailable) && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setSearch('');
                    setSelectedCategoryId(undefined);
                    setOnlyAvailable(false);
                  }}
                  className="mt-4 gap-1.5 text-xs"
                >
                  <X className="size-3.5" />
                  Limpiar filtros
                </Button>
              )}
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-3 2xl:grid-cols-4 gap-3 sm:gap-4">
            {products.map((product) => {
              const itemPriceUsd = resolvePosProductPrice(product, selectedPriceList);
              const itemPriceVes =
                activeRate && activeRate.rate > 0 ? itemPriceUsd * activeRate.rate : null;

              const rawStock = product.available_stock;
              const stockNum =
                rawStock == null ? 0 : typeof rawStock === 'string' ? parseFloat(rawStock) : rawStock;
              const isOutOfStock = (product.track_stock ?? true) && stockNum <= 0;

              const imageUrl =
                product.primary_image_url ||
                product.images?.find((img) => img.is_primary)?.url ||
                product.images?.[0]?.url ||
                product.images?.[0]?.thumb_url ||
                product.image_url;

              return (
                <div
                  key={product.id}
                  onClick={() => void handleProductClick(product)}
                  className={cn(
                    'group bg-surface rounded-2xl border border-border/80 hover:border-primary hover:shadow-md transition-all duration-150 flex flex-col overflow-hidden cursor-pointer select-none active:scale-[0.98]',
                    isOutOfStock && 'opacity-85',
                  )}
                  title={`Clic para agregar ${product.name} al ticket`}
                >
                  {/* Contenedor de Imagen de Producto */}
                  <div className="relative w-full h-32 sm:h-36 bg-slate-50 dark:bg-zinc-900/60 overflow-hidden flex items-center justify-center border-b border-border/40 p-2">
                    {imageUrl ? (
                      <img
                        src={imageUrl}
                        alt={product.name}
                        className="size-full object-contain group-hover:scale-105 transition-transform duration-200"
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

                    {/* Placeholder SVG */}
                    <div
                      className={cn(
                        'fallback-placeholder size-full flex flex-col items-center justify-center gap-1 text-text-muted',
                        imageUrl ? 'hidden' : 'flex',
                      )}
                    >
                      <Package className="size-9 stroke-[1.25] text-text-muted/50" />
                      <span className="text-[9px] font-bold tracking-wider uppercase text-text-muted/60">
                        Sin foto
                      </span>
                    </div>

                    {/* Badge de Stock en esquina superior izquierda */}
                    <div className="absolute top-2 left-2 flex flex-col gap-1">
                      {isOutOfStock ? (
                        <span className="bg-rose-600/95 backdrop-blur-xs text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-xs flex items-center gap-1">
                          <AlertTriangle className="size-2.5" />
                          Agotado
                        </span>
                      ) : (
                        <span className="bg-emerald-600/95 backdrop-blur-xs text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-xs flex items-center gap-1">
                          <CheckCircle2 className="size-2.5" />
                          {Number.isFinite(stockNum) ? stockNum.toFixed(0) : '0'}{' '}
                          {product.unit_of_measure && product.unit_of_measure.toLowerCase() !== 'unit'
                            ? product.unit_of_measure
                            : 'disp.'}
                        </span>
                      )}
                    </div>

                    {/* Badge de Marca en esquina superior derecha */}
                    {product.brand?.name && (
                      <span
                        className="absolute top-2 right-2 bg-primary/10 backdrop-blur-xs text-primary border border-primary/25 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full shadow-2xs truncate max-w-[110px]"
                        title={product.brand.name}
                      >
                        {product.brand.name}
                      </span>
                    )}

                    {/* Botón Info Multi-Sucursal */}
                    {onDetailProduct && (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          onDetailProduct(product);
                        }}
                        className="absolute bottom-2 right-2 p-1.5 rounded-lg bg-surface/90 text-text-muted hover:text-primary hover:bg-surface border border-border/80 shadow-xs opacity-90 sm:opacity-0 sm:group-hover:opacity-100 transition-all"
                        title="Ver existencias en otras sucursales"
                      >
                        <Info className="size-3.5" />
                      </button>
                    )}
                  </div>

                  {/* Cuerpo de la Tarjeta */}
                  <div className="p-3 flex flex-col flex-1 justify-between gap-2.5">
                    <div>
                      {/* Código o SKU */}
                      <p className="text-[10px] font-mono text-text-muted truncate">
                        {product.sku || product.barcode || 'Sin código'}
                      </p>

                      {/* Nombre del Producto */}
                      <h4
                        className="font-bold text-xs sm:text-sm text-text-primary line-clamp-2 leading-snug group-hover:text-primary transition-colors mt-0.5"
                        title={product.name}
                      >
                        {product.name}
                      </h4>
                    </div>

                    {/* Precios y Botón Agregar */}
                    <div className="mt-auto pt-2 border-t border-border/60">
                      <div className="flex items-baseline justify-between gap-1">
                        <div>
                          <span className="text-base sm:text-lg font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                            {formatMoney(itemPriceUsd)}
                          </span>
                          {itemPriceVes !== null && (
                            <span className="text-[10px] text-text-muted font-mono block">
                              Bs. {formatLocalNumber(itemPriceVes)}
                            </span>
                          )}
                        </div>

                        {/* Botón táctil Agregar */}
                        <Button
                          size="sm"
                          variant="secondary"
                          className="h-8 px-2.5 text-xs font-bold gap-1 bg-primary/10 text-primary hover:bg-primary hover:text-white border border-primary/20 shrink-0 shadow-2xs group-hover:bg-primary group-hover:text-white transition-all"
                        >
                          <Plus className="size-3.5" />
                          <span className="hidden sm:inline">Agregar</span>
                        </Button>
                      </div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* ============================================================ */}
      {/* 3. Barra Inferior: Paginación y Totales                      */}
      {/* ============================================================ */}
      <div className="border-border bg-surface flex items-center justify-between border-t px-4 py-2 text-xs">
        <span className="text-text-muted font-medium">
          Mostrando <strong className="text-text-primary">{products.length}</strong> de{' '}
          <strong className="text-text-primary">{totalProducts}</strong> productos
        </span>

        {totalPages > 1 && (
          <div className="flex items-center gap-1.5">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="h-8 px-2 text-xs gap-1"
            >
              <ChevronLeft className="size-3.5" />
              <span className="hidden sm:inline">Anterior</span>
            </Button>
            <span className="px-2 font-mono font-bold text-text-primary">
              {page} / {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              className="h-8 px-2 text-xs gap-1"
            >
              <span className="hidden sm:inline">Siguiente</span>
              <ChevronRight className="size-3.5" />
            </Button>
          </div>
        )}
      </div>
    </section>
  );
}
