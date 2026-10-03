import { create } from 'zustand';

const PENDING_CART_KEY = 'pos_pending_cart';
const HANDOFF_KEY = 'pos_handoff_cart';

export interface PendingPosItem {
  productId: number;
  name: string;
  sku: string | null;
  quantity: number;
  /** Lista de precio elegida (null = precio base). */
  price_list_id: number | null;
  price_list_name: string | null;
  /** Precio unitario en USD de la lista elegida. */
  price: number | null;
}

/** Clave unica por producto + lista de precio. */
export function pendingItemKey(item: Pick<PendingPosItem, 'productId' | 'price_list_id'>): string {
  return `${item.productId}_${item.price_list_id ?? 'base'}`;
}

interface PendingPosCartState {
  items: PendingPosItem[];
  add: (item: {
    productId: number;
    name: string;
    sku: string | null;
    quantity?: number;
    price_list_id?: number | null;
    price_list_name?: string | null;
    price?: number | null;
  }) => void;
  remove: (key: string) => void;
  clear: () => void;
  /** Mueve los items al handoff (para el POS) y vacia el mini carrito. */
  sendToPos: () => void;
}

function readItems(key: string): PendingPosItem[] {
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as PendingPosItem[]) : [];
  } catch {
    return [];
  }
}

function writeItems(key: string, items: PendingPosItem[]): void {
  try {
    sessionStorage.setItem(key, JSON.stringify(items));
  } catch {
    // sessionStorage no disponible (privado, SSR, etc.)
  }
}

function removeKey(key: string): void {
  try {
    sessionStorage.removeItem(key);
  } catch {
    // ignore
  }
}

/** Items que el inventario dejo listos para que el POS los cargue. */
export function readHandoffItems(): PendingPosItem[] {
  return readItems(HANDOFF_KEY);
}

export function clearHandoffItems(): void {
  removeKey(HANDOFF_KEY);
}

/**
 * Carrito temporal entre el Centro de Inventario y el POS.
 *
 * En el catalogo de inventario el usuario marca productos con el boton "+"
 * (por cada lista de precio). Al pulsar "Enviar al POS" los items se copian a
 * un handoff en sessionStorage y el mini carrito se vacia; el POS lee el
 * handoff y los agrega a su carrito real con la lista de precio elegida.
 */
export const usePendingPosCart = create<PendingPosCartState>((set, get) => ({
  items: readItems(PENDING_CART_KEY),
  add: (item) => {
    const quantity = Math.max(1, Math.floor(item.quantity ?? 1));
    const price_list_id = item.price_list_id ?? null;
    const key = pendingItemKey({ productId: item.productId, price_list_id });
    const current = get().items;
    const existing = current.find(
      (entry) => pendingItemKey(entry) === key,
    );
    const next = existing
      ? current.map((entry) =>
          pendingItemKey(entry) === key
            ? { ...entry, quantity: entry.quantity + quantity }
            : entry,
        )
      : [
          ...current,
          {
            productId: item.productId,
            name: item.name,
            sku: item.sku,
            quantity,
            price_list_id,
            price_list_name: item.price_list_name ?? null,
            price: item.price ?? null,
          },
        ];
    writeItems(PENDING_CART_KEY, next);
    set({ items: next });
  },
  remove: (key) => {
    const next = get().items.filter((entry) => pendingItemKey(entry) !== key);
    writeItems(PENDING_CART_KEY, next);
    set({ items: next });
  },
  clear: () => {
    writeItems(PENDING_CART_KEY, []);
    set({ items: [] });
  },
  sendToPos: () => {
    const current = get().items;
    if (current.length === 0) return;
    writeItems(HANDOFF_KEY, current);
    writeItems(PENDING_CART_KEY, []);
    set({ items: [] });
  },
}));
