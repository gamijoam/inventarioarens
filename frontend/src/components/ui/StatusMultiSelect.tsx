import { useEffect, useRef, useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';

export interface StatusMultiOption {
  value: string;
  label: string;
}

interface StatusMultiSelectProps {
  options: StatusMultiOption[];
  selected: string[];
  onChange: (values: string[]) => void;
  placeholder?: string;
  label?: string;
  id?: string;
}

/**
 * Dropdown multi-seleccion de estados (checkboxes). El valor es un array de
 * strings; vacio = todos. Se usa en CxC y CxP para filtrar varios estados a
 * la vez (ej. pendientes + vencidas).
 */
export function StatusMultiSelect({
  options,
  selected,
  onChange,
  placeholder = 'Todos',
  label,
  id,
}: StatusMultiSelectProps) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    function closeOnOutside(event: PointerEvent): void {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    }
    function closeOnEscape(event: KeyboardEvent): void {
      if (event.key === 'Escape') setOpen(false);
    }
    document.addEventListener('pointerdown', closeOnOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [open]);

  const toggle = (value: string) => {
    if (selected.includes(value)) {
      onChange(selected.filter((v) => v !== value));
    } else {
      onChange([...selected, value]);
    }
  };

  const summary =
    selected.length === 0
      ? placeholder
      : selected.length === options.length
        ? 'Todos'
        : options
            .filter((o) => selected.includes(o.value))
            .map((o) => o.label)
            .join(', ');

  return (
    <div ref={rootRef} className="relative">
      {label && (
        <label htmlFor={id} className="text-xs text-text-muted">
          {label}
        </label>
      )}
      <button
        type="button"
        id={id}
        onClick={() => setOpen((v) => !v)}
        className="border-border-strong bg-surface mt-1 flex h-9 w-full items-center justify-between gap-2 rounded border px-3 text-left text-sm shadow-sm"
        data-testid="status-multi-select"
      >
        <span className={selected.length === 0 ? 'text-text-muted' : 'truncate'}>{summary}</span>
        <ChevronDown className={`size-4 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="border-border bg-surface absolute z-50 mt-1 w-full min-w-52 rounded-md border p-1 shadow-lg">
          {options.map((option) => {
            const checked = selected.includes(option.value);
            return (
              <button
                key={option.value}
                type="button"
                onClick={() => toggle(option.value)}
                className="hover:bg-bg flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm"
              >
                <span className="flex size-4 items-center justify-center rounded border border-border-strong bg-surface">
                  {checked && <Check className="size-3 text-primary" />}
                </span>
                <span className="flex-1">{option.label}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
