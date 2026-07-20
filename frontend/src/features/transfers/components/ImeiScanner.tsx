/**
 * ImeiScanner: componente reutilizable que permite al user seleccionar
 * IMEIs / seriales de un almacen para agregarlos a un traslado
 * (CreateTransfer, PrepareTransfer, ReceiveTransfer).
 *
 * Muestra una lista de ProductUnits disponibles con un boton de
 * toggle. El user puede buscar por prefijo del serial. A medida que
 * selecciona/deselecciona, sincronizamos el array `selected` con el
 * padre.
 *
 * Props:
 *  - productId: producto a listar.
 *  - warehouseId: almacen del que se listan los IMEIs.
 *  - serialType: 'imei' | 'serial' (filto por tipo).
 *  - selected: array actual de IMEIs/seriales seleccionados (controlado).
 *  - onChange: callback que notifica al padre con el array actualizado.
 *  - max: limite de seleccion (default = la cantidad esperada del item).
 */
import { useState, useEffect } from 'react';
import { Check, Search, X } from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { useAvailableProductUnits } from '@/features/inventory-center/api';

import { cn } from '@/lib/cn';

export interface ImeiScannerProps {
  productId: number | null;
  warehouseId: number | null;
  serialType?: 'imei' | 'serial';
  selected: string[];
  onChange: (selected: string[]) => void;
  max?: number;
  disabled?: boolean;
  dataTestIdPrefix?: string;
}

export function ImeiScanner({
  productId,
  warehouseId,
  serialType = 'imei',
  selected,
  onChange,
  max,
  disabled = false,
  dataTestIdPrefix = 'imei-scanner',
}: ImeiScannerProps) {
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  useEffect(() => {
    const t = window.setTimeout(() => setDebouncedSearch(search), 200);
    return () => window.clearTimeout(t);
  }, [search]);

  const { data, isLoading, isError } = useAvailableProductUnits(
    productId ?? 0,
    warehouseId,
    debouncedSearch,
    'available',
  );

  // Filter by serial type client-side
  const units = (data ?? []).filter((u) => u.serial_type === serialType);

  function toggle(serial: string) {
    if (disabled) return;
    if (selected.includes(serial)) {
      onChange(selected.filter((s) => s !== serial));
      return;
    }
    if (max != null && selected.length >= max) {
      // Already at max. Reject and show feedback (frontend already shows
      // the expected count, so this is mostly defensive).
      return;
    }
    onChange([...selected, serial]);
  }

  const reachedMax = max != null && selected.length >= max;

  return (
    <div className="space-y-2" data-testid={dataTestIdPrefix}>
      <div className="relative">
        <Search
          className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
          aria-hidden="true"
        />
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={`Buscar ${serialType === 'serial' ? 'seriales' : 'IMEIs'} por prefijo...`}
          className="pl-8"
          disabled={disabled || !productId || !warehouseId}
          data-testid={`${dataTestIdPrefix}-search`}
        />
      </div>

      {!productId || !warehouseId ? (
        <p className="text-xs text-text-muted">
          Selecciona un producto y un almacen para ver las unidades disponibles.
        </p>
      ) : isLoading ? (
        <Spinner label={`Buscando ${serialType === 'serial' ? 'seriales' : 'IMEIs'} disponibles...`} />
      ) : isError ? (
        <p className="text-xs text-danger">
          No se pudo cargar la lista de unidades.
        </p>
      ) : units.length === 0 ? (
        <EmptyState
          title={`Sin ${serialType === 'serial' ? 'seriales' : 'IMEIs'} disponibles`}
          description="No hay unidades disponibles en este almacen. Verifica que el stock tenga IMEIs registrados."
        />
      ) : (
        <ul
          className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-border bg-bg/30 p-2"
          data-testid={`${dataTestIdPrefix}-list`}
        >
          {units.map((u) => {
            const isSelected = selected.includes(u.serial_number);
            return (
              <li key={u.id}>
                <button
                  type="button"
                  disabled={disabled || (!isSelected && reachedMax)}
                  onClick={() => toggle(u.serial_number)}
                  data-testid={`${dataTestIdPrefix}-item-${u.id}`}
                  className={cn(
                    'flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-sm transition-colors',
                    isSelected
                      ? 'bg-primary/10 text-primary font-medium ring-1 ring-primary/30'
                      : 'hover:bg-bg/60 disabled:opacity-50',
                  )}
                >
                  <span className="flex items-center gap-2">
                    {isSelected ? (
                      <Check className="size-4 shrink-0" aria-hidden="true" />
                    ) : (
                      <span className="inline-block size-4 shrink-0 rounded border border-border" />
                    )}
                    <span className="font-mono text-xs">{u.serial_number}</span>
                  </span>
                  <Badge variant="info" className="text-[10px]">
                    {u.status}
                  </Badge>
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {max != null && (
        <p className="text-xs text-text-muted" data-testid={`${dataTestIdPrefix}-counter`}>
          {selected.length} / {max} {serialType === 'serial' ? 'seriales' : 'IMEIs'} seleccionados
          {reachedMax && ' (maximo alcanzado)'}
        </p>
      )}

      {selected.length > 0 && (
        <div className="flex flex-wrap gap-1" data-testid={`${dataTestIdPrefix}-chips`}>
          {selected.map((s) => (
            <span
              key={s}
              className="inline-flex items-center gap-1 rounded-md border border-border bg-bg/50 px-2 py-0.5 font-mono text-[11px]"
              data-testid={`${dataTestIdPrefix}-chip-${s}`}
            >
              {s}
              <button
                type="button"
                onClick={() => toggle(s)}
                disabled={disabled}
                className="text-text-muted hover:text-danger"
                aria-label={`Quitar ${s}`}
              >
                <X className="size-3" />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}