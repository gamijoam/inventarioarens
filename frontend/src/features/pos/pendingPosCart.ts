import { create } from 'zustand';

const PENDING_CART_KEY = 'pos_pending_cart';
const HANDOFF_KEY = 'pos_handoff_cart';

export interface PendingPosItem {
  productId: number;
  name: string;
  sku: string | null;
  quantity: number;
}

interface PendingPosCartState {
  items: PendingPosItem[];
  add: (item: { productId: number; name: string; sku: string | null; quantity?: number }) => void;
  remove: (productId: number) => void;
  clear: () => void;
  /** Mueve los items al handoff (para el POS) y vacia el mini carrito. */
  sendToPos: () => void;
}

function readItems(key: string): PendingPosItem[] {
  try {
    const raw = sessionStorage.getItem(key);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
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
 * (mini carrito). Al pulsar "Enviar al POS" los items se copian a un handoff
 * en sessionStorage y el mini carrito se vacia; el POS lee el handoff y los
 * agrega a su carrito real. Se usa sessionStorage para sobrevivir la
 * navegacion entre vistas dentro de la misma pestana (y para no depender de
 * que el modulo Zustand sea exactamente la misma instancia en ambas vistas).
 */
export const usePendingPosCart = create<PendingPosCartState>((set, get) => ({
  items: readItems(PENDING_CART_KEY),
  add: (item) => {
    const quantity = Math.max(1, Math.floor(item.quantity ?? 1));
    const current = get().items;
    const existing = current.find((entry) => entry.productId === item.productId);
    const next = existing
      ? current.map((entry) =>
          entry.productId === item.productId
            ? { ...entry, quantity: entry.quantity + quantity }
            : entry,
        )
      : [...current, { productId: item.productId, name: item.name, sku: item.sku, quantity }];
    writeItems(PENDING_CART_KEY, next);
    set({ items: next });
  },
  remove: (productId) => {
    const next = get().items.filter((entry) => entry.productId !== productId);
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
