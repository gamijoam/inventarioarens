import { useEffect, useId, useMemo, useState } from 'react';
import { LoaderCircle, PackageSearch, Search, X } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { useProductsForPurchase } from '@/features/purchases/api';
import type { Product } from '@/features/inventory-center/schemas';
import { cn } from '@/lib/cn';

export interface ProductAutocompleteOption {
  id: number;
  name: string;
  sku: string | null;
  barcode: string | null;
  tracking_type?: string;
  unit_of_measure?: string;
  base_price?: number | string | null;
  average_cost?: number | string | null;
  last_purchase_cost?: number | string | null;
  profit_margin?: number | string | null;
  pricing_mode?: 'manual' | 'automatic' | null;
  is_active?: boolean | null;
  available_stock?: number | string | null;
}

/** Indicador de estado del producto para el flujo de compras. */
export function ProductActiveBadge({ isActive }: { isActive?: boolean | null }) {
  if (isActive === undefined || isActive === null) return null;

  return isActive ? (
    <Badge variant="success" className="text-[10px] font-semibold">
      Activo
    </Badge>
  ) : (
    <Badge variant="danger" className="text-[10px] font-semibold">
      Inactivo
    </Badge>
  );
}

/**
 * Convierte un Product del inventario al option del autocompletado de compras,
 * para reutilizar el mismo shape tras crear o editar un producto.
 */
export function productToOption(product: Product): ProductAutocompleteOption {
  return {
    id: product.id,
    name: product.name,
    sku: product.sku ?? null,
    barcode: product.barcode ?? null,
    tracking_type: product.tracking_type,
    unit_of_measure: product.unit_of_measure,
    base_price: product.base_price ?? null,
    average_cost: product.average_cost ?? null,
    last_purchase_cost: product.last_purchase_cost ?? null,
    profit_margin: product.profit_margin ?? null,
    pricing_mode: product.pricing_mode ?? null,
    is_active: product.is_active,
    available_stock: product.available_stock ?? null,
  };
}

interface ProductAutocompleteProps {
  value: number | null;
  selectedProduct?: ProductAutocompleteOption | null;
  onChange: (productId: number | null, product?: ProductAutocompleteOption) => void;
  placeholder?: string;
  onProductNotFound?: (query: string) => void;
  onToggleActive?: (productId: number, nextActive: boolean) => void;
  invalid?: boolean;
}

