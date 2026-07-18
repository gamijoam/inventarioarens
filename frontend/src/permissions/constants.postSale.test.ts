import { describe, expect, it } from 'vitest';

import { PERMISSIONS } from './constants';

describe('post-sale permissions', () => {
  it('uses official backend permission names for returns and warranties', () => {
    expect(PERMISSIONS.SALES_RETURNS_VIEW).toBe('sales_returns.view');
    expect(PERMISSIONS.SALES_RETURNS_CREATE).toBe('sales_returns.create');
    expect(PERMISSIONS.WARRANTIES_VIEW).toBe('warranties.view');
    expect(PERMISSIONS.WARRANTIES_CREATE).toBe('warranties.create');
    expect(PERMISSIONS.WARRANTIES_REVIEW).toBe('warranties.review');
    expect(PERMISSIONS.WARRANTIES_RESOLVE).toBe('warranties.resolve');
    expect(PERMISSIONS.WARRANTIES_DELIVER).toBe('warranties.deliver');
  });
});
