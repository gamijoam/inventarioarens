/**
 * SupplierAutocomplete: input con typeahead single-select para buscar
 * proveedores por nombre o documento. Variante simplificada del
 * ProductAutocomplete (sin informacion adicional del proveedor).
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { useSuppliers } from '@/features/suppliers/api';
import { cn } from '@/lib/cn';

export interface SupplierOption {
  id: number;
  name: string;
  document_type: string | null;
  document_number: string | null;
}

interface SupplierAutocompleteProps {
  value: number | null;
  onChange: (supplierId: number | null, supplier?: SupplierOption) => void;
  placeholder?: string;
  invalid?: boolean;
}

export function SupplierAutocomplete({
  value,
  onChange,
  placeholder = 'Buscar proveedor por nombre o documento...',
  invalid,
}: SupplierAutocompleteProps) {
  const { data: suppliers = [] } = useSuppliers({ active_only: true });
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);

  const selected = useMemo(
    () => suppliers.find((s) => s.id === value) ?? null,
    [suppliers, value],
  );

  const matches = useMemo(() => {
    if (!query.trim()) return suppliers.slice(0, 8);
    const q = query.toLowerCase();
    return suppliers
      .filter((s) => {
        const name = (s.name ?? '').toLowerCase();
        const doc = `${s.document_type ?? ''}${s.document_number ?? ''}`.toLowerCase();
        return name.includes(q) || doc.includes(q);
      })
      .slice(0, 12);
  }, [suppliers, query]);

  useEffect(() => {
    function onDocClick(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  function pick(s: SupplierOption) {
    onChange(s.id, s);
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
            {selected.document_number && (
              <div className="text-xs text-text-muted">
                <code className="rounded bg-bg px-1 py-0.5">
                  {selected.document_type}-{selected.document_number}
                </code>
              </div>
            )}
          </div>
          <button
            type="button"
            onClick={clear}
            className="rounded p-1 text-text-muted hover:bg-bg hover:text-danger"
            aria-label="Quitar proveedor"
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
                pick(matches[highlight] as SupplierOption);
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

      {open && !selected && matches.length > 0 && (
        <div className="absolute z-50 mt-1 w-full max-h-64 overflow-auto rounded border border-border bg-surface shadow-lg">
          <ul role="listbox">
            {matches.map((s, i) => (
              <li
                key={s.id}
                role="option"
                aria-selected={i === highlight}
                onClick={() => pick(s as SupplierOption)}
                onMouseEnter={() => setHighlight(i)}
                className={cn(
                  'cursor-pointer border-b border-border px-3 py-2 last:border-b-0',
                  i === highlight && 'bg-primary/10',
                )}
              >
                <div className="truncate text-sm font-medium">{s.name}</div>
                {s.document_number && (
                  <div className="text-xs text-text-muted">
                    <code className="rounded bg-bg px-1 py-0.5">
                      {s.document_type}-{s.document_number}
                    </code>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      {open && !selected && matches.length === 0 && (
        <div className="absolute z-50 mt-1 w-full rounded border border-border bg-surface p-3 text-sm text-text-muted shadow-lg">
          Sin proveedores activos. Crea uno en <a href="/suppliers" className="text-primary hover:underline">/suppliers</a>.
        </div>
      )}
    </div>
  );
}
