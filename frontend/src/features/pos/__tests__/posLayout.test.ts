import { describe, expect, it } from 'vitest';

import { POS_LAYOUT_CLASS_NAME } from '../PosTerminal';

describe('POS layout', () => {
  it('keeps the viewport as a flex column so the payment footer stays visible', () => {
    expect(POS_LAYOUT_CLASS_NAME).toContain('flex');
    expect(POS_LAYOUT_CLASS_NAME).toContain('flex-col');
    expect(POS_LAYOUT_CLASS_NAME).toContain('h-screen');
    expect(POS_LAYOUT_CLASS_NAME).toContain('overflow-hidden');
  });
});
