/**
 * ReceiveItemRow: fila de la tabla del ReceiveDialog. Muestra el item
 * pendiente del PurchaseOrder y permite:
 * - Editar la cantidad a recibir (default = todo lo pendiente).
 * - Si el producto es serializado, mostrar los IMEIs/seriales capturados
 *   en el draft (readonly por ahora; editar IMEIs al recibir sera FASE 4).
 * - Ver el producto, almacen, cantidad ya recibida y subtotal.
 */
import { useEffect, useMemo } from 'react';

import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import type { ImeiInput } from './ImeiListInput';

export interface ReceiveItemRowValue {
  purchase_item_id: number;
  product_id: number;
  product_name: string;
  product_sku?: string | null;
  product_tracking_type?: string;
  warehouse_code: string;
  /** Cantidad pedida en el draft original */
  ordered_quantity: number;
  /** Cantidad ya recibida (de recepciones previas) */
  received_quantity: number;
  /** Cantidad que se va a recibir en este ciclo */
  receiving_quantity: number;
  /** Costo unitario (puede ser null si el user no tiene finance.costs.view) */
  unit_cost: number | null;
  /** Seriales capturados en el draft (se conservan en la recepcion) */
  serial_units: ImeiInput[];
  /** Validacion local: el padre inyecta mensajes de error por item */
  error?: string;
}

interface ReceiveItemRowProps {
  value: ReceiveItemRowValue;
  onChange: (next: ReceiveItemRowValue) => void;
  disabled?: boolean;
}

export function ReceiveItemRow({ value, onChange, disabled }: ReceiveItemRowProps) {
  const pending = useMemo(
    () => Math.max(0, value.ordered_quantity - value.received_quantity),
    [value.ordered_quantity, value.received_quantity],
  );
  const isSerialized = value.product_tracking_type === 'serialized';
  const serialsToReceive = isSerialized ? value.serial_units.length : 0;

  // Si el padre nunca setea receiving_quantity, default = pending.
  useEffect(() => {
    if (value.receiving_quantity === 0 && pending > 0) {
      onChange({ ...value, receiving_quantity: pending });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pending]);

  const overReceive = value.receiving_quantity > pending;
  const isIntQuantity = isSerialized;

  return (
    <div className="rounded-md border border-border bg-bg/30 p-3" data-testid={`receive-item-${value.purchase_item_id}`}>
      {/* Row 1: Producto + almacen + status */}
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-medium">{value.product_name}</div>
          <div className="mt-0.5 flex items-center gap-1.5 text-xs text-text-muted">
            {value.product_sku && (
              <code className="rounded bg-bg px-1 py-0.5">{value.product_sku}</code>
            )}
            <span>|</span>
            <span>Almacen: <span className="font-medium">{value.warehouse_code}</span></span>
            {isSerialized && <><span>|</span><Badge variant="info" className="text-[10px]">Serializado</Badge></>}
          </div>
        </div>
        <div className="flex items-center gap-2 text-xs text-text-muted">
          <span>Pedido: <span className="font-semibold tabular-nums text-text-primary">{value.ordered_quantity}</span></span>
          {value.received_quantity > 0 && (
            <span>
              Ya recibido:{' '}
              <span className="font-semibold tabular-nums text-text-primary">{value.received_quantity}</span>
            </span>
          )}
          <span>
            Pendiente:{' '}
            <span className="font-semibold tabular-nums text-warning">{pending}</span>
          </span>
        </div>
      </div>

      {/* Row 2: Cantidad a recibir + subtotal preview */}
      <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Recibir
          </label>
          <Input
            type="number"
            min={isIntQuantity ? 1 : 0.0001}
            step={isIntQuantity ? 1 : 0.0001}
            max={pending}
            value={value.receiving_quantity ?? ''}
            onChange={(e) => onChange({ ...value, receiving_quantity: Number(e.target.value) })}
            disabled={disabled}
            className={cn('text-right tabular-nums', overReceive && 'border-danger')}
            aria-invalid={overReceive}
          />
          {overReceive && (
            <p className="text-xs text-danger">Excede el pendiente ({pending}).</p>
          )}
        </div>

        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Costo unit.
          </label>
          <div className="flex h-9 items-center rounded border border-transparent bg-bg/50 px-3 text-sm tabular-nums">
            {value.unit_cost != null ? formatMoney(value.unit_cost) : <span className="text-text-muted/60">No visible</span>}
          </div>
        </div>

        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Subtotal
          </label>
          <div className="flex h-9 items-center justify-end rounded border border-transparent bg-bg/50 px-3 text-sm font-semibold tabular-nums">
            {value.unit_cost != null && value.receiving_quantity > 0
              ? formatMoney(value.receiving_quantity * value.unit_cost)
              : '-'}
          </div>
        </div>
      </div>

      {/* Seriales readonly (FASE 4 permitira editar) */}
      {isSerialized && serialsToReceive > 0 && (
        <div className="mt-3 space-y-1 rounded border border-border-strong/50 bg-bg/50 p-2">
          <div className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            IMEIs / seriales a recibir
          </div>
          <ul className="space-y-0.5 text-xs">
            {value.serial_units.map((s, i) => (
              <li key={i} className="flex items-center gap-2 font-mono">
                <Badge variant="default" className="text-[10px] uppercase">{s.serial_type}</Badge>
                <span>{s.serial_number || <span className="text-danger">(vacio)</span>}</span>
              </li>
            ))}
          </ul>
          <p className="text-[10px] text-text-muted">
            Capturados al crear el borrador. Si necesitas editarlos, cancela la recepcion y
            modifica la compra.
          </p>
        </div>
      )}

      {value.error && (
        <p className="mt-2 text-xs text-danger">{value.error}</p>
      )}
    </div>
  );
}
