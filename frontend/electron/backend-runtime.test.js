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

  it('uses the production cloud URL when no local override is provided', () => {
    const previousUrl = process.env.INVENTARIO_SYNC_CLOUD_URL;
    delete process.env.INVENTARIO_SYNC_CLOUD_URL;

    try {
      const config = resolveRuntimeConfig({ dataRoot: '/shared/InventarioArens' });

      expect(config.syncCloudUrl).toBe('https://app.miinventariofacil.com/api');
    } finally {
      if (previousUrl === undefined) {
        delete process.env.INVENTARIO_SYNC_CLOUD_URL;
      } else {
        process.env.INVENTARIO_SYNC_CLOUD_URL = previousUrl;
      }
    }
  });

  it('points the Windows PHP runtime at the bundled CA bundle via PHP_INI_SCAN_DIR', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-php-certs-'));
    const phpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-php-dir-'));
    const certPath = path.join(phpDir, 'cacert.pem');
    fs.writeFileSync(certPath, 'fake-ca\n');

    const config = {
      dataRoot,
      phpBinary: path.join(phpDir, 'php.exe'),
      appKey: 'base64:test-key',
      bootstrapToken: 'bootstrap-token',
      databasePath: path.join(dataRoot, 'inventario.sqlite'),
      storagePath: path.join(dataRoot, 'storage'),
      apiUrl: 'http://127.0.0.1:8787',
      syncCloudUrl: '',
    };
    const environment = buildLaravelEnvironment(config, 'http://127.0.0.1:5173');

    const escaped = certPath.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    expect(environment.SSL_CERT_FILE).toBe(certPath);
    expect(environment.CURL_CA_BUNDLE).toBe(certPath);
    expect(environment.PHP_INI_SCAN_DIR).toBe(path.join(dataRoot, 'php-cert-scan'));

    const iniPath = path.join(environment.PHP_INI_SCAN_DIR, 'zz-cacert.ini');
    expect(fs.existsSync(iniPath)).toBe(true);
    const iniContent = fs.readFileSync(iniPath, 'utf8');
    expect(iniContent).toContain(`curl.cainfo = "${escaped}"`);
    expect(iniContent).toContain(`openssl.cafile = "${escaped}"`);

    fs.rmSync(dataRoot, { recursive: true, force: true });
    fs.rmSync(phpDir, { recursive: true, force: true });
  });

  it('keeps the API client local while enabling an explicit LAN bind host', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-runtime-lan-'));
    fs.writeFileSync(
      path.join(dataRoot, 'local-server.json'),
      JSON.stringify({ enabled: true, bind_host: '0.0.0.0', api_port: 8787 }),
    );

    const config = resolveRuntimeConfig({ dataRoot, isPackaged: false, platform: 'linux' });

    expect(config.apiBindHost).toBe('0.0.0.0');
    expect(config.apiHost).toBe('127.0.0.1');
    expect(config.apiUrl).toBe('http://127.0.0.1:8787');

    fs.rmSync(dataRoot, { recursive: true, force: true });
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