export function ProductAutocomplete({
  value,
  selectedProduct,
  onChange,
  placeholder = 'Buscar por SKU, codigo de barras o nombre...',
  onProductNotFound,
  onToggleActive,
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

  // Helper para normalizar texto: minusculas, sin acentos ni diacriticos
  const cleanStr = (str: string) =>
    str
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();

  const matches = useMemo(() => {
    if (!query.trim()) return products.slice(0, 30);

    const normQuery = cleanStr(query);
    const tokens = normQuery.split(/\s+/).filter(Boolean);

    // Filtrado inteligente multi-palabra (token-based):
    // El producto coincide si cada uno de los tokens escritos esta presente en el nombre, SKU o codigo de barras.
    const filtered = products.filter((product) => {
      const normName = cleanStr(product.name);
      const normSku = cleanStr(product.sku ?? '');
      const normBarcode = cleanStr(product.barcode ?? '');
      const combined = `${normName} ${normSku} ${normBarcode}`;

      return tokens.every((token) => combined.includes(token));
    });

    // Ordenamiento por relevancia:
    // 1. SKU o Codigo de barras exacto primero
    // 2. Nombre que empieza exactamente con la busqueda
    // 3. Coincidencia continua de la frase completa
    // 4. Coincidencias multi-palabra
    return filtered
      .sort((a, b) => {
        const aSku = cleanStr(a.sku ?? '');
        const bSku = cleanStr(b.sku ?? '');
        const aBar = cleanStr(a.barcode ?? '');
        const bBar = cleanStr(b.barcode ?? '');
        const aName = cleanStr(a.name);
        const bName = cleanStr(b.name);

        if (aSku === normQuery || aBar === normQuery) return -1;
        if (bSku === normQuery || bBar === normQuery) return 1;

        if (aName.startsWith(normQuery) && !bName.startsWith(normQuery)) return -1;
        if (!aName.startsWith(normQuery) && bName.startsWith(normQuery)) return 1;

        if (aName.includes(normQuery) && !bName.includes(normQuery)) return -1;
        if (!aName.includes(normQuery) && bName.includes(normQuery)) return 1;

        return 0;
      })
      .slice(0, 50);
  }, [products, query]);

  useEffect(() => {
    const timer = window.setTimeout(() => setSearchTerm(query.trim()), 120);
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
      <div className="border-primary/25 bg-primary/5 flex min-h-14 items-start gap-3 rounded-md border px-3 py-2">
        <div className="bg-primary/10 text-primary mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-md">
          <PackageSearch className="size-4" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="text-sm leading-snug font-semibold break-words whitespace-normal">
            {selected.name}
          </div>
          <div className="text-text-muted mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
            {selected.sku && <code className="bg-surface rounded px-1 py-0.5">{selected.sku}</code>}
            {selected.barcode && <span>Codigo: {selected.barcode}</span>}
            <span className="inline-flex items-center gap-1 rounded bg-bg px-1.5 py-0.5 font-semibold text-text-primary">
              Stock: {Number(selected.available_stock ?? 0)}
            </span>
            <Badge
              variant={selected.tracking_type === 'serialized' ? 'info' : 'default'}
              className="text-[10px]"
            >
              {selected.tracking_type === 'serialized' ? 'Serializado' : 'Por cantidad'}
            </Badge>
            <ProductActiveBadge isActive={selected.is_active} />
          </div>
        </div>
        {onToggleActive && selected.is_active !== undefined && selected.is_active !== null && (
          <button
            type="button"
            onClick={() => onToggleActive(selected.id, !selected.is_active)}
            className={cn(
              'rounded border px-2 py-1 text-[11px] font-semibold transition-colors',
              selected.is_active
                ? 'border-danger/40 text-danger hover:bg-danger/10'
                : 'border-success/40 text-success hover:bg-success/10',
            )}
            title={selected.is_active ? 'Desactivar producto' : 'Activar producto'}
            data-testid={`product-toggle-active-${selected.id}`}
          >
            {selected.is_active ? 'Desactivar' : 'Activar'}
          </button>
        )}
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

          <div className="max-h-80 overflow-y-auto overscroll-contain" tabIndex={-1}>
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
                  Busca por nombre, SKU o codigo de barras. Tambien se muestran los productos
                  inactivos.
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
                        'flex w-full flex-col gap-1.5 px-3 py-2.5 text-left transition-colors',
                        'hover:bg-primary/10 focus-visible:bg-primary/10 focus-visible:outline-none',
                        index === highlight && 'bg-primary/10',
                      )}
                    >
                      <div className="flex w-full items-start justify-between gap-2">
                        <span className="min-w-0 flex-1 text-sm leading-snug font-semibold break-words whitespace-normal">
                          {product.name}
                        </span>
                        <div className="flex shrink-0 flex-wrap items-center justify-end gap-1">
                          <ProductActiveBadge isActive={product.is_active} />
                          <Badge
                            variant={product.tracking_type === 'serialized' ? 'info' : 'default'}
                            className="text-[10px]"
                          >
                            {product.tracking_type === 'serialized' ? 'Serializado' : 'Por cantidad'}
                          </Badge>
                        </div>
                      </div>
                      <div className="text-text-muted flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                        {product.sku && <code>SKU: {product.sku}</code>}
                        {product.barcode && <span>Codigo: {product.barcode}</span>}
                        {product.base_price != null && <span>Base: {product.base_price}</span>}
                        <span className="font-semibold text-text-primary">
                          Stock: {Number(product.available_stock ?? 0)}
                        </span>
                      </div>
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
