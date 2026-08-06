import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

import backendRuntime from './backend-runtime.cjs';

const {
  buildLaravelEnvironment,
  createRuntimeLease,
  releaseRuntimeStartupLock,
  releaseRuntimeSupervisorLock,
  removeRuntimeLease,
  resolveRuntimeConfig,
  runtimeStartupLockPath,
  runtimeLeaseDirectory,
  runtimeSupervisorLockPath,
  runtimeSupervisorLockIsStale,
  runtimeSupervisorPidPath,
  listLiveRuntimeLeases,
  syncArguments,
  tryAcquireRuntimeStartupLock,
  tryAcquireRuntimeSupervisorLock,
} = backendRuntime;

describe('Local Laravel runtime configuration', () => {
  it('uses a shared writable data root for both desktop clients', () => {
    const config = resolveRuntimeConfig({
      appRoot: '/app',
      resourcesPath: '/resources',
      dataRoot: '/shared/InventarioArens',
      isPackaged: true,
      platform: 'win32',
    });

    expect(config.backendRoot.replace(/\\/g, '/')).toBe('/resources/backend');
    expect(config.phpBinary.replace(/\\/g, '/')).toBe('/resources/runtime/php/php.exe');
    expect(config.databasePath.replace(/\\/g, '/')).toBe(
      '/shared/InventarioArens/inventario.sqlite',
    );
    expect(config.storagePath.replace(/\\/g, '/')).toBe('/shared/InventarioArens/storage');
  });

  it('builds SQLite and CSRF environment values for the renderer origin', () => {
    const config = resolveRuntimeConfig({
      appRoot: '/repo/frontend',
      resourcesPath: '/resources',
      dataRoot: '/shared/InventarioArens',
      isPackaged: false,
      platform: 'linux',
      appKey: 'base64:test-key',
      bootstrapToken: 'bootstrap-token',
    });
    const environment = buildLaravelEnvironment(config, 'http://127.0.0.1:5173');

    expect(environment.DB_CONNECTION).toBe('sqlite');
    expect(environment.DB_DATABASE.replace(/\\/g, '/')).toBe(
      '/shared/InventarioArens/inventario.sqlite',
    );
    expect(environment.LARAVEL_STORAGE_PATH.replace(/\\/g, '/')).toBe(
      '/shared/InventarioArens/storage',
    );
    expect(environment.APP_ALLOWED_ORIGINS_FOR_CSRF).toContain('http://127.0.0.1:5173');
    expect(environment.APP_ALLOWED_ORIGINS_FOR_CSRF).toContain('http://127.0.0.1:8788');
    expect(environment.APP_ALLOWED_ORIGINS_FOR_CSRF).toContain('http://127.0.0.1:8789');
    expect(environment.APP_KEY).toBe('base64:test-key');
    expect(environment.APP_BOOTSTRAP_TOKEN).toBe('bootstrap-token');
  });

  it('only creates sync arguments when a tenant and cloud token are configured', () => {
    expect(syncArguments({})).toBeNull();
    expect(
      syncArguments({
        syncTenant: 'demo-valencia',
        syncToken: 'sync-token',
        syncCloudUrl: 'https://app.example.test/api',
      }),
    ).toEqual([
      'artisan',
      'sync:daemon',
      'demo-valencia',
      '--cloud-url=https://app.example.test/api',
      '--token=sync-token',
      '--node=LOCAL-01',
      '--name=Electron Local',
      '--installation=ELECTRON-LOCAL',
    ]);
  });

  it('serializes concurrent Laravel startup attempts', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-runtime-lock-'));
    const config = { dataRoot };

    expect(runtimeStartupLockPath(config)).toBe(path.join(dataRoot, '.runtime-startup.lock'));
    expect(tryAcquireRuntimeStartupLock(config)).toBe(true);
    expect(tryAcquireRuntimeStartupLock(config)).toBe(false);

    releaseRuntimeStartupLock(config);

    expect(tryAcquireRuntimeStartupLock(config)).toBe(true);
    releaseRuntimeStartupLock(config);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('keeps live client leases and removes stale leases', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-runtime-leases-'));
    const config = { dataRoot };
    const leasePath = createRuntimeLease(config, 'admin');

    expect(runtimeLeaseDirectory(config)).toBe(path.join(dataRoot, 'runtime-leases'));
    expect(runtimeSupervisorPidPath(config)).toBe(path.join(dataRoot, '.runtime-supervisor.pid'));
    expect(listLiveRuntimeLeases(config)).toHaveLength(1);

    const staleLeasePath = path.join(runtimeLeaseDirectory(config), 'stale-999999.lease');
    fs.writeFileSync(staleLeasePath, '{"pid":999999}');
    const staleTime = new Date(Date.now() - 20000);
    fs.utimesSync(staleLeasePath, staleTime, staleTime);
    expect(listLiveRuntimeLeases(config)).toHaveLength(1);
    expect(fs.existsSync(staleLeasePath)).toBe(false);

    removeRuntimeLease(leasePath);
    expect(listLiveRuntimeLeases(config)).toHaveLength(0);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('acquires the supervisor lock when no lock exists', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-supervisor-lock-'));
    const config = { dataRoot };

    expect(tryAcquireRuntimeSupervisorLock(config)).toBe(true);
    releaseRuntimeSupervisorLock(config);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('releases a stale supervisor lock and lets a new supervisor take over', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-supervisor-stale-'));
    const config = { dataRoot };

    fs.writeFileSync(runtimeSupervisorLockPath(config), '999999\n');
    expect(runtimeSupervisorLockIsStale(config)).toBe(true);

    expect(tryAcquireRuntimeSupervisorLock(config)).toBe(true);
    releaseRuntimeSupervisorLock(config);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('does not steal the supervisor lock from a still-alive supervisor', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-supervisor-alive-'));
    const config = { dataRoot };

    fs.writeFileSync(runtimeSupervisorLockPath(config), `${process.pid}\n`);
    expect(runtimeSupervisorLockIsStale(config)).toBe(false);
    expect(tryAcquireRuntimeSupervisorLock(config)).toBe(false);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });
});
