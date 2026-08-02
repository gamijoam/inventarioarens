import { useEffect, useId, useMemo, useState } from 'react';
import { LoaderCircle, PackageSearch, Search, X } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
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
  const resultsId = useId();
  const [query, setQuery] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const [pickedProduct, setPickedProduct] = useState<ProductAutocompleteOption | null>(null);
  const { data: products = [], isError, isFetching } = useProductsForPurchase(searchTerm);

  const selected = useMemo(() => {
    if (value == null) return null;
    if (selectedProduct?.id === value) return selectedProduct;
    if (pickedProduct?.id === value) return pickedProduct;
    return products.find((product) => product.id === value) ?? null;
  }, [pickedProduct, products, selectedProduct, value]);

  const matches = useMemo(() => {
    if (!query.trim()) return products.slice(0, 30);

    const normalizedQuery = query.toLowerCase().trim();
    return products
      .filter((product) => {
        const sku = (product.sku ?? '').toLowerCase();
        const barcode = (product.barcode ?? '').toLowerCase();
        const name = product.name.toLowerCase();
        return (
          sku.includes(normalizedQuery) ||
          barcode.includes(normalizedQuery) ||
          name.includes(normalizedQuery)
        );
      })
      .slice(0, 50);
  }, [products, query]);

  useEffect(() => {
    const timer = window.setTimeout(() => setSearchTerm(query.trim()), 180);
    return () => window.clearTimeout(timer);
  }, [query]);

  function pick(product: ProductAutocompleteOption) {
    setPickedProduct(product);
    onChange(product.id, product);
    setQuery('');
    setOpen(false);
  }

  function clear() {
    setPickedProduct(null);
    onChange(null);
    setQuery('');
    setSearchTerm('');
    setOpen(false);
  }

  if (selected) {
    return (
      <div className="border-primary/25 bg-primary/5 flex min-h-14 items-center gap-3 rounded-md border px-3 py-2">
        <div className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-md">
          <PackageSearch className="size-4" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold">{selected.name}</div>
          <div className="text-text-muted mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
            {selected.sku && <code className="bg-surface rounded px-1 py-0.5">{selected.sku}</code>}
            {selected.barcode && <span>Codigo: {selected.barcode}</span>}
            <Badge
              variant={selected.tracking_type === 'serialized' ? 'info' : 'default'}
              className="text-[10px]"
            >
              {selected.tracking_type === 'serialized' ? 'Serializado' : 'Por cantidad'}
            </Badge>
          </div>
        </div>
        <button
          type="button"
          onClick={clear}
          className="text-text-muted hover:bg-surface hover:text-danger rounded p-2 transition-colors"
          aria-label={`Quitar ${selected.name}`}
        >
          <X className="size-4" />
        </button>
      </div>
    );
  }

  return (
    <div
      className="relative"
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
      }}
    >
      <div className="relative">
        <Search className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
        <Input
          value={query}
          onChange={(event) => {
            setQuery(event.target.value);
            setOpen(true);
            setHighlight(0);
          }}
          onFocus={() => setOpen(true)}
          onClick={() => setOpen(true)}
          onKeyDown={(event) => {
            if (event.key === 'ArrowDown') {
              event.preventDefault();
              setHighlight((current) => Math.min(current + 1, matches.length - 1));
            } else if (event.key === 'ArrowUp') {
              event.preventDefault();
              setHighlight((current) => Math.max(current - 1, 0));
            } else if (event.key === 'Enter' && matches[highlight]) {
              event.preventDefault();
              pick(matches[highlight] as ProductAutocompleteOption);
            } else if (event.key === 'Escape') {
              setOpen(false);
            }
          }}
          placeholder={placeholder}
          className={cn('h-11 pl-10', invalid && 'border-danger')}
          autoComplete="off"
          aria-expanded={open}
          aria-controls={resultsId}
        />
      </div>

      {open && (
        <div
          id={resultsId}
          data-testid="purchase-product-results"
          className="border-border bg-surface mt-2 overflow-hidden rounded-md border shadow-sm"
        >
          <div className="border-border bg-bg/60 flex items-center justify-between gap-3 border-b px-3 py-2">
            <div className="flex min-w-0 items-center gap-2">
              <PackageSearch className="text-primary size-4 shrink-0" />
              <span className="text-text-secondary truncate text-xs font-semibold uppercase">
                {query.trim() ? `Resultados para "${query.trim()}"` : 'Productos recientes'}
              </span>
            </div>
            {isFetching && <LoaderCircle className="text-primary size-4 animate-spin" />}
          </div>

          <div className="max-h-60 overflow-y-auto overscroll-contain" tabIndex={-1}>
            {isFetching && matches.length === 0 ? (
              <div className="text-text-muted flex items-center gap-2 p-4 text-sm">
                <LoaderCircle className="text-primary size-4 animate-spin" />
                Buscando productos...
              </div>
            ) : isError ? (
              <div className="p-4 text-sm">
                <p className="text-danger font-medium">No se pudo consultar el catalogo.</p>
                <p className="text-text-muted mt-1 text-xs">
                  Verifica la conexion e intenta de nuevo.
                </p>
              </div>
            ) : matches.length === 0 ? (
              <div className="p-4 text-sm">
                <p className="text-text-primary font-medium">No encontramos ese producto.</p>
                <p className="text-text-muted mt-1 text-xs">
                  Busca por nombre, SKU o codigo de barras. El producto debe estar activo en esta
                  empresa.
                </p>
                {onProductNotFound && (
                  <button
                    type="button"
                    onClick={() => onProductNotFound(query)}
                    className="text-primary mt-2 text-xs font-semibold hover:underline"
                  >
                    Crear producto con este nombre
                  </button>
                )}
              </div>
            ) : (
              <ul role="listbox" className="divide-border divide-y">
                {matches.map((product, index) => (
                  <li key={product.id}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={index === highlight}
                      onClick={() => pick(product as ProductAutocompleteOption)}
                      onMouseEnter={() => setHighlight(index)}
                      className={cn(
                        'flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition-colors',
                        'hover:bg-primary/10 focus-visible:bg-primary/10 focus-visible:outline-none',
                        index === highlight && 'bg-primary/10',
                      )}
                    >
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold">{product.name}</div>
                        <div className="text-text-muted mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
                          {product.sku && <code>SKU: {product.sku}</code>}
                          {product.barcode && <span>Codigo: {product.barcode}</span>}
                          {product.base_price != null && <span>Base: {product.base_price}</span>}
                        </div>
                      </div>
                      <Badge
                        variant={product.tracking_type === 'serialized' ? 'info' : 'default'}
                        className="shrink-0 text-[10px]"
                      >
                        {product.tracking_type === 'serialized' ? 'Serializado' : 'Por cantidad'}
                      </Badge>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
