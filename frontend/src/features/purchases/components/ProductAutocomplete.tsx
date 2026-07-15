/**
 * ProductAutocomplete: input con typeahead single-select para buscar
 * productos por SKU o nombre. Pensado para formularios donde el user
 * conoce el SKU (escaner o lo escribe) o navega por nombre.
 *
 * No usa el Combobox multi-select existente porque necesitamos single
 * select + lookup de campos adicionales del producto (tracking_type,
 * unit_of_measure) cuando se selecciona.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
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
  onChange: (productId: number | null, product?: ProductAutocompleteOption) => void;
  placeholder?: string;
  /** Cuando el user no encuentra el producto en la lista */
  onProductNotFound?: (query: string) => void;
  invalid?: boolean;
}

export function ProductAutocomplete({
  value,
  onChange,
  placeholder = 'Buscar por SKU, codigo de barras o nombre...',
  onProductNotFound,
  invalid,
}: ProductAutocompleteProps) {
  const { data: products = [] } = useProductsForPurchase();
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);

  const selected = useMemo(
    () => products.find((p) => p.id === value) ?? null,
    [products, value],
  );

  const matches = useMemo(() => {
    if (!query.trim()) return products.slice(0, 8);
    const q = query.toLowerCase();
    return products
      .filter((p) => {
        const sku = (p.sku ?? '').toLowerCase();
        const barcode = (p.barcode ?? '').toLowerCase();
        const name = (p.name ?? '').toLowerCase();
        return sku.includes(q) || barcode.includes(q) || name.includes(q);
      })
      .slice(0, 12);
  }, [products, query]);

  // Click-outside cierra el dropdown.
  useEffect(() => {
    function onDocClick(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  function pick(p: ProductAutocompleteOption) {
    onChange(p.id, p);
    setQuery('');
    setOpen(false);
  }

  function clear() {
    onChange(null);
    setQuery('');
  }

  return (
    <div ref={containerRef} className="relative">
      {selected ? (
        <div className="flex items-center gap-2 rounded border border-border-strong bg-surface px-2 py-1.5">
          <div className="flex-1 min-w-0">
            <div className="truncate text-sm font-medium">{selected.name}</div>
            <div className="flex items-center gap-1.5 text-xs text-text-muted">
              {selected.sku && (
                <code className="rounded bg-bg px-1 py-0.5">{selected.sku}</code>
              )}
              {selected.tracking_type === 'serialized' && (
                <Badge variant="info" className="text-[10px]">Serializado</Badge>
              )}
            </div>
          </div>
          <button
            type="button"
            onClick={clear}
            className="rounded p-1 text-text-muted hover:bg-bg hover:text-danger"
            aria-label="Quitar producto"
          >
            <X className="size-3.5" />
          </button>
        </div>
      ) : (
        <div className="relative">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
          <Input
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setOpen(true);
              setHighlight(0);
            }}
            onFocus={() => setOpen(true)}
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

      {open && !selected && (
        <div className="absolute z-50 mt-1 w-full max-h-64 overflow-auto rounded border border-border bg-surface shadow-lg">
          {matches.length === 0 ? (
            <div className="p-3 text-sm text-text-muted">
              <p>Sin resultados para "{query}".</p>
              {onProductNotFound && (
                <button
                  type="button"
                  onClick={() => onProductNotFound(query)}
                  className="mt-1 text-xs text-primary hover:underline"
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
                    'cursor-pointer border-b border-border px-3 py-2 last:border-b-0',
                    i === highlight && 'bg-primary/10',
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm font-medium">{p.name}</div>
                      <div className="flex items-center gap-1.5 text-xs text-text-muted">
                        {p.sku && <code className="rounded bg-bg px-1 py-0.5">{p.sku}</code>}
                        {p.barcode && <span>BC: {p.barcode}</span>}
                      </div>
                    </div>
                    {p.tracking_type === 'serialized' && (
                      <Badge variant="info" className="shrink-0 text-[10px]">Serializado</Badge>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
