import { describe, expect, it } from 'vitest';

import linuxSmoke from '../../scripts/smoke-linux-appimage.cjs';

describe('Linux AppImage smoke configuration', () => {
  it('selects the correct artifact and isolated API port per client', () => {
    expect(linuxSmoke.getSmokeConfig('/repo', 'admin')).toEqual({
      appImage: '/repo/frontend/release/admin/Sistema-de-Inventario-Administrativo-0.1.0.AppImage',
      apiPort: 8805,
      mode: 'admin',
    });
    expect(linuxSmoke.getSmokeConfig('/repo', 'pos')).toEqual({
      appImage: '/repo/frontend/release/pos/POS-0.1.0.AppImage',
      apiPort: 8806,
      mode: 'pos',
    });
  });
});
