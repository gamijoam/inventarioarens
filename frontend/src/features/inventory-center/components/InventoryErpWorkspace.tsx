/**
 * InventoryErpWorkspace.tsx — Vista ERP Dividida (Split View) para el Centro de Inventario.
 *
 * Proporciona un entorno de trabajo de alta productividad:
 *  - Columna Izquierda:
 *      * Buscador instantáneo con hotkey [F3].
 *      * Filtros rápidos de Almacén, Tipo de Rastreo, Estado de Stock y Estado Activo/Inactivo.
 *      * Lista densa de productos con SKU, Nombre, Badge de Categoría destacado (📁), Marca y Stock.
 *      * Navegación fluida por teclado (Flechas Arriba/Abajo) y selección instantánea.
 *      * Paginación compacta.
 *  - Columna Derecha:
 *      * Encabezado del producto seleccionado (Nombre, Códigos, Categoría destacada, Marca, Stock Total).
 *      * Botón de Edición Rápida [F2] y Enlace a Ficha Completa.
 *      * Pestañas ERP con atajos:
 *          - [F5] Precios: Comparativa de tarifas con desglose exacto de IVA 16%, USD, VES y empaque.
 *          - [F6] Existencia: Desglose multi-almacén (disponible, reservado, dañado, físico).
 *          - [F8] Datos Principales: Galería de imágenes, ficha técnica, clasificación y parámetros de stock.
 *          - [F7] Seriales / IMEIs: Listado y búsqueda de números de serie/IMEI disponibles.
 *          - Kardex: Movimientos recientes de inventario con tipo, almacén, cantidad y referencia.
 *  - Barra de atajos en pie de vista ([F2] a [F8], [↑/↓], [Enter]).
 */
import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
} from 'react';
import { Link } from '@tanstack/react-router';
import {
  Barcode,
  Boxes,
  ChevronLeft,
  ChevronRight,
  DollarSign,
  Edit,
  ExternalLink,
  Folder,
  History,
  Info,
  Loader2,
  Package,
  Search,
  Tag,
  Warehouse,
  X,
} from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import {
  useProduct,
  useProductMovements,
  useProductSerials,
  useProductStockByWarehouse,
} from '@/features/inventory-center/api';
import { ProductImage as ProductImageView } from '@/features/inventory-center/components/ProductImage';
import { EditProductDialog } from '@/features/inventory-center/dialogs/EditProductDialog';
import {
  MOVEMENT_IN_TYPES,
  movementTypeLabel,
  referenceTypeLabel,
} from '@/features/inventory-center/movementLabels';
import type { PriceList, Product, ProductSerial } from '@/features/inventory-center/schemas';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import { formatRelative } from '@/lib/format';

const IVA_RATE = 0.16; // 16% IVA Venezuela

type ErpTabKey = 'precios' | 'existencia' | 'datos' | 'seriales' | 'kardex';

export interface InventoryErpWorkspaceProps {
  products: Product[];
  totalProducts: number;
  totalPages: number;
  currentPage: number;
  isLoading: boolean;
  search: string;
  onSearchChange: (search: string) => void;
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
  netUsd: number;
  taxUsd: number;
  totalUsd: number;
  netVes: number;
  taxVes: number;
  totalVes: number;
  packageFactor: number;
  packageUsd: number;
  packageVes: number;
  marginPercent?: number | null;
}

