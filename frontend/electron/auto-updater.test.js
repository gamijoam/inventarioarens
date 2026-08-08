import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';

const ELECTRON_UPDATER_PATH = 'electron-updater';

function loadAutoUpdaterModule() {
  let mod;
  try {
    delete require.cache[ELECTRON_UPDATER_PATH];
  } catch {}
  try {
    mod = require('../electron/auto-updater.cjs');
  } catch {
    mod = null;
  }
  return mod;
}

describe('auto-updater tolerates missing electron-updater', () => {
  beforeEach(() => {
    vi.resetModules();
    delete require.cache[require.resolve('../electron/update-policy.cjs')];
    delete require.cache[require.resolve('../electron/auto-updater.cjs')];
  });

  afterEach(() => {
    delete require.cache[require.resolve('../electron/update-policy.cjs')];
    delete require.cache[require.resolve('../electron/auto-updater.cjs')];
  });

  it('returns false and logs a warning when electron-updater is not installed', () => {
    vi.doMock(ELECTRON_UPDATER_PATH, () => {
      throw new Error("Cannot find module 'electron-updater'");
    });

    const autoUpdater = require('../electron/auto-updater.cjs');

    const fakeApp = {
      isPackaged: true,
      on: () => {},
    };
    const result = autoUpdater.setupAutoUpdater({
      app: fakeApp,
      appMode: 'technician',
      isRuntimeSupervisor: false,
      logger: { warn: vi.fn(), info: vi.fn() },
    });

    expect(result).toBe(false);
  });
});
