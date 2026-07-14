/**
 * Combobox multi-select con typeahead.
 * Usar para tags, marcas, almacenes y cualquier lista plana donde el usuario
 * pueda buscar y seleccionar varios items.
 *
 * UX:
 *   - Escribir filtra las opciones en tiempo real (case-insensitive).
 *   - Click en una opcion o Enter la selecciona y la agrega como chip.
 *   - Click en la X del chip la remueve.
 *   - Backspace en input vacio remueve el ultimo chip.
 *   - Popover se abre al focus, se cierra al seleccionar o al click afuera.
 */
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Check, ChevronDown, X } from 'lucide-react';
import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';

export interface ComboboxOption {
  value: string | number;
  label: string;
  /** Texto opcional para resaltar (ej: nombre + codigo) */
  hint?: string;
  /** Color opcional para el chip (ej: tags con color hex) */
  color?: string;
}

export interface ComboboxProps {
  options: ComboboxOption[];
  value: ComboboxOption['value'][];
  onChange: (next: ComboboxOption['value'][]) => void;
  placeholder?: string;
  emptyMessage?: string;
  disabled?: boolean;
  invalid?: boolean;
  /** Label accesible (no se renderiza, debe estar fuera) */
  'aria-label'?: string;
  className?: string;
}

export function Combobox({
  options,
  value,
  onChange,
  placeholder = 'Buscar...',
  emptyMessage = 'Sin resultados',
  disabled = false,
  invalid = false,
  className,
  ...aria
}: ComboboxProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);
  const inputId = useId();

  const selected = useMemo(
    () => options.filter((o) => value.includes(o.value)),
    [options, value],
  );

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter(
      (o) =>
        o.label.toLowerCase().includes(q) ||
        (o.hint ?? '').toLowerCase().includes(q),
    );
  }, [options, query]);

  const add = (optionValue: ComboboxOption['value']) => {
    if (value.includes(optionValue)) return;
    onChange([...value, optionValue]);
    setQuery('');
    inputRef.current?.focus();
  };

  const remove = (optionValue: ComboboxOption['value']) => {
    onChange(value.filter((v) => v !== optionValue));
    inputRef.current?.focus();
  };

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && query === '' && selected.length > 0) {
      remove(selected[selected.length - 1]!.value);
    }
    if (e.key === 'Enter' && filtered.length > 0) {
      e.preventDefault();
      add(filtered[0]!.value);
    }
  };

  return (
    <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
      <div
        className={cn(
          'flex min-h-9 w-full flex-wrap items-center gap-1.5 rounded border bg-surface px-2 py-1.5 shadow-sm transition-colors',
          'focus-within:outline-none focus-within:ring-2 focus-within:ring-primary focus-within:ring-offset-1 focus-within:ring-offset-bg',
          disabled && 'cursor-not-allowed opacity-50',
          invalid
            ? 'border-danger focus-within:ring-danger'
            : 'border-border-strong',
          className,
        )}
      >
        {selected.map((opt) => (
          <span
            key={opt.value}
            className="inline-flex items-center gap-1 rounded bg-bg px-2 py-0.5 text-xs font-medium"
            style={opt.color ? { backgroundColor: `${opt.color}20`, color: opt.color } : undefined}
          >
            {opt.label}
            <button
              type="button"
              onClick={() => remove(opt.value)}
              className="rounded p-0.5 hover:bg-text-primary/10"
              aria-label={`Quitar ${opt.label}`}
              disabled={disabled}
            >
              <X className="size-3" aria-hidden="true" />
            </button>
          </span>
        ))}

        <PopoverPrimitive.Trigger asChild>
          <input
            ref={inputRef}
            id={inputId}
            type="text"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              if (!open) setOpen(true);
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={handleKeyDown}
            disabled={disabled}
            placeholder={selected.length === 0 ? placeholder : ''}
            className="flex-1 min-w-[120px] bg-transparent text-sm outline-none placeholder:text-text-muted disabled:cursor-not-allowed"
            autoComplete="off"
            {...aria}
          />
        </PopoverPrimitive.Trigger>

        <ChevronDown className="size-4 text-text-muted" aria-hidden="true" />
      </div>

      <PopoverPrimitive.Portal>
        <PopoverPrimitive.Content
          align="start"
          sideOffset={4}
          className={cn(
            'z-50 max-h-60 w-[var(--radix-popover-trigger-width)] overflow-auto rounded-md border border-border bg-surface p-1 shadow-md',
            'data-[state=open]:animate-in data-[state=closed]:animate-out',
            'data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
          )}
          onOpenAutoFocus={(e) => e.preventDefault()}
        >
          {filtered.length === 0 ? (
            <div className="px-3 py-2 text-sm text-text-muted">{emptyMessage}</div>
          ) : (
            filtered.map((opt) => {
              const isSelected = value.includes(opt.value);
              return (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => add(opt.value)}
                  className={cn(
                    'flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-sm transition-colors',
                    'hover:bg-bg',
                    isSelected && 'opacity-50',
                  )}
                >
                  <div className="min-w-0 flex-1">
                    <div className="truncate">{opt.label}</div>
                    {opt.hint && (
                      <div className="truncate text-xs text-text-muted">{opt.hint}</div>
                    )}
                  </div>
                  {isSelected && <Check className="size-4 shrink-0 text-primary" aria-hidden="true" />}
                </button>
              );
            })
          )}
        </PopoverPrimitive.Content>
      </PopoverPrimitive.Portal>
    </PopoverPrimitive.Root>
  );
}