export function InventoryErpWorkspace({
  products,
  totalProducts,
  totalPages,
  currentPage,
  isLoading,
  search,
  onSearchChange,
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
}: InventoryErpWorkspaceProps) {
  const [searchInput, setSearchInput] = useState(search);
  const [selectedIndex, setSelectedIndex] = useState(0);
  const [activeTab, setActiveTab] = useState<ErpTabKey>('precios');
  const [packageFactor, setPackageFactor] = useState<number>(1);
  const [serialFilter, setSerialFilter] = useState('');
  const [editDialogOpen, setEditDialogOpen] = useState(false);

  const searchInputRef = useRef<HTMLInputElement>(null);
  const listContainerRef = useRef<HTMLDivElement>(null);

  // Sincronizar input local con prop search
  useEffect(() => {
    setSearchInput(search);
  }, [search]);

  // Debounce búsqueda al escribir
  useEffect(() => {
    if (searchInput === search) return;
    const timer = setTimeout(() => {
      onSearchChange(searchInput);
    }, 250);
    return () => clearTimeout(timer);
  }, [searchInput, search, onSearchChange]);

  // Reset selected index cuando cambie la lista de productos
  useEffect(() => {
    setSelectedIndex(0);
  }, [products]);

  // Asegurar índice seguro
  const selectedProductSummary = useMemo(() => {
    if (products.length === 0) return null;
    const safeIdx = Math.min(Math.max(0, selectedIndex), products.length - 1);
    return products[safeIdx] ?? null;
  }, [products, selectedIndex]);

  // Auto-scroll del item seleccionado en la lista
  useEffect(() => {
    if (!listContainerRef.current) return;
    const selectedRow = listContainerRef.current.querySelector(
      `[data-erp-index="${selectedIndex}"]`,
    ) as HTMLElement | null;
    if (selectedRow && typeof selectedRow.scrollIntoView === 'function') {
      selectedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }, [selectedIndex]);

  // Consulta de detalle completo para el producto seleccionado
  const { data: fullProduct } = useProduct(selectedProductSummary?.id ?? 0);

  const activeProduct = fullProduct ?? selectedProductSummary;

  // Consultas complementarias
  const { data: stockByWarehouse = [], isLoading: loadingStock } = useProductStockByWarehouse(
    activeProduct?.id ?? 0,
  );

  const { data: productSerials = [], isLoading: loadingSerials } = useProductSerials(
    activeProduct?.id ?? 0,
  );

  const { data: movements = [], isLoading: loadingMovements } = useProductMovements(
    activeProduct?.id ?? 0,
  );

  // Categoría formateada
  const activeCategory = useMemo(() => getProductCategory(activeProduct), [activeProduct]);

  // Cálculo de stock total consolidado
  const totalStockAvailable = useMemo(() => {
    if (stockByWarehouse.length > 0) {
      return stockByWarehouse.reduce((acc, curr) => acc + (Number(curr.available) || 0), 0);
    }
    return Number(activeProduct?.available_stock ?? 0);
  }, [stockByWarehouse, activeProduct]);

  // Atajos de teclado globales en el ERP Workspace
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (editDialogOpen) return;

      const target = e.target as HTMLElement | null;
      const isInputFocused =
        target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT');

      // F2: Editar producto
      if (e.key === 'F2') {
        e.preventDefault();
        if (activeProduct) {
          setEditDialogOpen(true);
        }
        return;
      }

      // F3: Enfocar buscador
      if (e.key === 'F3') {
        e.preventDefault();
        searchInputRef.current?.focus();
        searchInputRef.current?.select();
        return;
      }

      // F5: Pestaña Precios
      if (e.key === 'F5') {
        e.preventDefault();
        setActiveTab('precios');
        return;
      }

      // F6: Pestaña Existencia
      if (e.key === 'F6') {
        e.preventDefault();
        setActiveTab('existencia');
        return;
      }

      // F7: Pestaña Seriales
      if (e.key === 'F7') {
        e.preventDefault();
        setActiveTab('seriales');
        return;
      }

      // F8: Pestaña Datos
      if (e.key === 'F8') {
        e.preventDefault();
        setActiveTab('datos');
        return;
      }

      // Navegación con Flechas Arriba / Abajo
      if (e.key === 'ArrowDown') {
        if (isInputFocused && target !== searchInputRef.current) return;
        e.preventDefault();
        setSelectedIndex((prev) => Math.min(prev + 1, Math.max(0, products.length - 1)));
        return;
      }

      if (e.key === 'ArrowUp') {
        if (isInputFocused && target !== searchInputRef.current) return;
        e.preventDefault();
        setSelectedIndex((prev) => Math.max(0, prev - 1));
        return;
      }

      // Enter para editar si no está escribiendo en el buscador
      if (e.key === 'Enter' && !isInputFocused && activeProduct) {
        e.preventDefault();
        setEditDialogOpen(true);
        return;
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [editDialogOpen, activeProduct, products.length]);

  // Filas de la tabla de precios
  const priceRows = useMemo<PriceTableRow[]>(() => {
    if (!activeProduct) return [];

    const rateVal = activeRate?.rate && activeRate.rate > 0 ? activeRate.rate : 0;
    const basePrice = Number(activeProduct.base_price ?? 0);
    const existingPrices = activeProduct.prices ?? [];
    const cost = Number(activeProduct.last_purchase_cost ?? activeProduct.average_cost ?? 0);

    const rows: PriceTableRow[] = [];

    const buildRow = (
      id: string | number,
      label: string,
      usdPriceWithTax: number,
      code?: string,
      isDefault?: boolean,
    ): PriceTableRow => {
      const totalUsd = Math.max(0, Math.round(Number(usdPriceWithTax || 0) * 100) / 100);
      const netUsd = totalUsd > 0 ? Math.round((totalUsd / (1 + IVA_RATE)) * 100) / 100 : 0;
      const taxUsd = totalUsd > 0 ? Math.round((totalUsd - netUsd) * 100) / 100 : 0;

      const totalVes = rateVal > 0 && totalUsd > 0 ? Math.round(totalUsd * rateVal * 100) / 100 : 0;
      const netVes = totalVes > 0 ? Math.round((totalVes / (1 + IVA_RATE)) * 100) / 100 : 0;
      const taxVes = totalVes > 0 ? Math.round((totalVes - netVes) * 100) / 100 : 0;

      const factor = Math.max(1, packageFactor);
      const packageUsd = Math.round(totalUsd * factor * 100) / 100;
      const packageVes = Math.round(totalVes * factor * 100) / 100;

      // Margen sobre base imponible
      let marginPercent: number | null = null;
      if (netUsd > 0 && cost > 0) {
        marginPercent = Math.round(((netUsd - cost) / netUsd) * 1000) / 10;
      }

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
        marginPercent,
      };
    };

    if (priceLists.length > 0) {
      priceLists.forEach((list, idx) => {
        const found = existingPrices.find(
          (p) => p.price_list_id === list.id || p.price_list?.id === list.id,
        );
        let listPrice = found ? Number(found.price) : 0;

        if (!found && (list.is_default || idx === 0)) {
          listPrice = basePrice;
        }

        if (!found && list.markup_percentage && basePrice > 0) {
          listPrice = basePrice * (1 + Number(list.markup_percentage) / 100);
        }

        const labelName = list.name || `Precio ${idx + 1}`;
        rows.push(buildRow(list.id, labelName, listPrice, list.code, list.is_default));
      });
    }

    if (rows.length === 0) {
      rows.push(buildRow('p1', 'Precio 1 (Detal / Normal)', basePrice, 'P1', true));
      const p2 = existingPrices.find((p) => p.price_list?.code === 'P2' || p.price_list_id === 2);
      rows.push(
        buildRow('p2', 'Precio 2 (Al Mayor)', p2 ? Number(p2.price) : basePrice * 0.9, 'P2'),
      );
      const p3 = existingPrices.find((p) => p.price_list?.code === 'P3' || p.price_list_id === 3);
      rows.push(
        buildRow(
          'p3',
          'Precio 3 (Especial / Mayorista)',
          p3 ? Number(p3.price) : basePrice * 0.85,
          'P3',
        ),
      );
    }

    return rows;
  }, [activeProduct, activeRate, packageFactor, priceLists]);

  // Seriales filtrados
  const filteredSerials = useMemo(() => {
    if (!productSerials) return [];
    if (!serialFilter.trim()) return productSerials;
    const q = serialFilter.toLowerCase().trim();
    return productSerials.filter(
      (s: ProductSerial) =>
        s.serial_number?.toLowerCase().includes(q) ||
        s.status?.toLowerCase().includes(q) ||
        s.warehouse_name?.toLowerCase().includes(q),
    );
  }, [productSerials, serialFilter]);

  const selectClass = cn(
    'h-8 text-xs rounded border border-border bg-surface px-2 shadow-2xs',
    'focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary',
  );

  return (
    <div className="flex flex-col gap-3">
      {/* Contenedor Principal Split View */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-3 min-h-[680px]">
        {/* ========================================================= */}
        {/* COLUMNA IZQUIERDA: Buscador, Filtros y Lista de Productos */}
        {/* ========================================================= */}
        <div className="lg:col-span-5 xl:col-span-5 flex flex-col bg-surface rounded-lg border border-border overflow-hidden shadow-xs h-[720px]">
          {/* Cabecera de Búsqueda y Filtros */}
          <div className="p-3 border-b border-border bg-surface-subtle/40 space-y-2.5">
            <div className="flex items-center gap-2">
              <div className="relative flex-1">
                <Search
                  className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                  aria-hidden="true"
                />
                <Input
                  ref={searchInputRef}
                  value={searchInput}
                  onChange={(e: ChangeEvent<HTMLInputElement>) => setSearchInput(e.target.value)}
                  placeholder="Buscar SKU, código o nombre... [F3]"
                  className={cn('pl-8 pr-16 h-9 text-sm', searchInput && 'pr-20')}
                  data-testid="erp-inventory-search"
                />
                <div className="absolute top-1/2 right-2 -translate-y-1/2 flex items-center gap-1">
                  {searchInput ? (
                    <button
                      type="button"
                      onClick={() => {
                        setSearchInput('');
                        onSearchChange('');
                        searchInputRef.current?.focus();
                      }}
                      className="text-text-muted hover:text-text-primary rounded p-1 transition-colors"
                      aria-label="Limpiar búsqueda"
                    >
                      <X className="size-3.5" />
                    </button>
                  ) : null}
                  <span className="hidden sm:inline-flex items-center text-[10px] font-mono font-medium px-1.5 py-0.5 rounded bg-bg text-text-muted border border-border">
                    F3
                  </span>
                </div>
              </div>

              <Button
                size="sm"
                variant="outline"
                onClick={onNewProduct}
                className="h-9 text-xs shrink-0 font-medium"
                title="Crear nuevo producto"
              >
                + Nuevo
              </Button>
            </div>

            {/* Selectores de filtros rápidos */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
              <select
                className={selectClass}
                value={warehouseId ? String(warehouseId) : ''}
                onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                  onWarehouseChange(e.target.value ? Number(e.target.value) : undefined)
                }
                title="Filtrar por almacén"
              >
                <option value="">Almacenes (todos)</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={String(w.id)}>
                    {w.code}
                  </option>
                ))}
              </select>

              <select
                className={selectClass}
                value={stock}
                onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                  onStockChange(e.target.value as any)
                }
                title="Filtrar por estado de stock"
              >
                <option value="all">Stock (todo)</option>
                <option value="available">Con stock</option>
                <option value="low">Stock bajo</option>
                <option value="critical">Crítico</option>
                <option value="out">Agotado</option>
              </select>

              <select
                className={selectClass}
                value={tracking}
                onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                  onTrackingChange(e.target.value as any)
                }
                title="Tipo de seguimiento"
              >
                <option value="all">Tipos (todos)</option>
                <option value="quantity">Cantidad</option>
                <option value="serialized">Serializado</option>
              </select>

              <select
                className={selectClass}
                value={status}
                onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                  onStatusChange(e.target.value as any)
                }
                title="Filtrar por estado activo/inactivo"
              >
                <option value="all">Estados</option>
                <option value="active">Activos</option>
                <option value="inactive">Inactivos</option>
              </select>
            </div>

            {/* Contador de resultados */}
            <div className="flex items-center justify-between text-xs text-text-muted px-0.5">
              <span>
                {isLoading ? (
                  <span className="flex items-center gap-1">
                    <Loader2 className="size-3 animate-spin" /> Cargando catálogo...
                  </span>
                ) : (
                  <span>
                    Mostrando <strong className="text-text-primary">{products.length}</strong> de{' '}
                    <strong className="text-text-primary">{totalProducts}</strong> productos
                  </span>
                )}
              </span>
              <span className="text-[11px]">Navega con [↑] [↓]</span>
            </div>
          </div>

          {/* Listado de Productos */}
          <div
            ref={listContainerRef}
            className="flex-1 overflow-y-auto divide-y divide-border/60 focus:outline-none"
            tabIndex={0}
            aria-label="Lista de productos del ERP"
          >
            {isLoading && products.length === 0 && (
              <div className="p-4 space-y-3">
                {Array.from({ length: 7 }).map((_, i) => (
                  <div key={i} className="flex flex-col gap-1.5 p-2 rounded bg-surface-subtle/30 animate-pulse">
                    <div className="flex justify-between">
                      <div className="h-4 w-20 bg-border/60 rounded" />
                      <div className="h-4 w-12 bg-border/60 rounded" />
                    </div>
                    <div className="h-4 w-44 bg-border/60 rounded" />
                    <div className="h-3 w-28 bg-border/40 rounded" />
                  </div>
                ))}
              </div>
            )}

            {!isLoading && products.length === 0 && (
              <div className="h-full flex items-center justify-center p-6 text-center">
                <EmptyState
                  icon={<Package className="size-8 text-text-muted" />}
                  title="No se encontraron productos"
                  description="Ajusta el término de búsqueda o limpia los filtros."
                />
              </div>
            )}

            {products.map((prod, idx) => {
              const isSelected = idx === selectedIndex;
              const prodCat = getProductCategory(prod);
              const avail = Number(prod.available_stock ?? 0);
              const isSerialized = prod.tracking_type === 'serialized';

              return (
                <div
                  key={prod.id}
                  data-erp-index={idx}
                  onClick={() => setSelectedIndex(idx)}
                  onDoubleClick={() => setEditDialogOpen(true)}
                  className={cn(
                    'p-2.5 transition-all cursor-pointer flex flex-col gap-1 select-none',
                    isSelected
                      ? 'bg-primary/10 border-l-4 border-primary text-text-primary font-medium'
                      : 'hover:bg-muted/40 text-text-secondary border-l-4 border-transparent',
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-1.5 overflow-hidden">
                      <span className="font-mono text-[11px] px-1.5 py-0.5 rounded bg-bg/80 border border-border text-text-muted shrink-0">
                        {prod.sku || 'SIN SKU'}
                      </span>
                      {prod.barcode && (
                        <span className="font-mono text-[10px] text-text-muted truncate hidden sm:inline">
                          CB: {prod.barcode}
                        </span>
                      )}
                    </div>

                    <div className="flex items-center gap-1.5 shrink-0">
                      {/* Badge de Stock */}
                      {isSerialized ? (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                          <Boxes className="size-3" />
                          {avail} un.
                        </span>
                      ) : avail > 0 ? (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                          {avail} un.
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                          Agotado
                        </span>
                      )}

                      {/* Precio de Referencia */}
                      <span className="text-xs font-semibold text-text-primary">
                        {money(prod.base_price)}
                      </span>
                    </div>
                  </div>

                  {/* Nombre del Producto */}
                  <div className="text-sm font-semibold text-text-primary line-clamp-1 leading-snug">
                    {prod.name}
                  </div>

                  {/* Fila Inferior: Categoría destacada y Marca */}
                  <div className="flex items-center gap-1.5 flex-wrap pt-0.5">
                    {/* Badge de Categoría Destacado */}
                    <span
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/30 shrink-0"
                      title={`Categoría: ${prodCat}`}
                    >
                      <Folder className="size-3 shrink-0" />
                      <span className="max-w-[180px] truncate">{prodCat}</span>
                    </span>

                    {prod.brand?.name && (
                      <span className="text-[11px] text-text-muted truncate max-w-[120px]">
                        {prod.brand.name}
                      </span>
                    )}

                    {prod.unit_of_measure && (
                      <span className="text-[10px] uppercase font-mono text-text-muted">
                        · {prod.unit_of_measure}
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>

          {/* Pie de Paginación Compacta */}
          <div className="p-2.5 border-t border-border bg-surface-subtle/30 flex items-center justify-between text-xs text-text-muted">
            <span>
              Página <strong className="text-text-primary">{currentPage}</strong> de{' '}
              <strong className="text-text-primary">{totalPages || 1}</strong>
            </span>
            <div className="flex items-center gap-1.5">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1 || isLoading}
                onClick={() => onPageChange(Math.max(1, currentPage - 1))}
                className="h-7 px-2 text-xs"
              >
                <ChevronLeft className="size-3.5 mr-0.5" />
                Ant.
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= totalPages || isLoading}
                onClick={() => onPageChange(currentPage + 1)}
                className="h-7 px-2 text-xs"
              >
                Sig.
                <ChevronRight className="size-3.5 ml-0.5" />
              </Button>
            </div>
          </div>
        </div>

        {/* ========================================================= */}
        {/* COLUMNA DERECHA: Ficha Detallada ERP con Pestañas y Acciones */}
        {/* ========================================================= */}
        <div className="lg:col-span-7 xl:col-span-7 flex flex-col bg-surface rounded-lg border border-border overflow-hidden shadow-xs h-[720px]">
          {activeProduct ? (
            <>
              {/* Cabecera del Producto Seleccionado */}
              <div className="p-3.5 border-b border-border bg-surface-subtle/20 space-y-2.5">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 space-y-1">
                    <h2 className="text-lg sm:text-xl font-bold text-text-primary truncate tracking-tight">
                      {activeProduct.name}
                    </h2>
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="font-mono text-xs px-2 py-0.5 rounded bg-bg border border-border text-text-primary font-semibold">
                        SKU: {activeProduct.sku || 'SIN SKU'}
                      </span>
                      {activeProduct.barcode && (
                        <span className="font-mono text-xs px-2 py-0.5 rounded bg-bg border border-border text-text-muted flex items-center gap-1">
                          <Barcode className="size-3.5" /> {activeProduct.barcode}
                        </span>
                      )}
                      {/* Badge de Categoría Destacado Medio-Grande */}
                      <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 text-xs font-semibold rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/30">
                        <Folder className="size-3.5 text-amber-500 shrink-0" />
                        <span>Categoría: {activeCategory}</span>
                      </span>
                    </div>
                  </div>

                  {/* Acciones de Cabecera: Editar [F2] y Enlace a Ficha */}
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      size="sm"
                      variant="primary"
                      onClick={() => setEditDialogOpen(true)}
                      className="h-8 gap-1.5 text-xs font-semibold shadow-xs"
                      title="Editar producto [F2]"
                    >
                      <Edit className="size-3.5" />
                      Editar [F2]
                    </Button>
                    <Link
                      to="/inventory/$productId"
                      params={{ productId: String(activeProduct.id) }}
                      className="inline-flex items-center gap-1 h-8 px-2.5 rounded-md border border-border bg-surface hover:bg-muted text-text-secondary text-xs font-medium transition-colors"
                      title="Ver ficha completa en página independiente"
                    >
                      <ExternalLink className="size-3.5" />
                      Ficha
                    </Link>
                  </div>
                </div>

                {/* Sub-barra: Stock total consolidado, Marca, Unidad y Tasa */}
                <div className="flex items-center justify-between gap-2 pt-1 border-t border-border/50 text-xs flex-wrap">
                  <div className="flex items-center gap-2">
                    <span
                      className={cn(
                        'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-bold border',
                        totalStockAvailable > 0
                          ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                          : 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/30',
                      )}
                    >
                      <Warehouse className="size-3.5" />
                      Stock Total: {totalStockAvailable} {activeProduct.unit_of_measure ?? 'un.'}
                    </span>

                    {activeProduct.brand?.name && (
                      <span className="text-text-muted">
                        Marca: <strong className="text-text-primary">{activeProduct.brand.name}</strong>
                      </span>
                    )}

                    <Badge variant={activeProduct.is_active ? 'success' : 'default'} className="text-[11px]">
                      {activeProduct.is_active ? 'Activo' : 'Inactivo'}
                    </Badge>
                  </div>

                  {activeRate && (
                    <div className="text-text-muted flex items-center gap-1 font-mono text-[11px]">
                      <span>Tasa {activeRate.name || activeRate.exchange_rate_type_code || activeRate.code || 'BCV'}:</span>
                      <strong className="text-text-primary">Bs. {activeRate.rate.toFixed(2)}</strong>
                    </div>
                  )}
                </div>
              </div>

              {/* Barra de Pestañas ERP */}
              <div className="border-b border-border bg-surface flex items-center gap-1 px-3 pt-1 overflow-x-auto select-none">
                <button
                  type="button"
                  onClick={() => setActiveTab('precios')}
                  className={cn(
                    'flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition-colors whitespace-nowrap',
                    activeTab === 'precios'
                      ? 'border-primary text-primary bg-primary/5'
                      : 'border-transparent text-text-muted hover:text-text-primary hover:bg-muted/40',
                  )}
                >
                  <DollarSign className="size-3.5" />
                  [F5] Precios
                </button>

                <button
                  type="button"
                  onClick={() => setActiveTab('existencia')}
                  className={cn(
                    'flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition-colors whitespace-nowrap',
                    activeTab === 'existencia'
                      ? 'border-primary text-primary bg-primary/5'
                      : 'border-transparent text-text-muted hover:text-text-primary hover:bg-muted/40',
                  )}
                >
                  <Warehouse className="size-3.5" />
                  [F6] Existencia
                </button>

                <button
                  type="button"
                  onClick={() => setActiveTab('datos')}
                  className={cn(
                    'flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition-colors whitespace-nowrap',
                    activeTab === 'datos'
                      ? 'border-primary text-primary bg-primary/5'
                      : 'border-transparent text-text-muted hover:text-text-primary hover:bg-muted/40',
                  )}
                >
                  <Info className="size-3.5" />
                  [F8] Datos Principales
                </button>

                <button
                  type="button"
                  onClick={() => setActiveTab('seriales')}
                  className={cn(
                    'flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition-colors whitespace-nowrap',
                    activeTab === 'seriales'
                      ? 'border-primary text-primary bg-primary/5'
                      : 'border-transparent text-text-muted hover:text-text-primary hover:bg-muted/40',
                  )}
                >
                  <Boxes className="size-3.5" />
                  [F7] Seriales / IMEIs
                </button>

                <button
                  type="button"
                  onClick={() => setActiveTab('kardex')}
                  className={cn(
                    'flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition-colors whitespace-nowrap',
                    activeTab === 'kardex'
                      ? 'border-primary text-primary bg-primary/5'
                      : 'border-transparent text-text-muted hover:text-text-primary hover:bg-muted/40',
                  )}
                >
                  <History className="size-3.5" />
                  Kardex / Movimientos
                </button>
              </div>

              {/* Contenido de la Pestaña Activa */}
              <div className="flex-1 overflow-y-auto p-4">
                {/* ---------------------------------------------------- */}
                {/* PESTAÑA 1: [F5] PRECIOS                              */}
                {/* ---------------------------------------------------- */}
                {activeTab === 'precios' && (
                  <div className="space-y-4">
                    {/* Controles de empaque e info fiscal */}
                    <div className="flex items-center justify-between gap-3 p-3 rounded-lg border border-border bg-surface-subtle/30 text-xs">
                      <div className="flex items-center gap-2">
                        <span className="text-text-muted font-medium">Factor de Empaque:</span>
                        <div className="inline-flex items-center gap-1">
                          {[1, 6, 12, 24, 48].map((f) => (
                            <button
                              key={f}
                              type="button"
                              onClick={() => setPackageFactor(f)}
                              className={cn(
                                'px-2 py-0.5 rounded text-xs font-semibold transition-colors border',
                                packageFactor === f
                                  ? 'bg-primary text-primary-foreground border-primary shadow-2xs'
                                  : 'bg-surface text-text-muted hover:text-text-primary border-border',
                              )}
                            >
                              x{f}
                            </button>
                          ))}
                        </div>
                      </div>

                      <div className="text-text-muted text-right">
                        <span>Precios ya incluyen <strong className="text-text-primary">16% IVA</strong>.</span>
                      </div>
                    </div>

                    {/* Tabla de Tarifas */}
                    <div className="border border-border rounded-lg overflow-hidden">
                      <table className="w-full text-left text-xs border-collapse">
                        <thead className="bg-muted/50 border-b border-border text-text-secondary uppercase tracking-wider font-semibold text-[11px]">
                          <tr>
                            <th className="p-2.5">Lista de Precio</th>
                            <th className="p-2.5 text-right">USD Neto</th>
                            <th className="p-2.5 text-right">IVA (16%)</th>
                            <th className="p-2.5 text-right font-bold text-text-primary bg-primary/5">
                              PVP USD
                            </th>
                            <th className="p-2.5 text-right">VES Neto</th>
                            <th className="p-2.5 text-right font-bold text-text-primary bg-primary/5">
                              PVP VES
                            </th>
                            {packageFactor > 1 && (
                              <th className="p-2.5 text-right font-semibold text-amber-600 dark:text-amber-400">
                                Empaque (x{packageFactor})
                              </th>
                            )}
                            <th className="p-2.5 text-right">Margen</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-border/60 font-mono">
                          {priceRows.map((row) => (
                            <tr key={row.id} className="hover:bg-muted/30">
                              <td className="p-2.5 font-sans font-medium text-text-primary">
                                <div className="flex items-center gap-1.5">
                                  <span>{row.label}</span>
                                  {row.isDefault && (
                                    <span className="text-[10px] px-1 py-0.2 rounded bg-primary/10 text-primary font-mono">
                                      DEF
                                    </span>
                                  )}
                                </div>
                              </td>
                              <td className="p-2.5 text-right text-text-muted">{money(row.netUsd)}</td>
                              <td className="p-2.5 text-right text-text-muted">{money(row.taxUsd)}</td>
                              <td className="p-2.5 text-right font-bold text-text-primary bg-primary/5">
                                {money(row.totalUsd)}
                              </td>
                              <td className="p-2.5 text-right text-text-muted">{moneyVes(row.netVes)}</td>
                              <td className="p-2.5 text-right font-bold text-text-primary bg-primary/5">
                                {moneyVes(row.totalVes)}
                              </td>
                              {packageFactor > 1 && (
                                <td className="p-2.5 text-right font-bold text-amber-600 dark:text-amber-400">
                                  {money(row.packageUsd)}
                                </td>
                              )}
                              <td className="p-2.5 text-right font-sans">
                                {row.marginPercent != null ? (
                                  <span
                                    className={cn(
                                      'px-1.5 py-0.5 rounded text-[11px] font-semibold',
                                      row.marginPercent > 20
                                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                        : row.marginPercent > 0
                                          ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                          : 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                                    )}
                                  >
                                    {row.marginPercent.toFixed(1)}%
                                  </span>
                                ) : (
                                  <span className="text-text-muted">—</span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    {/* Resumen de Costos y Rentabilidad */}
                    {(activeProduct.last_purchase_cost != null || activeProduct.average_cost != null) && (
                      <div className="grid grid-cols-2 sm:grid-cols-3 gap-2.5 p-3 rounded-lg border border-border bg-surface-subtle/30 text-xs">
                        <div>
                          <span className="text-text-muted block text-[11px]">Último Costo de Compra:</span>
                          <strong className="text-sm font-mono text-text-primary">
                            {formatMoney(activeProduct.last_purchase_cost)}
                          </strong>
                        </div>
                        <div>
                          <span className="text-text-muted block text-[11px]">Costo Promedio (WAC):</span>
                          <strong className="text-sm font-mono text-text-primary">
                            {formatMoney(activeProduct.average_cost)}
                          </strong>
                        </div>
                        <div>
                          <span className="text-text-muted block text-[11px]">Margen Configurado:</span>
                          <strong className="text-sm text-primary">
                            {activeProduct.profit_margin != null
                              ? `${Number(activeProduct.profit_margin).toFixed(1)}%`
                              : 'Sin margen'}
                          </strong>
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {/* ---------------------------------------------------- */}
                {/* PESTAÑA 2: [F6] EXISTENCIA MULTI-ALMACÉN             */}
                {/* ---------------------------------------------------- */}
                {activeTab === 'existencia' && (
                  <div className="space-y-4">
                    {loadingStock ? (
                      <div className="p-8 text-center text-text-muted">
                        <Loader2 className="size-6 animate-spin mx-auto mb-2" />
                        Cargando existencia por almacén...
                      </div>
                    ) : stockByWarehouse.length === 0 ? (
                      <EmptyState
                        icon={<Warehouse className="size-8 text-text-muted" />}
                        title="Sin existencia registrada"
                        description="Este producto no tiene balances registrados en los almacenes."
                      />
                    ) : (
                      <div className="border border-border rounded-lg overflow-hidden">
                        <table className="w-full text-left text-xs border-collapse">
                          <thead className="bg-muted/50 border-b border-border text-text-secondary uppercase tracking-wider font-semibold text-[11px]">
                            <tr>
                              <th className="p-2.5">Almacén</th>
                              <th className="p-2.5 text-right">Disponible</th>
                              <th className="p-2.5 text-right">Reservado</th>
                              <th className="p-2.5 text-right">Dañado</th>
                              <th className="p-2.5 text-right font-bold text-text-primary">Físico Total</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-border/60 font-mono">
                            {stockByWarehouse.map((stockItem) => {
                              const avail = Number(stockItem.available || 0);
                              const res = Number(stockItem.reserved || 0);
                              const dam = Number(stockItem.damaged || 0);
                              const phys = avail + res + dam;

                              return (
                                <tr key={stockItem.warehouse_id} className="hover:bg-muted/30">
                                  <td className="p-2.5 font-sans font-medium text-text-primary">
                                    <div className="flex items-center gap-2">
                                      <Warehouse className="size-3.5 text-text-muted shrink-0" />
                                      <span>{stockItem.warehouse_name || `Almacén #${stockItem.warehouse_id}`}</span>
                                      {stockItem.warehouse_code && (
                                        <span className="font-mono text-[10px] px-1 py-0.2 rounded bg-bg text-text-muted border border-border">
                                          {stockItem.warehouse_code}
                                        </span>
                                      )}
                                    </div>
                                  </td>
                                  <td className="p-2.5 text-right">
                                    <span
                                      className={cn(
                                        'px-2 py-0.5 rounded font-bold',
                                        avail > 0
                                          ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                          : 'text-text-muted',
                                      )}
                                    >
                                      {avail}
                                    </span>
                                  </td>
                                  <td className="p-2.5 text-right text-amber-600 dark:text-amber-400">
                                    {res > 0 ? res : '—'}
                                  </td>
                                  <td className="p-2.5 text-right text-rose-600 dark:text-rose-400">
                                    {dam > 0 ? dam : '—'}
                                  </td>
                                  <td className="p-2.5 text-right font-bold text-text-primary">{phys}</td>
                                </tr>
                              );
                            })}
                          </tbody>
                          <tfoot className="bg-surface-subtle/50 border-t border-border font-mono font-bold text-xs">
                            <tr>
                              <td className="p-2.5 font-sans">Totales consolidados:</td>
                              <td className="p-2.5 text-right text-emerald-600 dark:text-emerald-400">
                                {stockByWarehouse.reduce((acc, s) => acc + (Number(s.available) || 0), 0)}
                              </td>
                              <td className="p-2.5 text-right text-amber-600 dark:text-amber-400">
                                {stockByWarehouse.reduce((acc, s) => acc + (Number(s.reserved) || 0), 0)}
                              </td>
                              <td className="p-2.5 text-right text-rose-600 dark:text-rose-400">
                                {stockByWarehouse.reduce((acc, s) => acc + (Number(s.damaged) || 0), 0)}
                              </td>
                              <td className="p-2.5 text-right text-text-primary">
                                {stockByWarehouse.reduce(
                                  (acc, s) =>
                                    acc +
                                    (Number(s.available) || 0) +
                                    (Number(s.reserved) || 0) +
                                    (Number(s.damaged) || 0),
                                  0,
                                )}
                              </td>
                            </tr>
                          </tfoot>
                        </table>
                      </div>
                    )}
                  </div>
                )}

                {/* ---------------------------------------------------- */}
                {/* PESTAÑA 3: [F8] DATOS PRINCIPALES                    */}
                {/* ---------------------------------------------------- */}
                {activeTab === 'datos' && (
                  <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
                    {/* Visualización de Imagen */}
                    <div className="md:col-span-4 flex flex-col items-center gap-3">
                      <div className="size-44 rounded-lg border border-border bg-surface-subtle/40 overflow-hidden flex items-center justify-center p-1 shadow-2xs">
                        <ProductImageView
                          image={activeProduct.images?.find((img) => img.is_primary) ?? activeProduct.images?.[0]}
                          src={activeProduct.primary_image_url ?? activeProduct.image_url ?? undefined}
                          alt={activeProduct.name}
                          variant="medium"
                          fit="contain"
                          className="size-full object-contain"
                        />
                      </div>
                      <span className="text-[11px] text-text-muted text-center">
                        {activeProduct.images && activeProduct.images.length > 0
                          ? `${activeProduct.images.length} imagen(es) registrada(s)`
                          : 'Sin imagen secundaria'}
                      </span>
                    </div>

                    {/* Ficha Técnica y Clasificación */}
                    <div className="md:col-span-8 space-y-3 text-xs">
                      {/* Clasificación */}
                      <div className="p-3 rounded-lg border border-border bg-surface-subtle/20 space-y-2">
                        <span className="text-[10px] uppercase font-bold tracking-wider text-text-muted block">
                          Clasificación de Inventario
                        </span>
                        <div className="grid grid-cols-2 gap-2">
                          <div>
                            <span className="text-text-muted block text-[11px]">Categoría:</span>
                            <span className="font-semibold text-amber-600 dark:text-amber-400 flex items-center gap-1 mt-0.5">
                              <Folder className="size-3.5 shrink-0" />
                              {activeCategory}
                            </span>
                          </div>
                          <div>
                            <span className="text-text-muted block text-[11px]">Marca:</span>
                            <span className="font-semibold text-text-primary mt-0.5 block">
                              {activeProduct.brand?.name ?? 'Sin marca'}
                            </span>
                          </div>
                        </div>

                        {activeProduct.tags && activeProduct.tags.length > 0 && (
                          <div>
                            <span className="text-text-muted block text-[11px] mb-1">Etiquetas:</span>
                            <div className="flex flex-wrap gap-1">
                              {activeProduct.tags.map((t) => (
                                <span
                                  key={t.id}
                                  className="text-[10px] px-1.5 py-0.5 rounded bg-bg border border-border text-text-muted flex items-center gap-1"
                                >
                                  <Tag className="size-2.5" />
                                  {t.name}
                                </span>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>

                      {/* Parámetros de Stock */}
                      <div className="p-3 rounded-lg border border-border bg-surface-subtle/20 space-y-2">
                        <span className="text-[10px] uppercase font-bold tracking-wider text-text-muted block">
                          Parámetros de Control y Reposición
                        </span>
                        <div className="grid grid-cols-3 gap-2">
                          <div>
                            <span className="text-text-muted block text-[11px]">Stock Mínimo:</span>
                            <strong className="text-sm font-mono text-text-primary">
                              {activeProduct.min_stock ?? '—'}
                            </strong>
                          </div>
                          <div>
                            <span className="text-text-muted block text-[11px]">Stock Máximo:</span>
                            <strong className="text-sm font-mono text-text-primary">
                              {activeProduct.max_stock ?? '—'}
                            </strong>
                          </div>
                          <div>
                            <span className="text-text-muted block text-[11px]">Punto de Reorden:</span>
                            <strong className="text-sm font-mono text-text-primary">
                              {activeProduct.reorder_quantity ?? '—'}
                            </strong>
                          </div>
                        </div>
                      </div>

                      {/* Descripción */}
                      {activeProduct.description && (
                        <div className="p-3 rounded-lg border border-border bg-surface-subtle/20 space-y-1">
                          <span className="text-[10px] uppercase font-bold tracking-wider text-text-muted block">
                            Descripción / Observaciones
                          </span>
                          <p className="text-text-secondary leading-relaxed">
                            {stripHtml(activeProduct.description)}
                          </p>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {/* ---------------------------------------------------- */}
                {/* PESTAÑA 4: [F7] SERIALES / IMEIS                     */}
                {/* ---------------------------------------------------- */}
                {activeTab === 'seriales' && (
                  <div className="space-y-3">
                    {activeProduct.tracking_type !== 'serialized' ? (
                      <EmptyState
                        icon={<Boxes className="size-8 text-text-muted" />}
                        title="Producto no serializado"
                        description="Este producto se controla por cantidad simple, no requiere seriales ni IMEIs."
                      />
                    ) : (
                      <>
                        <div className="flex items-center justify-between gap-3">
                          <div className="relative flex-1 max-w-sm">
                            <Search className="text-text-muted absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" />
                            <Input
                              value={serialFilter}
                              onChange={(e: ChangeEvent<HTMLInputElement>) => setSerialFilter(e.target.value)}
                              placeholder="Filtrar por número de serie o almacén..."
                              className="pl-8 h-8 text-xs"
                            />
                          </div>
                          <span className="text-xs text-text-muted">
                            Total: <strong className="text-text-primary">{filteredSerials.length}</strong> serial(es)
                          </span>
                        </div>

                        {loadingSerials ? (
                          <div className="p-8 text-center text-text-muted">
                            <Loader2 className="size-6 animate-spin mx-auto mb-2" />
                            Cargando seriales...
                          </div>
                        ) : filteredSerials.length === 0 ? (
                          <EmptyState
                            icon={<Boxes className="size-8 text-text-muted" />}
                            title="Sin seriales encontrados"
                            description="No hay seriales o IMEIs registrados que coincidan con el filtro."
                          />
                        ) : (
                          <div className="border border-border rounded-lg overflow-hidden max-h-[360px] overflow-y-auto">
                            <table className="w-full text-left text-xs border-collapse">
                              <thead className="bg-muted/50 border-b border-border text-text-secondary uppercase tracking-wider font-semibold text-[11px] sticky top-0">
                                <tr>
                                  <th className="p-2.5">Serial / Identificador</th>
                                  <th className="p-2.5">Tipo</th>
                                  <th className="p-2.5">Almacén</th>
                                  <th className="p-2.5 text-right">Estado</th>
                                </tr>
                              </thead>
                              <tbody className="divide-y divide-border/60 font-mono">
                                {filteredSerials.map((s) => (
                                  <tr key={s.id} className="hover:bg-muted/30">
                                    <td className="p-2.5 font-bold text-text-primary">
                                      {s.serial_number}
                                    </td>
                                    <td className="p-2.5 font-sans text-text-muted capitalize">
                                      {s.serial_type || 'serial'}
                                    </td>
                                    <td className="p-2.5 font-sans text-text-muted">
                                      {s.warehouse_name ?? `Almacén #${s.warehouse_id ?? '—'}`}
                                    </td>
                                    <td className="p-2.5 text-right font-sans">
                                      <Badge
                                        variant={
                                          s.status === 'available'
                                            ? 'success'
                                            : s.status === 'sold'
                                              ? 'default'
                                              : 'warning'
                                        }
                                        className="text-[10px]"
                                      >
                                        {s.status === 'available'
                                          ? 'Disponible'
                                          : s.status === 'sold'
                                            ? 'Vendido'
                                            : s.status}
                                      </Badge>
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        )}
                      </>
                    )}
                  </div>
                )}

                {/* ---------------------------------------------------- */}
                {/* PESTAÑA 5: KARDEX / MOVIMIENTOS RECIENTES            */}
                {/* ---------------------------------------------------- */}
                {activeTab === 'kardex' && (
                  <div className="space-y-3">
                    {loadingMovements ? (
                      <div className="p-8 text-center text-text-muted">
                        <Loader2 className="size-6 animate-spin mx-auto mb-2" />
                        Cargando movimientos de inventario...
                      </div>
                    ) : movements.length === 0 ? (
                      <EmptyState
                        icon={<History className="size-8 text-text-muted" />}
                        title="Sin movimientos registrados"
                        description="Este producto aún no tiene entradas, salidas o traslados."
                      />
                    ) : (
                      <div className="border border-border rounded-lg overflow-hidden max-h-[380px] overflow-y-auto">
                        <table className="w-full text-left text-xs border-collapse">
                          <thead className="bg-muted/50 border-b border-border text-text-secondary uppercase tracking-wider font-semibold text-[11px] sticky top-0">
                            <tr>
                              <th className="p-2.5">Fecha</th>
                              <th className="p-2.5">Tipo</th>
                              <th className="p-2.5">Almacén</th>
                              <th className="p-2.5 text-right">Cantidad</th>
                              <th className="p-2.5">Referencia</th>
                              <th className="p-2.5 text-right">Usuario</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-border/60">
                            {movements.map((mov) => {
                              const isEntry = MOVEMENT_IN_TYPES.has(mov.type);
                              const qty = Number(mov.quantity);

                              return (
                                <tr key={mov.id} className="hover:bg-muted/30">
                                  <td className="p-2.5 text-text-muted whitespace-nowrap">
                                    {mov.created_at ? formatRelative(mov.created_at) : '—'}
                                  </td>
                                  <td className="p-2.5">
                                    <Badge variant={isEntry ? 'success' : 'default'} className="text-[10px]">
                                      {movementTypeLabel(mov.type)}
                                    </Badge>
                                  </td>
                                  <td className="p-2.5 text-text-muted">
                                    {mov.warehouse_name ?? `Almacén #${mov.warehouse_id ?? '—'}`}
                                  </td>
                                  <td className="p-2.5 text-right font-mono font-bold">
                                    <span className={isEntry ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}>
                                      {isEntry ? `+${qty}` : `-${qty}`}
                                    </span>
                                  </td>
                                  <td className="p-2.5 text-text-muted font-mono text-[11px]">
                                    {mov.reference ? referenceTypeLabel(mov.reference) : '—'}
                                  </td>
                                  <td className="p-2.5 text-right text-text-muted">
                                    {mov.user_name ?? '—'}
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* Barra de Atajos de Teclado ERP en el Pie */}
              <div className="p-2.5 border-t border-border bg-surface-subtle/40 flex items-center justify-between text-[11px] text-text-muted flex-wrap gap-2">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">F2</kbd> Editar
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">F3</kbd> Buscar
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">F5</kbd> Precios
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">F6</kbd> Existencia
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">F7</kbd> Seriales
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">F8</kbd> Datos
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">↑/↓</kbd> Navegar
                  </span>
                  <span className="inline-flex items-center gap-1 font-mono">
                    <kbd className="px-1.5 py-0.5 rounded bg-bg border border-border text-text-primary">Enter</kbd> Editar
                  </span>
                </div>

                <div className="flex items-center gap-1 text-[10px]">
                  <span>Modo ERP Dividido</span>
                </div>
              </div>
            </>
          ) : (
            <div className="h-full flex items-center justify-center p-8 text-center">
              <EmptyState
                icon={<Package className="size-10 text-text-muted" />}
                title="Ningún producto seleccionado"
                description="Selecciona un producto de la columna izquierda para inspeccionar sus datos."
              />
            </div>
          )}
        </div>
      </div>

      {/* Dialog de Edición de Producto [F2] */}
      {activeProduct && editDialogOpen && (
        <EditProductDialog
          product={activeProduct}
          open={editDialogOpen}
          onOpenChange={setEditDialogOpen}
        />
      )}
    </div>
  );
}
