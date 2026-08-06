import { describe, expect, it } from 'vitest';

import linuxSmoke from '../../scripts/smoke-linux-appimage.cjs';

describe('Linux AppImage smoke configuration', () => {
  it('selects the correct artifact and isolated API port per client', () => {
    const configAdmin = linuxSmoke.getSmokeConfig('/repo', 'admin');
    configAdmin.appImage = configAdmin.appImage.replace(/\\/g, '/');
    expect(configAdmin).toEqual({
      appImage: '/repo/frontend/release/admin/Sistema-de-Inventario-Administrativo-0.1.0.AppImage',
      apiPort: 8805,
      mode: 'admin',
    });

    const configPos = linuxSmoke.getSmokeConfig('/repo', 'pos');
    configPos.appImage = configPos.appImage.replace(/\\/g, '/');
    expect(configPos).toEqual({
      appImage: '/repo/frontend/release/pos/POS-0.1.0.AppImage',
      apiPort: 8806,
      mode: 'pos',
    });
  });
});
