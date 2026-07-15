/**
 * ImeiListInput: lista de N inputs para capturar seriales/IMEIs de un
 * producto serializado. La cantidad de inputs es dinamica: el user
 * puede agregar o quitar. Cada input se valida por regex y unicidad
 * dentro de la lista.
 *
 * Comportamiento:
 * - Si la cantidad esperada es conocida (quantity del item), se renderiza
 *   exactamente esa cantidad de inputs.
 * - El user puede agregar inputs adicionales (paste rapido, escaner).
 * - Boton "Quitar" en cada fila elimina el input (no se puede eliminar
 *   si deja menos inputs que `expectedQuantity`).
 * - Errores inline en rojo debajo de cada input.
 */
import { useEffect, useRef, useState } from 'react';
import { Plus, X } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { cn } from '@/lib/cn';

const IMEI_REGEX = /^[A-Z0-9-]{6,32}$/;

export interface ImeiInput {
  serial_type: 'imei' | 'serial';
  serial_number: string;
}

interface ImeiListInputProps {
  value: ImeiInput[];
  onChange: (next: ImeiInput[]) => void;
  /** Cantidad esperada (la cantidad del item de la compra). Determina cuantos inputs se renderizan inicialmente. */
  expectedQuantity: number;
  /** Tipo de serial por defecto (imei para telefonos, serial para otros). */
  defaultType?: 'imei' | 'serial';
  disabled?: boolean;
}

export function ImeiListInput({
  value,
  onChange,
  expectedQuantity,
  defaultType = 'imei',
  disabled,
}: ImeiListInputProps) {
  const [touched, setTouched] = useState<Set<number>>(new Set());
  const initRanRef = useRef(false);

  // Auto-inicializa con N inputs vacios solo la primera vez que
  // expectedQuantity > 0 y value esta vacio. Guard con ref para
  // evitar loops de re-render.
  useEffect(() => {
    if (initRanRef.current) return;
    if (value.length === 0 && expectedQuantity > 0) {
      initRanRef.current = true;
      const initial: ImeiInput[] = Array.from({ length: expectedQuantity }, () => ({
        serial_type: defaultType,
        serial_number: '',
      }));
      onChange(initial);
    }
    if (value.length > 0) {
      initRanRef.current = true;
    }
  }, [value, expectedQuantity, defaultType, onChange]);

  function update(index: number, patch: Partial<ImeiInput>) {
    const next = value.map((item, i) => (i === index ? { ...item, ...patch } : item));
    onChange(next);
  }

  function add() {
    onChange([...value, { serial_type: defaultType, serial_number: '' }]);
  }

  function remove(index: number) {
    if (value.length <= Math.max(1, expectedQuantity)) return;
    onChange(value.filter((_, i) => i !== index));
  }

  function isDuplicate(serialNumber: string, index: number): boolean {
    return value.some((item, i) => i !== index && item.serial_number === serialNumber);
  }

  const canAdd = value.length < 50;
  const canRemove = value.length > Math.max(1, expectedQuantity);

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <Label className="text-xs">
          IMEIs / seriales ({value.length})
        </Label>
        {canAdd && (
          <Button type="button" size="sm" variant="outline" onClick={add} disabled={disabled}>
            <Plus className="size-3.5" /> Agregar
          </Button>
        )}
      </div>

      <div className="space-y-1.5">
        {value.map((item, i) => {
          const isTouched = touched.has(i);
          const isEmpty = item.serial_number.trim() === '';
          const isInvalid = isTouched && !isEmpty && !IMEI_REGEX.test(item.serial_number);
          const isDuplicated = isTouched && !isEmpty && isDuplicate(item.serial_number, i);
          const showError = isInvalid || isDuplicated;

          return (
            <div key={i} className="flex items-center gap-1.5">
              <select
                value={item.serial_type}
                onChange={(e) => update(i, { serial_type: e.target.value as 'imei' | 'serial' })}
                disabled={disabled}
                className="h-9 rounded border border-border-strong bg-surface px-2 text-sm"
                aria-label={`Tipo de serial #${i + 1}`}
              >
                <option value="imei">IMEI</option>
                <option value="serial">Serial</option>
              </select>
              <Input
                value={item.serial_number}
                onChange={(e) => update(i, { serial_number: e.target.value.toUpperCase() })}
                onBlur={() => {
                  const t = new Set(touched);
                  t.add(i);
                  setTouched(t);
                }}
                placeholder={item.serial_type === 'imei' ? '123456789012345' : 'SN-XXXX-001'}
                disabled={disabled}
                className={cn('flex-1', showError && 'border-danger')}
                aria-invalid={showError}
                aria-label={`Serial #${i + 1}`}
                autoComplete="off"
                inputMode="text"
                maxLength={32}
              />
              {canRemove && (
                <Button
                  type="button"
                  size="icon-sm"
                  variant="ghost"
                  onClick={() => remove(i)}
                  disabled={disabled}
                  aria-label={`Quitar serial #${i + 1}`}
                >
                  <X className="size-3.5 text-danger" />
                </Button>
              )}
            </div>
          );
        })}
      </div>

      <p className="text-xs text-text-muted">
        Formato: 6-32 caracteres alfanumericos o guion (mayusculas). Cada IMEI/serial
        debe ser unico dentro de la lista.
      </p>
    </div>
  );
}
