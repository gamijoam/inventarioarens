import { describe, expect, it } from 'vitest';

import { POS_LAYOUT_CLASS_NAME } from '../PosTerminal';

describe('POS layout', () => {
  it('delegates the viewport to PosShell while keeping the terminal as a flex column', () => {
    expect(POS_LAYOUT_CLASS_NAME).toContain('flex');
    expect(POS_LAYOUT_CLASS_NAME).toContain('flex-col');
    expect(POS_LAYOUT_CLASS_NAME).toContain('min-h-0');
    expect(POS_LAYOUT_CLASS_NAME).toContain('flex-1');
    expect(POS_LAYOUT_CLASS_NAME).toContain('overflow-hidden');
    expect(POS_LAYOUT_CLASS_NAME).not.toContain('h-screen');
  });
});
