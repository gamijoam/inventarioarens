/**
 * PurchaseItemRow: card editable de un item en PurchaseFormDialog.
 * Cambiamos de <tr> a <div> para evitar scroll horizontal: con 7 columnas
 * (almacen, producto, qty, cost, subtotal, IMEIs, delete) la tabla se
 * desbordaba en pantallas < 1100px. Cada card ocupa todo el ancho
 * y apila los campos internamente con grid responsive.
 *
 * Layout:
 *   [Almacen | Producto (info)                          | (delete)]
 *   [Cantidad | Costo unit. | Subtotal                    ]
 *   [ImeiListInput (solo si product es serializado)        ]
 */
import { useMemo } from 'react';
import { Trash2, Package } from 'lucide-react';

import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { useWarehouses } from '@/features/inventory-center/api';
import type { ImeiInput } from './ImeiListInput';
import { ImeiListInput } from './ImeiListInput';
import { ProductAutocomplete, type ProductAutocompleteOption } from './ProductAutocomplete';
import { cn } from '@/lib/cn';

export interface PurchaseItemRowValue {
  warehouse_id: number | null;
  product_id: number | null;
  product_info: ProductAutocompleteOption | null;
  quantity: number;
  unit_cost: number;
  serial_units: ImeiInput[];
}

interface PurchaseItemRowProps {
  value: PurchaseItemRowValue;
  onChange: (next: PurchaseItemRowValue) => void;
  onRemove: () => void;
  disabled?: boolean;
  canRemove: boolean;
  index: number;
}

export function PurchaseItemRow({
  value,
  onChange,
  onRemove,
  disabled,
  canRemove,
  index,
}: PurchaseItemRowProps) {
  const { data: warehouses = [] } = useWarehouses();
  const subtotal = useMemo(
    () => (Number.isFinite(value.quantity) ? value.quantity * value.unit_cost : 0),
    [value.quantity, value.unit_cost],
  );
  const isSerialized = value.product_info?.tracking_type === 'serialized';

  return (
    <div
      className="rounded-md border border-border bg-bg/30 p-3"
      data-testid={`purchase-item-${index}`}
    >
      {/* Row 1: Cantidad + Costo + Subtotal (cantidad SIEMPRE editable) */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-[120px_140px_1fr]">
        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Cantidad
          </label>
          <Input
            type="number"
            min={isSerialized ? 1 : 0.0001}
            step={isSerialized ? 1 : 0.0001}
            value={value.quantity ?? ''}
            onChange={(e) => onChange({ ...value, quantity: Number(e.target.value) })}
            disabled={disabled}
            placeholder="0"
            className="text-right tabular-nums"
            data-testid={`purchase-item-quantity-`}
          />
          {isSerialized && (
            <p className="text-[10px] text-text-muted">Serializado: solo enteros (1 por IMEI).</p>
          )}
        </div>

        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Costo unit.
          </label>
          <Input
            type="number"
            min={0.0001}
            step={0.0001}
            value={value.unit_cost ?? ''}
            onChange={(e) => onChange({ ...value, unit_cost: Number(e.target.value) })}
            disabled={disabled}
            placeholder="0.00"
            className="text-right tabular-nums"
          />
        </div>

        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Subtotal
          </label>
          <div className="flex h-9 items-center justify-end rounded border border-transparent bg-bg/50 px-3 text-sm font-semibold tabular-nums">
            {subtotal > 0
              ? subtotal.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              : '-'}
          </div>
        </div>
      </div>

      {/* Row 2: Producto + Almacén + delete */}
      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_180px_auto]">
        <div className="space-y-1 min-w-0">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Producto
          </label>
          <ProductAutocomplete
            value={value.product_id}
            onChange={(id, product) =>
              onChange({
                ...value,
                product_id: id,
                product_info: product ?? null,
                serial_units:
                  product?.tracking_type === 'serialized' ? [] : value.serial_units,
              })
            }
          />
          {value.product_info && (
            <div className="flex items-center gap-2 text-xs text-text-muted">
              <Package className="size-3 shrink-0" />
              <span>{value.product_info.unit_of_measure ?? 'unit'}</span>
              {value.product_info.base_price != null && (
                <>
                  <span className="text-text-muted/50">|</span>
                  <span>
                    Base: {Number(value.product_info.base_price).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                  </span>
                </>
              )}
              {value.product_info.tracking_type === 'serialized' && (
                <>
                  <span className="text-text-muted/50">|</span>
                  <span className="font-semibold text-warning">Serializado (IMEI)</span>
                </>
              )}
            </div>
          )}
        </div>

        <div className="space-y-1">
          <label className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
            Almacen <span className="text-danger">*</span>
          </label>
          <Select
            value={value.warehouse_id ? String(value.warehouse_id) : ''}
            onChange={(e) =>
              onChange({ ...value, warehouse_id: e.target.value ? Number(e.target.value) : null })
            }
            disabled={disabled}
            className={cn(!value.warehouse_id && 'border-warning')}
          >
            <option value="">{warehouses.length === 0 ? 'Sin almacenes (crea uno en /inventory/admin)' : 'Almacen...'}</option>
            {warehouses.map((w) => (
              <option key={w.id} value={String(w.id)}>
                {w.code}
              </option>
            ))}
          </Select>
          {!value.warehouse_id && (
            <p className="text-[10px] text-warning">Requerido.</p>
          )}
        </div>

        <div className="flex items-start sm:items-end sm:pb-1">
          {canRemove ? (
            <Button
              type="button"
              size="icon-sm"
              variant="ghost"
              onClick={onRemove}
              disabled={disabled}
              aria-label="Eliminar linea"
            >
              <Trash2 className="size-4 text-danger" />
            </Button>
          ) : (
            <span className="text-xs text-text-muted">Linea 1</span>
          )}
        </div>
      </div>

      {/* Row 3: IMEIs (solo si producto es serializado) */}
      {isSerialized && value.product_id && (
        <div className="mt-3 space-y-1.5 rounded border border-border-strong/50 bg-bg/50 p-2">
          <ImeiListInput
            value={value.serial_units}
            onChange={(serial_units) => onChange({ ...value, serial_units })}
            expectedQuantity={value.quantity || 1}
            disabled={disabled}
          />
        </div>
      )}
    </div>
  );
}
