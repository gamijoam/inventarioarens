import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import {
  Users,
  Trash2,
  Plus,
  Minus,
  Barcode,
  Package,
  ShoppingBag,
  Clock,
  Search,
  Edit3,
  X,
  Check,
  ArrowLeft,
  LayoutGrid,
  Receipt,
  Wallet,
  FileText,
  Settings2,
  ClipboardList,
} from 'lucide-react';
import type { PosCartLine, CartTotals, PosPaymentLine, PaymentTotals } from './posLogic';
import type { Customer } from './api';
import type { Product, PriceList } from '@/features/inventory-center/schemas';
import { fetchPosProductsDirect } from './api';
import { ProductImage } from '@/features/inventory-center/components/ProductImage';
import { cn } from '@/lib/cn';
import { toast } from 'sonner';

export interface ClassicPosViewProps {
  cart: PosCartLine[];
  cartTotals: CartTotals;
  payments?: PosPaymentLine[];
  paymentTotals?: PaymentTotals;
  activeRate: { code: string; rate: number } | null;
  selectedCustomer: Customer | null;
  customerName?: string;
  sellerName?: string;
  canDiscount?: boolean;
  allowNegativeStock?: boolean;
  warehouseId?: number | null;
  selectedPriceList?: PriceList | null;
  orderNumber?: string;
  pendingCount?: number;
  allowRateEdit?: boolean;
  onSelectCustomer: () => void;
  onOpenPayment: () => void;
  onClearPayments?: () => void;
  onClearCart: () => void;
  onUpdateLine: (lineId: string, patch: Partial<PosCartLine>) => void;
  onRemoveLine: (lineId: string) => void;
  onAddProduct: (product: Product) => Promise<boolean | void>;
  onOpenProductSearch: () => void;
  onHoldSale?: () => void;
  onOpenHoldOrders?: () => void;
  onExitPos?: () => void;
  onChangeViewMode?: (mode: 'catalog' | 'ticket') => void;
  onOpenCashRegister?: () => void;
  onOpenReceipt?: () => void;
  onOpenQuotation?: () => void;
  onOpenQuotations?: () => void;
  onEditRate?: () => void;
}

