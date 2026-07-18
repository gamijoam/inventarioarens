import { describe, expect, it } from 'vitest';

import { PERMISSIONS } from './constants';

describe('printing permissions', () => {
  it('uses official backend permission names', () => {
    expect(PERMISSIONS.PRINTING_VIEW).toBe('printing.view');
    expect(PERMISSIONS.PRINTING_MANAGE).toBe('printing.manage');
    expect(PERMISSIONS.PRINTING_PRINT).toBe('printing.print');
    expect(PERMISSIONS.PRINTING_REPRINT).toBe('printing.reprint');
    expect(PERMISSIONS.PRINTING_DIGITAL).toBe('printing.digital');
  });
});
