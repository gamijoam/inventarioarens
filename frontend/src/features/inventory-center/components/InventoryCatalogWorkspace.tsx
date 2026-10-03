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
import { useEffect, useMemo, useRef, useState, type ChangeEvent } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import {
  AlertTriangle,
  Boxes,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  DollarSign,
  Edit,
  ExternalLink,
  Folder,
  Package,
  Plus,
  Save,
  Search,
  Send,
  ShoppingCart,
  Sliders,
  Sparkles,
  Star,
  Trash2,
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
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Spinner } from '@/components/ui/Spinner';
import { pendingItemKey, usePendingPosCart } from '@/features/pos/pendingPosCart';
import { loadPinnedCategories, savePinnedCategories } from '@/features/inventory-center/pinnedCategories';
import { useUiPreferences, useUpdateUiPreferences } from '@/features/company-settings/api';
import { useSessionStore } from '@/stores/session';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import {
  useBrands,
  useCategories,
  useProductStockByWarehouse,
  useUpdateProduct,
  useWarrantyPolicies,
} from '@/features/inventory-center/api';
import { putOne } from '@/api/client';
import { productKeys } from '@/features/inventory-center/queries';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { EditProductDialog } from '@/features/inventory-center/dialogs/EditProductDialog';
import {
  STANDARD_UNITS_OF_MEASURE,
  type PriceList,
  type Product,
} from '@/features/inventory-center/schemas';
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
  onNewProduct?: () => void;
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

  // Categorías y selector inteligente con buscador (Opción 1)
  const { data: categories = [] } = useCategories();
  const [categorySearch, setCategorySearch] = useState('');
  const [categoryDropdownOpen, setCategoryDropdownOpen] = useState(false);
  const categoryDropdownRef = useRef<HTMLDivElement>(null);

  // Mini carrito para enviar productos al POS.
  const navigate = useNavigate();
  const pendingItems = usePendingPosCart((state) => state.items);
  const addPendingItem = usePendingPosCart((state) => state.add);
  const removePendingItem = usePendingPosCart((state) => state.remove);
  const clearPendingCart = usePendingPosCart((state) => state.clear);
  const sendPendingToPos = usePendingPosCart((state) => state.sendToPos);
  const [pendingCartOpen, setPendingCartOpen] = useState(false);
  const pendingCount = pendingItems.reduce((sum, item) => sum + item.quantity, 0);

  // Categorias fijadas (filtros rapidos a primera vista). Fuente de verdad en
  // ui_preferences (server) para que persistan entre dispositivos y sesiones;
  // localStorage solo como cache instantaneo.
  const tenantId = useSessionStore((state) => state.tenant?.id);
  const { data: uiPreferences } = useUiPreferences();
  const updateUiPreferences = useUpdateUiPreferences();
  const [pinnedIds, setPinnedIds] = useState<number[]>(() => loadPinnedCategories(tenantId));
  const [pinnedDialogOpen, setPinnedDialogOpen] = useState(false);

  useEffect(() => {
    const fromServer = uiPreferences?.pinned_inventory_categories;
    if (Array.isArray(fromServer)) {
      setPinnedIds(fromServer);
      savePinnedCategories(tenantId, fromServer);
    } else {
      setPinnedIds(loadPinnedCategories(tenantId));
    }
  }, [uiPreferences, tenantId]);

  const pinnedCategories = categories.filter((cat) => pinnedIds.includes(cat.id));

  function togglePinnedCategory(id: number): void {
    const next = pinnedIds.includes(id)
      ? pinnedIds.filter((value) => value !== id)
      : [...pinnedIds, id];
    setPinnedIds(next);
    savePinnedCategories(tenantId, next);
    updateUiPreferences.mutate({
      ...(uiPreferences ?? {}),
      pinned_inventory_categories: next,
    });
  }

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (categoryDropdownRef.current && !categoryDropdownRef.current.contains(e.target as Node)) {
        setCategoryDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filteredCategories = useMemo(() => {
    if (!categorySearch.trim()) return categories;
    const q = categorySearch.toLowerCase();
    return categories.filter((c) => c.name.toLowerCase().includes(q));
  }, [categories, categorySearch]);

  const selectedCategoryName = useMemo(() => {
    if (!categoryId) return null;
    return categories.find((c) => c.id === categoryId)?.name || 'Categoría';
  }, [categories, categoryId]);

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

            {/* Filtros compactos: Categoría, Almacén, Tipo y Stock */}
            <div className="flex flex-wrap items-center gap-2">
              {/* Selector de Categoría Inteligente con Buscador (Opción 1) */}
              <div ref={categoryDropdownRef} className="relative">
                <button
                  type="button"
                  onClick={() => setCategoryDropdownOpen(!categoryDropdownOpen)}
                  className={cn(
                    'h-10 rounded-md border px-3 text-xs sm:text-sm font-medium transition-all shadow-2xs flex items-center gap-2 max-w-[240px]',
                    categoryId
                      ? 'bg-primary/10 border-primary text-primary font-semibold'
                      : 'bg-surface border-border text-text-primary hover:border-text-secondary',
                  )}
                  title="Filtrar por categoría"
                >
                  <Folder className="size-4 shrink-0 text-primary" />
                  <span className="truncate">
                    {selectedCategoryName || `Categoría: Todas (${categories.length})`}
                  </span>
                  {categoryId ? (
                    <span
                      role="button"
                      tabIndex={0}
                      onClick={(e) => {
                        e.stopPropagation();
                        onCategoryChange?.(undefined);
                      }}
                      className="ml-1 hover:text-rose-500 rounded p-0.5"
                      title="Quitar filtro de categoría"
                    >
                      <X className="size-3" />
                    </span>
                  ) : (
                    <ChevronDown className="size-3.5 shrink-0 opacity-60 ml-auto" />
                  )}
                </button>

                {/* Popover con Buscador Integrado */}
                {categoryDropdownOpen && (
                  <div className="absolute top-full left-0 mt-1 w-64 max-h-80 bg-surface rounded-xl border border-border shadow-xl z-50 flex flex-col p-2 animate-in fade-in-50 zoom-in-95">
                    <div className="relative mb-2">
                      <Search className="size-3.5 text-text-muted absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                      <input
                        type="text"
                        placeholder="Buscar categoría..."
                        value={categorySearch}
                        onChange={(e) => setCategorySearch(e.target.value)}
                        autoFocus
                        className="w-full pl-8 pr-2.5 py-1.5 text-xs rounded-md bg-surface-subtle border border-border focus:outline-none focus:ring-1 focus:ring-primary"
                      />
                    </div>

                    <div className="overflow-y-auto flex-1 flex flex-col gap-0.5 max-h-60 pr-1">
                      <button
                        type="button"
                        onClick={() => {
                          onCategoryChange?.(undefined);
                          setCategoryDropdownOpen(false);
                          setCategorySearch('');
                        }}
                        className={cn(
                          'w-full text-left px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center justify-between',
                          !categoryId
                            ? 'bg-primary text-primary-foreground font-bold'
                            : 'hover:bg-surface-subtle text-text-primary',
                        )}
                      >
                        <span>Todas las categorías</span>
                        <span className="text-[10px] opacity-80">({totalProducts})</span>
                      </button>

                      {filteredCategories.map((cat) => {
                        const isSelected = categoryId === cat.id;
                        return (
                          <button
                            key={cat.id}
                            type="button"
                            onClick={() => {
                              onCategoryChange?.(isSelected ? undefined : cat.id);
                              setCategoryDropdownOpen(false);
                              setCategorySearch('');
                            }}
                            className={cn(
                              'w-full text-left px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center justify-between',
                              isSelected
                                ? 'bg-primary text-primary-foreground font-bold'
                                : 'hover:bg-surface-subtle text-text-primary',
                            )}
                          >
                            <span className="truncate mr-2">{cat.name}</span>
                            {isSelected && <Check className="size-3 shrink-0" />}
                          </button>
                        );
                      })}

                      {filteredCategories.length === 0 && (
                        <div className="p-3 text-center text-xs text-text-muted">
                          No hay coincidencias
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>

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

              <button
                type="button"
                onClick={() => setPinnedDialogOpen(true)}
                className={cn(
                  'h-10 rounded-md border px-3 text-xs sm:text-sm font-medium transition-all shadow-2xs flex items-center gap-2',
                  pinnedCategories.length > 0
                    ? 'bg-amber-500/10 border-amber-500/50 text-amber-600'
                    : 'bg-surface border-border text-text-primary hover:border-text-secondary',
                )}
                title="Fijar categorías para acceso rápido"
                data-testid="catalog-pin-categories-btn"
              >
                <Star className="size-4 shrink-0" />
                <span className="hidden sm:inline">Fijar categorías</span>
              </button>
            </div>
          </div>

          {pinnedCategories.length > 0 && (
            <div
              className="flex flex-wrap items-center gap-1.5 border-t border-border/60 pt-3"
              data-testid="catalog-pinned-categories"
            >
              <span className="text-text-muted mr-0.5 text-[11px] font-semibold uppercase tracking-wide">
                Fijas:
              </span>
              <button
                type="button"
                onClick={() => onCategoryChange?.(undefined)}
                className={cn(
                  'rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                  !categoryId
                    ? 'bg-primary text-primary-foreground border-primary'
                    : 'bg-surface border-border text-text-secondary hover:border-primary/40 hover:text-text-primary',
                )}
              >
                Todas
              </button>
              {pinnedCategories.map((cat) => {
                const active = categoryId === cat.id;
                return (
                  <button
                    key={cat.id}
                    type="button"
                    onClick={() => onCategoryChange?.(active ? undefined : cat.id)}
                    className={cn(
                      'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                      active
                        ? 'bg-primary text-primary-foreground border-primary'
                        : 'bg-surface border-border text-text-secondary hover:border-primary/40 hover:text-text-primary',
                    )}
                    data-testid={`catalog-pinned-chip-${cat.id}`}
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
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 2xl:grid-cols-4 gap-4 sm:gap-5">
          {Array.from({ length: 8 }).map((_, idx) => (
            <div
              key={idx}
              className="bg-surface rounded-xl border border-border p-3 flex flex-col gap-2 animate-pulse"
            >
              <div className="w-full h-48 bg-surface-subtle rounded-lg" />
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
              action={
                onNewProduct ? (
                  <Button
                    onClick={onNewProduct}
                    size="sm"
                    className="gap-1.5 mt-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium"
                  >
                    <Sparkles className="size-3.5" />
                    Crear producto rápido
                  </Button>
                ) : undefined
              }
            />
          </CardContent>
        </Card>
      ) : (
        <div
          className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 2xl:grid-cols-4 gap-4 sm:gap-5"
          data-testid="inventory-catalog-grid"
        >
          {products.map((product) => {
            const prices = computeProductPrices(product, priceLists);
            const defaultPrice = prices.find((p) => p.isDefault) ?? prices[0];
            const priceVal = defaultPrice?.price ?? (product.base_price != null ? Number(product.base_price) : 0);

            // Costo promedio / última compra
            const rawCost = product.average_cost != null && Number(product.average_cost) > 0
              ? product.average_cost
              : (product.last_purchase_cost != null && Number(product.last_purchase_cost) > 0 ? product.last_purchase_cost : null);
            const costVal = rawCost != null ? Number(rawCost) : null;

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
                {/* Contenedor de Imagen panorámica de tarjeta */}
                <div className="relative w-full h-48 sm:h-52 bg-slate-50 dark:bg-zinc-900/60 overflow-hidden flex items-center justify-center border-b border-border/40">
                  {imageUrl ? (
                    <img
                      src={imageUrl}
                      alt={product.name}
                      className="size-full object-contain p-2.5 group-hover:scale-105 transition-transform duration-300"
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
                    <Package className="size-12 stroke-[1.25] text-text-muted/60" />
                    <span className="text-[10px] font-medium tracking-wide uppercase text-text-muted/60">
                      Sin foto
                    </span>
                  </div>

                  {/* Badge de Stock en esquina superior izquierda */}
                  <div className="absolute top-2.5 left-2.5 flex flex-col gap-1">
                    {isOutOfStock ? (
                      <span className="bg-rose-600/90 backdrop-blur-xs text-white text-[11px] font-bold px-2.5 py-0.5 rounded-full shadow-xs flex items-center gap-1">
                        <AlertTriangle className="size-3" />
                        Agotado
                      </span>
                    ) : (
                      <span className="bg-emerald-600/90 backdrop-blur-xs text-white text-[11px] font-semibold px-2.5 py-0.5 rounded-full shadow-xs flex items-center gap-1">
                        <CheckCircle2 className="size-3" />
                        {Number.isFinite(stockNum) ? stockNum.toFixed(0) : '0'} disponibles
                      </span>
                    )}
                  </div>

                  {/* Badge de Categoría en esquina superior derecha */}
                  {categoryName && (
                    <span
                      className="absolute top-2.5 right-2.5 bg-surface/90 backdrop-blur-xs text-text-secondary text-[11px] font-medium px-2.5 py-0.5 rounded-full border border-border/60 shadow-2xs truncate max-w-[120px]"
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
                    className="absolute bottom-2.5 right-14 p-2 rounded-lg bg-surface/95 text-text-primary border border-border/80 hover:bg-primary hover:text-white shadow-sm opacity-90 sm:opacity-0 sm:group-hover:opacity-100 transition-all duration-150"
                    data-testid={`catalog-edit-btn-${product.id}`}
                  >
                    <Edit className="size-4" />
                  </button>

                  {/* Botón "+" para agregar al mini carrito y enviar al POS */}
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation();
                      addPendingItem({
                        productId: product.id,
                        name: product.name,
                        sku: product.sku ?? null,
                      });
                    }}
                    title="Agregar al carrito para el POS"
                    className="absolute bottom-2.5 right-2.5 p-2 rounded-lg bg-primary text-primary-foreground border border-primary/60 hover:brightness-110 shadow-sm transition-all duration-150"
                    data-testid={`catalog-add-btn-${product.id}`}
                  >
                    <Plus className="size-4" />
                  </button>
                </div>

                {/* Contenido de la Tarjeta (Nombre + Marca + Precios de Venta + Costo) */}
                <div className="p-3.5 sm:p-4 flex flex-col flex-1 justify-between gap-3">
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between gap-1 text-[11px]">
                      {product.sku ? (
                        <span className="font-mono text-text-muted truncate">
                          SKU: {product.sku}
                        </span>
                      ) : (
                        <span></span>
                      )}
                      {product.brand?.name && (
                        <span
                          className="font-bold text-primary truncate max-w-[140px] uppercase bg-primary/10 px-2 py-0.5 rounded border border-primary/20 text-[10px] tracking-wide shrink-0"
                          title={`Marca: ${product.brand.name}`}
                        >
                          {product.brand.name}
                        </span>
                      )}
                    </div>
                    <h3
                      className="font-semibold text-sm sm:text-base text-text-primary line-clamp-2 leading-snug group-hover:text-primary transition-colors"
                      title={product.name}
                    >
                      {product.name}
                    </h3>
                  </div>

                  {/* Precios de Venta y Costo (Desglose de hasta 3 tarifas + costo) */}
                  <div className="pt-2.5 mt-auto border-t border-border/50 flex flex-col gap-2">
                    {/* Lista amplia y legible de tarifas */}
                    <div className="flex flex-col gap-1.5 bg-surface-subtle/50 rounded-lg p-2 border border-border/40">
                      {prices.length > 0 ? (
                        prices.slice(0, 3).map((p) => {
                          const pVes = activeRate ? p.price * activeRate.rate : null;
                          const shortName = p.listName.length > 25 ? p.listName.slice(0, 23) + '…' : p.listName;
                          return (
                            <div key={p.listId} className="flex items-center justify-between gap-1 text-xs leading-tight">
                              <span className="text-text-muted font-medium truncate max-w-[130px] sm:max-w-[160px]" title={p.listName}>
                                {shortName}:
                              </span>
                              <div className="flex items-center gap-1.5">
                                <div className="flex items-baseline gap-1.5 font-bold text-text-primary tabular-nums">
                                  <span className="text-xs sm:text-sm">{formatMoney(p.price)}</span>
                                  {pVes != null && (
                                    <span className="text-[10px] text-text-muted font-normal">
                                      (Bs {pVes.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})
                                    </span>
                                  )}
                                </div>
                                <button
                                  type="button"
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    addPendingItem({
                                      productId: product.id,
                                      name: product.name,
                                      sku: product.sku ?? null,
                                      price_list_id: p.listId > 0 ? p.listId : null,
                                      price_list_name: p.listName,
                                      price: p.price,
                                    });
                                  }}
                                  title={`Agregar al carrito con ${p.listName}`}
                                  className="shrink-0 rounded-md bg-primary/10 text-primary hover:bg-primary hover:text-primary-foreground p-0.5 transition-colors"
                                  data-testid={`catalog-add-price-${product.id}-${p.listId}`}
                                >
                                  <Plus className="size-3.5" />
                                </button>
                              </div>
                            </div>
                          );
                        })
                      ) : (
                        <div className="flex items-center justify-between text-xs">
                          <span className="text-text-muted font-medium">Precio:</span>
                          <span className="font-bold text-text-primary tabular-nums text-sm">{formatMoney(priceVal)}</span>
                        </div>
                      )}
                      {prices.length > 3 && (
                        <span className="text-[10px] text-primary font-medium text-right">
                          +{prices.length - 3} tarifas más
                        </span>
                      )}
                    </div>

                    {/* Fila de Costo y Margen */}
                    <div className="flex items-center justify-between text-xs px-1 text-text-muted">
                      <span className="font-medium text-text-secondary">Costo de compra:</span>
                      {costVal != null && costVal > 0 ? (
                        <span className="font-semibold text-text-primary tabular-nums">
                          {formatMoney(costVal)}
                          {priceVal > costVal && (
                            <span className="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold ml-1.5">
                              (+{(((priceVal - costVal) / costVal) * 100).toFixed(0)}%)
                            </span>
                          )}
                        </span>
                      ) : (
                        <span className="text-[11px] text-text-muted/60 italic">Sin costo registrado</span>
                      )}
                    </div>
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

      {/* 4. Modal de Detalle y Gestión Rápida Todo-en-Uno del Producto */}
      {selectedProduct && (
        <ProductCatalogDetailModal
          key={`catalog-detail-${selectedProduct.id}`}
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

      {/* 5. Dialog para Editar el Producto en modo ERP clásico */}
      {editingProduct && (
        <EditProductDialog
          product={editingProduct}
          open={Boolean(editingProduct)}
          onOpenChange={(open) => {
            if (!open) setEditingProduct(null);
          }}
        />
      )}

      {/* 6. Mini carrito: enviar productos al POS */}
      {pendingCount > 0 && (
        <button
          type="button"
          onClick={() => setPendingCartOpen(true)}
          className="fixed bottom-5 right-5 z-40 flex items-center gap-2 rounded-full bg-primary px-4 py-3 text-primary-foreground shadow-2xl transition-all hover:brightness-110 active:scale-95"
          data-testid="inventory-pending-cart-button"
          title="Productos por enviar al POS"
        >
          <ShoppingCart className="size-5" />
          <span className="text-sm font-bold">{pendingCount}</span>
        </button>
      )}

      <Dialog open={pendingCartOpen} onOpenChange={setPendingCartOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ShoppingCart className="size-5" /> Enviar al POS
            </DialogTitle>
            <DialogDescription>
              Estos productos se cargarán en el carrito del POS para cobrarlos y elegir el método
              de pago.
            </DialogDescription>
          </DialogHeader>
          <div className="max-h-72 space-y-2 overflow-y-auto">
            {pendingItems.map((item) => (
              <div
                key={pendingItemKey(item)}
                className="flex items-center justify-between gap-3 rounded-lg border border-border p-2.5"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{item.name}</p>
                  <p className="text-xs text-text-muted">
                    {item.sku ? `SKU: ${item.sku}` : ''}
                    {item.price_list_name ? ` · ${item.price_list_name}` : ''}
                    {item.price != null ? ` · ${formatMoney(item.price)}` : ''}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <span className="text-sm font-bold tabular-nums">x{item.quantity}</span>
                  <button
                    type="button"
                    onClick={() => removePendingItem(pendingItemKey(item))}
                    className="rounded p-1 text-text-muted hover:bg-danger/10 hover:text-danger"
                    aria-label={`Quitar ${item.name}`}
                  >
                    <Trash2 className="size-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => clearPendingCart()}
              disabled={pendingItems.length === 0}
            >
              Vaciar
            </Button>
            <Button
              onClick={() => {
                sendPendingToPos();
                setPendingCartOpen(false);
                void navigate({ to: '/pos' });
              }}
              disabled={pendingItems.length === 0}
              className="gap-2"
            >
              <Send className="size-4" /> Enviar al POS
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* 7. Configurar categorias fijadas (filtros rapidos) */}
      <Dialog open={pinnedDialogOpen} onOpenChange={setPinnedDialogOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Star className="size-5 text-amber-500" /> Fijar categorías
            </DialogTitle>
            <DialogDescription>
              Marca las categorías que querés ver a primera vista como filtros rápidos en el
              catálogo.
            </DialogDescription>
          </DialogHeader>
          <div className="max-h-80 space-y-1 overflow-y-auto">
            {categories.length === 0 && (
              <p className="p-3 text-center text-sm text-text-muted">No hay categorías registradas.</p>
            )}
            {categories.map((cat) => {
              const checked = pinnedIds.includes(cat.id);
              return (
                <button
                  key={cat.id}
                  type="button"
                  onClick={() => togglePinnedCategory(cat.id)}
                  className={cn(
                    'flex w-full items-center justify-between rounded-lg border px-3 py-2 text-sm transition-colors',
                    checked
                      ? 'border-primary bg-primary/10 text-primary font-semibold'
                      : 'border-border hover:bg-surface-subtle',
                  )}
                  data-testid={`catalog-pin-toggle-${cat.id}`}
                >
                  <span className="truncate">{cat.name}</span>
                  {checked && <Check className="size-4 shrink-0" />}
                </button>
              );
            })}
          </div>
          <DialogFooter>
            <Button onClick={() => setPinnedDialogOpen(false)}>Listo</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/**
 * Ficha Integral Todo-en-Uno para la Vista Catálogo.
 * Permite visualizar y editar de forma inmediata en una sola pantalla:
 *  - Foto, nombre, SKU, código de barras, categoría, marca, estado y unidad.
 *  - Costo de compra, margen en vivo y los precios en todas las tarifas (USD + Bs).
 *  - Existencias reales por almacén.
 *  - Pestaña secundaria para datos avanzados (garantía, límites de stock, descripción).
 *  - Guardado directo en 1 solo clic.
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
  const qc = useQueryClient();
  const updateProduct = useUpdateProduct();

  const { data: brands = [] } = useBrands();
  const { data: categories = [] } = useCategories();
  const { data: warranties = [] } = useWarrantyPolicies();
  const { data: stockByWarehouse = [], isLoading: isLoadingStock } = useProductStockByWarehouse(product.id);

  const [activeTab, setActiveTab] = useState<'main' | 'advanced'>('main');

  // Datos principales
  const [name, setName] = useState(product.name ?? '');
  const [sku, setSku] = useState(product.sku ?? '');
  const [barcode, setBarcode] = useState(product.barcode ?? '');
  const [brandId, setBrandId] = useState<number | undefined>(
    product.brand_id ?? product.brand?.id ?? undefined,
  );
  const [categoryId, setCategoryId] = useState<number | undefined>(
    product.categories?.[0]?.id ?? undefined,
  );
  const [unitOfMeasure, setUnitOfMeasure] = useState(product.unit_of_measure ?? 'unit');
  const [isActive, setIsActive] = useState(product.is_active ?? true);
  const [imageUrl, setImageUrl] = useState(
    product.image_url || product.primary_image_url || product.images?.[0]?.url || '',
  );

  // Costo y Precios
  const initialCost =
    product.average_cost != null && Number(product.average_cost) > 0
      ? String(product.average_cost)
      : product.last_purchase_cost != null && Number(product.last_purchase_cost) > 0
        ? String(product.last_purchase_cost)
        : '';
  const [cost, setCost] = useState(initialCost);
  const [basePrice, setBasePrice] = useState(
    product.base_price != null ? String(product.base_price) : '',
  );

  // Precios computados y mapa editable
  const computedPrices = useMemo(
    () => computeProductPrices(product, priceLists),
    [product, priceLists],
  );
  const [priceMap, setPriceMap] = useState<Record<number, { amount: string; isDirty: boolean }>>(() => {
    const map: Record<number, { amount: string; isDirty: boolean }> = {};
    for (const p of computedPrices) {
      map[p.listId] = { amount: String(p.price), isDirty: false };
    }
    return map;
  });

  // Campos avanzados
  const [minStock, setMinStock] = useState(
    product.min_stock != null ? String(product.min_stock) : '',
  );
  const [maxStock, setMaxStock] = useState(
    product.max_stock != null ? String(product.max_stock) : '',
  );
  const [reorderQuantity, setReorderQuantity] = useState(
    product.reorder_quantity != null ? String(product.reorder_quantity) : '',
  );
  const [longDescription, setLongDescription] = useState(
    product.long_description ?? product.description ?? '',
  );
  const [trackingType, setTrackingType] = useState<'quantity' | 'serialized'>(
    product.tracking_type ?? 'quantity',
  );
  const [trackStock, setTrackStock] = useState(product.track_stock ?? true);
  const [warrantyPolicyId, setWarrantyPolicyId] = useState<number | undefined>(
    product.warranty_policy_id ?? undefined,
  );

  const [isSaving, setIsSaving] = useState(false);

  // Galería de imágenes
  const allImages = useMemo(() => {
    const list: string[] = [];
    if (imageUrl && !list.includes(imageUrl)) list.push(imageUrl);
    if (product.primary_image_url && !list.includes(product.primary_image_url))
      list.push(product.primary_image_url);
    if (product.images) {
      for (const img of product.images) {
        if (img.url && !list.includes(img.url)) list.push(img.url);
      }
    }
    return list;
  }, [imageUrl, product]);

  const [activeImageIndex, setActiveImageIndex] = useState(0);
  const currentPreviewImage = allImages[activeImageIndex] || imageUrl || null;

  // Manejo de cambio de precio en una lista específica
  const handlePriceChange = (listId: number, newAmount: string, isDefault: boolean) => {
    setPriceMap((prev) => ({
      ...prev,
      [listId]: { amount: newAmount, isDirty: true },
    }));
    if (isDefault) {
      setBasePrice(newAmount);
    }
  };

  // Guardar cambios
  const handleSave = async () => {
    if (!name.trim()) {
      toast.error('El nombre del producto es obligatorio.');
      return;
    }

    setIsSaving(true);
    try {
      const costNum = cost.trim() !== '' ? parseFloat(cost) : null;
      const basePriceNum = basePrice.trim() !== '' ? parseFloat(basePrice) : null;

      let marginNum: number | null = null;
      if (costNum != null && costNum > 0 && basePriceNum != null && basePriceNum > 0) {
        marginNum = Number((((basePriceNum - costNum) / costNum) * 100).toFixed(2));
      }

      // 1. Guardar atributos del producto
      await updateProduct.mutateAsync({
        id: product.id,
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

      // 2. Guardar precios si fueron modificados
      const dirtyPrices = Object.entries(priceMap)
        .filter(([, v]) => v.isDirty && v.amount.trim() !== '')
        .map(([listIdStr, v]) => ({
          price_list_id: Number(listIdStr),
          price: parseFloat(v.amount),
          currency: 'USD' as const,
        }));

      if (dirtyPrices.length > 0) {
        await putOne(`/products/${product.id}/prices`, { prices: dirtyPrices });
      }

      toast.success('Producto y tarifas guardados correctamente.');
      void qc.invalidateQueries({ queryKey: productKeys.lists() });
      void qc.invalidateQueries({ queryKey: productKeys.detail(product.id) });
      void qc.invalidateQueries({ queryKey: productKeys.prices(product.id) });
      onOpenChange(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al guardar los cambios.');
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-6xl w-[98vw] max-h-[92vh] overflow-hidden p-0 flex flex-col rounded-2xl shadow-2xl border-border">
        {/* Cabecera del Modal */}
        <DialogHeader className="px-4 py-2.5 bg-surface-subtle/50 border-b border-border/80">
          <div className="flex items-start justify-between gap-3">
            <div className="flex flex-col gap-0.5">
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-surface border border-border text-text-muted">
                  SKU: {sku || 'Sin SKU'}
                </span>
                {barcode && (
                  <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-surface border border-border text-text-muted">
                    BAR: {barcode}
                  </span>
                )}
                <Badge variant={isActive ? 'success' : 'default'} className="text-[9px] py-0 px-1.5">
                  {isActive ? 'Activo para venta' : 'Inactivo'}
                </Badge>
              </div>
              <DialogTitle className="text-base sm:text-lg font-bold text-text-primary mt-0.5">
                {name || 'Producto sin nombre'}
              </DialogTitle>
              <DialogDescription className="text-[11px] text-text-muted leading-tight">
                Ficha integral: edita datos, precios y consulta existencias en un solo lugar.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {/* Pestañas de Navegación */}
        <div className="flex items-center gap-2 px-4 border-b border-border bg-surface-subtle/30 shrink-0">
          <button
            type="button"
            onClick={() => setActiveTab('main')}
            className={cn(
              'px-3 py-2 text-xs font-semibold border-b-2 transition-all flex items-center gap-1.5',
              activeTab === 'main'
                ? 'border-primary text-primary bg-surface/60'
                : 'border-transparent text-text-muted hover:text-text-primary',
            )}
          >
            <Sparkles className="size-3.5" />
            Todo-en-Uno (Datos, Costo, Precios y Stock)
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('advanced')}
            className={cn(
              'px-3 py-2 text-xs font-semibold border-b-2 transition-all flex items-center gap-1.5',
              activeTab === 'advanced'
                ? 'border-primary text-primary bg-surface/60'
                : 'border-transparent text-text-muted hover:text-text-primary',
            )}
          >
            <Sliders className="size-3.5" />
            Ficha Técnica & Parámetros Extendidos
          </button>
        </div>

        {/* Cuerpo del Modal con Scroll */}
        <div className="p-3.5 sm:p-4 flex-1 overflow-y-auto">
          {activeTab === 'main' ? (
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-3.5 items-start">
              {/* Columna 1: Foto & Datos Básicos (4 cols) */}
              <div className="lg:col-span-4 flex flex-col gap-2">
                {/* Foto del Producto con miniaturas */}
                <div className="flex flex-col gap-1.5">
                  <div className="w-full h-28 sm:h-32 bg-slate-50 dark:bg-zinc-900 rounded-lg border border-border overflow-hidden flex items-center justify-center p-2 relative group">
                    {currentPreviewImage ? (
                      <img
                        src={currentPreviewImage}
                        alt={name}
                        className="size-full object-contain"
                      />
                    ) : (
                      <div className="flex flex-col items-center justify-center gap-1 text-text-muted">
                        <Package className="size-10 stroke-[1.25] text-text-muted/50" />
                        <span className="text-[10px] font-medium text-text-muted/60">
                          Sin imagen registrada
                        </span>
                      </div>
                    )}
                  </div>

                  {allImages.length > 1 && (
                    <div className="flex items-center gap-1.5 overflow-x-auto pb-0.5">
                      {allImages.map((img, idx) => (
                        <button
                          key={idx}
                          type="button"
                          onClick={() => setActiveImageIndex(idx)}
                          className={cn(
                            'size-8 rounded border overflow-hidden p-0.5 bg-surface transition-all shrink-0',
                            activeImageIndex === idx
                              ? 'border-primary ring-2 ring-primary/20'
                              : 'border-border hover:border-text-secondary',
                          )}
                        >
                          <img src={img} alt={`Thumb ${idx}`} className="size-full object-cover rounded" />
                        </button>
                      ))}
                    </div>
                  )}

                  {/* URL de foto editable */}
                  <div className="flex items-center gap-1.5">
                    <Label className="text-[10px] text-text-secondary font-medium shrink-0">URL:</Label>
                    <Input
                      placeholder="https://ejemplo.com/foto.jpg"
                      value={imageUrl}
                      onChange={(e) => setImageUrl(e.target.value)}
                      className="text-xs h-7"
                    />
                  </div>
                </div>

                {/* Nombre del Producto */}
                <div>
                  <Label className="text-[11px] font-semibold text-text-primary">
                    Nombre del Producto <span className="text-rose-500">*</span>
                  </Label>
                  <Input
                    placeholder="Ej. Bujía Denso K20PR-U"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="mt-0.5 font-medium h-7 text-xs"
                  />
                </div>

                {/* SKU y Código de Barras */}
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label className="text-[10px] text-text-secondary">Código / SKU</Label>
                    <Input
                      placeholder="SKU-1234"
                      value={sku}
                      onChange={(e) => setSku(e.target.value)}
                      className="mt-0.5 font-mono text-xs h-7"
                    />
                  </div>
                  <div>
                    <Label className="text-[10px] text-text-secondary">Código de barras</Label>
                    <Input
                      placeholder="759123456789"
                      value={barcode}
                      onChange={(e) => setBarcode(e.target.value)}
                      className="mt-0.5 font-mono text-xs h-7"
                    />
                  </div>
                </div>

                {/* Categoría y Marca */}
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label className="text-[10px] text-text-secondary">Categoría</Label>
                    <Select
                      value={categoryId ? String(categoryId) : ''}
                      onChange={(e) =>
                        setCategoryId(e.target.value ? Number(e.target.value) : undefined)
                      }
                      className="mt-0.5 text-xs h-7"
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
                    <Label className="text-[10px] text-text-secondary">Marca</Label>
                    <Select
                      value={brandId ? String(brandId) : ''}
                      onChange={(e) =>
                        setBrandId(e.target.value ? Number(e.target.value) : undefined)
                      }
                      className="mt-0.5 text-xs h-7"
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
                    <Label className="text-[10px] text-text-secondary">Unidad de Medida</Label>
                    <Select
                      value={unitOfMeasure}
                      onChange={(e) => setUnitOfMeasure(e.target.value)}
                      className="mt-0.5 text-xs h-7"
                    >
                      {STANDARD_UNITS_OF_MEASURE.map((u) => (
                        <option key={u.value} value={u.value}>
                          {u.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="flex items-center justify-between bg-surface-subtle/50 border border-border/80 rounded-md px-2.5 h-7 mt-3.5">
                    <Label className="text-xs cursor-pointer" onClick={() => setIsActive(!isActive)}>
                      Activo
                    </Label>
                    <Switch checked={isActive} onCheckedChange={setIsActive} />
                  </div>
                </div>
              </div>

              {/* Columna 2: Precios de Venta & Costo (5 cols) */}
              <div className="lg:col-span-5 flex flex-col gap-2">
                <div className="bg-surface border border-border/80 rounded-xl p-3 shadow-2xs flex flex-col gap-2">
                  <div className="flex items-center justify-between border-b border-border/60 pb-1.5">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-text-secondary flex items-center gap-1.5">
                      <DollarSign className="size-3.5 text-primary" />
                      Precios de Venta y Costo
                    </h4>
                    {activeRate && (
                      <span className="text-[10px] font-medium text-text-muted bg-surface-subtle px-1.5 py-0.5 rounded border border-border/60">
                        1 USD = {activeRate.rate.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES
                      </span>
                    )}
                  </div>

                  {/* Fila de Costo de Compra */}
                  <div className="bg-amber-500/10 border border-amber-500/20 rounded-lg px-2.5 py-1.5 flex items-center justify-between gap-2">
                    <div className="flex flex-col">
                      <Label className="text-xs font-semibold text-amber-700 dark:text-amber-400">
                        Costo de compra (USD)
                      </Label>
                      <span className="text-[9px] text-text-muted leading-none">
                        Base para calcular margen y ganancia
                      </span>
                    </div>
                    <div className="w-24">
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0.00"
                        value={cost}
                        onChange={(e) => setCost(e.target.value)}
                        className="text-right font-bold h-7 text-xs"
                      />
                    </div>
                  </div>

                  {/* Tarifas de Precios de Venta */}
                  <div className="flex flex-col gap-1.5 pt-0.5 max-h-[310px] overflow-y-auto pr-0.5">
                    {priceLists
                      .filter((l) => l.is_active)
                      .map((list) => {
                        const currentVal = priceMap[list.id]?.amount ?? '';
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
                              'px-2.5 py-1.5 rounded-lg border flex flex-col gap-0.5 transition-colors',
                              isDefault
                                ? 'bg-primary/5 border-primary/30'
                                : 'bg-surface-subtle/40 border-border/70',
                            )}
                          >
                            <div className="flex items-center justify-between gap-1.5">
                              <div className="flex items-center gap-1 min-w-0">
                                <span className="text-xs font-bold text-text-primary truncate">
                                  {list.name}
                                </span>
                                {isDefault && (
                                  <span className="text-[8px] font-semibold text-primary bg-primary/10 px-1 py-0.2 rounded shrink-0">
                                    Predet.
                                  </span>
                                )}
                                {list.code && (
                                  <span className="text-[9px] font-mono text-text-muted truncate">
                                    ({list.code})
                                  </span>
                                )}
                              </div>
                              <div className="flex items-center gap-1 shrink-0">
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
                                  className="w-24 text-right font-bold text-xs h-7"
                                />
                              </div>
                            </div>

                            {/* Conversión en Bs y Margen */}
                            <div className="flex items-center justify-between text-[10px] text-text-muted pt-0.5 border-t border-border/30">
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
                                    'font-medium text-[9px]',
                                    Number(margin) >= 0
                                      ? 'text-emerald-600 dark:text-emerald-400'
                                      : 'text-rose-600 dark:text-rose-400',
                                  )}
                                >
                                  Margen: +{margin}% (+${profit})
                                </span>
                              )}
                            </div>
                          </div>
                        );
                      })}
                  </div>
                </div>
              </div>

              {/* Columna 3: Existencias & Parámetros Rápidos (3 cols) */}
              <div className="lg:col-span-3 flex flex-col gap-2">
                {/* Bloque: Existencias por Almacén */}
                <div className="bg-surface border border-border/80 rounded-xl p-2.5 shadow-2xs flex flex-col gap-1.5">
                  <h4 className="text-xs font-bold uppercase tracking-wider text-text-secondary flex items-center gap-1.5 border-b border-border/60 pb-1">
                    <Boxes className="size-3.5 text-primary" />
                    Existencias
                  </h4>

                  {isLoadingStock ? (
                    <div className="p-3 text-center text-xs text-text-muted">
                      Consultando...
                    </div>
                  ) : stockByWarehouse.length === 0 ? (
                    <div className="p-2 bg-surface-subtle/50 rounded-lg border border-border text-[11px] text-text-muted text-center">
                      Sin existencias registradas.
                    </div>
                  ) : (
                    <div className="overflow-x-auto rounded-lg border border-border/80 max-h-36 overflow-y-auto">
                      <table className="w-full text-[11px] text-left">
                        <thead className="bg-surface-subtle/80 border-b border-border text-text-secondary font-semibold">
                          <tr>
                            <th className="px-2 py-1">Almacén</th>
                            <th className="px-2 py-1 text-right">Disp.</th>
                            <th className="px-2 py-1 text-right">Res.</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                          {stockByWarehouse.map((sw) => {
                            const avail =
                              typeof sw.available === 'string'
                                ? parseFloat(sw.available)
                                : sw.available;
                            return (
                              <tr key={sw.warehouse_id} className="hover:bg-surface-subtle/30">
                                <td className="px-2 py-1 font-medium text-text-primary truncate max-w-[80px]" title={sw.warehouse_name || sw.warehouse_code}>
                                  {sw.warehouse_name || sw.warehouse_code}
                                </td>
                                <td className="px-2 py-1 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                  {Number.isFinite(avail) ? avail : 0}
                                </td>
                                <td className="px-2 py-1 text-right text-text-muted">
                                  {sw.reserved ?? 0}
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>

                {/* Límites de Stock */}
                <div className="bg-surface border border-border/80 rounded-xl p-2.5 shadow-2xs flex flex-col gap-1.5">
                  <h4 className="text-[11px] font-bold uppercase tracking-wider text-text-secondary">
                    Límites de Stock
                  </h4>
                  <div className="grid grid-cols-3 gap-1.5">
                    <div>
                      <Label className="text-[9px] text-text-secondary">Mínimo</Label>
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0"
                        value={minStock}
                        onChange={(e) => setMinStock(e.target.value)}
                        className="mt-0.5 text-xs h-7 px-1.5"
                      />
                    </div>
                    <div>
                      <Label className="text-[9px] text-text-secondary">Máximo</Label>
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0"
                        value={maxStock}
                        onChange={(e) => setMaxStock(e.target.value)}
                        className="mt-0.5 text-xs h-7 px-1.5"
                      />
                    </div>
                    <div>
                      <Label className="text-[9px] text-text-secondary">Reorden</Label>
                      <Input
                        type="number"
                        step="any"
                        min="0"
                        placeholder="0"
                        value={reorderQuantity}
                        onChange={(e) => setReorderQuantity(e.target.value)}
                        className="mt-0.5 text-xs h-7 px-1.5"
                      />
                    </div>
                  </div>
                </div>

                {/* Política de Garantía */}
                <div className="bg-surface border border-border/80 rounded-xl p-2.5 shadow-2xs flex flex-col gap-1">
                  <Label className="text-[11px] font-bold uppercase tracking-wider text-text-secondary">
                    Garantía
                  </Label>
                  <Select
                    value={warrantyPolicyId ? String(warrantyPolicyId) : ''}
                    onChange={(e) =>
                      setWarrantyPolicyId(e.target.value ? Number(e.target.value) : undefined)
                    }
                    className="text-xs h-7"
                  >
                    <option value="">(Sin garantía)</option>
                    {warranties.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name} ({w.duration_days}d)
                      </option>
                    ))}
                  </Select>
                </div>
              </div>
            </div>
          ) : (
            /* Pestaña Secundaria: Avanzado / Parámetros */
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="flex flex-col gap-3">
                <div>
                  <Label className="text-xs font-semibold text-text-secondary">
                    Descripción detallada / Ficha técnica
                  </Label>
                  <Textarea
                    rows={5}
                    placeholder="Detalles técnicos, especificaciones, compatibilidad o notas..."
                    value={longDescription}
                    onChange={(e) => setLongDescription(e.target.value)}
                    className="mt-1 text-xs"
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

              <div className="flex flex-col gap-3">
                {/* Control de Inventario */}
                <div className="p-3.5 rounded-xl border border-border bg-surface flex flex-col gap-2.5">
                  <h5 className="text-xs font-bold uppercase tracking-wider text-text-secondary">
                    Control de Inventario
                  </h5>
                  <div className="flex items-center justify-between">
                    <div>
                      <Label className="text-xs">Rastrear existencias</Label>
                      <p className="text-[10px] text-text-muted">
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
                <div className="p-3.5 rounded-xl border border-border bg-surface flex flex-col gap-2.5">
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
                        className="mt-1 text-xs h-7"
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
                        className="mt-1 text-xs h-7"
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
                        className="mt-1 text-xs h-7"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer con Acciones */}
        <DialogFooter className="border-t border-border px-4 py-2.5 bg-surface-subtle/40 flex items-center justify-between sm:justify-between w-full shrink-0">
          <div className="flex items-center gap-3">
            <Link
              to="/inventory/$productId"
              params={{ productId: String(product.id) }}
              className="text-xs text-primary font-medium hover:underline inline-flex items-center gap-1"
            >
              <ExternalLink className="size-3.5" />
              Kardex / Historial
            </Link>
            <button
              type="button"
              onClick={onEdit}
              className="text-xs text-text-muted hover:text-text-primary hover:underline inline-flex items-center gap-1"
            >
              <Edit className="size-3" />
              Asistente ERP completo (F1-F9)
            </button>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => onOpenChange(false)}
              disabled={isSaving}
            >
              Cancelar
            </Button>
            <Can I={PERMISSIONS.PRODUCTS_UPDATE}>
              <Button
                size="sm"
                onClick={handleSave}
                disabled={isSaving}
                className="gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm"
              >
                {isSaving ? <Spinner size="sm" className="text-white" /> : <Save className="size-3.5" />}
                {isSaving ? 'Guardando...' : 'Guardar Cambios'}
              </Button>
            </Can>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
