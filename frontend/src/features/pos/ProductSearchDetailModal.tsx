/**
 * ProductSearchDetailModal.tsx — Modal Desktop ERP de Búsqueda y Detalle de Productos en POS.
 *
 * Se abre al presionar F3 en el POS o al hacer clic en 'Buscar' / 'Ver todos'.
 *
 * Layout dividido en dos columnas:
 *  - Izquierda:
 *      * Barra de búsqueda por código, SKU, código de barras o nombre con auto-focus.
 *      * Selector de almacén.
 *      * Tabla de resultados con columnas Código, Descripción y Stock.
 *      * Navegación por teclado (Flechas Arriba/Abajo) y selección con doble clic o Enter/F2.
 *      * Controles de paginación (Anterior, Siguiente, Página X de Y, total).
 *  - Derecha:
 *      * Encabezado del producto seleccionado (Nombre, códigos, stock total).
 *      * Pestañas:
 *          1. [F8] Datos principales (imagen, descripciones, costos promedio/última compra, parámetros de stock, marca, categoría).
 *          2. [F6] Existencia (desglose por almacén: disponible, reservado, dañado, total físico).
 *          3. [F5] Precios (tabla comparativa Precio 1, 2 y 3 c/impuesto en USD referencial, VES local y por empaque).
 *          4. [F7] Seriales (listado de IMEIs / seriales disponibles con filtro de búsqueda).
 *          5. Lotes (trazabilidad, lote, fecha de vencimiento o control general).
 *  - Pie de ventana:
 *      * Barra de atajos de teclado del F2 al F8.
 *      * Botón Salir ([Esc]) y Botón Aceptar ([Enter]/[F2]).
 */
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  Boxes,
  Calendar,
  Check,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Lock,
  Package,
  Search,
  ShieldCheck,
  Warehouse,
  X,
} from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Dialog, DialogContent } from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import {
  useProducts,
  useProductStockByWarehouse,
  useProductSerials,
} from '@/features/inventory-center/api';
import { ProductImage as ProductImageView } from '@/features/inventory-center/components/ProductImage';
import type { PriceList, Product } from '@/features/inventory-center/schemas';
import { cn } from '@/lib/cn';

interface ProductSearchDetailModalProps {
  open: boolean;
  onClose: () => void;
  onSelect: (product: Product) => void | Promise<void>;
  warehouses: { id: number; code: string; name: string }[];
  warehouseId: number | null;
  onWarehouseChange: (warehouseId: number | null) => void;
  priceLists?: PriceList[];
  selectedPriceList?: PriceList | null;
  activeRate?: { id?: number; name: string; code?: string; rate: number } | null;
  initialSearch?: string;
}

type TabKey = 'datos' | 'existencia' | 'precios' | 'seriales' | 'lotes';

const IVA_RATE = 0.16; // 16% IVA Venezuela
const PAGE_SIZE = 10;

function money(value: number | string | null | undefined): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value || 0));
}

function moneyVes(value: number | string | null | undefined): string {
  const formatted = Number(value || 0).toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `Bs. ${formatted}`;
}

function stripHtml(html?: string | null): string {
  if (!html) return '';
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  return tmp.textContent ?? tmp.innerText ?? '';
}

function primaryProductImage(product: Product) {
  return product.images?.find((img) => img.is_primary) ?? product.images?.[0];
}

interface PriceTableRow {
  id: string | number;
  label: string;
  code?: string;
  isDefault?: boolean;
  netUsd: number;
  taxUsd: number;
  totalUsd: number;
  netVes: number;
  taxVes: number;
  totalVes: number;
  packageFactor: number;
  packageUsd: number;
  packageVes: number;
}

