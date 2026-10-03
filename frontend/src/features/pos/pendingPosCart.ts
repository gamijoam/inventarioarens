import { create } from 'zustand';

const PENDING_CART_KEY = 'pos_pending_cart';

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
}

function loadItems(): PendingPosItem[] {
  try {
    const raw = sessionStorage.getItem(PENDING_CART_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as PendingPosItem[]) : [];
  } catch {
    return [];
  }
}

function saveItems(items: PendingPosItem[]): void {
  try {
    sessionStorage.setItem(PENDING_CART_KEY, JSON.stringify(items));
  } catch {
    // sessionStorage no disponible (privado, SSR, etc.)
  }
}

/**
 * Carrito temporal entre el Centro de Inventario y el POS.
 *
 * En el catalogo de inventario el usuario puede marcar productos con el boton
 * "+" (mini carrito). Al pulsar "Enviar al POS" se persisten aqui y el POS,
 * al montarse, los agrega a su carrito real para cobrar. Se guarda en
 * sessionStorage para sobrevivir la navegacion entre vistas dentro de la
 * misma pestana.
 */
export const usePendingPosCart = create<PendingPosCartState>((set, get) => ({
  items: loadItems(),
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
    saveItems(next);
    set({ items: next });
  },
  remove: (productId) => {
    const next = get().items.filter((entry) => entry.productId !== productId);
    saveItems(next);
    set({ items: next });
  },
  clear: () => {
    saveItems([]);
    set({ items: [] });
  },
}));
