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
  Folder,
  Loader2,
  Lock,
  Package,
  PanelRightClose,
  PanelRightOpen,
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
  useProductSerials,
  useProductStockByWarehouse,
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

type TabKey = 'datos' | 'existencia' | 'precios' | 'seriales';

const PAGE_SIZE = 10;
const IVA_RATE = 0.16; // 16% IVA Venezuela

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

/**
 * Obtiene el nombre formateado de la categoría o categorías de un producto
 */
function getProductCategory(product: Product | null | undefined): string {
  if (!product) return 'Sin categoría';
  if (product.categories && product.categories.length > 0) {
    const names = product.categories
      .map((c) => c.full_path || c.name)
      .filter(Boolean);
    if (names.length > 0) return names.join(', ');
  }
  const anyProd = product as unknown as {
    category?: { name?: string; full_path?: string } | string;
    category_name?: string;
  };
  if (typeof anyProd.category === 'string' && anyProd.category.trim()) {
    return anyProd.category.trim();
  }
  if (anyProd.category && typeof anyProd.category === 'object' && anyProd.category.name) {
    return anyProd.category.full_path || anyProd.category.name;
  }
  if (typeof anyProd.category_name === 'string' && anyProd.category_name.trim()) {
    return anyProd.category_name.trim();
  }
  return 'Sin categoría';
}

interface PriceTableRow {
  id: string | number;
  label: string;
  code?: string;
  isDefault?: boolean;
  costBase: number;
  costWithIva: number;
  netUsd: number;
  taxUsd: number;
  totalUsd: number;
  netVes: number;
  taxVes: number;
  totalVes: number;
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
  const [serialFilter, setSerialFilter] = useState('');
  const [detailOpen, setDetailOpen] = useState<boolean>(() => {
    if (typeof window === 'undefined') return true;

    try {
      return window.localStorage.getItem('pos.f3.detailOpen') !== 'false';
    } catch {
      return true;
    }
  });

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

  // Categoría formateada del producto seleccionado
  const selectedCategory = useMemo(
    () => getProductCategory(selectedProduct),
    [selectedProduct],
  );

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

  // Persistir la preferencia del panel de detalle entre aperturas del modal
  useEffect(() => {
    if (typeof window === 'undefined') return;

    try {
      window.localStorage.setItem('pos.f3.detailOpen', detailOpen ? 'true' : 'false');
    } catch {
      // localStorage puede no estar disponible (modo privado o SSR).
    }
  }, [detailOpen]);

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

    const cost = Number(selectedProduct.last_purchase_cost ?? selectedProduct.average_cost ?? 0);
    const costBase = cost > 0 ? Math.round(cost * 100) / 100 : 0;
    const costWithIva = costBase > 0 ? Math.round(costBase * (1 + IVA_RATE) * 100) / 100 : 0;

    const rows: PriceTableRow[] = [];

