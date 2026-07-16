/**
 * ImeiListInput: lista de N inputs para capturar seriales/IMEIs de un
 * producto serializado. Pensado para ingreso RAPIDO en bodega:
 *
 *  - Auto-inicializa N inputs cuando expectedQuantity > 0 y value vacio.
 *  - Re-inicializa cuando cambia expectedQuantity (el usuario cambia la
 *    cantidad del item arriba).
 *  - Auto-focus al primer input vacio.
 *  - Enter salta al siguiente (crea uno nuevo si era el ultimo).
 *  - Pegar varios IMEIs (uno por linea, o separados por coma/espacio)
 *    los reparte automaticamente.
 *  - Backspace en input vacio va al anterior.
 *  - Contador X/Y visibles y bordes verdes en inputs validos.
 *  - Compatible con escaneo (cada escaneo termina en \n o \r).
 */
import { useEffect, useMemo, useRef, useState } from 'react';
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
  expectedQuantity: number;
  defaultType?: 'imei' | 'serial';
  disabled?: boolean;
}

function splitPastedSerials(raw: string): string[] {
  return raw
    .split(/[\n\r,;\s]+/g)
    .map((t) => t.trim().toUpperCase())
    .filter((t) => t.length > 0);
}

export function ImeiListInput({
  value,
  onChange,
  expectedQuantity,
  defaultType = 'imei',
  disabled,
}: ImeiListInputProps) {
  const [touched, setTouched] = useState<Set<number>>(new Set());
  const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

  // Track the last expectedQuantity we initialized for. If it changes,
  // re-initialize (extending or trimming the array as needed).
  const lastInitQtyRef = useRef<number>(0);

  const safeQty = Math.max(0, Math.floor(expectedQuantity));

  // Auto-inicializa y RE-inicializa cuando cambia expectedQuantity.
  useEffect(() => {
    if (disabled) return;
    if (lastInitQtyRef.current === safeQty) {
      return;
    }

    if (safeQty <= 0) {
      lastInitQtyRef.current = safeQty;
      return;
    }

    if (value.length === 0) {
      const initial: ImeiInput[] = Array.from({ length: safeQty }, () => ({
        serial_type: defaultType,
        serial_number: '',
      }));
      lastInitQtyRef.current = safeQty;
      onChange(initial);
      return;
    }

    // Ya hay value: ajustar tamaño si la cantidad cambió.
    if (value.length < safeQty) {
      const extra: ImeiInput[] = Array.from(
        { length: safeQty - value.length },
        () => ({ serial_type: defaultType, serial_number: '' }),
      );
      lastInitQtyRef.current = safeQty;
      onChange([...value, ...extra]);
    } else if (value.length > safeQty) {
      // Truncar pero solo si los inputs extra están vacíos.
      const trimmed = value.slice(0, safeQty);
      const allKeptFull = trimmed.every((v) => v.serial_number.trim() !== '');
      if (allKeptFull) {
        // No se puede truncar con inputs llenos: mantener el array completo
        // y marcar la ultima cantidad; el usuario deberá borrar manualmente.
        lastInitQtyRef.current = value.length;
        return;
      }
      lastInitQtyRef.current = safeQty;
      onChange(trimmed);
    } else {
      lastInitQtyRef.current = safeQty;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [safeQty, defaultType, disabled]);

  // Auto-focus al primer input vacio (sin interferir si el usuario está
  // escribiendo en otro).
  useEffect(() => {
    if (disabled) return;
    const idx = value.findIndex((v) => v.serial_number.trim() === '');
    if (idx < 0) return;
    const el = inputRefs.current[idx];
    if (!el) return;
    if (
      document.activeElement === document.body ||
      document.activeElement?.tagName !== 'INPUT'
    ) {
      el.focus();
    }
  }, [value, disabled]);

  function update(index: number, patch: Partial<ImeiInput>) {
    const next = value.map((item, i) => (i === index ? { ...item, ...patch } : item));
    onChange(next);
  }

  function add() {
    const next = [...value, { serial_type: defaultType, serial_number: '' }];
    onChange(next);
    lastInitQtyRef.current = next.length;
  }

  function remove(index: number) {
    if (value.length <= Math.max(1, safeQty)) return;
    const next = value.filter((_, i) => i !== index);
    onChange(next);
    lastInitQtyRef.current = next.length;
    setTimeout(() => {
      const target = Math.max(0, index - 1);
      inputRefs.current[target]?.focus();
    }, 0);
  }

  function isDuplicate(serialNumber: string, index: number): boolean {
    return value.some((item, i) => i !== index && item.serial_number === serialNumber);
  }

  function handlePaste(e: React.ClipboardEvent<HTMLInputElement>, index: number) {
    const text = e.clipboardData.getData('text');
    const tokens = splitPastedSerials(text);
    if (tokens.length <= 1) return;
    e.preventDefault();

    const current = value[index]?.serial_number.trim() ?? '';
    const startIdx = current.length > 0 ? index + 1 : index;

    const next: ImeiInput[] = [...value];
    let i = startIdx;
    for (const tok of tokens) {
      while (i < next.length && next[i]!.serial_number.trim() !== '') i += 1;
      if (i >= next.length) {
        next.push({ serial_type: defaultType, serial_number: tok });
        i = next.length;
      } else {
        next[i] = { ...next[i]!, serial_number: tok };
        i += 1;
      }
    }
    onChange(next);
    lastInitQtyRef.current = next.length;
    const t = new Set(touched);
    for (let k = startIdx; k < i; k += 1) t.add(k);
    setTouched(t);
    setTimeout(() => {
      const idx2 = next.findIndex((v) => v.serial_number.trim() === '');
      const target = idx2 >= 0 ? idx2 : next.length - 1;
      inputRefs.current[target]?.focus();
    }, 0);
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>, index: number) {
    if (e.key === 'Enter') {
      e.preventDefault();
      const nextIdx = index + 1;
      if (nextIdx >= value.length) {
        add();
        setTimeout(() => {
          inputRefs.current[nextIdx]?.focus();
        }, 0);
      } else {
        inputRefs.current[nextIdx]?.focus();
      }
    } else if (e.key === 'Backspace' && value[index]?.serial_number === '' && index > 0) {
      e.preventDefault();
      inputRefs.current[index - 1]?.focus();
    }
  }

  const canAdd = value.length < 50;
  const canRemove = value.length > Math.max(1, safeQty);

  const filled = useMemo(
    () => value.filter((v) => v.serial_number.trim() !== '').length,
    [value],
  );
  const valid = useMemo(() => {
    if (filled !== value.length) return false;
    if (value.length !== safeQty) return false;
    for (let i = 0; i < value.length; i += 1) {
      const vi = value[i]!;
      if (!IMEI_REGEX.test(vi.serial_number)) return false;
      for (let j = i + 1; j < value.length; j += 1) {
        if (vi.serial_number === value[j]!.serial_number) return false;
      }
    }
    return true;
  }, [value, filled, safeQty]);

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <Label className="text-xs">
          IMEIs / seriales{' '}
          <span
            className={cn(
              'ml-1 rounded px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
              valid
                ? 'bg-emerald-100 text-emerald-700'
                : filled > 0
                  ? 'bg-amber-100 text-amber-700'
                  : 'bg-surface-muted text-text-muted',
            )}
          >
            {filled} / {value.length}
          </span>
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
                ref={(el) => {
                  inputRefs.current[i] = el;
                }}
                value={item.serial_number}
                onChange={(e) => update(i, { serial_number: e.target.value.toUpperCase() })}
                onPaste={(e) => handlePaste(e, i)}
                onKeyDown={(e) => handleKeyDown(e, i)}
                onBlur={() => {
                  const t = new Set(touched);
                  t.add(i);
                  setTouched(t);
                }}
                placeholder={item.serial_type === 'imei' ? 'Escanear o escribir IMEI y Enter' : 'SN-XXXX-001'}
                disabled={disabled}
                className={cn(
                  'flex-1 font-mono',
                  showError && 'border-danger',
                  !isEmpty && !showError && 'border-emerald-500',
                )}
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
        Tip: escanea o escribe un IMEI y presiona <kbd className="rounded border px-1">Enter</kbd>{' '}
        para saltar al siguiente. Puedes pegar varios IMEIs (uno por linea o separados por
        coma) y se repartiran automaticamente.
      </p>
    </div>
  );
}
