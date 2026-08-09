import { describe, expect, it } from 'vitest';

import {
  countPendingOrders,
  hasPendingOrders,
  newPendingOrderIds,
} from '../pendingBadge';

describe('pendingBadge helpers', () => {
  it('detecta ids nuevos que no estaban en el snapshot anterior', () => {
    expect(newPendingOrderIds([1, 2], [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }])).toEqual([
      3, 4,
    ]);
  });

  it('no reporta nuevas cuando no hay cambios', () => {
    expect(newPendingOrderIds([1, 2], [{ id: 1 }, { id: 2 }])).toEqual([]);
  });

  it('reporta todas las ordenes como nuevas cuando el snapshot anterior estaba vacio', () => {
    expect(newPendingOrderIds([], [{ id: 7 }, { id: 8 }])).toEqual([7, 8]);
  });

  it('cuenta las ordenes pendientes', () => {
    expect(countPendingOrders([])).toBe(0);
    expect(countPendingOrders([{ id: 1 }, { id: 2 }, { id: 3 }])).toBe(3);
  });

  it('indica si hay ordenes pendientes', () => {
    expect(hasPendingOrders([])).toBe(false);
    expect(hasPendingOrders([{ id: 1 }])).toBe(true);
  });
});
