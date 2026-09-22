import type { PurchaseItemRowValue } from './components/PurchaseItemRow';

/**
 * Borrador persistente del formulario de compra.
 *
 * Se guarda en localStorage (por tenant) para que no se pierda una factura en
 * curso si la sesion se cierra, el cliente se reinicia o el usuario sale por
 * error. NO se borra al cerrar sesion.
 */
export interface PurchaseDraft {
  version: 1;
  savedAt: string;
  supplierId: number | null;
  documentNumber: string;
  issuedAt: string;
  dueDate: string;
  currency: 'USD' | 'VES';
  rateTypeId: number | null;
  defaultWarehouseId: number | null;
  items: PurchaseItemRowValue[];
}

export type PurchaseDraftInput = Omit<PurchaseDraft, 'version' | 'savedAt'>;

const KEY_PREFIX = 'inventory_purchase_draft';

function keyFor(tenantId: number | null | undefined): string {
  return `${KEY_PREFIX}_${tenantId ?? 'none'}`;
}

function storage(): Storage | null {
  return typeof window === 'undefined' ? null : window.localStorage;
}

export function savePurchaseDraft(
  tenantId: number | null | undefined,
  input: PurchaseDraftInput,
): void {
  const store = storage();
  if (!store) return;

  try {
    const payload: PurchaseDraft = {
      version: 1,
      savedAt: new Date().toISOString(),
      ...input,
    };
    store.setItem(keyFor(tenantId), JSON.stringify(payload));
  } catch {
    // localStorage puede no estar disponible (modo privado/cuota).
  }
}

export function loadPurchaseDraft(tenantId: number | null | undefined): PurchaseDraft | null {
  const store = storage();
  if (!store) return null;

  try {
    const raw = store.getItem(keyFor(tenantId));
    if (!raw) return null;

    const parsed = JSON.parse(raw) as PurchaseDraft | null;
    if (parsed?.version !== 1 || !Array.isArray(parsed?.items)) return null;

    return parsed;
  } catch {
    return null;
  }
}

export function clearPurchaseDraft(tenantId: number | null | undefined): void {
  const store = storage();
  if (!store) return;

  try {
    store.removeItem(keyFor(tenantId));
  } catch {
    // ignore
  }
}
