import { beforeEach, describe, expect, it } from 'vitest';

import { usePendingPosCart } from './pendingPosCart';

describe('pendingPosCart', () => {
  beforeEach(() => {
    usePendingPosCart.getState().clear();
    sessionStorage.clear();
    usePendingPosCart.setState({ items: [] });
  });

  it('agrega un producto nuevo', () => {
    usePendingPosCart.getState().add({ productId: 1, name: 'Caucho', sku: 'C1' });
    expect(usePendingPosCart.getState().items).toEqual([
      { productId: 1, name: 'Caucho', sku: 'C1', quantity: 1 },
    ]);
  });

  it('acumula cantidad al repetir el mismo producto', () => {
    const { add } = usePendingPosCart.getState();
    add({ productId: 1, name: 'Caucho', sku: 'C1' });
    add({ productId: 1, name: 'Caucho', sku: 'C1', quantity: 2 });
    expect(usePendingPosCart.getState().items).toHaveLength(1);
    expect(usePendingPosCart.getState().items[0]?.quantity).toBe(3);
  });

  it('elimina y limpia', () => {
    const { add, remove } = usePendingPosCart.getState();
    add({ productId: 1, name: 'A', sku: null });
    add({ productId: 2, name: 'B', sku: null });
    remove(1);
    expect(usePendingPosCart.getState().items.map((i) => i.productId)).toEqual([2]);
    usePendingPosCart.getState().clear();
    expect(usePendingPosCart.getState().items).toEqual([]);
  });

  it('persiste en sessionStorage', () => {
    usePendingPosCart.getState().add({ productId: 9, name: 'X', sku: 'X9' });
    const raw = sessionStorage.getItem('pos_pending_cart');
    expect(raw).toBeTruthy();
    expect(JSON.parse(raw as string)[0].productId).toBe(9);
  });

  it('sendToPos mueve los items al handoff y vacia el mini carrito', () => {
    const { add, sendToPos } = usePendingPosCart.getState();
    add({ productId: 3, name: 'Caucho', sku: 'C3', quantity: 2 });
    add({ productId: 4, name: 'Bateria', sku: 'B4' });

    sendToPos();

    expect(usePendingPosCart.getState().items).toEqual([]);
    const handoff = JSON.parse(sessionStorage.getItem('pos_handoff_cart') as string);
    expect(handoff).toHaveLength(2);
    expect(handoff[0].productId).toBe(3);
  });
});
