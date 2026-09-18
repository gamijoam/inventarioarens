import { describe, expect, it } from 'vitest';
import { createRequire } from 'node:module';

import linuxSmoke from '../../scripts/smoke-linux-appimage.cjs';

const require = createRequire(import.meta.url);
const { version } = require('../package.json');

describe('Linux AppImage smoke configuration', () => {
  it('selects the correct artifact and isolated API port per client', () => {
    const configAdmin = linuxSmoke.getSmokeConfig('/repo', 'admin', version);
    configAdmin.appImage = configAdmin.appImage.replace(/\\/g, '/');
    expect(configAdmin).toEqual({
      appImage: `/repo/frontend/release/admin/Sistema-de-Inventario-Administrativo-${version}.AppImage`,
      apiPort: 8805,
      mode: 'admin',
    });

    const configPos = linuxSmoke.getSmokeConfig('/repo', 'pos', version);
    configPos.appImage = configPos.appImage.replace(/\\/g, '/');
    expect(configPos).toEqual({
      appImage: `/repo/frontend/release/pos/Sistema-de-Inventario-POS-${version}.AppImage`,
      apiPort: 8806,
      mode: 'pos',
    });

    const configTechnician = linuxSmoke.getSmokeConfig('/repo', 'technician', version);
    configTechnician.appImage = configTechnician.appImage.replace(/\\/g, '/');
    expect(configTechnician).toEqual({
      appImage: `/repo/frontend/release/technician/Soporte-Tecnico-Inventario-${version}.AppImage`,
      apiPort: 8807,
      mode: 'technician',
    });

    const configBalanzaProPos = linuxSmoke.getSmokeConfig('/repo', 'balanzapro-pos', version);
    configBalanzaProPos.appImage = configBalanzaProPos.appImage.replace(/\\/g, '/');
    expect(configBalanzaProPos).toEqual({
      appImage: `/repo/frontend/release/balanzapro-pos/BalanzaPro-POS-${version}.AppImage`,
      apiPort: 8808,
      mode: 'pos',
    });

    const configBalanzaProAdmin = linuxSmoke.getSmokeConfig('/repo', 'balanzapro-admin', version);
    configBalanzaProAdmin.appImage = configBalanzaProAdmin.appImage.replace(/\\/g, '/');
    expect(configBalanzaProAdmin).toEqual({
      appImage: `/repo/frontend/release/balanzapro-admin/BalanzaPro-Administrativo-${version}.AppImage`,
      apiPort: 8809,
      mode: 'admin',
    });

    const configBalanzaProTech = linuxSmoke.getSmokeConfig('/repo', 'balanzapro-technician', version);
    configBalanzaProTech.appImage = configBalanzaProTech.appImage.replace(/\\/g, '/');
    expect(configBalanzaProTech).toEqual({
      appImage: `/repo/frontend/release/balanzapro-technician/BalanzaPro-Soporte-Tecnico-${version}.AppImage`,
      apiPort: 8810,
      mode: 'technician',
    });
  });
});
