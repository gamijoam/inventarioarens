import { afterEach, describe, expect, it } from 'vitest';

import {
  POS_TOUCH_CLASS,
  applyPosViewport,
  enablePosTouchMode,
  posViewportContent,
} from '../touchSupport';

function createMeta(): HTMLMetaElement {
  const meta = document.createElement('meta');
  meta.setAttribute('name', 'viewport');
  document.head.appendChild(meta);
  return meta;
}

describe('pos touchSupport', () => {
  afterEach(() => {
    document.head.querySelectorAll('meta[name="viewport"]').forEach((meta) => meta.remove());
    document.body.classList.remove(POS_TOUCH_CLASS);
  });

  it('genera un viewport tactil sin zoom para el POS', () => {
    expect(posViewportContent()).toContain('user-scalable=no');
    expect(posViewportContent()).toContain('maximum-scale=1.0');
    expect(posViewportContent()).toContain('width=device-width');
  });

  it('aplica el viewport tactil al meta actual', () => {
    const meta = createMeta();
    const applied = applyPosViewport(document);
    expect(applied).toBe(true);
    expect(meta.getAttribute('content')).toBe(posViewportContent());
  });

  it('no falla si no existe el meta viewport', () => {
    expect(applyPosViewport(document)).toBe(false);
  });

  it('agrega la clase pos-touch-mode al body', () => {
    enablePosTouchMode(document);
    expect(document.body.classList.contains(POS_TOUCH_CLASS)).toBe(true);
  });
});