    // Helper para armar fila considerando que los precios del sistema ya incluyen IVA
    const buildRow = (
      id: string | number,
      label: string,
      usdPriceWithTax: number,
      code?: string,
      isDefault?: boolean,
    ): PriceTableRow => {
      // El precio del sistema ya es el precio con impuesto (PVP)
      const totalUsd = Math.max(0, Math.round(Number(usdPriceWithTax || 0) * 100) / 100);
      // Base imponible desglosando el 16% de IVA
      const netUsd = totalUsd > 0 ? Math.round((totalUsd / (1 + IVA_RATE)) * 100) / 100 : 0;
      // Impuesto correspondiente al desglose
      const taxUsd = totalUsd > 0 ? Math.round((totalUsd - netUsd) * 100) / 100 : 0;

      // En moneda local (VES): precio final con impuesto convertido a la tasa activa
      const totalVes = rateVal > 0 && totalUsd > 0 ? Math.round(totalUsd * rateVal * 100) / 100 : 0;
      // Base imponible en VES desglosando el 16% de IVA
      const netVes = totalVes > 0 ? Math.round((totalVes / (1 + IVA_RATE)) * 100) / 100 : 0;
      // Impuesto en VES correspondiente al desglose
      const taxVes = totalVes > 0 ? Math.round((totalVes - netVes) * 100) / 100 : 0;

      return {
        id,
        label,
        code,
        isDefault,
        costBase,
        costWithIva,
        netUsd,
        taxUsd,
        totalUsd,
        netVes,
        taxVes,
        totalVes,
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
  }, [selectedProduct, priceLists, activeRate]);

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
          const target = event.target as HTMLElement | null;
          const isCancelBtn = Boolean(
            target?.closest(
              '[data-testid="search-modal-cancel-btn"], [data-action="cancel"], button[aria-label="Cerrar ventana"]',
            ),
          );
          if (isCancelBtn) {
            event.preventDefault();
            onClose();
          } else if (target?.tagName !== 'SELECT') {
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

  // Captura global de teclado mientras el modal esté abierto:
  // Garantiza que Enter agregue el producto aunque el usuario haya hecho clic en pestañas,
  // botones de empaque, tablas o cualquier parte de la columna derecha.
  useEffect(() => {
    if (!open) return;

    const onGlobalKeyDown = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;

      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        onClose();
        return;
      }

      if (event.key === 'F2') {
        event.preventDefault();
        event.stopPropagation();
        handleConfirmSelect();
        return;
      }

      if (event.key === 'F3') {
        event.preventDefault();
        event.stopPropagation();
        searchInputRef.current?.focus();
        searchInputRef.current?.select();
        return;
      }

      if (event.key === 'F4') {
        event.preventDefault();
        event.stopPropagation();
        warehouseSelectRef.current?.focus();
        return;
      }

      if (event.key === 'F5') {
        event.preventDefault();
        event.stopPropagation();
        setActiveTab('precios');
        return;
      }

      if (event.key === 'F6') {
        event.preventDefault();
        event.stopPropagation();
        setActiveTab('existencia');
        return;
      }

      if (event.key === 'F7') {
        event.preventDefault();
        event.stopPropagation();
        setActiveTab('seriales');
        return;
      }

      if (event.key === 'F8') {
        event.preventDefault();
        event.stopPropagation();
        setActiveTab('datos');
        return;
      }

      if (event.key === 'ArrowDown') {
        if (target?.tagName !== 'SELECT' && products.length > 0) {
          event.preventDefault();
          event.stopPropagation();
          setSelectedIndex((curr) => (curr + 1 < products.length ? curr + 1 : curr));
        }
        return;
      }

      if (event.key === 'ArrowUp') {
        if (target?.tagName !== 'SELECT' && products.length > 0) {
          event.preventDefault();
          event.stopPropagation();
          setSelectedIndex((curr) => (curr > 0 ? curr - 1 : 0));
        }
        return;
      }

      if (event.key === 'PageDown') {
        if (page < totalPages) {
          event.preventDefault();
          event.stopPropagation();
          setPage((p) => p + 1);
        }
        return;
      }

      if (event.key === 'PageUp') {
        if (page > 1) {
          event.preventDefault();
          event.stopPropagation();
          setPage((p) => p - 1);
        }
        return;
      }

      if (event.key === 'Enter') {
        const isCancelBtn = Boolean(
          target?.closest(
            '[data-testid="search-modal-cancel-btn"], [data-action="cancel"], button[aria-label="Cerrar ventana"]',
          ),
        );
        if (isCancelBtn) {
          event.preventDefault();
          event.stopPropagation();
          onClose();
          return;
        }

        if (target?.tagName !== 'SELECT') {
          event.preventDefault();
          event.stopPropagation();
          handleConfirmSelect();
          return;
        }
      }
    };

    window.addEventListener('keydown', onGlobalKeyDown, true);
    return () => {
      window.removeEventListener('keydown', onGlobalKeyDown, true);
    };
  }, [open, onClose, handleConfirmSelect, products.length, page, totalPages]);

  // Seriales filtrados
  const filteredSerials = useMemo(() => {
    if (!productSerials || !Array.isArray(productSerials)) return [];
    if (!serialFilter.trim()) return productSerials;
    const q = serialFilter.toLowerCase();
    return productSerials.filter((s) => s.serial_number?.toLowerCase().includes(q));
  }, [productSerials, serialFilter]);

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent
        className="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-50 flex h-[88vh] max-h-[88vh] w-[94vw] max-w-[1500px] flex-col overflow-hidden rounded-2xl border border-border bg-surface p-0 shadow-2xl"
        onKeyDown={handleKeyDown}
        data-testid="product-search-detail-modal"
      >
        {/* Barra superior: búsqueda y controles */}
        <div className="flex shrink-0 flex-wrap items-center gap-2.5 border-b border-border bg-bg/40 px-4 py-2.5">
          <div className="hidden items-center gap-2.5 md:flex">
            <div className="flex size-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <Boxes className="size-5" />
            </div>
            <h2 className="text-base font-bold tracking-tight text-text-primary xl:hidden">
              Productos
            </h2>
            <h2 className="hidden text-base font-bold tracking-tight text-text-primary xl:block">
              Búsqueda y Detalle de Productos
            </h2>
          </div>

          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4.5 -translate-y-1/2 text-text-muted" />
            <Input
              ref={searchInputRef}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar por código, SKU o nombre... (F3)"
              className="h-10 pl-10 pr-9 text-base shadow-xs"
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

          <div className="flex items-center gap-2 text-sm text-text-muted sm:w-56">
            <Warehouse className="size-4 shrink-0 text-text-muted" />
            <Select
              ref={warehouseSelectRef}
              value={warehouseId ?? ''}
              onChange={(e) =>
                onWarehouseChange(e.target.value ? Number(e.target.value) : null)
              }
              className="h-10 flex-1 text-sm shadow-xs"
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

          {activeRate && (
            <span className="hidden items-center gap-1.5 rounded-full border border-border bg-surface px-3.5 py-1.5 text-sm shadow-2xs lg:flex">
              <span className="text-text-muted">Tasa</span>
              <span className="font-bold text-primary">{activeRate.rate.toFixed(2)}</span>
              <span className="text-text-muted">({activeRate.name})</span>
            </span>
          )}

          <button
            type="button"
            onClick={() => setDetailOpen((value) => !value)}
            className="hidden items-center gap-1.5 rounded-xl border border-border bg-surface px-2.5 py-2 text-xs font-medium text-text-secondary transition-colors hover:border-primary hover:text-text-primary lg:flex"
            aria-label={detailOpen ? 'Ocultar detalle' : 'Mostrar detalle'}
            data-testid="search-modal-detail-toggle"
          >
            {detailOpen ? (
              <PanelRightClose className="size-4.5" />
            ) : (
              <PanelRightOpen className="size-4.5" />
            )}
            <span>{detailOpen ? 'Ocultar detalle' : 'Mostrar detalle'}</span>
          </button>

          <button
            type="button"
            onClick={onClose}
            className="rounded-xl p-2 text-text-muted transition-colors hover:bg-bg hover:text-text-primary"
            aria-label="Cerrar ventana"
          >
            <X className="size-5.5" />
          </button>
        </div>

        {/* Cuerpo Principal: lista de productos + panel de detalle */}
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden lg:flex-row">
          {/* ============================================================== */}
          {/* COLUMNA IZQUIERDA: Tabla de Resultados y Paginación */}
          {/* ============================================================== */}
          <div className="flex h-full min-h-0 flex-1 flex-col gap-3 overflow-hidden p-4">
            {/* Tabla de Resultados */}
            <div
              ref={tableContainerRef}
              className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-border/70 bg-surface"
            >
              {/* Encabezado fijo de la tabla */}
              <div className="grid grid-cols-12 gap-2 border-b border-border/70 bg-bg/40 px-4 py-3 text-[11px] font-semibold tracking-wider text-text-muted uppercase">
                <div className="col-span-4">Código / SKU</div>
                <div className="col-span-6">Descripción</div>
                <div className="col-span-2 text-right">Stock</div>
              </div>

              {/* Contenido scrolleable */}
              <div className="flex-1 overflow-y-auto p-2">
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
                  <div className="flex flex-col gap-1">
                    {products.map((p, index) => {
                      const isSelected = index === selectedIndex;
                      const stock = Number(p.available_stock ?? 0);
                      const isLowStock =
                        stock <= Number(p.min_stock ?? 0) && Number(p.min_stock ?? 0) > 0;

                      return (
                        <div
                          key={p.id}
                          data-row-index={index}
                          onClick={() => {
                            setSelectedIndex(index);
                            setDetailOpen(true);
                          }}
                          onDoubleClick={handleConfirmSelect}
                          className={cn(
                            'grid cursor-pointer grid-cols-12 items-center gap-2 rounded-xl px-3 py-3 text-sm transition-colors select-none',
                            isSelected
                              ? 'bg-primary/10 ring-1 ring-primary/40'
                              : 'hover:bg-bg/60',
                          )}
                          data-testid={`search-modal-row-${p.id}`}
                        >
                          <div className="col-span-4 truncate font-mono text-xs font-semibold text-text-primary sm:text-sm">
                            {p.sku || p.barcode || 'S/C'}
                          </div>
                          <div className="col-span-6 min-w-0" title={p.name}>
                            <div
                              className={cn(
                                'truncate text-sm',
                                isSelected ? 'font-bold text-text-primary' : 'text-text-secondary',
                              )}
                            >
                              {p.name}
                            </div>
                            <div className="mt-1 flex items-center gap-1.5">
                              <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                                <Folder className="size-2.5 shrink-0" />
                                <span className="truncate">{getProductCategory(p)}</span>
                              </span>
                              {p.brand?.name && (
                                <span className="truncate text-[10px] text-text-muted">
                                  {p.brand.name}
                                </span>
                              )}
                            </div>
                          </div>
                          <div className="col-span-2 flex justify-end">
                            <Badge
                              variant={stock > 0 ? (isLowStock ? 'warning' : 'success') : 'danger'}
                              className="px-2.5 py-1 text-xs font-mono font-bold leading-none tabular-nums"
                            >
                              {stock}
                            </Badge>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>

            {/* Controles de Paginación */}
            <div className="flex shrink-0 items-center justify-between rounded-2xl border border-border/70 bg-bg/30 px-4 py-2.5 text-sm text-text-muted">
              <span>
                Pág. <strong className="text-text-primary">{page}</strong> de{' '}
                <strong className="text-text-primary">{totalPages}</strong> · {totalProducts} items
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1 || loadingProducts}
                  className="h-9 gap-1 rounded-xl px-3 text-sm font-medium"
                  aria-label="Página anterior"
                >
                  <ChevronLeft className="size-4" />
                  <span>Ant.</span>
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  disabled={page >= totalPages || loadingProducts}
                  className="h-9 gap-1 rounded-xl px-3 text-sm font-medium"
                  aria-label="Página siguiente"
                >
                  <span>Sig.</span>
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            </div>
          </div>

          {/* ============================================================== */}
          {/* COLUMNA DERECHA: Detalle con Pestañas */}
          {/* ============================================================== */}
          {detailOpen ? (
            <div className="flex h-full min-h-0 w-full shrink-0 flex-col overflow-hidden border-t border-border bg-bg/20 p-4 lg:w-[58%] lg:border-t-0 lg:border-l xl:w-[55%]">
            {!selectedProduct ? (
              <div className="flex h-full flex-col items-center justify-center gap-3 text-text-muted">
                <Package className="size-12 stroke-1 text-text-muted/40" />
                <p className="text-sm">Selecciona un producto para visualizar su información detallada.</p>
              </div>
            ) : (
              <div className="flex h-full flex-col overflow-hidden">
                {/* Cabecera del producto seleccionado */}
                <div className="shrink-0 pb-4">
                  <h3 className="truncate text-xl font-bold tracking-tight text-text-primary">
                    {selectedProduct.name}
                  </h3>

                  <div className="mt-2.5 flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-xs text-text-muted">
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                      <Folder className="size-3.5 shrink-0" />
                      <span className="truncate">Categoría: {selectedCategory}</span>
                    </span>
                    {selectedProduct.brand?.name && (
                      <span className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2.5 py-1 font-medium text-text-secondary">
                        Marca:{' '}
                        <strong className="font-semibold text-text-primary">
                          {selectedProduct.brand.name}
                        </strong>
                      </span>
                    )}
                    <span className="font-mono">SKU: {selectedProduct.sku ?? 'N/A'}</span>
                    <span>•</span>
                    <span className="font-mono">Barras: {selectedProduct.barcode ?? 'N/A'}</span>
                    <span>•</span>
                    <span className="uppercase">
                      Unidad: {selectedProduct.unit_of_measure ?? 'UND'}
                    </span>
                  </div>
                </div>

                {/* Contenedor de Pestañas */}
                <Tabs
                  value={activeTab}
                  onValueChange={(v) => setActiveTab(v as TabKey)}
                  className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3"
                >
                  <TabsList className="grid h-10 w-full shrink-0 grid-cols-4 gap-1 rounded-full bg-bg/50 p-1">
                    <TabsTrigger value="precios" className="rounded-full text-xs py-1.5 px-2 truncate">
                      [F5] Precios
                    </TabsTrigger>
                    <TabsTrigger value="existencia" className="rounded-full text-xs py-1.5 px-2 truncate">
                      [F6] Existencia
                    </TabsTrigger>
                    <TabsTrigger value="datos" className="rounded-full text-xs py-1.5 px-2 truncate">
                      [F8] Datos
                    </TabsTrigger>
                    <TabsTrigger value="seriales" className="rounded-full text-xs py-1.5 px-2 truncate">
                      [F7] Seriales/Lotes
                    </TabsTrigger>
                  </TabsList>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 1: PRECIOS (TABLA COMPARATIVA PRECIO 1, 2 Y 3)       */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="precios"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3 space-y-3"
                  >
                    {/* Barra de Tasa e IVA */}
                    <div className="flex flex-wrap items-center gap-2.5 rounded-2xl border border-border/70 bg-bg/40 px-4 py-3 text-xs">
                      <span className="font-semibold text-text-primary">Tasa activa</span>
                      <span className="rounded-full border border-primary/40 bg-primary/5 px-3 py-1 font-mono font-semibold text-primary">
                        {activeRate ? `1 USD = ${moneyVes(activeRate.rate)} (${activeRate.name})` : 'Sin tasa definida'}
                      </span>
                      <span className="text-text-muted">IVA 16% incluido</span>
                    </div>

                    {/* Tabla Comparativa de Precios */}
                    <div className="flex-1 overflow-auto rounded-2xl border border-border/70 bg-surface">
                      <table className="w-full text-left text-xs border-collapse">
                        <thead className="sticky top-0 z-10 border-b border-border/70 bg-bg/80 backdrop-blur-xs text-[11px] font-semibold text-text-muted uppercase">
                          <tr>
                            <th className="px-4 py-3">Nivel / Lista de Precio</th>
                            <th className="px-3 py-3 text-right">Costo Compra</th>
                            <th className="px-3 py-3 text-right">Costo c/IVA</th>
                            <th className="px-3 py-3 text-right text-primary font-bold">PVP USD</th>
                            <th className="px-3 py-3 text-right text-primary font-bold">PVP VES</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-border/70">
                          {priceRows.map((row, idx) => (
                            <tr
                              key={row.id}
                              className={cn(
                                'transition-colors hover:bg-bg/40',
                                idx === 0 && 'bg-primary/5',
                              )}
                            >
                              <td className="px-4 py-3.5 font-medium">
                                <div className="flex items-center gap-2">
                                  <span>{row.label}</span>
                                  {row.isDefault && (
                                    <Badge
                                      variant="outline"
                                      className="border-primary/40 px-1.5 py-0 text-[9px] text-primary"
                                    >
                                      Base
                                    </Badge>
                                  )}
                                </div>
                              </td>
                              <td className="px-3 py-3.5 text-right font-mono font-bold text-text-muted">
                                {row.costBase > 0 ? money(row.costBase) : '—'}
                              </td>
                              <td className="px-3 py-3.5 text-right font-mono text-text-muted">
                                {row.costWithIva > 0 ? money(row.costWithIva) : '—'}
                              </td>
                              <td className="px-3 py-3.5 text-right font-mono font-bold text-text-primary text-sm">
                                {money(row.totalUsd)}
                              </td>
                              <td className="px-3 py-3.5 text-right font-mono font-bold text-text-primary">
                                {moneyVes(row.totalVes)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    <div className="flex items-center justify-between text-[11px] text-text-muted px-1">
                      <span>* Precios con 16% de IVA incluido. Las columnas netas reflejan la base imponible desglosada.</span>
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
                          <span className="text-xs">Consultando existencias por almacén...</span>
                        </div>
                      ) : stockByWarehouse.length === 0 ? (
                        <div className="flex h-48 flex-col items-center justify-center gap-2 p-6 text-center text-text-muted">
                          <Warehouse className="size-8 stroke-1 text-text-muted/60" />
                          <p className="text-xs">No hay registros de inventario para este producto.</p>
                        </div>
                      ) : (
                        <table className="w-full text-left text-xs border-collapse">
                          <thead className="sticky top-0 z-10 border-b border-border bg-bg/80 backdrop-blur-xs text-[11px] font-semibold text-text-muted uppercase">
                            <tr>
                              <th className="py-2.5 px-3">Almacén</th>
                              <th className="py-2.5 px-3">Sucursal</th>
                              <th className="py-2.5 px-3 text-right">Disponible</th>
                              <th className="py-2.5 px-3 text-right">Reservado</th>
                              <th className="py-2.5 px-3 text-right">Dañado</th>
                              <th className="py-2.5 px-3 text-right font-bold text-text-primary">Físico Total</th>
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
                                  <td className="py-2.5 px-3">
                                    <div className="flex items-center gap-1.5 font-medium">
                                      <span>{item.warehouse_name || `Almacén #${item.warehouse_id}`}</span>
                                      {item.warehouse_code && (
                                        <span className="font-mono text-[10px] text-text-muted">
                                          ({item.warehouse_code})
                                        </span>
                                      )}
                                      {isCurrentWarehouse && (
                                        <Badge variant="outline" className="text-[9px] px-1 py-0 border-primary/40 text-primary">
                                          Terminal
                                        </Badge>
                                      )}
                                    </div>
                                  </td>
                                  <td className="py-2.5 px-3 text-text-secondary">
                                    {item.branch_name || 'Principal'}
                                  </td>
                                  <td className="py-2.5 px-3 text-right font-mono">
                                    <Badge
                                      variant={avail > 0 ? 'success' : 'danger'}
                                      className="px-2 py-0.5 text-xs font-semibold"
                                    >
                                      {avail}
                                    </Badge>
                                  </td>
                                  <td className="py-2.5 px-3 text-right font-mono text-text-muted">
                                    {res}
                                  </td>
                                  <td className="py-2.5 px-3 text-right font-mono text-text-muted">
                                    {dam}
                                  </td>
                                  <td className="py-2.5 px-3 text-right font-mono font-bold text-text-primary">
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
                        <span className="text-[11px] text-text-muted">
                          {selectedProduct.images?.length
                            ? `${selectedProduct.images.length} imágenes`
                            : 'Sin galería'}
                        </span>
                      </div>

                      {/* Especificaciones y Costos */}
                      <div className="space-y-3.5">
                        {/* Descripción corta y extendida */}
                        {selectedProduct.description && (
                          <div className="rounded-xl border border-border bg-bg/25 p-3">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-text-muted">
                              Descripción Corta
                            </span>
                            <p className="mt-1 text-xs text-text-primary leading-relaxed">
                              {selectedProduct.description}
                            </p>
                          </div>
                        )}

                        {selectedProduct.long_description && (
                          <div className="rounded-xl border border-border bg-bg/25 p-3">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-text-muted">
                              Descripción Detallada / Técnica
                            </span>
                            <p className="mt-1 max-h-32 overflow-y-auto whitespace-pre-wrap text-xs text-text-secondary leading-relaxed">
                              {stripHtml(selectedProduct.long_description)}
                            </p>
                          </div>
                        )}

                        {/* Clasificación Principal: Categoría, Marca, Unidad */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                          <div className="rounded-xl border border-primary/25 bg-primary/5 p-2.5">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-primary">
                              Categoría
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5 truncate" title={selectedCategory}>
                              {selectedCategory}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-2.5">
                            <span className="text-[10px] font-semibold uppercase text-text-muted">
                              Marca
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5 truncate">
                              {selectedProduct.brand?.name ?? 'Sin marca'}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-2.5">
                            <span className="text-[10px] font-semibold uppercase text-text-muted">
                              Unidad de Medida
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5 uppercase">
                              {selectedProduct.unit_of_measure ?? 'UND'}
                            </p>
                          </div>
                        </div>

                        {/* Parámetros de Stock y Parámetros Operativos */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                          <div className="rounded-xl border border-border bg-surface p-2.5">
                            <span className="text-[10px] font-semibold uppercase text-text-muted">
                              Stock Mínimo
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5">
                              {selectedProduct.min_stock ?? 0}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-2.5">
                            <span className="text-[10px] font-semibold uppercase text-text-muted">
                              Stock Máximo
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5">
                              {selectedProduct.max_stock ?? 'N/D'}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-2.5">
                            <span className="text-[10px] font-semibold uppercase text-text-muted">
                              Punto Reorden
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5">
                              {selectedProduct.reorder_quantity ?? 'N/D'}
                            </p>
                          </div>
                          <div className="rounded-xl border border-border bg-surface p-2.5">
                            <span className="text-[10px] font-semibold uppercase text-text-muted">
                              Control de Stock
                            </span>
                            <p className="text-sm font-bold text-text-primary mt-0.5">
                              {selectedProduct.track_stock ? 'Sí' : 'No'}
                            </p>
                          </div>
                        </div>

                        {/* Sección de Costos y Rentabilidad */}
                        <div className="rounded-xl border border-border bg-bg/30 p-3">
                          <div className="flex items-center justify-between mb-2">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-text-muted">
                              Costos y Rentabilidad
                            </span>
                            {selectedProduct.average_cost === null && (
                              <Badge variant="outline" className="text-[10px] text-text-muted gap-1">
                                <Lock className="size-3" /> Restringido
                              </Badge>
                            )}
                          </div>

                          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div>
                              <span className="text-xs text-text-muted">Último Costo Compra:</span>
                              <p className="text-sm font-bold text-text-primary font-mono mt-0.5">
                                {selectedProduct.last_purchase_cost !== null
                                  ? money(selectedProduct.last_purchase_cost)
                                  : '••••••'}
                              </p>
                            </div>
                            <div>
                              <span className="text-xs text-text-muted">Costo Promedio:</span>
                              <p className="text-sm font-bold text-text-primary font-mono mt-0.5">
                                {selectedProduct.average_cost !== null
                                  ? money(selectedProduct.average_cost)
                                  : '••••••'}
                              </p>
                            </div>
                            <div>
                              <span className="text-xs text-text-muted">Margen Ganancia:</span>
                              <p className="text-sm font-bold text-primary font-mono mt-0.5">
                                {selectedProduct.profit_margin ? `${selectedProduct.profit_margin}%` : 'N/D'}
                              </p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </TabsContent>

                  {/* -------------------------------------------------------- */}
                  {/* TAB 4: SERIALES / IMEIS Y LOTES                           */}
                  {/* -------------------------------------------------------- */}
                  <TabsContent
                    value="seriales"
                    className="flex min-h-0 flex-1 flex-col overflow-hidden mt-3 space-y-3"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div className="relative flex-1 max-w-sm">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-text-muted" />
                        <Input
                          id="serial-filter-input"
                          value={serialFilter}
                          onChange={(e) => setSerialFilter(e.target.value)}
                          placeholder="Filtrar número serial o IMEI..."
                          className="h-10 pl-8 text-xs"
                        />
                      </div>
                      <span className="text-xs text-text-muted">
                        Total disponibles: <strong className="text-text-primary">{filteredSerials.length}</strong>
                      </span>
                    </div>

                    <div className="flex-1 overflow-auto rounded-2xl border border-border/70 bg-surface">
                      {loadingSerials ? (
                        <div className="flex h-40 flex-col items-center justify-center gap-2 text-text-muted">
                          <Loader2 className="size-6 animate-spin text-primary" />
                          <span className="text-xs">Cargando seriales...</span>
                        </div>
                      ) : filteredSerials.length === 0 ? (
                        <div className="flex h-40 flex-col items-center justify-center gap-2 p-6 text-center text-text-muted">
                          <ShieldCheck className="size-8 stroke-1 text-text-muted/60" />
                          <p className="text-xs">
                            {selectedProduct.tracking_type === 'serialized'
                              ? 'No hay unidades serializadas disponibles en existencia.'
                              : 'Este producto no requiere control individual por serial o IMEI.'}
                          </p>
                        </div>
                      ) : (
                        <table className="w-full text-left text-xs border-collapse">
                          <thead className="sticky top-0 z-10 border-b border-border/70 bg-bg/80 backdrop-blur-xs text-[11px] font-semibold text-text-muted uppercase">
                            <tr>
                              <th className="px-4 py-3">Número Serial / IMEI</th>
                              <th className="px-4 py-3">Tipo</th>
                              <th className="px-4 py-3">Almacén</th>
                              <th className="px-4 py-3 text-right">Estado</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-border/70">
                            {filteredSerials.map((serial) => (
                              <tr key={serial.id} className="transition-colors hover:bg-bg/40">
                                <td className="px-4 py-3 font-mono font-bold text-text-primary">
                                  {serial.serial_number}
                                </td>
                                <td className="px-4 py-3 uppercase text-text-secondary text-[11px]">
                                  {serial.serial_type || 'IMEI'}
                                </td>
                                <td className="px-4 py-3 text-text-secondary">
                                  {serial.warehouse_name || 'Principal'}
                                </td>
                                <td className="px-4 py-3 text-right">
                                  <Badge
                                    variant={serial.status === 'available' ? 'success' : 'outline'}
                                    className="text-[10px] uppercase font-semibold"
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

                    {/* Trazabilidad de lotes y vencimientos */}
                    <div className="flex shrink-0 items-center gap-3 rounded-2xl border border-dashed border-border/70 bg-bg/20 px-4 py-3">
                      <Calendar className="size-5 shrink-0 text-text-muted/70" />
                      <div className="min-w-0">
                        <p className="text-xs font-semibold text-text-primary">
                          Trazabilidad de lotes y vencimientos
                        </p>
                        <p className="text-[11px] leading-relaxed text-text-muted">
                          Inventario continuo estándar sin fechas de caducidad ni lotes perecederos.
                        </p>
                      </div>
                      <Badge variant="outline" className="ml-auto shrink-0 text-[10px]">
                        Control por Unidad ({selectedProduct.unit_of_measure ?? 'UND'})
                      </Badge>
                    </div>
                  </TabsContent>
                </Tabs>
              </div>
            )}
            </div>
          ) : (
            <div className="flex shrink-0 items-center justify-center border-t border-border bg-bg/20 p-2 lg:w-12 lg:border-t-0 lg:border-l">
              <button
                type="button"
                onClick={() => setDetailOpen(true)}
                className="flex items-center gap-2 rounded-xl border border-border bg-surface px-3 py-2 text-xs font-medium text-text-secondary transition-colors hover:border-primary hover:text-text-primary lg:flex-col lg:px-2.5 lg:py-4"
                aria-label="Mostrar detalle"
                data-testid="search-modal-detail-rail-toggle"
              >
                <PanelRightOpen className="size-4.5" />
                <span className="lg:[writing-mode:vertical-rl]">Detalle</span>
              </button>
            </div>
          )}
        </div>

        {/* ============================================================== */}
        {/* PIE DE VENTANA: Barra de Atajos F2..F8 y Botones de Acción     */}
        {/* ============================================================== */}
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border bg-bg/50 px-6 py-3.5">
          {/* Chips de atajos de teclado F2 al F8 */}
          <div className="flex flex-wrap items-center gap-1.5">
            <button
              type="button"
              onClick={handleConfirmSelect}
              className="flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1.5 text-xs text-text-secondary transition-colors hover:border-primary hover:text-text-primary"
            >
              <kbd className="rounded bg-primary/10 px-1.5 py-0.5 font-mono text-[11px] font-bold text-primary">
                F2
              </kbd>
              <span>Aceptar</span>
            </button>

            <button
              type="button"
              onClick={() => {
                searchInputRef.current?.focus();
                searchInputRef.current?.select();
              }}
              className="flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1.5 text-xs text-text-secondary transition-colors hover:border-primary hover:text-text-primary"
            >
              <kbd className="rounded bg-bg px-1.5 py-0.5 font-mono text-[11px] font-bold text-text-muted">
                F3
              </kbd>
              <span>Buscar</span>
            </button>

            <button
              type="button"
              onClick={() => warehouseSelectRef.current?.focus()}
              className="flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1.5 text-xs text-text-secondary transition-colors hover:border-primary hover:text-text-primary"
            >
              <kbd className="rounded bg-bg px-1.5 py-0.5 font-mono text-[11px] font-bold text-text-muted">
                F4
              </kbd>
              <span>Almacén</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('precios')}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs transition-colors',
                activeTab === 'precios'
                  ? 'border-primary bg-primary/10 font-semibold text-primary'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg px-1.5 py-0.5 font-mono text-[11px] font-bold text-text-muted">
                F5
              </kbd>
              <span>Precios</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('existencia')}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs transition-colors',
                activeTab === 'existencia'
                  ? 'border-primary bg-primary/10 font-semibold text-primary'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg px-1.5 py-0.5 font-mono text-[11px] font-bold text-text-muted">
                F6
              </kbd>
              <span>Existencia</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('seriales')}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs transition-colors',
                activeTab === 'seriales'
                  ? 'border-primary bg-primary/10 font-semibold text-primary'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg px-1.5 py-0.5 font-mono text-[11px] font-bold text-text-muted">
                F7
              </kbd>
              <span>Seriales/Lotes</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('datos')}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs transition-colors',
                activeTab === 'datos'
                  ? 'border-primary bg-primary/10 font-semibold text-primary'
                  : 'border-border bg-surface text-text-secondary hover:border-primary hover:text-text-primary',
              )}
            >
              <kbd className="rounded bg-bg px-1.5 py-0.5 font-mono text-[11px] font-bold text-text-muted">
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
              className="h-10 rounded-xl px-5 text-sm font-bold"
              data-testid="search-modal-cancel-btn"
            >
              Salir <span className="ml-1 font-mono text-xs text-text-muted">(Esc)</span>
            </Button>
            <Button
              type="button"
              onClick={handleConfirmSelect}
              disabled={!selectedProduct}
              className="h-10 gap-2 rounded-xl px-6 text-sm sm:text-base font-bold shadow-sm"
              data-testid="search-modal-accept-btn"
            >
              <Check className="size-4.5" />
              <span>Aceptar</span>
              <span className="ml-1 font-mono text-xs opacity-80">(Enter/F2)</span>
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