export function ProductSearchDetailModal({
  open,
  onClose,
  onSelect,
  warehouses,
  warehouseId,
  onWarehouseChange,
  priceLists = [],
  selectedPriceList,
  activeRate,
  initialSearch = '',
}: ProductSearchDetailModalProps) {
  const [search, setSearch] = useState(initialSearch);
  const [debouncedSearch, setDebouncedSearch] = useState(initialSearch);
  const [page, setPage] = useState(1);
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [activeTab, setActiveTab] = useState<TabKey>('precios');
  const [packageFactor, setPackageFactor] = useState<number>(1);
  const [serialFilter, setSerialFilter] = useState('');

  const searchInputRef = useRef<HTMLInputElement>(null);
  const warehouseSelectRef = useRef<HTMLSelectElement>(null);
  const tableContainerRef = useRef<HTMLDivElement>(null);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search);
      setPage(1);
    }, 180);
    return () => clearTimeout(timer);
  }, [search]);

  // Query paginada de productos
  const { data: paginatedData, isLoading: loadingProducts } = useProducts(
    {
      search: debouncedSearch.trim(),
      warehouse_id: warehouseId ?? undefined,
      page,
      per_page: PAGE_SIZE,
      active_status: 'active',
      tracking_type: 'all',
      stock_status: 'all',
      with_images: 1,
      with_prices: 1,
    },
    { enabled: open },
  );

  const products = useMemo(() => paginatedData?.data ?? [], [paginatedData]);
  const meta = paginatedData?.meta;
  const totalPages = meta?.last_page ?? 1;
  const totalProducts = meta?.total ?? 0;

  // Producto actualmente seleccionado
  const selectedProduct = useMemo(() => {
    if (products.length === 0) return null;
    const safeIdx = Math.min(Math.max(0, selectedIndex), products.length - 1);
    return products[safeIdx] ?? null;
  }, [products, selectedIndex]);

  // Consulta de existencia multi-almacén para el producto seleccionado
  const { data: stockByWarehouse = [], isLoading: loadingStock } = useProductStockByWarehouse(
    selectedProduct?.id ?? 0,
  );

  // Consulta de seriales para el producto seleccionado
  const { data: productSerials = [], isLoading: loadingSerials } = useProductSerials(
    selectedProduct?.id ?? 0,
  );

  // Auto-focus en el buscador al abrir
  useEffect(() => {
    if (open) {
      const t = setTimeout(() => {
        searchInputRef.current?.focus();
        searchInputRef.current?.select();
      }, 60);
      return () => clearTimeout(t);
    }
  }, [open]);

  // Reset selected index al cambiar de página o búsqueda
  useEffect(() => {
    setSelectedIndex(0);
  }, [debouncedSearch, page]);

  // Asegurar visibilidad de la fila seleccionada
  useEffect(() => {
    if (!tableContainerRef.current) return;
    const selectedRow = tableContainerRef.current.querySelector(
      `[data-row-index="${selectedIndex}"]`,
    ) as HTMLElement | null;
    if (selectedRow && typeof selectedRow.scrollIntoView === 'function') {
      selectedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }, [selectedIndex]);

  // Cálculo de tabla comparativa de precios (Precio 1, 2, 3 c/impuesto en USD, VES y por empaque)
  const priceRows = useMemo<PriceTableRow[]>(() => {
    if (!selectedProduct) return [];

    const rateVal = activeRate?.rate && activeRate.rate > 0 ? activeRate.rate : 0;
    const basePrice = Number(selectedProduct.base_price ?? 0);
    const existingPrices = selectedProduct.prices ?? [];

    const rows: PriceTableRow[] = [];

    // Helper para armar fila
    const buildRow = (
      id: string | number,
      label: string,
      netUsdPrice: number,
      code?: string,
      isDefault?: boolean,
    ): PriceTableRow => {
      const netUsd = Math.max(0, Number(netUsdPrice || 0));
      const taxUsd = Math.round(netUsd * IVA_RATE * 100) / 100;
      const totalUsd = Math.round((netUsd + taxUsd) * 100) / 100;

      const netVes = rateVal > 0 ? Math.round(netUsd * rateVal * 100) / 100 : 0;
      const taxVes = rateVal > 0 ? Math.round(taxUsd * rateVal * 100) / 100 : 0;
      const totalVes = rateVal > 0 ? Math.round(totalUsd * rateVal * 100) / 100 : 0;

      const factor = Math.max(1, packageFactor);
      const packageUsd = Math.round(totalUsd * factor * 100) / 100;
      const packageVes = Math.round(totalVes * factor * 100) / 100;

      return {
        id,
        label,
        code,
        isDefault,
        netUsd,
        taxUsd,
        totalUsd,
        netVes,
        taxVes,
        totalVes,
        packageFactor: factor,
        packageUsd,
        packageVes,
      };
    };

    // Si existen listas de precios de la empresa, mapearlas
    if (priceLists.length > 0) {
      priceLists.forEach((list, idx) => {
        const found = existingPrices.find(
          (p) => p.price_list_id === list.id || p.price_list?.id === list.id,
        );
        let listPrice = found ? Number(found.price) : 0;

        // Fallback para lista por defecto si no tiene precio específico
        if (!found && (list.is_default || idx === 0)) {
          listPrice = basePrice;
        }

        // Si tiene porcentaje de margen configurado sobre el precio base
        if (!found && list.markup_percentage && basePrice > 0) {
          listPrice = basePrice * (1 + Number(list.markup_percentage) / 100);
        }

        const labelName = list.name || `Precio ${idx + 1}`;
        rows.push(buildRow(list.id, labelName, listPrice, list.code, list.is_default));
      });
    }

    // Garantizar que existan al menos Precio 1, Precio 2 y Precio 3
    if (rows.length === 0) {
      // Precio 1
      rows.push(buildRow('p1', 'Precio 1 (Detal / Normal)', basePrice, 'P1', true));

      // Precio 2
      const p2 = existingPrices.find((p) => p.price_list?.code === 'P2' || p.price_list_id === 2);
      rows.push(
        buildRow('p2', 'Precio 2 (Al Mayor)', p2 ? Number(p2.price) : basePrice * 0.9, 'P2'),
      );

      // Precio 3
      const p3 = existingPrices.find((p) => p.price_list?.code === 'P3' || p.price_list_id === 3);
      rows.push(
        buildRow(
          'p3',
          'Precio 3 (Especial / Mayorista)',
          p3 ? Number(p3.price) : basePrice * 0.85,
          'P3',
        ),
      );
    } else if (rows.length < 3) {
      // Si solo hay 1 o 2 listas configuradas, asegurar visualización completa de los 3 niveles
      if (rows.length === 1) {
        rows.push(buildRow('p2', 'Precio 2 (Al Mayor)', basePrice * 0.9, 'P2'));
        rows.push(buildRow('p3', 'Precio 3 (Especial / Mayorista)', basePrice * 0.85, 'P3'));
      } else if (rows.length === 2) {
        rows.push(buildRow('p3', 'Precio 3 (Especial / Mayorista)', basePrice * 0.85, 'P3'));
      }
    }

    return rows;
  }, [selectedProduct, priceLists, activeRate, packageFactor]);

  // Acción de agregar el producto seleccionado al ticket
  const handleConfirmSelect = useCallback(() => {
    if (!selectedProduct) return;
    void onSelect(selectedProduct);
  }, [selectedProduct, onSelect]);

  // Manejador centralizado de atajos de teclado F2..F8, flechas y Enter
  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      switch (event.key) {
        case 'Escape': {
          event.preventDefault();
          onClose();
          break;
        }
        case 'F2': {
          event.preventDefault();
          handleConfirmSelect();
          break;
        }
        case 'F3': {
          event.preventDefault();
          searchInputRef.current?.focus();
          searchInputRef.current?.select();
          break;
        }
        case 'F4': {
          event.preventDefault();
          warehouseSelectRef.current?.focus();
          break;
        }
        case 'F5': {
          event.preventDefault();
          setActiveTab('precios');
          break;
        }
        case 'F6': {
          event.preventDefault();
          setActiveTab('existencia');
          break;
        }
        case 'F7': {
          event.preventDefault();
          setActiveTab('seriales');
          break;
        }
        case 'F8': {
          event.preventDefault();
          setActiveTab('datos');
          break;
        }
        case 'ArrowDown': {
          if (products.length > 0) {
            event.preventDefault();
            setSelectedIndex((curr) => (curr + 1 < products.length ? curr + 1 : curr));
          }
          break;
        }
        case 'ArrowUp': {
          if (products.length > 0) {
            event.preventDefault();
            setSelectedIndex((curr) => (curr > 0 ? curr - 1 : 0));
          }
          break;
        }
        case 'PageDown': {
          if (page < totalPages) {
            event.preventDefault();
            setPage((p) => p + 1);
          }
          break;
        }
        case 'PageUp': {
          if (page > 1) {
            event.preventDefault();
            setPage((p) => p - 1);
          }
          break;
        }
        case 'Enter': {
          // Si el foco está en un input y no es el de seriales, agregar producto
          const target = event.target as HTMLElement | null;
          if (target && target.tagName !== 'BUTTON' && target.id !== 'serial-filter-input') {
            event.preventDefault();
            handleConfirmSelect();
          }
          break;
        }
        default:
          break;
      }
    },
    [products.length, page, totalPages, handleConfirmSelect, onClose],
  );

  // Seriales filtrados
  const filteredSerials = useMemo(() => {
    if (!productSerials || !Array.isArray(productSerials)) return [];
    if (!serialFilter.trim()) return productSerials;
    const q = serialFilter.toLowerCase();
    return productSerials.filter((s) => s.serial_number?.toLowerCase().includes(q));
  }, [productSerials, serialFilter]);

  // Stock total sumando todos los almacenes
  const totalStockAllWarehouses = useMemo(() => {
    if (!stockByWarehouse || stockByWarehouse.length === 0) {
      return Number(selectedProduct?.available_stock ?? 0);
    }
    return stockByWarehouse.reduce((acc, row) => acc + Number(row.available || 0), 0);
  }, [stockByWarehouse, selectedProduct]);

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent
        className="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-50 flex h-[92vh] max-h-[92vh] w-[96vw] max-w-7xl flex-col overflow-hidden rounded-2xl border border-border bg-surface p-0 shadow-2xl"
        onKeyDown={handleKeyDown}
        data-testid="product-search-detail-modal"
      >
        {/* Encabezado Superior */}
        <div className="flex shrink-0 items-center justify-between border-b border-border bg-bg/50 px-5 py-3.5">
          <div className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-xl border border-primary/30 bg-primary/10 text-primary">
              <Boxes className="size-5" />
            </div>
            <div>
              <h2 className="text-lg sm:text-xl font-bold text-text-primary tracking-tight">
                Búsqueda y Detalle de Productos
              </h2>
              <p className="text-sm text-text-muted">
                Consulta técnica, catálogo de precios e inventario multi-almacén
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2.5">
            {activeRate && (
              <Badge variant="outline" className="border-border bg-surface px-3 py-1.5 text-sm">
                Tasa: <span className="ml-1.5 font-bold text-primary">{activeRate.rate.toFixed(2)}</span>{' '}
                <span className="text-text-muted ml-1">({activeRate.name})</span>
              </Badge>
            )}
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg p-2 text-text-muted transition-colors hover:bg-bg hover:text-text-primary"
              aria-label="Cerrar ventana"
            >
              <X className="size-5.5" />
            </button>
          </div>
        </div>

        {/* Cuerpo Principal: 2 Columnas */}
        <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden divide-y divide-border md:grid-cols-12 md:divide-y-0 md:divide-x">
          {/* ============================================================== */}
          {/* COLUMNA IZQUIERDA: Búsqueda, Tabla de Resultados y Paginación */}
          {/* ============================================================== */}
          <div className="flex h-full flex-col overflow-hidden bg-bg/25 p-3.5 gap-3 md:col-span-5">
            {/* Controles de Búsqueda y Almacén */}
            <div className="flex flex-col gap-2.5">
              <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4.5 -translate-y-1/2 text-text-muted" />
                <Input
                  ref={searchInputRef}
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Buscar por código, SKU o nombre... (F3)"
                  className="h-11 pl-10 pr-9 text-base shadow-xs"
                  data-testid="search-modal-input"
                />
                {search.length > 0 && (
                  <button
                    type="button"
                    onClick={() => {
                      setSearch('');
                      searchInputRef.current?.focus();
                    }}
                    className="absolute top-1/2 right-3 -translate-y-1/2 text-text-muted hover:text-text-primary"
                    aria-label="Limpiar búsqueda"
                  >
                    <X className="size-4" />
                  </button>
                )}
              </div>

              <div className="flex items-center gap-2">
                <div className="flex min-w-0 flex-1 items-center gap-2 text-sm text-text-muted">
                  <Warehouse className="size-4 shrink-0 text-text-muted" />
                  <Select
                    ref={warehouseSelectRef}
                    value={warehouseId ?? ''}
                    onChange={(e) =>
                      onWarehouseChange(e.target.value ? Number(e.target.value) : null)
                    }
                    className="h-9.5 text-sm shadow-xs"
                    data-testid="search-modal-warehouse-select"
                  >
                    <option value="">Todos los almacenes</option>
                    {warehouses.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.code} - {w.name}
                      </option>
                    ))}
                  </Select>
                </div>
              </div>
            </div>

            {/* Tabla de Resultados */}
            <div
              ref={tableContainerRef}
              className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-xs"
            >
              {/* Encabezado fijo de la tabla */}
              <div className="grid grid-cols-12 border-b border-border bg-bg/60 px-3.5 py-2.5 text-xs sm:text-sm font-bold tracking-wider text-text-muted uppercase">
                <div className="col-span-4">Código / SKU</div>
                <div className="col-span-6">Descripción</div>
                <div className="col-span-2 text-right">Stock</div>
              </div>

              {/* Contenido scrolleable */}
              <div className="flex-1 overflow-y-auto divide-y divide-border/60">
                {loadingProducts ? (
                  <div className="flex h-48 flex-col items-center justify-center gap-2 text-text-muted">
                    <Loader2 className="size-6 animate-spin text-primary" />
                    <span className="text-sm">Cargando catálogo...</span>
                  </div>
                ) : products.length === 0 ? (
                  <div className="flex h-48 flex-col items-center justify-center gap-2 p-6 text-center text-text-muted">
                    <Package className="size-8 stroke-1 text-text-muted/60" />
                    <p className="text-sm">No se encontraron productos coincidentes.</p>
                  </div>
                ) : (
                  products.map((p, index) => {
                    const isSelected = index === selectedIndex;
                    const stock = Number(p.available_stock ?? 0);
                    const isLowStock = stock <= Number(p.min_stock ?? 0) && Number(p.min_stock ?? 0) > 0;

                    return (
                      <div
                        key={p.id}
                        data-row-index={index}
                        onClick={() => setSelectedIndex(index)}
                        onDoubleClick={handleConfirmSelect}
                        className={cn(
                          'grid cursor-pointer grid-cols-12 items-center px-3.5 py-2.5 text-sm transition-colors select-none',
                          isSelected
                            ? 'bg-primary/10 border-l-4 border-l-primary font-medium text-text-primary'
                            : 'hover:bg-bg/60 text-text-secondary',
                        )}
                        data-testid={`search-modal-row-${p.id}`}
                      >
                        <div className="col-span-4 font-mono text-xs sm:text-sm font-semibold text-text-primary truncate">
                          {p.sku || p.barcode || 'S/C'}
                        </div>
                        <div className="col-span-6 truncate pr-2" title={p.name}>
                          <span className={cn('text-sm', isSelected && 'font-bold text-text-primary text-sm sm:text-base')}>
                            {p.name}
                          </span>
                        </div>
                        <div className="col-span-2 flex justify-end">
                          <Badge
                            variant={stock > 0 ? (isLowStock ? 'warning' : 'success') : 'danger'}
                            className="px-2 py-0.5 text-xs font-mono font-bold leading-none"
                          >
                            {stock}
                          </Badge>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>
            </div>

            {/* Controles de Paginación */}
            <div className="flex shrink-0 items-center justify-between border-t border-border/80 pt-2.5 text-sm text-text-muted">
              <span>
                Pág. <strong className="text-text-primary">{page}</strong> de{' '}
                <strong className="text-text-primary">{totalPages}</strong> ({totalProducts} items)
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1 || loadingProducts}
                  className="h-8.5 px-3 text-sm font-medium"
                  aria-label="Página anterior"
                >
                  <ChevronLeft className="size-4" />
                  <span className="hidden sm:inline ml-1">Ant.</span>
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={page >= totalPages || loadingProducts}
                  className="h-8.5 px-3 text-sm font-medium"
                  aria-label="Página siguiente"
                >
                  <span className="hidden sm:inline mr-1">Sig.</span>
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            </div>
          </div>

          {/* ============================================================== */}
          {/* COLUMNA DERECHA: Detalle con Pestañas */}
          {/* ============================================================== */}
          <div className="flex h-full flex-col overflow-hidden bg-surface p-4 md:col-span-7">
            {!selectedProduct ? (
              <div className="flex h-full flex-col items-center justify-center gap-3 text-text-muted">
                <Package className="size-12 stroke-1 text-text-muted/40" />
                <p className="text-sm">Selecciona un producto para visualizar su información detallada.</p>
              </div>
            ) : (
              <div className="flex h-full flex-col overflow-hidden">
                {/* Cabecera del producto seleccionado */}
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-border pb-3.5">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <h3 className="truncate text-lg sm:text-2xl font-bold text-text-primary leading-tight">
                        {selectedProduct.name}
                      </h3>
                    </div>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2.5 text-sm sm:text-base text-text-muted">
                      <span className="font-mono font-semibold text-text-primary">
                        SKU: {selectedProduct.sku ?? 'N/A'}
                      </span>
                      <span>•</span>
                      <span className="font-mono">
                        Barras: {selectedProduct.barcode ?? 'N/A'}
                      </span>
                      {selectedProduct.brand?.name && (
                        <>
                          <span>•</span>
                          <span>Marca: {selectedProduct.brand.name}</span>
                        </>
                      )}
                      {selectedProduct.unit_of_measure && (
                        <>
                          <span>•</span>
                          <span className="uppercase font-medium">
                            Unidad: {selectedProduct.unit_of_measure}
                          </span>
                        </>
                      )}
                    </div>
                  </div>

                  <div className="flex shrink-0 flex-col items-end gap-1">
                    <Badge
                      variant={totalStockAllWarehouses > 0 ? 'success' : 'danger'}
                      className="px-3.5 py-1.5 text-sm sm:text-base font-bold shadow-xs"
                    >
                      {totalStockAllWarehouses > 0
                        ? `Stock: ${totalStockAllWarehouses} ${selectedProduct.unit_of_measure ?? 'UND'}`
                        : 'Sin existencia'}
                    </Badge>
                    <span className="text-xs sm:text-sm text-text-muted font-mono font-medium">
                      Ref: {money(selectedProduct.base_price)}
                    </span>
                  </div>
                </div>

                {/* Contenedor de Pestañas */}
                <Tabs
                  value={activeTab}
                  onValueChange={(v) => setActiveTab(v as TabKey)}
                  className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3"
                >
                  <TabsList className="grid w-full grid-cols-5 h-11 bg-bg/60 p-1 border border-border rounded-xl shrink-0">
                    <TabsTrigger value="precios" className="text-sm sm:text-base font-semibold py-1.5 px-2 truncate">
                      [F5] Precios
                    </TabsTrigger>
                    <TabsTrigger value="existencia" className="text-sm sm:text-base font-semibold py-1.5 px-2 truncate">
                      [F6] Existencia
                    </TabsTrigger>
                    <TabsTrigger value="datos" className="text-sm sm:text-base font-semibold py-1.5 px-2 truncate">
                      [F8] Datos
                    </TabsTrigger>
                    <TabsTrigger value="seriales" className="text-sm sm:text-base font-semibold py-1.5 px-2 truncate">
                      [F7] Seriales
                    </TabsTrigger>
                    <TabsTrigger value="lotes" className="text-sm sm:text-base font-semibold py-1.5 px-2 truncate">
                      Lotes
                    </TabsTrigger>
                  </TabsList>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 1: PRECIOS (TABLA COMPARATIVA PRECIO 1, 2 Y 3)       */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="precios"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3 space-y-3"
                  >
                    {/* Barra de Tasa, Factor de Empaque e IVA */}
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-bg/40 p-3 text-sm">
                      <div className="flex items-center gap-2.5">
                        <span className="font-semibold text-text-primary">Tasa activa:</span>
                        <Badge variant="outline" className="border-primary/40 bg-primary/5 px-2.5 py-1 font-mono text-sm font-bold text-primary">
                          {activeRate ? `1 USD = ${moneyVes(activeRate.rate)} (${activeRate.name})` : 'Sin tasa definida'}
                        </Badge>
                        <span className="text-text-muted font-medium">• IVA 16%</span>
                      </div>

                      <div className="flex items-center gap-2">
                        <span className="text-text-muted font-medium">Empaque:</span>
                        <div className="flex items-center rounded-lg border border-border bg-surface p-0.5">
                          {[1, 6, 12, 24].map((factor) => (
                            <button
                              key={factor}
                              type="button"
                              onClick={() => setPackageFactor(factor)}
                              className={cn(
                                'rounded px-3 py-1 text-sm font-bold transition-colors',
                                packageFactor === factor
                                    ? 'bg-primary text-primary-foreground'
                                  : 'text-text-muted hover:text-text-primary',
                              )}
                            >
                              x{factor}
                            </button>
                          ))}
                        </div>
                      </div>
                    </div>

                    {/* Tabla Comparativa de Precios */}
                    <div className="flex-1 overflow-auto rounded-xl border border-border bg-surface shadow-xs">
                      <table className="w-full text-left text-sm border-collapse">
                        <thead className="sticky top-0 z-10 border-b border-border bg-bg/80 backdrop-blur-xs text-xs sm:text-sm font-bold text-text-muted uppercase">
                          <tr>
                            <th className="py-3 px-3.5">Nivel / Lista de Precio</th>
                            <th className="py-3 px-2.5 text-right">USD Neto</th>
                            <th className="py-3 px-2.5 text-right">IVA (16%)</th>
                            <th className="py-3 px-3.5 text-right text-primary font-bold">USD c/Imp</th>
                            <th className="py-3 px-2.5 text-right">VES Neto</th>
                            <th className="py-3 px-2.5 text-right">IVA VES</th>
                            <th className="py-3 px-3.5 text-right text-primary font-bold">VES c/Imp</th>
                            <th className="py-3 px-3.5 text-right bg-primary/5">
                              x{packageFactor} {selectedProduct.unit_of_measure ?? 'UND'}
                            </th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                          {priceRows.map((row, idx) => (
                            <tr
                              key={row.id}
                              className={cn(
                                'transition-colors hover:bg-bg/40',
                                idx === 0 && 'bg-primary/5 font-medium',
                              )}
                            >
                              <td className="py-3 px-3.5 font-medium">
                                <div className="flex items-center gap-2">
                                  <span className="text-sm sm:text-base font-semibold">{row.label}</span>
                                  {row.isDefault && (
                                    <Badge variant="outline" className="text-xs px-1.5 py-0.5 border-primary/40 text-primary font-bold">
                                      Base
                                    </Badge>
                                  )}
                                </div>
                              </td>
                              <td className="py-3 px-2.5 text-right font-mono text-sm sm:text-base text-text-secondary">
                                {money(row.netUsd)}
                              </td>
                              <td className="py-3 px-2.5 text-right font-mono text-sm sm:text-base text-text-muted">
                                {money(row.taxUsd)}
                              </td>
                              <td className="py-3 px-3.5 text-right font-mono font-black text-base sm:text-lg text-text-primary">
                                {money(row.totalUsd)}
                              </td>
                              <td className="py-3 px-2.5 text-right font-mono text-sm sm:text-base text-text-secondary">
                                {moneyVes(row.netVes)}
                              </td>
                              <td className="py-3 px-2.5 text-right font-mono text-sm sm:text-base text-text-muted">
                                {moneyVes(row.taxVes)}
                              </td>
                              <td className="py-3 px-3.5 text-right font-mono font-black text-base sm:text-lg text-text-primary">
                                {moneyVes(row.totalVes)}
                              </td>
                              <td className="py-3 px-3.5 text-right font-mono font-black bg-primary/5 text-primary text-base sm:text-lg">
                                <div>{money(row.packageUsd)}</div>
                                <div className="text-xs sm:text-sm font-semibold text-text-muted">{moneyVes(row.packageVes)}</div>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    <div className="flex items-center justify-between text-xs sm:text-sm text-text-muted px-1">
                      <span>* Precios calculados con el 16% de IVA sobre el valor base de cada lista.</span>
                      {selectedPriceList && (
                        <span>
                          Lista actual del POS: <strong className="text-text-primary">{selectedPriceList.name}</strong>
                        </span>
                      )}
                    </div>
                  </TabsContent>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 2: EXISTENCIA (DESGLOSE POR ALMACÉN)                 */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="existencia"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3 space-y-3"
                  >
                    <div className="flex-1 overflow-auto rounded-xl border border-border bg-surface shadow-xs">
                      {loadingStock ? (
                        <div className="flex h-48 flex-col items-center justify-center gap-2 text-text-muted">
                          <Loader2 className="size-6 animate-spin text-primary" />
                          <span className="text-sm">Consultando existencias por almacén...</span>
                        </div>
                      ) : stockByWarehouse.length === 0 ? (
                        <div className="flex h-48 flex-col items-center justify-center gap-2 p-6 text-center text-text-muted">
                          <Warehouse className="size-8 stroke-1 text-text-muted/60" />
                          <p className="text-sm">No hay registros de inventario para este producto.</p>
                        </div>
                      ) : (
                        <table className="w-full text-left text-sm border-collapse">
                          <thead className="sticky top-0 z-10 border-b border-border bg-bg/80 backdrop-blur-xs text-xs sm:text-sm font-bold text-text-muted uppercase">
                            <tr>
                              <th className="py-3 px-3.5">Almacén</th>
                              <th className="py-3 px-3.5">Sucursal</th>
                              <th className="py-3 px-3.5 text-right">Disponible</th>
                              <th className="py-3 px-3.5 text-right">Reservado</th>
                              <th className="py-3 px-3.5 text-right">Dañado</th>
                              <th className="py-3 px-3.5 text-right font-black text-text-primary">Físico Total</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-border">
                            {stockByWarehouse.map((item) => {
                              const avail = Number(item.available || 0);
                              const res = Number(item.reserved || 0);
                              const dam = Number(item.damaged || 0);
                              const totalFisico = avail + res + dam;
                              const isCurrentWarehouse = warehouseId === item.warehouse_id;

                              return (
                                <tr
                                  key={item.warehouse_id}
                                  className={cn(
                                    'transition-colors hover:bg-bg/40',
                                    isCurrentWarehouse && 'bg-primary/5 font-medium',
                                  )}
                                >
                                  <td className="py-3 px-3.5">
                                    <div className="flex items-center gap-2 font-medium">
                                      <span className="text-sm sm:text-base font-semibold">{item.warehouse_name || `Almacén #${item.warehouse_id}`}</span>
                                      {item.warehouse_code && (
                                        <span className="font-mono text-xs text-text-muted">
                                          ({item.warehouse_code})
                                        </span>
                                      )}
                                      {isCurrentWarehouse && (
                                        <Badge variant="outline" className="text-xs px-1.5 py-0.5 border-primary/40 text-primary font-bold">
                                          Terminal
                                        </Badge>
                                      )}
                                    </div>
                                  </td>
                                  <td className="py-3 px-3.5 text-sm sm:text-base text-text-secondary">
                                    {item.branch_name || 'Principal'}
                                  </td>
                                  <td className="py-3 px-3.5 text-right font-mono">
                                    <Badge
                                      variant={avail > 0 ? 'success' : 'danger'}
                                      className="px-3 py-1 text-sm font-bold"
                                    >
                                      {avail}
                                    </Badge>
                                  </td>
                                  <td className="py-3 px-3.5 text-right font-mono text-sm sm:text-base text-text-muted">
                                    {res}
                                  </td>
                                  <td className="py-3 px-3.5 text-right font-mono text-sm sm:text-base text-text-muted">
                                    {dam}
                                  </td>
                                  <td className="py-3 px-3.5 text-right font-mono font-black text-base sm:text-lg text-text-primary">
                                    {totalFisico}
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      )}
                    </div>
                  </TabsContent>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 3: DATOS PRINCIPALES (DESCRIPCIONES, COSTOS, PARAMS)  */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="datos"
                    className="flex min-h-0 flex-1 flex-col overflow-y-auto mt-3 pr-1 space-y-4"
                  >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-[180px_1fr]">
                      {/* Imagen grande del producto */}
                      <div className="flex flex-col items-center self-start gap-2">
                        <ProductImageView
                          image={primaryProductImage(selectedProduct)}
                          src={selectedProduct.primary_image_url ?? selectedProduct.image_url ?? undefined}
                          alt={selectedProduct.name}
                          variant="medium"
                          fit="contain"
                          className="aspect-square w-full rounded-2xl border border-border bg-bg/50 p-2 shadow-xs"
                        />
                        <span className="text-xs sm:text-sm text-text-muted">
                          {selectedProduct.images?.length
                            ? `${selectedProduct.images.length} imágenes`
                            : 'Sin galería'}
                        </span>
                      </div>

                      {/* Especificaciones y Costos */}
                      <div className="space-y-3.5">
                        {/* Descripción corta y extendida */}
                        {selectedProduct.description && (
                          <div className="rounded-xl border border-border bg-bg/25 p-3.5">
                            <span className="text-xs sm:text-sm font-bold uppercase tracking-wider text-text-muted">
                              Descripción Corta
                            </span>
                            <p className="mt-1.5 text-sm sm:text-base text-text-primary leading-relaxed">
                              {selectedProduct.description}
                            </p>
                          </div>
                        )}

                        {selectedProduct.long_description && (
                          <div className="rounded-xl border border-border bg-bg/25 p-3.5">
                            <span className="text-xs sm:text-sm font-bold uppercase tracking-wider text-text-muted">
                              Descripción Detallada / Técnica
                            </span>
                            <p className="mt-1.5 max-h-36 overflow-y-auto whitespace-pre-wrap text-sm sm:text-base text-text-secondary leading-relaxed">
                              {stripHtml(selectedProduct.long_description)}
                            </p>
                          </div>
                        )}

                        {/* Parámetros de Stock y Parámetros Operativos */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                          <div className="rounded-xl border border-border bg-surface p-3">
                            <span className="text-xs sm:text-sm font-semibold uppercase text-text-muted">
                              Stock Mínimo
                            </span>
                            <p className="text-base sm:text-lg font-bold text-text-primary mt-1">
                              {selectedProduct.min_stock ?? 0}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-3">
                            <span className="text-xs sm:text-sm font-semibold uppercase text-text-muted">
                              Stock Máximo
                            </span>
                            <p className="text-base sm:text-lg font-bold text-text-primary mt-1">
                              {selectedProduct.max_stock ?? 'N/D'}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-3">
                            <span className="text-xs sm:text-sm font-semibold uppercase text-text-muted">
                              Punto Reorden
                            </span>
                            <p className="text-base sm:text-lg font-bold text-text-primary mt-1">
                              {selectedProduct.reorder_quantity ?? 'N/D'}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-3">
                            <span className="text-xs sm:text-sm font-semibold uppercase text-text-muted">
                              Control de Stock
                            </span>
                            <p className="text-base sm:text-lg font-bold text-text-primary mt-1">
                              {selectedProduct.track_stock ? 'Sí' : 'No'}
                            </p>
                          </div>
                        </div>

                        {/* Sección de Costos y Rentabilidad */}
                        <div className="rounded-xl border border-border bg-bg/30 p-3.5">
                          <div className="flex items-center justify-between mb-2.5">
                            <span className="text-xs sm:text-sm font-bold uppercase tracking-wider text-text-muted">
                              Costos y Rentabilidad
                            </span>
                            {selectedProduct.average_cost === null && (
                              <Badge variant="outline" className="text-xs text-text-muted gap-1 px-2 py-0.5">
                                <Lock className="size-3" /> Restringido
                              </Badge>
                            )}
                          </div>

                          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3.5">
                            <div>
                              <span className="text-sm text-text-muted">Último Costo Compra:</span>
                              <p className="text-base sm:text-lg font-bold text-text-primary font-mono mt-1">
                                {selectedProduct.last_purchase_cost !== null
                                  ? money(selectedProduct.last_purchase_cost)
                                  : '••••••'}
                              </p>
                            </div>
                            <div>
                              <span className="text-sm text-text-muted">Costo Promedio:</span>
                              <p className="text-base sm:text-lg font-bold text-text-primary font-mono mt-1">
                                {selectedProduct.average_cost !== null
                                  ? money(selectedProduct.average_cost)
                                  : '••••••'}
                              </p>
                            </div>
                            <div>
                              <span className="text-sm text-text-muted">Margen Ganancia:</span>
                              <p className="text-base sm:text-lg font-black text-primary font-mono mt-1">
                                {selectedProduct.profit_margin ? `${selectedProduct.profit_margin}%` : 'N/D'}
                              </p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </TabsContent>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 4: SERIALES / IMEIS                                   */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="seriales"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3 space-y-3"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div className="relative flex-1 max-w-sm">
                        <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-text-muted" />
                        <Input
                          id="serial-filter-input"
                          value={serialFilter}
                          onChange={(e) => setSerialFilter(e.target.value)}
                          placeholder="Filtrar número serial o IMEI..."
                          className="h-10 pl-9 text-sm shadow-xs"
                        />
                      </div>
                      <span className="text-sm sm:text-base text-text-muted">
                        Total disponibles: <strong className="text-text-primary">{filteredSerials.length}</strong>
                      </span>
                    </div>

                    <div className="flex-1 overflow-auto rounded-xl border border-border bg-surface shadow-xs">
                      {loadingSerials ? (
                        <div className="flex h-40 flex-col items-center justify-center gap-2 text-text-muted">
                          <Loader2 className="size-6 animate-spin text-primary" />
                          <span className="text-sm">Cargando seriales...</span>
                        </div>
                      ) : filteredSerials.length === 0 ? (
                        <div className="flex h-40 flex-col items-center justify-center gap-2 p-6 text-center text-text-muted">
                          <ShieldCheck className="size-8 stroke-1 text-text-muted/60" />
                          <p className="text-sm">
                            {selectedProduct.tracking_type === 'serialized'
                              ? 'No hay unidades serializadas disponibles en existencia.'
                              : 'Este producto no requiere control individual por serial o IMEI.'}
                          </p>
                        </div>
                      ) : (
                        <table className="w-full text-left text-sm border-collapse">
                          <thead className="sticky top-0 z-10 border-b border-border bg-bg/80 backdrop-blur-xs text-xs sm:text-sm font-bold text-text-muted uppercase">
                            <tr>
                              <th className="py-3 px-3.5">Número Serial / IMEI</th>
                              <th className="py-3 px-3.5">Tipo</th>
                              <th className="py-3 px-3.5">Almacén</th>
                              <th className="py-3 px-3.5 text-right">Estado</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-border">
                            {filteredSerials.map((serial) => (
                              <tr key={serial.id} className="transition-colors hover:bg-bg/40">
                                <td className="py-3 px-3.5 font-mono font-bold text-text-primary text-sm sm:text-base">
                                  {serial.serial_number}
                                </td>
                                <td className="py-3 px-3.5 uppercase text-text-secondary text-xs sm:text-sm font-medium">
                                  {serial.serial_type || 'IMEI'}
                                </td>
                                <td className="py-3 px-3.5 text-text-secondary text-sm sm:text-base">
                                  {serial.warehouse_name || 'Principal'}
                                </td>
                                <td className="py-3 px-3.5 text-right">
                                  <Badge
                                    variant={serial.status === 'available' ? 'success' : 'outline'}
                                    className="text-xs uppercase font-semibold px-2.5 py-1"
                                  >
                                    {serial.status === 'available' ? 'Disponible' : serial.status}
                                  </Badge>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </div>
                  </TabsContent>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 5: LOTES Y TRAZABILIDAD                              */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="lotes"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3 space-y-3"
                  >
                    <div className="flex h-full flex-col items-center justify-center gap-3.5 p-8 text-center text-text-muted rounded-xl border border-dashed border-border bg-bg/20">
                      <Calendar className="size-11 stroke-1 text-text-muted/60" />
                      <div>
                        <h4 className="text-base sm:text-lg font-bold text-text-primary">
                          Trazabilidad de Lotes y Vencimientos
                        </h4>
                        <p className="mt-1.5 max-w-md text-sm leading-relaxed text-text-muted">
                          Este producto opera con inventario continuo estándar sin fechas de caducidad
                          o lotes perecederos asignados.
                        </p>
                      </div>
                      <Badge variant="outline" className="text-xs sm:text-sm px-3.5 py-1">
                        Control General por Unidad ({selectedProduct.unit_of_measure ?? 'UND'})
                      </Badge>
                    </div>
                  </TabsContent>
                </Tabs>
              </div>
            )}
          </div>
        </div>

        {/* ============================================================== */}
        {/* PIE DE VENTANA: Barra de Atajos F2..F8 y Botones de Acción     */}
        {/* ============================================================== */}
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border bg-bg/75 px-5 py-3.5">
          {/* Pills de atajos de teclado F2 al F8 */}
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={handleConfirmSelect}
              className="flex items-center gap-2 rounded-xl border border-border bg-surface px-3 py-1.5 text-sm text-text-secondary transition-colors hover:border-primary hover:text-text-primary"
            >
              <kbd className="rounded bg-primary/10 border border-primary/30 px-2 py-0.5 font-mono text-xs font-bold text-primary">
                F2
              </kbd>
              <span>Aceptar / Agregar</span>
            </button>

            <button
              type="button"
              onClick={() => {
                searchInputRef.current?.focus();
                searchInputRef.current?.select();
              }}
              className="flex items-center gap-2 rounded-xl border border-border bg-surface px-3 py-1.5 text-sm text-text-secondary transition-colors hover:border-primary hover:text-text-primary"
            >
              <kbd className="rounded bg-bg border border-border px-2 py-0.5 font-mono text-xs font-bold text-text-muted">
                F3
              </kbd>
              <span>Buscar</span>
            </button>

            <button
              type="button"
              onClick={() => warehouseSelectRef.current?.focus()}
              className="flex items-center gap-2 rounded-xl border border-border bg-surface px-3 py-1.5 text-sm text-text-secondary transition-colors hover:border-primary hover:text-text-primary"
            >
              <kbd className="rounded bg-bg border border-border px-2 py-0.5 font-mono text-xs font-bold text-text-muted">
                F4
              </kbd>
              <span>Almacén</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('precios')}
              className={cn(
                'flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition-colors',
                activeTab === 'precios'
                  ? 'border-primary bg-primary/10 text-primary font-semibold'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg border border-border px-2 py-0.5 font-mono text-xs font-bold text-text-muted">
                F5
              </kbd>
              <span>Precios</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('existencia')}
              className={cn(
                'flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition-colors',
                activeTab === 'existencia'
                  ? 'border-primary bg-primary/10 text-primary font-semibold'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg border border-border px-2 py-0.5 font-mono text-xs font-bold text-text-muted">
                F6
              </kbd>
              <span>Existencia</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('seriales')}
              className={cn(
                'flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition-colors',
                activeTab === 'seriales'
                  ? 'border-primary bg-primary/10 text-primary font-semibold'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg border border-border px-2 py-0.5 font-mono text-xs font-bold text-text-muted">
                F7
              </kbd>
              <span>Seriales/Lotes</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('datos')}
              className={cn(
                'flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition-colors',
                activeTab === 'datos'
                  ? 'border-primary bg-primary/10 text-primary font-semibold'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg border border-border px-2 py-0.5 font-mono text-xs font-bold text-text-muted">
                F8
              </kbd>
              <span>Datos</span>
            </button>
          </div>

          {/* Botones Salir y Aceptar */}
          <div className="flex items-center gap-2.5">
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              className="h-10 px-5 text-sm font-bold"
              data-testid="search-modal-cancel-btn"
            >
              Salir <span className="ml-1 text-xs text-text-muted font-mono">(Esc)</span>
            </Button>
            <Button
              type="button"
              onClick={handleConfirmSelect}
              disabled={!selectedProduct}
              className="h-10 px-6 text-sm sm:text-base font-bold gap-2 shadow-sm"
              data-testid="search-modal-accept-btn"
            >
              <Check className="size-4.5" />
              <span>Aceptar</span>
              <span className="ml-1 text-xs opacity-80 font-mono">(Enter/F2)</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
