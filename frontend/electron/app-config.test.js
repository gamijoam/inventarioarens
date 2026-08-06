import { describe, expect, it } from 'vitest';

import appConfig from './app-config.cjs';

const { getAppConfig, normalizeAppMode, rendererDirectory, userDataDirectory } = appConfig;

describe('Electron app configuration', () => {
  it('normalizes unknown modes to the administrative app', () => {
    expect(normalizeAppMode('unknown')).toBe('admin');
    expect(normalizeAppMode(undefined)).toBe('admin');
    expect(normalizeAppMode('pos')).toBe('pos');
  });

  it('keeps the exact product names for both installers', () => {
    expect(getAppConfig('admin').productName).toBe('Sistema de Inventario (Administrativo)');
    expect(getAppConfig('pos').productName).toBe('Sistema de Inventario (POS)');
  });

  it('assigns stable renderer ports to both clients', () => {
    expect(getAppConfig('admin').rendererPort).toBe(8788);
    expect(getAppConfig('pos').rendererPort).toBe(8789);
  });

  it('selects an isolated renderer directory per app', () => {
    expect(rendererDirectory('/bundle', 'admin').replace(/\\/g, '/')).toBe('/bundle/dist/admin');
    expect(rendererDirectory('/bundle', 'pos').replace(/\\/g, '/')).toBe('/bundle/dist/pos');
  });

  it('selects an isolated Electron user data directory per app', () => {
    expect(userDataDirectory('/home/user/.config', 'admin').replace(/\\/g, '/')).toBe(
      '/home/user/.config/InventarioArens-Administrativo',
    );
    expect(userDataDirectory('/home/user/.config', 'pos').replace(/\\/g, '/')).toBe(
      '/home/user/.config/InventarioArens-POS',
    );
  });
});
