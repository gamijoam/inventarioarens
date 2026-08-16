import { describe, expect, it } from 'vitest';

import { PERMISSIONS } from './constants';

describe('promotion permissions', () => {
  it('keeps request and cashier validation as separate capabilities', () => {
    expect(PERMISSIONS.POS_PROMOTIONS_REQUEST).toBe('pos.promotions.request');
    expect(PERMISSIONS.POS_PROMOTIONS_VALIDATE).toBe('pos.promotions.validate');
    expect(PERMISSIONS.POS_PROMOTIONS_REQUEST).not.toBe(PERMISSIONS.POS_PROMOTIONS_VALIDATE);
  });
});
