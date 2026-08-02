/**
 * ProductAutocomplete: input con typeahead single-select para buscar
 * productos por SKU o nombre. Pensado para formularios donde el user
 * conoce el SKU (escaner o lo escribe) o navega por nombre.
 *
 * No usa el Combobox multi-select existente porque necesitamos single
 * select + lookup de campos adicionales del producto (tracking_type,
 * unit_of_measure) cuando se selecciona.
 */
import { createPortal } from 'react-dom';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { useProductsForPurchase } from '@/features/purchases/api';
import { cn } from '@/lib/cn';

export interface ProductAutocompleteOption {
  id: number;
  name: string;
  sku: string | null;
  barcode: string | null;
  tracking_type?: string;
  unit_of_measure?: string;
  base_price?: number | string | null;
}

interface ProductAutocompleteProps {
  value: number | null;
  selectedProduct?: ProductAutocompleteOption | null;
  onChange: (productId: number | null, product?: ProductAutocompleteOption) => void;
  placeholder?: string;
  /** Cuando el user no encuentra el producto en la lista */
  onProductNotFound?: (query: string) => void;
  invalid?: boolean;
}

export function ProductAutocomplete({
  value,
  selectedProduct,
  onChange,
  placeholder = 'Buscar por SKU, codigo de barras o nombre...',
  onProductNotFound,
  invalid,
}: ProductAutocompleteProps) {
  const [query, setQuery] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const { data: products = [] } = useProductsForPurchase(searchTerm);
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const [pickedProduct, setPickedProduct] = useState<ProductAutocompleteOption | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const [dropdownPosition, setDropdownPosition] = useState<{
    top: number;
    left: number;
    width: number;
    maxHeight: number;
    placement: 'above' | 'below';
  } | null>(null);

  const selected = useMemo(() => {
    if (value == null) return null;
    if (selectedProduct?.id === value) return selectedProduct;
    if (pickedProduct?.id === value) return pickedProduct;
    return products.find((p) => p.id === value) ?? null;
  }, [pickedProduct, products, selectedProduct, value]);

  const matches = useMemo(() => {
    if (!query.trim()) return products.slice(0, 20);
    const q = query.toLowerCase().trim();
    return products
      .filter((p) => {
        const sku = (p.sku ?? '').toLowerCase();
        const barcode = (p.barcode ?? '').toLowerCase();
        const name = (p.name ?? '').toLowerCase();
        if (sku === q || barcode === q) return true;
        return sku.includes(q) || barcode.includes(q) || name.includes(q);
      })
      .slice(0, 20);
  }, [products, query]);

  // Esperamos brevemente antes de consultar para no hacer una peticion por
  // cada tecla y mantenemos el catalogo inicial mientras se escribe.
  useEffect(() => {
    const timer = window.setTimeout(() => setSearchTerm(query.trim()), 180);
    return () => window.clearTimeout(timer);
  }, [query]);

  const updateDropdownPosition = useCallback(() => {
    const anchor = containerRef.current;
    if (!anchor) return;

    const rect = anchor.getBoundingClientRect();
    const gutter = 8;
    const gap = 4;
    const preferredHeight = 256;
    const minimumHeight = 120;
    const availableBelow = Math.max(0, window.innerHeight - rect.bottom - gutter);
    const availableAbove = Math.max(0, rect.top - gutter);
    const placement =
      availableBelow < minimumHeight && availableAbove > availableBelow ? 'above' : 'below';
    const availableSpace = placement === 'above' ? availableAbove : availableBelow;
    const maxHeight = Math.max(
      minimumHeight,
      Math.min(preferredHeight, Math.max(minimumHeight, availableSpace - gap)),
    );

    setDropdownPosition({
      top: placement === 'above' ? Math.max(gutter, rect.top - maxHeight - gap) : rect.bottom + gap,
      left: rect.left,
      width: rect.width,
      maxHeight,
      placement,
    });
  }, []);

  // El dropdown vive en body para no quedar recortado por el scroll del
  // modal. Recalculamos al desplazar el modal o cambiar el viewport.
  useLayoutEffect(() => {
    if (!open || selected) return;

    updateDropdownPosition();
    const handleViewportChange = () => updateDropdownPosition();
    window.addEventListener('resize', handleViewportChange);
    document.addEventListener('scroll', handleViewportChange, true);

    return () => {
      window.removeEventListener('resize', handleViewportChange);
      document.removeEventListener('scroll', handleViewportChange, true);
    };
  }, [open, selected, updateDropdownPosition]);

  // Click-outside cierra el dropdown.
  useEffect(() => {
    function onDocClick(e: MouseEvent) {
      const target = e.target as Node;
      if (containerRef.current?.contains(target) || dropdownRef.current?.contains(target)) return;
      setOpen(false);
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  function pick(p: ProductAutocompleteOption) {
    setPickedProduct(p);
    onChange(p.id, p);
    setQuery('');
    setOpen(false);
  }

  function clear() {
    setPickedProduct(null);
    onChange(null);
    setQuery('');
  }

  return (
    <div ref={containerRef} className="relative">
      {selected ? (
        <div className="border-border-strong bg-surface flex items-center gap-2 rounded border px-2 py-1.5">
          <div className="min-w-0 flex-1">
            <div className="truncate text-sm font-medium">{selected.name}</div>
            <div className="text-text-muted flex items-center gap-1.5 text-xs">
              {selected.sku && <code className="bg-bg rounded px-1 py-0.5">{selected.sku}</code>}
              {selected.tracking_type === 'serialized' && (
                <Badge variant="info" className="text-[10px]">
                  Serializado
                </Badge>
              )}
            </div>
          </div>
          <button
            type="button"
            onClick={clear}
            className="text-text-muted hover:bg-bg hover:text-danger rounded p-1"
            aria-label="Quitar producto"
          >
            <X className="size-3.5" />
          </button>
        </div>
      ) : (
        <div className="relative">
          <Search className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
          <Input
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setOpen(true);
              setHighlight(0);
            }}
            onFocus={() => setOpen(true)}
            onClick={() => setOpen(true)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                setHighlight((h) => Math.min(h + 1, matches.length - 1));
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setHighlight((h) => Math.max(h - 1, 0));
              } else if (e.key === 'Enter' && matches[highlight]) {
                e.preventDefault();
                pick(matches[highlight] as ProductAutocompleteOption);
              } else if (e.key === 'Escape') {
                setOpen(false);
              }
            }}
            placeholder={placeholder}
            className={cn('pl-9', invalid && 'border-danger')}
            autoComplete="off"
          />
        </div>
      )}

      {open &&
        !selected &&
        dropdownPosition &&
        createPortal(
          <div
            ref={dropdownRef}
            className="border-border bg-surface fixed z-[100] overflow-auto rounded border shadow-xl"
            style={{
              top: dropdownPosition.top,
              left: dropdownPosition.left,
              width: dropdownPosition.width,
              maxHeight: dropdownPosition.maxHeight,
            }}
          >
            {matches.length === 0 ? (
              <div className="text-text-muted p-3 text-sm">
                <p>Sin resultados para "{query}".</p>
                {onProductNotFound && (
                  <button
                    type="button"
                    onClick={() => onProductNotFound(query)}
                    className="text-primary mt-1 text-xs hover:underline"
                  >
                    Crear nuevo producto con este termino
                  </button>
                )}
              </div>
            ) : (
              <ul role="listbox">
                {matches.map((p, i) => (
                  <li
                    key={p.id}
                    role="option"
                    aria-selected={i === highlight}
                    onClick={() => pick(p as ProductAutocompleteOption)}
                    onMouseEnter={() => setHighlight(i)}
                    className={cn(
                      'border-border cursor-pointer border-b px-3 py-2 last:border-b-0',
                      i === highlight && 'bg-primary/10',
                    )}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium">{p.name}</div>
                        <div className="text-text-muted flex items-center gap-1.5 text-xs">
                          {p.sku && <code className="bg-bg rounded px-1 py-0.5">{p.sku}</code>}
                          {p.barcode && <span>BC: {p.barcode}</span>}
                        </div>
                      </div>
                      {p.tracking_type === 'serialized' && (
                        <Badge variant="info" className="shrink-0 text-[10px]">
                          Serializado
                        </Badge>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>,
          document.body,
        )}
    </div>
  );
}
