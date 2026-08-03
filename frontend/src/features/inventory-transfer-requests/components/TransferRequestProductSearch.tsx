import { useEffect, useId, useMemo, useState } from 'react';
import { LoaderCircle, PackageSearch, Search, X } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { useTransferRequestProducts } from '@/features/inventory-transfer-requests/api';
import type { Product } from '@/features/inventory-center/schemas';
import { cn } from '@/lib/cn';
import { compareMatches, scoreMatch, type ProductLiteForMatch } from '../scoreMatch';

interface TransferRequestProductSearchProps {
  index: number;
  value: string;
  selectedProduct: Product | null;
  onChange: (productId: string, product: Product | null) => void;
  invalid?: boolean;
  initialQuery?: string;
  trackingType?: Product['tracking_type'];
  matchSource?: ProductLiteForMatch | null;
  autoSelectExact?: boolean;
}

export function TransferRequestProductSearch({
  index,
  value,
  selectedProduct,
  onChange,
  invalid,
  initialQuery = '',
  trackingType,
  matchSource,
  autoSelectExact = false,
}: TransferRequestProductSearchProps) {
  const resultsId = useId();
  const [query, setQuery] = useState(initialQuery);
  const [searchTerm, setSearchTerm] = useState(initialQuery.trim());
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const [usedNameFallback, setUsedNameFallback] = useState(false);
  const { data: products = [], isError, isFetching } = useTransferRequestProducts(searchTerm);

  const matches = useMemo(() => {
    const compatibleProducts = products.filter(
      (product) => !trackingType || product.tracking_type === trackingType,
    );

    if (!query.trim()) return compatibleProducts.slice(0, 20);

    const normalizedQuery = query.toLowerCase().trim();
    const filtered = compatibleProducts.filter((product) => {
      const name = product.name.toLowerCase();
      const sku = (product.sku ?? '').toLowerCase();
      const barcode = (product.barcode ?? '').toLowerCase();

      return (
        name.includes(normalizedQuery) ||
        sku.includes(normalizedQuery) ||
        barcode.includes(normalizedQuery)
      );
    });

    if (!matchSource) return filtered.slice(0, 50);

    return filtered
      .map((product) => ({
        product,
        match: scoreMatch(matchSource, product),
      }))
      .sort((a, b) => compareMatches(a.match, b.match))
      .map(({ product }) => product)
      .slice(0, 50);
  }, [matchSource, products, query, trackingType]);

  const exactMatch = useMemo(() => {
    if (!matchSource) return null;

    return (
      matches.find((product) => {
        const match = scoreMatch(matchSource, product);
        if (match.matchType === 'sku' || match.matchType === 'barcode') return true;

        return product.name.trim().toLowerCase() === matchSource.name.trim().toLowerCase();
      }) ?? null
    );
  }, [matchSource, matches]);

  useEffect(() => {
    const timer = window.setTimeout(() => setSearchTerm(query.trim()), 180);
    return () => window.clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    if (!autoSelectExact || value || !exactMatch) return;
    onChange(String(exactMatch.id), exactMatch);
    setQuery('');
    setOpen(false);
  }, [autoSelectExact, exactMatch, onChange, value]);

  useEffect(() => {
    if (
      !autoSelectExact ||
      value ||
      isFetching ||
      usedNameFallback ||
      matches.length > 0 ||
      !matchSource?.name ||
      query.trim().toLowerCase() === matchSource.name.trim().toLowerCase()
    ) {
      return;
    }

    setUsedNameFallback(true);
    setQuery(matchSource.name);
    setSearchTerm(matchSource.name.trim());
  }, [autoSelectExact, isFetching, matchSource, matches.length, query, usedNameFallback, value]);

  function selectProduct(product: Product) {
    onChange(String(product.id), product);
    setQuery('');
    setOpen(false);
  }

  function clearProduct() {
    onChange('', null);
    setQuery('');
    setSearchTerm('');
    setOpen(false);
    setUsedNameFallback(false);
  }

  if (value && selectedProduct) {
    return (
      <div className="border-primary/25 bg-primary/5 flex min-h-14 items-center gap-3 rounded-md border px-3 py-2">
        <div className="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-md">
          <PackageSearch className="size-4" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold">{selectedProduct.name}</div>
          <div className="text-text-muted mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
            {selectedProduct.sku && <code>SKU: {selectedProduct.sku}</code>}
            {selectedProduct.barcode && <span>Codigo: {selectedProduct.barcode}</span>}
            <Badge
              variant={selectedProduct.tracking_type === 'serialized' ? 'info' : 'default'}
              className="text-[10px]"
            >
              {selectedProduct.tracking_type === 'serialized' ? 'Serializado' : 'Por cantidad'}
            </Badge>
          </div>
        </div>
        <button
          type="button"
          onClick={clearProduct}
          className="text-text-muted hover:bg-surface hover:text-danger rounded p-2 transition-colors"
          aria-label={`Quitar ${selectedProduct.name}`}
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
              setHighlight((current) => Math.min(current + 1, Math.max(matches.length - 1, 0)));
            } else if (event.key === 'ArrowUp') {
              event.preventDefault();
              setHighlight((current) => Math.max(current - 1, 0));
            } else if (event.key === 'Enter' && matches[highlight]) {
              event.preventDefault();
              selectProduct(matches[highlight]);
            } else if (event.key === 'Escape') {
              setOpen(false);
            }
          }}
          placeholder="Buscar por nombre, SKU o codigo..."
          className={cn('h-11 pl-10', invalid && 'border-danger')}
          autoComplete="off"
          aria-expanded={open}
          aria-controls={resultsId}
          data-testid={`item-product-${index}`}
        />
      </div>

      {open && (
        <div
          id={resultsId}
          data-testid={`item-product-results-${index}`}
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

          <div className="max-h-56 overflow-y-auto overscroll-contain" tabIndex={-1}>
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
                <p className="font-medium">No encontramos ese producto.</p>
                <p className="text-text-muted mt-1 text-xs">
                  Busca por nombre, SKU o codigo de barras. Tambien se incluyen productos
                  serializados.
                </p>
              </div>
            ) : (
              <ul role="listbox" className="divide-border divide-y">
                {matches.map((product, resultIndex) => (
                  <li key={product.id}>
                    <button
                      type="button"
                      role="option"
                      aria-selected={resultIndex === highlight}
                      onClick={() => selectProduct(product)}
                      onMouseEnter={() => setHighlight(resultIndex)}
                      className={cn(
                        'flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition-colors',
                        'hover:bg-primary/10 focus-visible:bg-primary/10 focus-visible:outline-none',
                        resultIndex === highlight && 'bg-primary/10',
                      )}
                    >
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold">{product.name}</div>
                        <div className="text-text-muted mt-0.5 flex flex-wrap gap-2 text-xs">
                          {product.sku && <code>SKU: {product.sku}</code>}
                          {product.barcode && <span>Codigo: {product.barcode}</span>}
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
