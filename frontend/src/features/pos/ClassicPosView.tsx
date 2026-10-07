import { useState, useEffect, useRef, useMemo } from 'react';
import {
  Users,
  Trash2,
  Plus,
  Minus,
  Barcode,
  Package,
  ShoppingBag,
  Clock,
} from 'lucide-react';
import type { PosCartLine, CartTotals } from './posLogic';
import type { Customer } from './api';
import { ProductImage } from '@/features/inventory-center/components/ProductImage';
import { cn } from '@/lib/cn';

export interface ClassicPosViewProps {
  cart: PosCartLine[];
  cartTotals: CartTotals;
  activeRate: { code: string; rate: number } | null;
  selectedCustomer: Customer | null;
  customerName?: string;
  sellerName?: string;
  canDiscount?: boolean;
  allowNegativeStock?: boolean;
  onSelectCustomer: () => void;
  onOpenPayment: () => void;
  onClearCart: () => void;
  onUpdateLine: (lineId: string, patch: Partial<PosCartLine>) => void;
  onRemoveLine: (lineId: string) => void;
  onSearchSubmit: (term: string) => Promise<boolean | void>;
  onOpenSerials?: (lineId: string) => void;
  orderNumber?: string;
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
  activeRate,
  selectedCustomer,
  customerName,
  sellerName,
  canDiscount: _canDiscount = false,
  allowNegativeStock = false,
  onSelectCustomer,
  onOpenPayment,
  onClearCart,
  onUpdateLine,
  onRemoveLine,
  onSearchSubmit,
  onOpenSerials: _onOpenSerials,
  orderNumber,
}: ClassicPosViewProps) {
  // Índice del renglón activo en la tabla
  const [selectedIndex, setSelectedIndex] = useState<number>(0);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [isSearching, setIsSearching] = useState(false);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const tableContainerRef = useRef<HTMLDivElement>(null);

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

  // Producto de la línea seleccionada actualmente
  const activeLine: PosCartLine | undefined = cart[selectedIndex];

  // Total de unidades físicas
  const totalUnits = useMemo(() => {
    return cart.reduce((acc, line) => acc + (Number(line.quantity) || 0), 0);
  }, [cart]);

  // Manejo de búsqueda por código o Enter
  const handleBarcodeSubmit = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const term = searchTerm.trim();
    if (!term || isSearching) return;

    try {
      setIsSearching(true);
      await onSearchSubmit(term);
      setSearchTerm('');
    } finally {
      setIsSearching(false);
      searchInputRef.current?.focus();
    }
  };

  // Atajos de teclado locales para navegación de filas y funciones
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      // Si el foco está en un input pero presiona F5 o F1 o F10
      if (event.key === 'F5') {
        event.preventDefault();
        searchInputRef.current?.focus();
        searchInputRef.current?.select();
        return;
      }
      if (event.key === 'F1') {
        event.preventDefault();
        onSelectCustomer();
        return;
      }
      if (event.key === 'F10') {
        event.preventDefault();
        onOpenPayment();
        return;
      }

      // Si estamos dentro del input de búsqueda, solo atender flechas si no hay texto
      const isInput = document.activeElement === searchInputRef.current;
      if (isInput && searchTerm.length > 0) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        setSelectedIndex((prev) => (cart.length > 0 ? Math.min(prev + 1, cart.length - 1) : 0));
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        setSelectedIndex((prev) => (cart.length > 0 ? Math.max(prev - 1, 0) : 0));
      } else if (event.key === '+' || event.key === 'Add') {
        if (!isInput && activeLine) {
          event.preventDefault();
          onUpdateLine(activeLine.id, { quantity: activeLine.quantity + 1 });
        }
      } else if (event.key === '-' || event.key === 'Subtract') {
        if (!isInput && activeLine) {
          event.preventDefault();
          if (activeLine.quantity > 1) {
            onUpdateLine(activeLine.id, { quantity: activeLine.quantity - 1 });
          } else {
            onRemoveLine(activeLine.id);
          }
        }
      } else if (event.key === 'Delete') {
        if (!isInput && activeLine) {
          event.preventDefault();
          onRemoveLine(activeLine.id);
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [
    activeLine,
    cart.length,
    onOpenPayment,
    onRemoveLine,
    onSelectCustomer,
    onUpdateLine,
    searchTerm.length,
  ]);

  // Cálculos monetarios para los números gigantes superiores
  const rateVal = activeRate?.rate ?? 1;
  const activeLinePriceUsd = activeLine ? Number(activeLine.unit_price) : 0;
  const activeLinePriceBs = activeLinePriceUsd * rateVal;

  const totalUsd = Number(cartTotals.total) || 0;
  const totalBs = totalUsd * rateVal;

  const docNumber = orderNumber ? `FAC${orderNumber.padStart(8, '0')}` : 'FAC00000001';

  return (
    <div className="flex flex-col flex-1 h-full min-h-0 bg-[#f1f5f9] select-none font-sans overflow-hidden">
      {/* ========================================================================= */}
      {/* 1. HEADER SUPERIOR CON INDICADORES GIGANTES (ESTILO 1SISTEMA / VALERY)      */}
      {/* ========================================================================= */}
      <div className="grid grid-cols-12 gap-1.5 p-2 bg-[#0b1329] border-b-2 border-blue-900 text-white shrink-0 items-stretch">
        {/* BLOQUE FECHA / HORA / FACTURA */}
        <div className="col-span-12 sm:col-span-3 flex flex-col justify-between gap-1">
          <div className="bg-[#1e293b] border border-blue-900/80 rounded px-2.5 py-1 text-center font-mono text-xs sm:text-sm font-semibold tracking-wide text-blue-200 shadow-inner flex items-center justify-center gap-1.5">
            <Clock className="size-3.5 text-blue-400 shrink-0" />
            <span>{currentTime}</span>
          </div>
          <div className="bg-red-950/80 border-2 border-red-500 rounded px-2.5 py-0.5 text-center font-mono text-xs sm:text-sm font-black tracking-widest text-red-300 shadow-inner">
            {docNumber}
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

        {/* BLOQUE GRAN TOTAL FACTURA (ROJO GIGANTE - BOTÓN DE COBRO F10) */}
        <button
          type="button"
          onClick={onOpenPayment}
          disabled={cart.length === 0}
          title="Haga clic aquí o presione F10 para cobrar"
          className={cn(
            'col-span-5 sm:col-span-4 bg-gradient-to-b from-[#ef4444] to-[#b91c1c] rounded-lg p-1.5 sm:p-2 border-2 border-red-300 flex flex-col justify-between shadow-lg transition-transform active:scale-[0.99] text-left cursor-pointer group',
            cart.length === 0 && 'opacity-70 cursor-not-allowed',
          )}
        >
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-1.5">
              <span className="text-[10px] sm:text-xs font-black uppercase tracking-wider text-red-950">
                TOTAL [F10]
              </span>
              <span className="hidden lg:inline-block text-[9px] bg-red-900/60 text-red-100 px-1 rounded uppercase font-bold">
                COBRAR
              </span>
            </div>
            <span className="bg-white/95 text-red-900 font-mono font-black text-[10px] sm:text-xs px-2 py-0.2 rounded shadow-xs">
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
            {activeLine ? activeLine.name : 'SIN ARTÍCULOS - ESCANEE O BUSQUE UN PRODUCTO'}
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
            <div className="bg-blue-950/60 border border-blue-400/40 rounded-xl p-2.5 shadow-sm space-y-1.5">
              <button
                type="button"
                onClick={onSelectCustomer}
                className="w-full flex items-center justify-between text-left group cursor-pointer hover:bg-blue-800/40 p-1 rounded-lg transition-colors"
                title="Haga clic o presione F1 para seleccionar cliente"
              >
                <span className="font-mono font-black text-xs sm:text-sm text-yellow-300 group-hover:text-yellow-200 flex items-center gap-1.5">
                  <span className="bg-yellow-400 text-blue-950 px-1 py-0.2 rounded font-black text-[11px]">
                    F1
                  </span>
                  <span>Cliente:</span>
                </span>
                <span className="text-[11px] font-mono text-blue-200 underline opacity-80 group-hover:opacity-100">
                  {selectedCustomer ? 'Cambiar' : 'Seleccionar'}
                </span>
              </button>

              <div className="flex items-center gap-3 pt-1">
                <div className="size-11 rounded-full bg-gradient-to-tr from-cyan-400 to-blue-600 border-2 border-white flex items-center justify-center shrink-0 shadow-md">
                  <Users className="size-6 text-white" />
                </div>
                <div className="min-w-0 flex-1">
                  <p className="font-bold text-xs sm:text-sm uppercase truncate text-white leading-tight">
                    {selectedCustomer
                      ? selectedCustomer.name
                      : (customerName ?? 'CONSUMIDOR FINAL')}
                  </p>
                  <p className="font-mono text-xs text-blue-200 mt-0.5 font-semibold">
                    {getCustomerDoc(selectedCustomer)}
                  </p>
                  {selectedCustomer?.phone && (
                    <p className="font-mono text-[11px] text-blue-300 truncate">
                      {selectedCustomer.phone}
                    </p>
                  )}
                </div>
              </div>
            </div>

            {/* F2. VENDEDOR */}
            <div className="bg-blue-950/40 border border-blue-400/30 rounded-lg px-2.5 py-1.5 flex items-center justify-between">
              <span className="font-mono font-black text-xs text-yellow-300 flex items-center gap-1.5">
                <span className="bg-yellow-400 text-blue-950 px-1 py-0.2 rounded font-black text-[10px]">
                  F2
                </span>
                <span>Vendedor:</span>
              </span>
              <span className="font-bold text-xs text-white uppercase truncate max-w-[170px]">
                {sellerName ?? 'CAJA PRINCIPAL'}
              </span>
            </div>

            {/* F5. BUSCADOR / ENTRADA DE CÓDIGO DE BARRAS */}
            <form onSubmit={handleBarcodeSubmit} className="space-y-1">
              <label
                htmlFor="pos-classic-barcode-input"
                className="text-[11px] font-bold text-blue-200 flex items-center justify-between"
              >
                <span className="flex items-center gap-1">
                  <Barcode className="size-3.5" />
                  <span>Escáner / Código de barras</span>
                </span>
                <span className="font-mono text-[10px] bg-blue-950 px-1.5 py-0.5 rounded text-blue-300">
                  Enter para agregar
                </span>
              </label>
              <div className="flex items-center gap-1">
                <div className="bg-black text-white font-mono font-black text-sm px-2.5 py-2 rounded-l-md border border-slate-700 shrink-0 shadow-inner">
                  F5
                </div>
                <input
                  id="pos-classic-barcode-input"
                  ref={searchInputRef}
                  type="text"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  placeholder="Escanee o escriba código..."
                  disabled={isSearching}
                  autoComplete="off"
                  className="flex-1 bg-white text-slate-900 font-mono font-bold text-sm px-3 py-2 rounded-r-md border border-slate-400 focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 shadow-inner"
                />
              </div>
            </form>

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
          <div className="bg-slate-100 border-b border-slate-300 px-3 py-1.5 flex items-center justify-between text-xs text-slate-700">
            <div className="flex items-center gap-3">
              <span className="font-bold uppercase tracking-wide text-slate-800">
                Líneas del Ticket: <span className="font-mono text-blue-700">{cart.length}</span>
              </span>
              <span className="text-slate-400">|</span>
              <span className="text-slate-600 hidden md:inline">
                Navegue con <kbd className="px-1 py-0.5 bg-white border rounded font-mono text-[10px]">↑</kbd> <kbd className="px-1 py-0.5 bg-white border rounded font-mono text-[10px]">↓</kbd>, <kbd className="px-1 py-0.5 bg-white border rounded font-mono text-[10px]">+</kbd> <kbd className="px-1 py-0.5 bg-white border rounded font-mono text-[10px]">-</kbd>, o <kbd className="px-1 py-0.5 bg-white border rounded font-mono text-[10px]">Supr</kbd>
              </span>
            </div>

            {cart.length > 0 && (
              <button
                type="button"
                onClick={onClearCart}
                className="text-red-600 hover:text-red-800 hover:bg-red-50 px-2 py-0.5 rounded text-xs font-semibold flex items-center gap-1 transition-colors cursor-pointer"
                title="Vaciar ticket completo"
              >
                <Trash2 className="size-3.5" />
                <span>Vaciar ticket</span>
              </button>
            )}
          </div>

          {/* TABLA CON ENCABEZADO NEGRO ESTILO 1SISTEMA */}
          <div
            ref={tableContainerRef}
            className="flex-1 overflow-y-auto overscroll-contain bg-white min-h-0"
          >
            <table className="w-full border-collapse text-left font-sans text-xs sm:text-sm">
              {/* ENCABEZADOS NEGROS */}
              <thead className="bg-[#0f172a] text-white font-mono sticky top-0 z-10 shadow-xs text-xs">
                <tr>
                  <th className="py-2 px-2.5 font-bold uppercase w-12 text-center border-r border-slate-700">
                    #
                  </th>
                  <th className="py-2 px-2.5 font-bold uppercase w-32 border-r border-slate-700">
                    Codigo
                  </th>
                  <th className="py-2 px-3 font-bold uppercase border-r border-slate-700">
                    Descripcion
                  </th>
                  <th className="py-2 px-2 font-bold uppercase w-14 text-center border-r border-slate-700">
                    I.V.A
                  </th>
                  <th className="py-2 px-2.5 font-bold uppercase w-28 text-right border-r border-slate-700">
                    Precio (Bs)
                  </th>
                  <th className="py-2 px-2 font-bold uppercase w-16 text-center border-r border-slate-700">
                    Cant.
                  </th>
                  <th className="py-2 px-2 font-bold uppercase w-12 text-center border-r border-slate-700">
                    Dec.
                  </th>
                  <th className="py-2 px-3 font-bold uppercase w-32 text-right border-r border-slate-700">
                    Total (Bs)
                  </th>
                  <th className="py-2 px-2 font-bold uppercase w-20 text-center">
                    Acción
                  </th>
                </tr>
              </thead>

              {/* FILAS DE PRODUCTOS */}
              <tbody className="divide-y divide-slate-200 font-mono">
                {cart.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="py-16 text-center text-slate-400 font-sans">
                      <Package className="size-12 mx-auto mb-2 text-slate-300 stroke-[1.25]" />
                      <p className="font-bold text-sm text-slate-600">Ticket vacío</p>
                      <p className="text-xs text-slate-400 mt-1">
                        Escanee un código de barras en el campo F5 o presione F3 para buscar.
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
                        className={cn(
                          'cursor-pointer transition-colors group select-none text-xs sm:text-sm',
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
                            'py-2 px-2 text-center border-r',
                            isSelected
                              ? 'border-blue-500 font-black'
                              : 'border-slate-200 text-slate-500',
                          )}
                        >
                          {index + 1}
                        </td>

                        {/* 2. CÓDIGO (BARCODE / SKU) */}
                        <td
                          className={cn(
                            'py-2 px-2.5 truncate max-w-[120px] border-r font-semibold',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-700',
                          )}
                          title={line.barcode || line.sku || ''}
                        >
                          {line.barcode || line.sku || '-'}
                        </td>

                        {/* 3. DESCRIPCIÓN */}
                        <td
                          className={cn(
                            'py-2 px-3 border-r font-sans font-medium',
                            isSelected ? 'border-blue-500 text-white font-bold' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          <div className="flex items-center gap-1.5">
                            <span className="truncate">{line.name}</span>
                            {stockIssue && (
                              <span
                                className={cn(
                                  'text-[10px] px-1 rounded font-mono shrink-0',
                                  isSelected ? 'bg-amber-400 text-slate-900' : 'bg-red-100 text-red-700',
                                )}
                                title={`Stock disponible: ${line.available_stock}`}
                              >
                                ⚠️ Stock
                              </span>
                            )}
                          </div>
                        </td>

                        {/* 4. I.V.A */}
                        <td
                          className={cn(
                            'py-2 px-2 text-center border-r font-semibold',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-600',
                          )}
                        >
                          16%
                        </td>

                        {/* 5. PRECIO UNITARIO (BS) */}
                        <td
                          className={cn(
                            'py-2 px-2.5 text-right border-r tabular-nums font-bold',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          {formatBs(linePriceBs)}
                        </td>

                        {/* 6. CANTIDAD */}
                        <td
                          className={cn(
                            'py-2 px-2 text-center border-r font-black text-sm',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          {line.quantity}
                        </td>

                        {/* 7. DECIMALES */}
                        <td
                          className={cn(
                            'py-2 px-2 text-center border-r text-slate-400',
                            isSelected ? 'border-blue-500 text-blue-200' : 'border-slate-200',
                          )}
                        >
                          0
                        </td>

                        {/* 8. TOTAL (BS) */}
                        <td
                          className={cn(
                            'py-2 px-3 text-right border-r tabular-nums font-black text-sm',
                            isSelected ? 'border-blue-500 text-white' : 'border-slate-200 text-slate-900',
                          )}
                        >
                          {formatBs(lineTotalBs)}
                        </td>

                        {/* 9. ACCIONES RÁPIDAS (+, -, TRASH) */}
                        <td className="py-1 px-1 text-center">
                          <div className="flex items-center justify-center gap-1">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                onUpdateLine(line.id, { quantity: line.quantity + 1 });
                              }}
                              className={cn(
                                'size-6 rounded flex items-center justify-center text-xs transition-colors cursor-pointer',
                                isSelected
                                  ? 'bg-blue-700 hover:bg-blue-800 text-white'
                                  : 'bg-slate-200 hover:bg-slate-300 text-slate-700',
                              )}
                              title="Aumentar cantidad (+)"
                            >
                              <Plus className="size-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                if (line.quantity > 1) {
                                  onUpdateLine(line.id, { quantity: line.quantity - 1 });
                                } else {
                                  onRemoveLine(line.id);
                                }
                              }}
                              className={cn(
                                'size-6 rounded flex items-center justify-center text-xs transition-colors cursor-pointer',
                                isSelected
                                  ? 'bg-blue-700 hover:bg-blue-800 text-white'
                                  : 'bg-slate-200 hover:bg-slate-300 text-slate-700',
                              )}
                              title="Disminuir cantidad (-)"
                            >
                              <Minus className="size-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                onRemoveLine(line.id);
                              }}
                              className={cn(
                                'size-6 rounded flex items-center justify-center text-xs transition-colors cursor-pointer',
                                isSelected
                                  ? 'bg-red-500 hover:bg-red-600 text-white'
                                  : 'hover:bg-red-100 text-slate-400 hover:text-red-600',
                              )}
                              title="Eliminar renglón"
                            >
                              <Trash2 className="size-3.5" />
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

          {/* BARRA INFERIOR DE RESUMEN Y ACCESO DIRECTO A COBRAR */}
          <div className="bg-slate-100 border-t-2 border-slate-300 p-2.5 flex items-center justify-between text-xs sm:text-sm">
            <div className="flex items-center gap-4 text-slate-700 font-mono">
              <div>
                <span className="text-slate-500">Subtotal: </span>
                <span className="font-bold">${formatUsd(cartTotals.subtotal)}</span>
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

            <button
              type="button"
              onClick={onOpenPayment}
              disabled={cart.length === 0}
              className={cn(
                'bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-4 py-2 rounded-lg flex items-center gap-2 shadow-sm transition-all cursor-pointer font-sans text-xs sm:text-sm',
                cart.length === 0 && 'opacity-50 cursor-not-allowed',
              )}
            >
              <ShoppingBag className="size-4" />
              <span>COBRAR FACTURA [F10]</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
