/**
 * ReceiveItemRow: fila de la tabla del ReceiveDialog. Muestra el item
 * pendiente del PurchaseOrder y permite:
 * - Editar la cantidad a recibir (default = todo lo pendiente).
 * - Si el producto es serializado, capturar o ajustar los IMEIs/seriales
 *   que entran en esta recepcion.
 * - Ver el producto, almacen, cantidad ya recibida y subtotal.
 */
import { useEffect, useMemo } from 'react';

import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import { formatMoney } from '@/lib/money';
import { ImeiListInput, type ImeiInput } from './ImeiListInput';

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
  /** Seriales/IMEIs a recibir en este ciclo */
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
    <div
      className="border-border bg-bg/30 rounded-md border p-3"
      data-testid={`receive-item-${value.purchase_item_id}`}
    >
      {/* Row 1: Producto + almacen + status */}
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-medium">{value.product_name}</div>
          <div className="text-text-muted mt-0.5 flex items-center gap-1.5 text-xs">
            {value.product_sku && (
              <code className="bg-bg rounded px-1 py-0.5">{value.product_sku}</code>
            )}
            <span>|</span>
            <span>
              Almacen: <span className="font-medium">{value.warehouse_code}</span>
            </span>
            {isSerialized && (
              <>
                <span>|</span>
                <Badge variant="info" className="text-[10px]">
                  Serializado
                </Badge>
              </>
            )}
          </div>
        </div>
        <div className="text-text-muted flex items-center gap-2 text-xs">
          <span>
            Pedido:{' '}
            <span className="text-text-primary font-semibold tabular-nums">
              {value.ordered_quantity}
            </span>
          </span>
          {value.received_quantity > 0 && (
            <span>
              Ya recibido:{' '}
              <span className="text-text-primary font-semibold tabular-nums">
                {value.received_quantity}
              </span>
            </span>
          )}
          <span>
            Pendiente: <span className="text-warning font-semibold tabular-nums">{pending}</span>
          </span>
        </div>
      </div>

      {/* Row 2: Cantidad a recibir + subtotal preview */}
      <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div className="space-y-1">
          <label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
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
          {overReceive && <p className="text-danger text-xs">Excede el pendiente ({pending}).</p>}
        </div>

        <div className="space-y-1">
          <label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
            Costo unit.
          </label>
          <div className="bg-bg/50 flex h-9 items-center rounded border border-transparent px-3 text-sm tabular-nums">
            {value.unit_cost != null ? (
              formatMoney(value.unit_cost)
            ) : (
              <span className="text-text-muted/60">No visible</span>
            )}
          </div>
        </div>

        <div className="space-y-1">
          <label className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
            Subtotal
          </label>
          <div className="bg-bg/50 flex h-9 items-center justify-end rounded border border-transparent px-3 text-sm font-semibold tabular-nums">
            {value.unit_cost != null && value.receiving_quantity > 0
              ? formatMoney(value.receiving_quantity * value.unit_cost)
              : '-'}
          </div>
        </div>
      </div>

      {isSerialized && (
        <div className="border-border-strong/50 bg-bg/50 mt-3 space-y-1 rounded border p-2">
          <div className="text-text-secondary text-xs font-semibold tracking-wide uppercase">
            IMEIs / seriales de esta recepcion
          </div>
          <ImeiListInput
            value={value.serial_units}
            onChange={(serialUnits) => onChange({ ...value, serial_units: serialUnits })}
            expectedQuantity={Math.max(0, Math.floor(value.receiving_quantity))}
            disabled={disabled}
          />
        </div>
      )}

      {value.error && <p className="text-danger mt-2 text-xs">{value.error}</p>}
    </div>
  );
}
