import type { PurchaseItemRowValue } from './components/PurchaseItemRow';

export interface PurchaseFormDirtyInput {
  items: PurchaseItemRowValue[];
  supplierId: number | null;
  documentNumber: string;
  dueDate: string;
}

/**
 * Determina si el formulario de compra tiene datos que el usuario perderia al
 * cerrarlo/cancelarlo. Se usa para pedir confirmacion antes de descartar.
 */
export function isPurchaseFormDirty(input: PurchaseFormDirtyInput): boolean {
  if (input.supplierId != null) return true;
  if (input.documentNumber.trim() !== '') return true;
  if (input.dueDate.trim() !== '') return true;
  if (input.items.length > 1) return true;

  return input.items.some(
    (item) =>
      item.product_id != null ||
      String(item.quantity ?? '') !== '' ||
      String(item.unit_cost ?? '') !== '',
  );
}
