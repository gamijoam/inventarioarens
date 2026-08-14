import { describe, expect, it } from 'vitest';

import appConfig from './app-config.cjs';

const {
  getAppConfig,
  localDataDirectory,
  normalizeAppMode,
  rendererDirectory,
  userDataDirectory,
} = appConfig;

describe('Electron app configuration', () => {
  it('normalizes unknown modes to the administrative app', () => {
    expect(normalizeAppMode('unknown')).toBe('admin');
    expect(normalizeAppMode(undefined)).toBe('admin');
    expect(normalizeAppMode('pos')).toBe('pos');
    expect(normalizeAppMode('technician')).toBe('technician');
  });

  it('keeps the exact product names for both installers', () => {
    expect(getAppConfig('admin').productName).toBe('Sistema de Inventario (Administrativo)');
    expect(getAppConfig('pos').productName).toBe('Sistema de Inventario (POS)');
    expect(getAppConfig('technician').productName).toBe('Soporte Técnico');
  });

  it('assigns stable renderer ports to both clients', () => {
    expect(getAppConfig('admin').rendererPort).toBe(8788);
    expect(getAppConfig('pos').rendererPort).toBe(8789);
    expect(getAppConfig('technician').rendererPort).toBe(8790);
  });

  it('selects an isolated renderer directory per app', () => {
    expect(rendererDirectory('/bundle', 'admin').replace(/\\/g, '/')).toBe('/bundle/dist/admin');
    expect(rendererDirectory('/bundle', 'pos').replace(/\\/g, '/')).toBe('/bundle/dist/pos');
    expect(rendererDirectory('/bundle', 'technician').replace(/\\/g, '/')).toBe(
      '/bundle/dist/technician',
    );
  });

  it('selects an isolated Electron user data directory per app', () => {
    expect(userDataDirectory('/home/user/.config', 'admin').replace(/\\/g, '/')).toBe(
      '/home/user/.config/InventarioArens-Administrativo',
    );
    expect(userDataDirectory('/home/user/.config', 'pos').replace(/\\/g, '/')).toBe(
      '/home/user/.config/InventarioArens-POS',
    );
    expect(userDataDirectory('/home/user/.config', 'technician').replace(/\\/g, '/')).toBe(
      '/home/user/.config/InventarioArens-Soporte',
    );
  });

  it('uses the shared ProgramData root when the dedicated backend marker exists', () => {
    const exists = (candidate) =>
      candidate.replace(/\\/g, '/') ===
      'C:/ProgramData/InventarioArens/backend-service.json';

    expect(
      localDataDirectory('C:/Users/test/AppData/Roaming', {
        environment: { ProgramData: 'C:/ProgramData' },
        fileExists: exists,
        platform: 'win32',
      }).replace(/\\/g, '/'),
    ).toBe('C:/ProgramData/InventarioArens');
  });

  it('keeps an explicit data root above the shared ProgramData marker', () => {
    expect(
      localDataDirectory('C:/Users/test/AppData/Roaming', {
        environment: {
          INVENTARIO_DATA_ROOT: 'D:/InventarioData',
          ProgramData: 'C:/ProgramData',
        },
        fileExists: () => true,
        platform: 'win32',
      }).replace(/\\/g, '/'),
    ).toBe('D:/InventarioData');
  });

  it('falls back to the current user data root without a dedicated marker', () => {
    expect(
      localDataDirectory('C:/Users/test/AppData/Roaming', {
        environment: { ProgramData: 'C:/ProgramData' },
        fileExists: () => false,
        platform: 'win32',
      }).replace(/\\/g, '/'),
    ).toBe('C:/Users/test/AppData/Roaming/InventarioArens');
  });
});
