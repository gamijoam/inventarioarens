/**
 * PurchaseItemRow: una fila editable de la tabla de items en
 * PurchaseFormDialog. Maneja:
 * - Autocomplete de producto (single-select con typeahead).
 * - Select de almacen.
 * - Inputs numericos para cantidad y unit_cost.
 * - Si el producto es serializado, captura de N IMEIs/seriales.
 * - Boton papelera para eliminar la fila.
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
}

export function PurchaseItemRow({
  value,
  onChange,
  onRemove,
  disabled,
  canRemove,
}: PurchaseItemRowProps) {
  const { data: warehouses = [] } = useWarehouses();
  const subtotal = useMemo(
    () => (Number.isFinite(value.quantity) ? value.quantity * value.unit_cost : 0),
    [value.quantity, value.unit_cost],
  );
  const isSerialized = value.product_info?.tracking_type === 'serialized';
  const isIntQuantity = isSerialized;

  return (
    <tr className="border-b border-border align-top">
      {/* Almacen */}
      <td className="px-2 py-2">
        <Select
          value={value.warehouse_id ? String(value.warehouse_id) : ''}
          onChange={(e) => onChange({ ...value, warehouse_id: e.target.value ? Number(e.target.value) : null })}
          disabled={disabled}
          className="w-32"
        >
          <option value="">Almacen...</option>
          {warehouses.map((w) => (
            <option key={w.id} value={String(w.id)}>
              {w.code}
            </option>
          ))}
        </Select>
      </td>

      {/* Producto */}
      <td className="px-2 py-2 min-w-[260px]">
        <ProductAutocomplete
          value={value.product_id}
          onChange={(id, product) => onChange({
            ...value,
            product_id: id,
            product_info: product ?? null,
            // Si cambia el producto y era serializado, limpiamos seriales para re-validar.
            serial_units: product?.tracking_type === 'serialized' ? value.serial_units : [],
          })}
        />
        {value.product_info && (
          <div className="mt-1 flex items-center gap-1 text-xs text-text-muted">
            <Package className="size-3" />
            {value.product_info.unit_of_measure ?? 'unit'}
            {value.product_info.base_price != null && (
              <span className="ml-1">
                Base: {Number(value.product_info.base_price).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
              </span>
            )}
          </div>
        )}
      </td>

      {/* Cantidad */}
      <td className="px-2 py-2">
        <Input
          type="number"
          min={isIntQuantity ? 1 : 0.0001}
          step={isIntQuantity ? 1 : 0.0001}
          value={value.quantity ?? ''}
          onChange={(e) => onChange({ ...value, quantity: Number(e.target.value) })}
          disabled={disabled || isSerialized}
          placeholder="0"
          className={cn('w-24 text-right tabular-nums', isSerialized && 'bg-bg')}
        />
        {isSerialized && (
          <p className="mt-1 text-[10px] uppercase tracking-wide text-text-muted">Por IMEI</p>
        )}
      </td>

      {/* Costo unitario */}
      <td className="px-2 py-2">
        <Input
          type="number"
          min={0.0001}
          step={0.0001}
          value={value.unit_cost || ''}
          onChange={(e) => onChange({ ...value, unit_cost: Number(e.target.value) })}
          disabled={disabled}
          placeholder="0.00"
          className="w-28 text-right tabular-nums"
        />
      </td>

      {/* Subtotal */}
      <td className="px-2 py-2 text-right tabular-nums">
        {subtotal > 0 ? subtotal.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '-'}
      </td>

      {/* IMEIs (si aplica) */}
      <td className="px-2 py-2 min-w-[300px]">
        {isSerialized && value.product_id && (
          <ImeiListInput
            value={value.serial_units}
            onChange={(serial_units) => onChange({ ...value, serial_units })}
            expectedQuantity={value.quantity || 1}
            disabled={disabled}
          />
        )}
      </td>

      {/* Acciones */}
      <td className="px-2 py-2 text-right">
        {canRemove && (
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
        )}
      </td>
    </tr>
  );
}