function formatBs(value: number): string {
  return Number(value || 0).toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatUsd(value: number): string {
  return Number(value || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function getCustomerDoc(customer: Customer | null): string {
  if (!customer) return 'V00000000';
  if (customer.document_type && customer.document_number) {
    return `${customer.document_type}-${customer.document_number}`;
  }
  if (typeof customer.tax_id === 'string' && customer.tax_id.trim()) {
    return customer.tax_id;
  }
  return 'V00000000';
}

export function ClassicPosView({
  cart,
  cartTotals,
  payments = [],
  paymentTotals,
  activeRate,
  selectedCustomer,
  customerName,
  sellerName,
  allowNegativeStock = false,
  warehouseId,
  orderNumber,
  pendingCount = 0,
  allowRateEdit = false,
  onSelectCustomer,
  onOpenPayment,
  onClearPayments,
  onClearCart,
  onUpdateLine,
  onRemoveLine,
  onAddProduct,
  onOpenProductSearch,
  onHoldSale,
  onOpenHoldOrders,
  onExitPos,
  onChangeViewMode,
  onOpenCashRegister,
  onOpenReceipt,
  onOpenQuotation,
  onOpenQuotations,
  onEditRate,
}: ClassicPosViewProps) {
  // Índice del renglón activo en la tabla
  const [selectedIndex, setSelectedIndex] = useState<number>(0);

  // Buscador predictivo en F5
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [searchResults, setSearchResults] = useState<Product[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [activeSearchIndex, setActiveSearchIndex] = useState<number>(0);
  const [showDropdown, setShowDropdown] = useState(false);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const searchContainerRef = useRef<HTMLDivElement>(null);
  const searchDebounceRef = useRef<NodeJS.Timeout | null>(null);

  // Modal rápido de edición de cantidad
  const [editingQtyLine, setEditingQtyLine] = useState<PosCartLine | null>(null);
  const [newQtyValue, setNewQtyValue] = useState<string>('1');
  const qtyInputRef = useRef<HTMLInputElement>(null);

  // Reloj digital en vivo
  const [currentTime, setCurrentTime] = useState<string>(() => {
    const d = new Date();
    return d.toISOString().replace('T', ' ').substring(0, 19);
  });

  useEffect(() => {
    const timer = setInterval(() => {
      const d = new Date();
      const pad = (n: number) => n.toString().padStart(2, '0');
      const formatted = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      setCurrentTime(formatted);
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  // Mantener el índice seleccionado dentro del rango válido
  useEffect(() => {
    if (cart.length === 0) {
      setSelectedIndex(0);
    } else if (selectedIndex >= cart.length) {
      setSelectedIndex(cart.length - 1);
    }
  }, [cart.length, selectedIndex]);

  // Si se añade un nuevo item, auto-seleccionar el último
  const prevCartLengthRef = useRef(cart.length);
  useEffect(() => {
    if (cart.length > prevCartLengthRef.current) {
      setSelectedIndex(cart.length - 1);
    }
    prevCartLengthRef.current = cart.length;
  }, [cart.length]);

  // Línea seleccionada actualmente
  const activeLine: PosCartLine | undefined = cart[selectedIndex];

  // Total de unidades físicas
  const totalUnits = useMemo(() => {
    return cart.reduce((acc, line) => acc + (Number(line.quantity) || 0), 0);
  }, [cart]);

  // Cerrar dropdown si se hace clic afuera
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (searchContainerRef.current && !searchContainerRef.current.contains(e.target as Node)) {
        setShowDropdown(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Búsqueda predictiva con debounce al tipear en F5
  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setSearchTerm(val);

    if (searchDebounceRef.current) {
      clearTimeout(searchDebounceRef.current);
    }

    if (!val || val.trim().length < 2) {
      setSearchResults([]);
      setShowDropdown(false);
      return;
    }

    setIsSearching(true);
    searchDebounceRef.current = setTimeout(async () => {
      try {
        const results = await fetchPosProductsDirect(val.trim(), warehouseId ?? null);
        setSearchResults(results.slice(0, 8)); // Top 8 resultados
        setActiveSearchIndex(0);
        setShowDropdown(results.length > 0);
      } catch {
        setSearchResults([]);
      } finally {
        setIsSearching(false);
      }
    }, 180);
  };

  // Agregar producto seleccionado al ticket
  const handleSelectProduct = useCallback(
    async (product: Product) => {
      try {
        const added = await onAddProduct(product);
        if (added) {
          toast.success(`Agregado: ${product.name}`);
        }
      } catch (err) {
        toast.error('No se pudo agregar el producto');
      } finally {
        setSearchTerm('');
        setSearchResults([]);
        setShowDropdown(false);
        searchInputRef.current?.focus();
      }
    },
    [onAddProduct],
  );

  // Manejo de Enter o escaneo de código de barras
  const handleBarcodeSubmit = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const term = searchTerm.trim();
    if (!term) return;

    // 1. Si hay una lista desplegable abierta con sugerencias y hay una seleccionada
    if (showDropdown && searchResults.length > 0) {
      const target = searchResults[activeSearchIndex] ?? searchResults[0];
      if (target) {
        await handleSelectProduct(target);
        return;
      }
    }

    // 2. Si es escaneo directo o búsqueda directa inmediata
    setIsSearching(true);
    try {
      const results = await fetchPosProductsDirect(term, warehouseId ?? null);
      const normalized = term.toLowerCase();
      const exact = results.find(
        (p) =>
          p.barcode?.toLowerCase() === normalized ||
          p.sku?.toLowerCase() === normalized ||
          p.id.toString() === normalized,
      );

      const target = exact ?? results[0];
      if (target) {
        await handleSelectProduct(target);
      } else {
        toast.error(`Producto no encontrado para: "${term}"`);
      }
    } catch {
      toast.error('Error al consultar el producto');
    } finally {
      setIsSearching(false);
    }
  };

  // Guardar edición rápida de cantidad
  const handleSaveQuantity = (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!editingQtyLine) return;

    const parsed = parseFloat(newQtyValue.replace(',', '.'));
    if (!Number.isFinite(parsed) || parsed <= 0) {
      toast.error('Ingrese una cantidad válida mayor a 0');
      return;
    }

    onUpdateLine(editingQtyLine.id, { quantity: parsed });
    setEditingQtyLine(null);
    searchInputRef.current?.focus();
  };

  // Abrir diálogo de edición de cantidad
  const openQuantityEditor = (line: PosCartLine) => {
    setEditingQtyLine(line);
    setNewQtyValue(String(line.quantity));
    setTimeout(() => {
      qtyInputRef.current?.focus();
      qtyInputRef.current?.select();
    }, 50);
  };

  // Atajos de teclado globales y locales
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      // Si el modal de cantidad está abierto, manejar Enter y Escape
      if (editingQtyLine) {
        if (event.key === 'Escape') {
          event.preventDefault();
          setEditingQtyLine(null);
          searchInputRef.current?.focus();
        }
        return;
      }

      // F1: Cliente
      if (event.key === 'F1') {
        event.preventDefault();
        onSelectCustomer();
        return;
      }
      // F3: Catálogo completo
      if (event.key === 'F3') {
        event.preventDefault();
        onOpenProductSearch();
        return;
      }
      // F5: Foco en escáner / buscador
      if (event.key === 'F5') {
        event.preventDefault();
        searchInputRef.current?.focus();
        searchInputRef.current?.select();
        return;
      }
      // F6: En Espera (Hold)
      if (event.key === 'F6') {
        event.preventDefault();
        onHoldSale?.();
        return;
      }
      // F7: Recuperar pedidos en espera
      if (event.key === 'F7') {
        event.preventDefault();
        onOpenHoldOrders?.();
        return;
      }
      // F10: Cobrar
      if (event.key === 'F10') {
        event.preventDefault();
        onOpenPayment();
        return;
      }

      // Navegación dentro del dropdown del buscador
      const isInput = document.activeElement === searchInputRef.current;
      if (isInput && showDropdown && searchResults.length > 0) {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          setActiveSearchIndex((prev) => (prev + 1) % searchResults.length);
          return;
        }
        if (event.key === 'ArrowUp') {
          event.preventDefault();
          setActiveSearchIndex((prev) => (prev - 1 + searchResults.length) % searchResults.length);
          return;
        }
        if (event.key === 'Escape') {
          event.preventDefault();
          setShowDropdown(false);
          return;
        }
      }

      // Si no estamos en el input de búsqueda, permitir atajos de ticket
      if (!isInput) {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          setSelectedIndex((prev) => (cart.length > 0 ? Math.min(prev + 1, cart.length - 1) : 0));
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          setSelectedIndex((prev) => (cart.length > 0 ? Math.max(prev - 1, 0) : 0));
        } else if (event.key === '+' || event.key === 'Add') {
          if (activeLine) {
            event.preventDefault();
            onUpdateLine(activeLine.id, { quantity: activeLine.quantity + 1 });
          }
        } else if (event.key === '-' || event.key === 'Subtract') {
          if (activeLine) {
            event.preventDefault();
            if (activeLine.quantity > 1) {
              onUpdateLine(activeLine.id, { quantity: activeLine.quantity - 1 });
            } else {
              onRemoveLine(activeLine.id);
            }
          }
        } else if (event.key === 'Delete') {
          if (activeLine) {
            event.preventDefault();
            onRemoveLine(activeLine.id);
          }
        } else if (event.key === '*' || event.key === 'Multiply') {
          if (activeLine) {
            event.preventDefault();
            openQuantityEditor(activeLine);
          }
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [
    activeLine,
    cart.length,
    editingQtyLine,
    handleSelectProduct,
    onHoldSale,
    onOpenHoldOrders,
    onOpenPayment,
    onOpenProductSearch,
    onRemoveLine,
    onSelectCustomer,
    onUpdateLine,
    searchResults,
    showDropdown,
  ]);

  // Cálculos monetarios para los números gigantes superiores
  const rateVal = activeRate?.rate ?? 1;
  const activeLinePriceUsd = activeLine ? Number(activeLine.unit_price) : 0;
  const activeLinePriceBs = activeLinePriceUsd * rateVal;

  const totalUsd = Number(cartTotals.total) || 0;
  const totalBs = totalUsd * rateVal;

  // Número correlativo real de factura/ticket
  const formattedDocNo = orderNumber
    ? `FAC${orderNumber.replace(/\D/g, '').padStart(8, '0')}`
    : 'FAC00000143';

  // Estado de los pagos
  const isFullyPaid = payments.length > 0 && (paymentTotals?.remaining ?? 0) <= 0;
  const hasPartialPayments = payments.length > 0 && (paymentTotals?.remaining ?? 0) > 0;

  return (
    <div className="flex flex-col flex-1 h-full min-h-0 bg-[#f1f5f9] select-none font-sans overflow-hidden">
      {/* ========================================================================= */}
      {/* 1. HEADER SUPERIOR CON INDICADORES GIGANTES (ESTILO 1SISTEMA / VALERY)      */}
      {/* ========================================================================= */}
      <div className="grid grid-cols-12 gap-1.5 p-2 bg-[#0b1329] border-b-2 border-blue-900 text-white shrink-0 items-stretch">
        {/* BLOQUE FECHA / HORA / FACTURA CORRELATIVA REAL */}
        <div className="col-span-12 sm:col-span-3 flex flex-col justify-between gap-1">
          <div className="bg-[#1e293b] border border-blue-900/80 rounded px-2.5 py-1 text-center font-mono text-xs sm:text-sm font-semibold tracking-wide text-blue-200 shadow-inner flex items-center justify-center gap-1.5">
            <Clock className="size-3.5 text-blue-400 shrink-0" />
            <span>{currentTime}</span>
          </div>
          <div
            className="bg-red-950/80 border-2 border-red-500 rounded px-2.5 py-0.5 text-center font-mono text-xs sm:text-sm font-black tracking-widest text-red-300 shadow-inner"
            title="Número correlativo de factura / ticket para esta venta"
          >
            {formattedDocNo}
          </div>
        </div>

        {/* BLOQUE PRECIO UNITARIO (VERDE) */}
        <div className="col-span-4 sm:col-span-3 bg-gradient-to-b from-[#22c55e] to-[#15803d] rounded-lg p-1.5 sm:p-2 border-2 border-emerald-400 flex flex-col justify-between shadow-md">
          <div className="flex items-center justify-between">
            <span className="text-[10px] sm:text-xs font-black uppercase tracking-wider text-emerald-950">
              PRECIO/U
            </span>
            <span className="bg-white/90 text-emerald-900 font-mono font-black text-[10px] sm:text-xs px-1.5 py-0.2 rounded shadow-xs">
              ${formatUsd(activeLinePriceUsd)}
            </span>
          </div>
          <div className="text-right font-mono font-black text-xl sm:text-3xl lg:text-4xl text-white tracking-tight leading-none drop-shadow-sm truncate">
            {formatBs(activeLinePriceBs)}
          </div>
        </div>

        {/* BLOQUE ITEMS TOTALES (NARANJA / AMBAR) */}
        <div className="col-span-3 sm:col-span-2 bg-gradient-to-b from-[#f59e0b] to-[#d97706] rounded-lg p-1.5 sm:p-2 border-2 border-amber-300 flex flex-col justify-between shadow-md">
          <span className="text-[10px] sm:text-xs font-black uppercase tracking-wider text-amber-950">
            ITEMS
          </span>
          <div className="text-center font-mono font-black text-2xl sm:text-3xl lg:text-4xl text-white tracking-tight leading-none drop-shadow-sm">
            {totalUnits}
          </div>
        </div>

        {/* BLOQUE GRAN TOTAL FACTURA (ROJO GIGANTE / VERDE SI YA ESTÁ PAGADO) */}
        <button
          type="button"
          onClick={onOpenPayment}
          disabled={cart.length === 0}
          title={
            isFullyPaid
              ? 'Haga clic aquí o presione F10 para confirmar y emitir factura'
              : 'Haga clic aquí o presione F10 para cobrar'
          }
          className={cn(
            'col-span-5 sm:col-span-4 bg-gradient-to-b from-[#ef4444] to-[#b91c1c] rounded-lg p-1.5 sm:p-2 border-2 border-red-300 flex flex-col justify-between shadow-lg transition-transform active:scale-[0.99] text-left cursor-pointer group',
            isFullyPaid &&
              'from-emerald-600 to-green-700 border-emerald-300 ring-2 ring-emerald-400 shadow-emerald-900/50',
            cart.length === 0 && 'opacity-70 cursor-not-allowed',
          )}
        >
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-1.5">
              <span className="text-[10px] sm:text-xs font-black uppercase tracking-wider text-white">
                {isFullyPaid ? 'CONFIRMAR [F10]' : 'TOTAL [F10]'}
              </span>
              <span className="hidden lg:inline-block text-[9px] bg-black/40 text-white px-1.5 py-0.2 rounded uppercase font-bold">
                {isFullyPaid ? 'LISTO' : 'COBRAR'}
              </span>
            </div>
            <span className="bg-white/95 text-slate-900 font-mono font-black text-[10px] sm:text-xs px-2 py-0.2 rounded shadow-xs">
              REF {formatUsd(totalUsd)}
            </span>
          </div>
          <div className="text-right font-mono font-black text-xl sm:text-3xl lg:text-4xl text-white tracking-tight leading-none drop-shadow-md truncate">
            <span className="text-xs sm:text-sm mr-1 font-bold opacity-90">Bss</span>
            {formatBs(totalBs)}
          </div>
        </button>
      </div>

      {/* ========================================================================= */}
      {/* 2. SUB-HEADER: RENGLÓN ACTIVO CON NÚMERO Y NOMBRE GIGANTE                 */}
      {/* ========================================================================= */}
      <div className="flex items-center gap-2 p-2 bg-[#0284c7] border-b border-sky-600 shadow-sm shrink-0">
        <div className="bg-white text-slate-900 border-2 border-slate-700 rounded-md font-mono font-black text-lg sm:text-2xl px-3 py-1 min-w-[50px] text-center shadow-inner">
          {cart.length > 0 && activeLine ? selectedIndex + 1 : '-'}
        </div>
        <div className="flex-1 bg-white border-2 border-slate-700 rounded-md px-3 py-1.5 shadow-inner min-w-0">
          <p className="font-mono font-black text-base sm:text-xl lg:text-2xl text-slate-900 truncate uppercase tracking-tight">
            {activeLine ? activeLine.name : 'SIN ARTÍCULOS - ESCANEE O BUSQUE UN PRODUCTO [F5]'}
          </p>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 3. CONTENIDO PRINCIPAL: PANEL IZQUIERDO AZUL + TABLA DERECHA DE RENGLONES  */}
      {/* ========================================================================= */}
      <div className="flex flex-1 min-h-0 overflow-hidden">
        {/* PANEL LATERAL IZQUIERDO (FONDO AZUL REY TRADICIONAL) */}
        <div className="w-[280px] sm:w-[320px] lg:w-[350px] bg-[#1e40af] text-white p-3 flex flex-col justify-between gap-3 border-r-2 border-blue-900 shrink-0 shadow-lg overflow-y-auto">
          <div className="space-y-3">
            {/* F1. CLIENTE */}
            <div className="bg-blue-950/60 border border-blue-400/40 rounded-xl p-3 shadow-sm space-y-2">
              <button
                type="button"
                onClick={onSelectCustomer}
                className="w-full flex items-center justify-between text-left group cursor-pointer hover:bg-blue-800/40 p-1.5 rounded-lg transition-colors"
                title="Haga clic o presione F1 para seleccionar cliente"
              >
                <span className="font-mono font-black text-sm sm:text-base text-yellow-300 group-hover:text-yellow-200 flex items-center gap-2">
                  <span className="bg-yellow-400 text-blue-950 px-1.5 py-0.5 rounded font-black text-xs">
                    F1
                  </span>
                  <span>Cliente:</span>
                </span>
                <span className="text-xs font-mono text-blue-200 underline opacity-90 group-hover:opacity-100 font-semibold">
                  {selectedCustomer ? 'Cambiar' : 'Seleccionar'}
                </span>
              </button>

              <div className="flex items-center gap-3 pt-1">
                <div className="size-12 rounded-full bg-gradient-to-tr from-cyan-400 to-blue-600 border-2 border-white flex items-center justify-center shrink-0 shadow-md">
                  <Users className="size-7 text-white" />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-sm sm:text-base uppercase truncate text-white leading-tight">
                    {selectedCustomer
                      ? selectedCustomer.name
                      : (customerName ?? 'CONSUMIDOR FINAL')}
                  </p>
                  <p className="font-mono text-xs sm:text-sm text-blue-200 mt-0.5 font-bold">
                    {getCustomerDoc(selectedCustomer)}
                  </p>
                  {selectedCustomer?.phone && (
                    <p className="font-mono text-xs text-blue-300 truncate">
                      {selectedCustomer.phone}
                    </p>
                  )}
                </div>
              </div>
            </div>

            {/* F2. VENDEDOR */}
            <div className="bg-blue-950/40 border border-blue-400/30 rounded-lg px-3 py-2 flex items-center justify-between">
              <span className="font-mono font-black text-xs sm:text-sm text-yellow-300 flex items-center gap-2">
                <span className="bg-yellow-400 text-blue-950 px-1.5 py-0.5 rounded font-black text-xs">
                  F2
                </span>
                <span>Vendedor:</span>
              </span>
              <span className="font-bold text-xs sm:text-sm text-white uppercase truncate max-w-[180px]">
                {sellerName ?? 'CAJA PRINCIPAL'}
              </span>
            </div>

            {/* F5. BUSCADOR INTELIGENTE EN VIVO CON DROPDOWN Y ESCÁNER */}
            <div ref={searchContainerRef} className="relative space-y-1.5">
              <div className="flex items-center justify-between text-xs font-bold text-blue-200">
                <span className="flex items-center gap-1.5">
                  <Barcode className="size-4" />
                  <span>Escáner / Buscador en Vivo</span>
                </span>
                <button
                  type="button"
                  onClick={onOpenProductSearch}
                  className="font-mono text-xs bg-blue-950 hover:bg-blue-900 border border-blue-400/30 px-2 py-0.5 rounded text-yellow-300 flex items-center gap-1 cursor-pointer transition-colors"
                  title="Abrir catálogo completo [F3]"
                >
                  <Search className="size-3" />
                  <span>[F3] Catálogo</span>
                </button>
              </div>

              <form onSubmit={handleBarcodeSubmit} className="flex items-center gap-1">
                <div className="bg-black text-white font-mono font-black text-base px-3 py-2.5 rounded-l-lg border border-slate-700 shrink-0 shadow-inner">
                  F5
                </div>
                <input
                  id="pos-classic-barcode-input"
                  ref={searchInputRef}
                  type="text"
                  value={searchTerm}
                  onChange={handleSearchChange}
                  onFocus={() => {
                    if (searchResults.length > 0) setShowDropdown(true);
                  }}
                  placeholder="Escriba nombre o código..."
                  autoComplete="off"
                  className="flex-1 bg-white text-slate-900 font-mono font-bold text-base px-3.5 py-2.5 rounded-r-lg border border-slate-400 focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 shadow-inner"
                />
              </form>

              {/* DROPDOWN PREDICTIVO FLOTANTE DE RESULTADOS EN TIEMPO REAL */}
              {showDropdown && (
                <div className="absolute top-full left-0 right-0 mt-1 bg-white border-2 border-blue-500 rounded-xl shadow-2xl z-50 overflow-hidden text-slate-900 animate-in fade-in-50 zoom-in-95 duration-150">
                  <div className="bg-slate-100 border-b border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-600 flex items-center justify-between">
                    <span>Resultados coincidentes ({searchResults.length})</span>
                    <span className="text-[10px] text-slate-400 font-mono">
                      ↑↓ Navegar • Enter Seleccionar
                    </span>
                  </div>

                  <div className="max-h-60 overflow-y-auto divide-y divide-slate-100">
                    {isSearching ? (
                      <div className="p-3 text-center text-xs text-slate-500">
                        Buscando productos...
                      </div>
                    ) : searchResults.length === 0 ? (
                      <div className="p-3 text-center text-xs text-slate-500">
                        No se encontraron productos coincidentes
                      </div>
                    ) : (
                      searchResults.map((product, idx) => {
                        const isSelected = idx === activeSearchIndex;
                        const priceUsd = Number(product.base_price ?? 0);
                        const priceBs = priceUsd * rateVal;
                        const stock = Number(product.available_stock ?? 0);

                        return (
                          <div
                            key={product.id}
                            onClick={() => void handleSelectProduct(product)}
                            onMouseEnter={() => setActiveSearchIndex(idx)}
                            className={cn(
                              'p-2 flex items-center gap-2.5 cursor-pointer transition-colors',
                              isSelected ? 'bg-blue-600 text-white' : 'hover:bg-blue-50',
                            )}
                          >
                            <ProductImage
                              src={product.image_url ?? undefined}
                              alt={product.name}
                              variant="thumb"
                              fit="contain"
                              className="size-9 rounded border border-slate-200 bg-white shrink-0 object-contain"
                            />
                            <div className="min-w-0 flex-1">
                              <p
                                className={cn(
                                  'font-bold text-xs truncate leading-snug',
                                  isSelected ? 'text-white' : 'text-slate-900',
                                )}
                              >
                                {product.name}
                              </p>
                              <div className="flex items-center gap-2 mt-0.5 text-[11px] font-mono">
                                <span
                                  className={cn(
                                    'truncate max-w-[120px]',
                                    isSelected ? 'text-blue-200' : 'text-slate-500',
                                  )}
                                >
                                  {product.barcode || product.sku || `ID: ${product.id}`}
                                </span>
                                <span
                                  className={cn(
                                    'px-1 rounded text-[10px] font-bold',
                                    stock > 0
                                      ? isSelected
                                        ? 'bg-emerald-500 text-white'
                                        : 'bg-emerald-100 text-emerald-800'
                                      : isSelected
                                        ? 'bg-red-400 text-slate-900'
                                        : 'bg-red-100 text-red-800',
                                  )}
                                >
                                  Stock: {stock}
                                </span>
                              </div>
                            </div>
                            <div className="text-right shrink-0">
                              <p
                                className={cn(
                                  'font-mono font-bold text-xs',
                                  isSelected ? 'text-white' : 'text-slate-900',
                                )}
                              >
                                ${formatUsd(priceUsd)}
                              </p>
                              <p
                                className={cn(
                                  'font-mono text-[10px]',
                                  isSelected ? 'text-blue-200' : 'text-slate-500',
                                )}
                              >
                                Bs {formatBs(priceBs)}
                              </p>
                            </div>
                          </div>
                        );
                      })
                    )}
                  </div>
                </div>
              )}
            </div>

            {/* VISOR DE FOTO GRANDE DEL PRODUCTO SELECCIONADO */}
            <div className="bg-white rounded-xl border-2 border-blue-300/80 p-2 shadow-inner flex flex-col items-center justify-center min-h-[170px] sm:min-h-[200px] lg:min-h-[220px] max-h-[260px] overflow-hidden relative">
              {activeLine ? (
                <div className="w-full h-full flex flex-col items-center justify-center">
                  <ProductImage
                    src={activeLine.image_url ?? undefined}
                    alt={activeLine.name}
                    variant="medium"
                    fit="contain"
                    className="w-full h-[150px] sm:h-[180px] object-contain rounded-lg"
                  />
                  <div className="w-full mt-1.5 pt-1 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono text-slate-600 px-1">
                    <span className="truncate max-w-[140px] font-semibold text-slate-800">
                      {activeLine.sku || activeLine.barcode || 'SKU'}
                    </span>
                    <span className="text-emerald-700 font-bold">
                      Stock: {activeLine.available_stock}
                    </span>
                  </div>
                </div>
              ) : (
                <div className="text-center p-4 text-slate-400 flex flex-col items-center justify-center">
                  <Package className="size-16 stroke-[1.25] text-slate-300 mb-2" />
                  <p className="font-bold text-xs text-slate-600">Vista de Foto del Artículo</p>
                  <p className="text-[10px] text-slate-400 mt-0.5">
                    Seleccione un renglón para previsualizarlo
                  </p>
                </div>
              )}
            </div>
          </div>

          {/* PIE DEL PANEL IZQUIERDO: LOGOTIPO / MARCA ESTILO 1SISTEMA */}
          <div className="pt-2 border-t border-blue-500/40 flex items-center justify-between text-blue-200 text-xs">
            <div className="flex items-center gap-1.5 font-black tracking-wider text-sm text-white">
              <span className="bg-blue-600 px-1.5 py-0.5 rounded text-[11px] font-mono shadow-xs">
                POS
              </span>
              <span>BALANZAPRO</span>
            </div>
            <span className="font-mono text-[10px] text-blue-300 font-semibold opacity-75">
              VISTA CLÁSICA
            </span>
          </div>
        </div>

        {/* TABLA DERECHA DE RENGLONES (TICKET FACTURA) */}
        <div className="flex-1 flex flex-col min-w-0 bg-white overflow-hidden">
          {/* BARRA SUPERIOR DE LA TABLA (CONTROLES RÁPIDOS) */}
          <div className="bg-slate-100 border-b border-slate-300 px-4 py-2 flex items-center justify-between text-sm text-slate-700">
            <div className="flex items-center gap-3">
              <span className="font-bold uppercase tracking-wide text-slate-800 text-sm">
                Líneas del Ticket: <span className="font-mono text-blue-700 font-black text-base">{cart.length}</span>
              </span>
              <span className="text-slate-400">|</span>
              <span className="text-slate-600 hidden md:inline text-xs">
                Navegue con <kbd className="px-1.5 py-0.5 bg-white border rounded font-mono text-xs">↑</kbd> <kbd className="px-1.5 py-0.5 bg-white border rounded font-mono text-xs">↓</kbd>, cambie cantidad con doble clic o <kbd className="px-1.5 py-0.5 bg-white border rounded font-mono text-xs">*</kbd>
              </span>
            </div>

            {cart.length > 0 && (
              <button
                type="button"
                onClick={onClearCart}
                className="text-red-600 hover:text-red-800 hover:bg-red-50 px-2.5 py-1 rounded-md text-xs sm:text-sm font-semibold flex items-center gap-1.5 transition-colors cursor-pointer"
                title="Vaciar ticket completo"
              >
                <Trash2 className="size-4" />
                <span>Vaciar ticket</span>
              </button>
            )}
          </div>

          {/* TABLA CON ENCABEZADO NEGRO ESTILO 1SISTEMA */}
          <div className="flex-1 overflow-y-auto overscroll-contain bg-white min-h-0">
            <table className="w-full border-collapse text-left font-sans text-sm sm:text-base">
              {/* ENCABEZADOS NEGROS */}
              <thead className="bg-[#0f172a] text-white font-mono sticky top-0 z-10 shadow-xs text-xs sm:text-sm">
                <tr>
                  <th className="py-2.5 px-3 font-bold uppercase w-12 text-center border-r border-slate-700">
                    #
                  </th>
                  <th className="py-2.5 px-3 font-bold uppercase w-36 border-r border-slate-700">
                    Codigo
                  </th>
                  <th className="py-2.5 px-4 font-bold uppercase border-r border-slate-700">
                    Descripcion
                  </th>
                  <th className="py-2.5 px-3 font-bold uppercase w-16 text-center border-r border-slate-700">
                    I.V.A
                  </th>
                  <th className="py-2.5 px-3 font-bold uppercase w-32 text-right border-r border-slate-700">
                    Precio (Bs)
                  </th>
                  <th className="py-2.5 px-3 font-bold uppercase w-24 text-center border-r border-slate-700">
                    Cant.
                  </th>
                  <th className="py-2.5 px-3 font-bold uppercase w-14 text-center border-r border-slate-700">
                    Dec.
                  </th>
                  <th className="py-2.5 px-4 font-bold uppercase w-36 text-right border-r border-slate-700">
                    Total (Bs)
                  </th>
                  <th className="py-2.5 px-3 font-bold uppercase w-28 text-center">
                    Acción
                  </th>
                </tr>
              </thead>

              {/* FILAS DE PRODUCTOS */}
              <tbody className="divide-y divide-slate-200 font-mono">
                {cart.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="py-20 text-center text-slate-400 font-sans">
                      <Package className="size-16 mx-auto mb-3 text-slate-300 stroke-[1.25]" />
                      <p className="font-bold text-base text-slate-600">Ticket vacío</p>
                      <p className="text-sm text-slate-400 mt-1">
                        Comience escribiendo o escaneando en el campo [F5] a la izquierda.
                      </p>
                    </td>
                  </tr>
                ) : (
                  cart.map((line, index) => {
                    const isSelected = index === selectedIndex;
                    const linePriceBs = Number(line.unit_price) * rateVal;
                    const lineTotalBs = linePriceBs * Number(line.quantity);
                    const stockIssue =
                      !allowNegativeStock && line.quantity > line.available_stock;

                    return (
                      <tr
                        key={line.id}
                        onClick={() => setSelectedIndex(index)}
                        onDoubleClick={() => openQuantityEditor(line)}
                        className={cn(
                          'cursor-pointer transition-colors group select-none text-sm sm:text-base',
                          isSelected
                            ? 'bg-[#2563eb] text-white font-bold shadow-xs'
                            : index % 2 === 0
                              ? 'bg-white text-slate-900 hover:bg-blue-50'
                              : 'bg-slate-50 text-slate-900 hover:bg-blue-50',
                          stockIssue && !isSelected && 'bg-amber-50 text-amber-900',
                        )}
                      >
                        {/* 1. NÚMERO DE RENGLÓN */}
                        <td
                          className={cn(
                            'py-3 px-3 text-center border-r',
                            isSelected
                              ? 'border-blue-500 font-black'
                              : 'border-slate-200 text-slate-500 font-bold',
                          )}
                        >
                          {index + 1}
                        </td>

                        {/* 2. CÓDIGO (BARCODE / SKU) */}
                        <td
                          className={cn(
                            'py-3 px-3 truncate max-w-[140px] border-r font-semibold font-mono text-xs sm:text-sm',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-700',
                          )}
                          title={line.barcode || line.sku || ''}
                        >
                          {line.barcode || line.sku || '-'}
                        </td>

                        {/* 3. DESCRIPCIÓN */}
                        <td
                          className={cn(
                            'py-3 px-4 border-r font-sans font-medium text-sm sm:text-base',
                            isSelected ? 'border-blue-500 text-white font-bold' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          <div className="flex items-center gap-2">
                            <span className="truncate leading-tight">{line.name}</span>
                            {stockIssue && (
                              <span
                                className={cn(
                                  'text-xs px-1.5 py-0.5 rounded font-mono shrink-0 font-bold',
                                  isSelected ? 'bg-amber-400 text-slate-900' : 'bg-red-100 text-red-700',
                                )}
                                title={`Stock disponible: ${line.available_stock}`}
                              >
                                ⚠️ Stock ({line.available_stock})
                              </span>
                            )}
                          </div>
                        </td>

                        {/* 4. I.V.A (16% o E) */}
                        <td
                          className={cn(
                            'py-3 px-3 text-center border-r font-semibold text-xs sm:text-sm',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-600',
                          )}
                        >
                          16%
                        </td>

                        {/* 5. PRECIO UNITARIO (BS) */}
                        <td
                          className={cn(
                            'py-3 px-3 text-right border-r tabular-nums font-bold text-sm sm:text-base',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          {formatBs(linePriceBs)}
                        </td>

                        {/* 6. CANTIDAD (EDITABLE CON CLIC O DOBLE CLIC) */}
                        <td
                          onClick={(e) => {
                            e.stopPropagation();
                            openQuantityEditor(line);
                          }}
                          className={cn(
                            'py-3 px-3 text-center border-r font-black text-base sm:text-lg hover:underline cursor-pointer',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-900',
                          )}
                          title="Haga clic para editar cantidad directamente"
                        >
                          <span className="inline-flex items-center gap-1.5">
                            <span>{line.quantity}</span>
                            <Edit3 className="size-3.5 opacity-60 inline" />
                          </span>
                        </td>

                        {/* 7. DECIMALES */}
                        <td
                          className={cn(
                            'py-3 px-3 text-center border-r text-xs sm:text-sm',
                            isSelected ? 'border-blue-500 text-blue-200' : 'border-slate-200 text-slate-400',
                          )}
                        >
                          0
                        </td>

                        {/* 8. TOTAL (BS) */}
                        <td
                          className={cn(
                            'py-3 px-4 text-right border-r tabular-nums font-black text-sm sm:text-base',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          {formatBs(lineTotalBs)}
                        </td>

                        {/* 9. ACCIONES RÁPIDAS (+, -, TRASH) */}
                        <td className="py-2 px-2 text-center">
                          <div className="flex items-center justify-center gap-1.5">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                onUpdateLine(line.id, { quantity: Number(line.quantity) + 1 });
                              }}
                              className={cn(
                                'size-8 rounded-lg flex items-center justify-center transition-all cursor-pointer shadow-xs active:scale-95',
                                isSelected
                                  ? 'bg-blue-700 hover:bg-blue-800 text-white ring-1 ring-white/40'
                                  : 'bg-slate-200 hover:bg-slate-300 text-slate-800 hover:text-black',
                              )}
                              title="Aumentar cantidad (+)"
                            >
                              <Plus className="size-4.5 stroke-[2.5]" />
                            </button>
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                const currentQ = Number(line.quantity);
                                if (currentQ > 1) {
                                  onUpdateLine(line.id, { quantity: currentQ - 1 });
                                } else {
                                  onRemoveLine(line.id);
                                }
                              }}
                              className={cn(
                                'size-8 rounded-lg flex items-center justify-center transition-all cursor-pointer shadow-xs active:scale-95',
                                isSelected
                                  ? 'bg-blue-700 hover:bg-blue-800 text-white ring-1 ring-white/40'
                                  : 'bg-slate-200 hover:bg-slate-300 text-slate-800 hover:text-black',
                              )}
                              title="Disminuir cantidad (-)"
                            >
                              <Minus className="size-4.5 stroke-[2.5]" />
                            </button>
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                onRemoveLine(line.id);
                              }}
                              className={cn(
                                'size-8 rounded-lg flex items-center justify-center transition-all cursor-pointer shadow-xs active:scale-95',
                                isSelected
                                  ? 'bg-red-500 hover:bg-red-600 text-white'
                                  : 'hover:bg-red-100 text-slate-400 hover:text-red-600',
                              )}
                              title="Eliminar renglón"
                            >
                              <Trash2 className="size-4 stroke-[2]" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* BARRA INFERIOR DE RESUMEN Y BOTÓN COBRAR */}
          <div className="bg-slate-100 border-t-2 border-slate-300 px-4 py-3 flex items-center justify-between text-sm sm:text-base">
            <div className="flex items-center gap-5 text-slate-700 font-mono text-sm sm:text-base">
              <div>
                <span className="text-slate-500">Subtotal: </span>
                <span className="font-bold text-slate-900">${formatUsd(cartTotals.subtotal)}</span>
              </div>
              {cartTotals.discount > 0 && (
                <div>
                  <span className="text-slate-500">Descuento: </span>
                  <span className="font-bold text-red-600">-${formatUsd(cartTotals.discount)}</span>
                </div>
              )}
              {activeRate && (
                <div className="hidden sm:inline">
                  <span className="text-slate-500">Tasa: </span>
                  <span className="font-bold text-blue-700">
                    {activeRate.code} = {formatBs(activeRate.rate)}
                  </span>
                </div>
              )}
            </div>

            <div className="flex items-center gap-3">
              {payments.length > 0 && (
                <div className="flex items-center gap-2 bg-emerald-100 text-emerald-950 border border-emerald-300 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold font-mono">
                  <span>Pagado: ${formatUsd(paymentTotals?.paid ?? 0)}</span>
                  {hasPartialPayments && (
                    <span className="text-amber-800">
                      (Resta: ${formatUsd(paymentTotals?.remaining ?? 0)})
                    </span>
                  )}
                  {onClearPayments && (
                    <button
                      type="button"
                      onClick={onClearPayments}
                      className="text-slate-500 hover:text-red-700 ml-1 p-0.5 rounded cursor-pointer"
                      title="Quitar métodos de pago seleccionados"
                    >
                      <X className="size-4" />
                    </button>
                  )}
                </div>
              )}

              <button
                type="button"
                onClick={onOpenPayment}
                disabled={cart.length === 0}
                className={cn(
                  'font-bold px-5 py-2.5 rounded-xl flex items-center gap-2.5 shadow-md transition-all cursor-pointer font-sans text-sm sm:text-base',
                  cart.length === 0
                    ? 'bg-slate-300 text-slate-500 cursor-not-allowed opacity-50'
                    : isFullyPaid
                      ? 'bg-gradient-to-r from-emerald-600 to-green-700 hover:from-emerald-700 hover:to-green-800 text-white ring-2 ring-emerald-300 shadow-lg animate-pulse'
                      : 'bg-emerald-600 hover:bg-emerald-700 text-white',
                )}
              >
                <ShoppingBag className="size-5" />
                <span>
                  {isFullyPaid
                    ? 'CONFIRMAR Y FACTURAR [F10]'
                    : hasPartialPayments
                      ? 'COMPLETAR PAGO [F10]'
                      : 'COBRAR FACTURA [F10]'}
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 4. BARRA INFERIOR DE TECLAS DE FUNCIÓN Y CONTROLES DEL SISTEMA           */}
      {/* ========================================================================= */}
      <footer className="bg-[#0f172a] border-t-2 border-slate-800 px-3 py-2 text-white shrink-0 overflow-x-auto shadow-2xl">
        <div className="flex items-center justify-between gap-2 min-w-max text-xs sm:text-sm font-mono">
          {/* GRUPO 1: VOLVER Y CAMBIO DE VISTA */}
          <div className="flex items-center gap-1.5 pr-2 border-r border-slate-700">
            {onExitPos && (
              <button
                type="button"
                onClick={onExitPos}
                className="flex items-center gap-1.5 bg-rose-950/80 hover:bg-rose-800 text-rose-200 hover:text-white px-2.5 py-1.5 rounded-lg border border-rose-700/60 transition-colors cursor-pointer"
                title="Volver atrás / Salir del POS [Esc]"
              >
                <ArrowLeft className="size-4" />
                <span className="font-bold font-sans">Volver</span>
              </button>
            )}

            {onChangeViewMode && (
              <button
                type="button"
                onClick={() => onChangeViewMode('catalog')}
                className="flex items-center gap-1.5 bg-slate-800 hover:bg-blue-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
                title="Cambiar a vista Catálogo"
              >
                <LayoutGrid className="size-4 text-sky-400" />
                <span className="font-sans text-xs">Catálogo</span>
              </button>
            )}
          </div>

          {/* GRUPO 2: ATAJOS DE VENTA CLÁSICOS (F1 a F10) */}
          <div className="flex items-center gap-1.5">
            <button
              type="button"
              onClick={onSelectCustomer}
              className="flex items-center gap-1.5 bg-slate-800 hover:bg-blue-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
            >
              <span className="font-black text-yellow-400">F1</span>
              <span className="text-slate-300">Cliente</span>
            </button>

            <button
              type="button"
              onClick={onOpenProductSearch}
              className="flex items-center gap-1.5 bg-slate-800 hover:bg-blue-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
            >
              <span className="font-black text-yellow-400">F3</span>
              <span className="text-slate-300">Catálogo</span>
            </button>

            <button
              type="button"
              onClick={() => searchInputRef.current?.focus()}
              className="flex items-center gap-1.5 bg-slate-800 hover:bg-blue-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
            >
              <span className="font-black text-yellow-400">F5</span>
              <span className="text-slate-300">Escáner</span>
            </button>

            {onHoldSale && (
              <button
                type="button"
                onClick={onHoldSale}
                disabled={cart.length === 0}
                className={cn(
                  'flex items-center gap-1.5 bg-slate-800 hover:bg-amber-600 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer',
                  cart.length === 0 && 'opacity-50 cursor-not-allowed',
                )}
              >
                <span className="font-black text-yellow-400">F6</span>
                <span className="text-slate-300">En Espera</span>
              </button>
            )}

            {onOpenHoldOrders && (
              <button
                type="button"
                onClick={onOpenHoldOrders}
                className="flex items-center gap-1.5 bg-slate-800 hover:bg-sky-600 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer relative"
              >
                <span className="font-black text-yellow-400">F7</span>
                <span className="text-slate-300">Pendientes</span>
                {pendingCount > 0 && (
                  <span className="bg-amber-400 text-slate-950 font-black rounded-full px-1.5 text-[10px]">
                    {pendingCount}
                  </span>
                )}
              </button>
            )}

            <button
              type="button"
              onClick={onOpenPayment}
              disabled={cart.length === 0}
              className={cn(
                'flex items-center gap-2 bg-emerald-700 hover:bg-emerald-600 px-3.5 py-1.5 rounded-lg border border-emerald-500 font-bold transition-colors cursor-pointer',
                cart.length === 0 && 'opacity-50 cursor-not-allowed',
              )}
            >
              <span className="font-black text-white text-base">F10</span>
              <span className="text-white uppercase font-bold">Cobrar</span>
            </button>

            <button
              type="button"
              onClick={() => {
                if (activeLine) onRemoveLine(activeLine.id);
              }}
              disabled={!activeLine}
              className={cn(
                'flex items-center gap-1.5 bg-slate-800 hover:bg-red-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer',
                !activeLine && 'opacity-50 cursor-not-allowed',
              )}
            >
              <span className="font-black text-red-400">Supr</span>
              <span className="text-slate-300">Eliminar</span>
            </button>

            <button
              type="button"
              onClick={() => {
                if (activeLine) openQuantityEditor(activeLine);
              }}
              disabled={!activeLine}
              className={cn(
                'flex items-center gap-1.5 bg-slate-800 hover:bg-purple-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer',
                !activeLine && 'opacity-50 cursor-not-allowed',
              )}
            >
              <span className="font-black text-purple-300 text-base">*</span>
              <span className="text-slate-300">Cantidad</span>
            </button>
          </div>

          {/* GRUPO 3: OPERACIONES ADICIONALES (Caja, Recibo, Cotización, Tasa) */}
          <div className="flex items-center gap-1.5 pl-2 border-l border-slate-700">
            {onOpenCashRegister && (
              <button
                type="button"
                onClick={onOpenCashRegister}
                className="flex items-center gap-1.5 bg-slate-800 hover:bg-emerald-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
                title="Movimientos y cierre de caja"
              >
                <Wallet className="size-3.5 text-emerald-400" />
                <span className="text-slate-300">Caja</span>
              </button>
            )}

            {onOpenReceipt && (
              <button
                type="button"
                onClick={onOpenReceipt}
                className="flex items-center gap-1.5 bg-slate-800 hover:bg-blue-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
                title="Reimprimir último recibo"
              >
                <Receipt className="size-3.5 text-blue-400" />
                <span className="text-slate-300">Recibo</span>
              </button>
            )}

            {onOpenQuotation && (
              <button
                type="button"
                onClick={onOpenQuotation}
                disabled={cart.length === 0}
                className={cn(
                  'flex items-center gap-1.5 bg-slate-800 hover:bg-purple-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer',
                  cart.length === 0 && 'opacity-50 cursor-not-allowed',
                )}
                title="Crear cotización a partir de este carrito"
              >
                <FileText className="size-3.5 text-purple-400" />
                <span className="text-slate-300">Cotizar</span>
              </button>
            )}

            {onOpenQuotations && (
              <button
                type="button"
                onClick={onOpenQuotations}
                className="flex items-center gap-1.5 bg-slate-800 hover:bg-indigo-700 px-2.5 py-1.5 rounded-lg border border-slate-700 transition-colors cursor-pointer"
                title="Ver lista de cotizaciones guardadas"
              >
                <ClipboardList className="size-3.5 text-indigo-400" />
                <span className="text-slate-300">Cotizaciones</span>
              </button>
            )}

            {activeRate && (
              <div
                onClick={onEditRate}
                className={cn(
                  'flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-700 bg-slate-900/90 text-xs font-bold font-mono',
                  allowRateEdit && onEditRate
                    ? 'hover:border-yellow-400 text-yellow-300 cursor-pointer'
                    : 'text-slate-300',
                )}
                title={
                  allowRateEdit
                    ? 'Tasa de cambio activa. Clic para editar.'
                    : 'Tasa de cambio activa'
                }
              >
                <span>{activeRate.code}:</span>
                <span className="text-white">{formatBs(activeRate.rate)}</span>
                {allowRateEdit && onEditRate && <Settings2 className="size-3 opacity-70 ml-0.5" />}
              </div>
            )}
          </div>
        </div>
      </footer>


      {/* ========================================================================= */}
      {/* 5. MODAL RÁPIDO PARA EDITAR CANTIDAD DIRECTAMENTE                         */}
      {/* ========================================================================= */}
      {editingQtyLine && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-2xl border-2 border-blue-600 max-w-sm w-full p-5 text-slate-900 animate-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between pb-3 border-b border-slate-200">
              <div className="flex items-center gap-2">
                <Edit3 className="size-5 text-blue-600" />
                <h3 className="font-bold text-base">Modificar Cantidad</h3>
              </div>
              <button
                type="button"
                onClick={() => setEditingQtyLine(null)}
                className="text-slate-400 hover:text-slate-600 p-1"
              >
                <X className="size-4" />
              </button>
            </div>

            <form onSubmit={handleSaveQuantity} className="mt-4 space-y-4">
              <div>
                <p className="font-semibold text-xs text-slate-500 uppercase">Producto</p>
                <p className="font-bold text-sm text-slate-900 truncate mt-0.5">
                  {editingQtyLine.name}
                </p>
              </div>

              <div>
                <label
                  htmlFor="quick-quantity-input"
                  className="block font-semibold text-xs text-slate-700 uppercase"
                >
                  Nueva Cantidad
                </label>
                <input
                  id="quick-quantity-input"
                  ref={qtyInputRef}
                  type="text"
                  inputMode="decimal"
                  value={newQtyValue}
                  onChange={(e) => setNewQtyValue(e.target.value)}
                  className="mt-1 w-full bg-slate-50 text-slate-900 font-mono font-black text-2xl text-center px-4 py-2.5 border-2 border-blue-500 rounded-xl focus:outline-none focus:ring-4 focus:ring-blue-100"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setEditingQtyLine(null)}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-lg transition-colors cursor-pointer"
                >
                  Cancelar [Esc]
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm flex items-center gap-1.5 transition-colors cursor-pointer"
                >
                  <Check className="size-4" />
                  <span>Aceptar [Enter]</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
