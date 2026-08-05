/**
 * SingleSelectCombobox: input con typeahead single-select para listas planas.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';

import { Badge } from './Badge';
import { Input } from './Input';
import { cn } from '@/lib/cn';

export interface SingleSelectOption {
  value: string | number;
  label: string;
  hint?: string;
  badge?: string;
}

interface SingleSelectComboboxProps {
  options: SingleSelectOption[];
  value: string | number | null;
  onChange: (value: string | number | null) => void;
  placeholder?: string;
  emptyMessage?: string;
  disabled?: boolean;
  invalid?: boolean;
  onQueryChange?: (query: string) => void;
  'aria-label'?: string;
  className?: string;
}

export function SingleSelectCombobox({
  options,
  value,
  onChange,
  placeholder = 'Buscar...',
  emptyMessage = 'Sin resultados',
  disabled = false,
  invalid = false,
  onQueryChange,
  className,
  ...aria
}: SingleSelectComboboxProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [highlight, setHighlight] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);

  const selected = useMemo(() => options.find((o) => o.value === value) ?? null, [options, value]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter(
      (o) =>
        o.label.toLowerCase().includes(q) ||
        (o.hint ?? '').toLowerCase().includes(q) ||
        (o.badge ?? '').toLowerCase().includes(q),
    );
  }, [options, query]);

  useEffect(() => {
    function onDocClick(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  function pick(option: SingleSelectOption) {
    onChange(option.value);
    setQuery('');
    setHighlight(0);
    setOpen(false);
  }

  function clear() {
    onChange(null);
    setQuery('');
    onQueryChange?.('');
    setHighlight(0);
    setOpen(true);
  }

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      {selected ? (
        <div
          className={cn(
            'border-border-strong bg-surface flex items-center gap-2 rounded border px-2 py-1.5',
            invalid && 'border-danger',
            disabled && 'opacity-50',
          )}
        >
          <div className="min-w-0 flex-1">
            <div className="truncate text-sm font-medium">{selected.label}</div>
            {(selected.hint ?? selected.badge) && (
              <div className="text-text-muted mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
                {selected.hint && <span className="truncate">{selected.hint}</span>}
                {selected.badge && (
                  <Badge variant="info" className="text-[10px]">
                    {selected.badge}
                  </Badge>
                )}
              </div>
            )}
          </div>
          <button
            type="button"
            onClick={clear}
            className="text-text-muted hover:bg-bg hover:text-danger rounded p-1"
            aria-label="Quitar selección"
            disabled={disabled}
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
              onQueryChange?.(e.target.value);
              setOpen(true);
              setHighlight(0);
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault();
                setHighlight((h) => Math.min(h + 1, Math.max(filtered.length - 1, 0)));
              } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setHighlight((h) => Math.max(h - 1, 0));
              } else if (e.key === 'Enter' && filtered[highlight]) {
                e.preventDefault();
                pick(filtered[highlight]);
              } else if (e.key === 'Escape') {
                setOpen(false);
              }
            }}
            placeholder={placeholder}
            className={cn('pl-9', invalid && 'border-danger')}
            autoComplete="off"
            disabled={disabled}
            {...aria}
          />
        </div>
      )}

      {open && !selected && (
        <div className="border-border bg-surface absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded border shadow-lg">
          {filtered.length === 0 ? (
            <div className="text-text-muted p-3 text-sm">{emptyMessage}</div>
          ) : (
            <ul role="listbox">
              {filtered.map((option, index) => (
                <li
                  key={option.value}
                  role="option"
                  aria-selected={index === highlight}
                  onClick={() => pick(option)}
                  onMouseEnter={() => setHighlight(index)}
                  className={cn(
                    'border-border cursor-pointer border-b px-3 py-2 last:border-b-0',
                    index === highlight && 'bg-primary/10',
                  )}
                >
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm font-medium">{option.label}</div>
                      {option.hint && (
                        <div className="text-text-muted truncate text-xs">{option.hint}</div>
                      )}
                    </div>
                    {option.badge && (
                      <Badge variant="info" className="shrink-0 text-[10px]">
                        {option.badge}
                      </Badge>
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
