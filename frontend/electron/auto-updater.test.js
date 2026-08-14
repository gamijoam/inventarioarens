import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

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

  it('does not overlap update checks while a previous check is pending', async () => {
    const updater = require('../electron/auto-updater.cjs');
    const resolvers = [];
    const fakeAutoUpdater = {
      checkForUpdates: vi.fn(
        () =>
          new Promise((resolve) => {
            resolvers.push(resolve);
          }),
      ),
    };
    const logger = { warn: vi.fn() };
    const scheduler = updater.createUpdateCheckScheduler(fakeAutoUpdater, { logger });

    const firstCheck = scheduler.checkForUpdates();
    const secondCheck = scheduler.checkForUpdates();
    await Promise.resolve();

    expect(fakeAutoUpdater.checkForUpdates).toHaveBeenCalledTimes(1);
    expect(secondCheck).toBe(firstCheck);

    resolvers[0]();
    await firstCheck;

    scheduler.checkForUpdates();
    await Promise.resolve();
    expect(fakeAutoUpdater.checkForUpdates).toHaveBeenCalledTimes(2);
    resolvers[1]();
  });

  it('writes updater diagnostics to a persistent log without breaking console logging', () => {
    const updater = require('../electron/auto-updater.cjs');
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-updater-log-'));
    const logPath = path.join(directory, 'updater.log');
    const logger = { info: vi.fn(), warn: vi.fn(), error: vi.fn() };
    const persistentLogger = updater.createUpdaterLogger(logPath, logger);

    persistentLogger.info('checking channel=pos');
    persistentLogger.warn('check failed: timeout');

    const content = fs.readFileSync(logPath, 'utf8');
    expect(content).toContain('checking channel=pos');
    expect(content).toContain('check failed: timeout');
    expect(logger.info).toHaveBeenCalledWith('checking channel=pos');
    expect(logger.warn).toHaveBeenCalledWith('check failed: timeout');

    fs.rmSync(directory, { recursive: true, force: true });
  });
});
