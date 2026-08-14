import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

import backendRuntime from './backend-runtime.cjs';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');

const {
  backendVersionPath,
  buildLaravelEnvironment,
  createRuntimeLease,
  isBackendOutdated,
  readBackendVersion,
  dedicatedServiceConfigPath,
  readDedicatedServiceSettings,
  releaseRuntimeStartupLock,
  releaseRuntimeSupervisorLock,
  removeRuntimeLease,
  resolveRuntimeConfig,
  runRepairIfPossible,
  runtimeStartupLockPath,
  runtimeLeaseDirectory,
  runtimeSupervisorLockPath,
  runtimeSupervisorLockIsStale,
  runtimeSupervisorPidPath,
  startDedicatedServices,
  listLiveRuntimeLeases,
  printerArguments,
  readSyncConfig,
  syncArguments,
  syncDaemons,
  tryAcquireRuntimeStartupLock,
  tryAcquireRuntimeSupervisorLock,
  writeBackendVersion,
} = backendRuntime;

describe('Local Laravel runtime configuration', () => {
  it('ships the dedicated Windows service installer with both services and migration wiring', () => {
    const installerPath = path.join(repositoryRoot, 'scripts', 'install-backend-service.ps1');
    const installer = fs.readFileSync(installerPath, 'utf8');

    expect(installer).toContain("$BackendService = 'InventarioArensBackend'");
    expect(installer).toContain("$PrinterService = 'InventarioArensPrinter'");
    expect(installer).toContain('local:install-sqlite');
    expect(installer).toContain('backend-service.json');
    expect(installer).toContain('La base de datos y los tokens existentes se conservaron');
  });

  it('preserves an existing Laravel app key prefix during service migration', () => {
    const installerPath = path.join(repositoryRoot, 'scripts', 'install-backend-service.ps1');
    const installer = fs.readFileSync(installerPath, 'utf8');

    expect(installer).toContain('function Read-AppKey');
    expect(installer).toContain("if ($value.StartsWith('base64:'))");
    expect(installer).toContain('$appKey = Read-AppKey');
  });

  it('self-elevates the service installer and persists actionable failure diagnostics', () => {
    const installerPath = path.join(repositoryRoot, 'scripts', 'install-backend-service.ps1');
    const installer = fs.readFileSync(installerPath, 'utf8');

    expect(installer).toContain('Start-Process');
    expect(installer).toContain('-Verb RunAs');
    expect(installer).toContain('$PSBoundParameters');
    expect(installer).toContain('service-install.log');
    expect(installer).toContain('catch');
    expect(installer).toContain('exit 1');
  });

  it('waits for Windows to finish deleting a previous service before recreating it', () => {
    const installerPath = path.join(repositoryRoot, 'scripts', 'install-backend-service.ps1');
    const installer = fs.readFileSync(installerPath, 'utf8');

    expect(installer).toContain('function Wait-ServiceRemoved');
    expect(installer).toContain('Wait-ServiceRemoved $Name');
    expect(installer).toContain('2>&1');
  });

  it('does not remove shared services from an individual client uninstaller', () => {
    const nsisPath = path.join(
      repositoryRoot,
      'frontend',
      'build',
      'nsis',
      'separate-install-dir.nsh',
    );
    const nsis = fs.readFileSync(nsisPath, 'utf8');

    expect(nsis).not.toContain('!macro customUnInstall');
    expect(nsis).not.toContain(' -Uninstall');
    expect(nsis).toContain('Pop $0');
    expect(nsis).toContain('Abort');
    expect(nsis).toContain('service-install.log');
  });

  it('starts both dedicated services even when the backend is already healthy', async () => {
    const calls = [];
    const fakeExecFile = (file, args, options, callback) => {
      calls.push({ file, args, options });
      callback(null, 'SERVICE_RUNNING', '');
    };
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-service-healthy-'));
    const runtime = backendRuntime.createRuntimeSupervisor({
      config: {
        apiUrl: 'http://127.0.0.1:8787',
        backendRoot: 'C:/ProgramData/InventarioArens/service/backend',
        dataRoot,
        platform: 'win32',
        serviceMode: true,
        backendServiceName: 'InventarioArensBackend',
        printerServiceName: 'InventarioArensPrinter',
      },
      execFile: fakeExecFile,
      requestHealth: async () => true,
      waitForRuntimeLeases: async () => {},
      spawnProcess: () => {
        throw new Error('No debe levantar PHP desde Electron en modo servicio');
      },
    });

    await runtime.run();

    expect(calls.map(({ args }) => args)).toEqual([
      ['start', 'InventarioArensBackend'],
      ['start', 'InventarioArensPrinter'],
    ]);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('detects a dedicated Windows service from the shared data root', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-service-config-'));
    const servicePath = dedicatedServiceConfigPath({ dataRoot });
    fs.writeFileSync(
      servicePath,
      JSON.stringify({
        enabled: true,
        backend_root: 'C:/ProgramData/InventarioArens/service/backend',
        php_binary: 'C:/ProgramData/InventarioArens/service/runtime/php/php.exe',
        backend_service: 'InventarioArensBackend',
        printer_service: 'InventarioArensPrinter',
      }),
    );

    const settings = readDedicatedServiceSettings(dataRoot);
    const config = resolveRuntimeConfig({ dataRoot, platform: 'win32', isPackaged: true });

    expect(settings).toMatchObject({ enabled: true });
    expect(config.serviceMode).toBe(true);
    expect(config.backendRoot.replace(/\\/g, '/')).toBe(
      'C:/ProgramData/InventarioArens/service/backend',
    );
    expect(config.phpBinary.replace(/\\/g, '/')).toBe(
      'C:/ProgramData/InventarioArens/service/runtime/php/php.exe',
    );
    expect(config.backendServiceName).toBe('InventarioArensBackend');
    expect(config.printerServiceName).toBe('InventarioArensPrinter');

    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('starts both dedicated services without spawning Laravel', async () => {
    const calls = [];
    const fakeExecFile = (file, args, options, callback) => {
      calls.push({ file, args, options });
      callback(null, 'START_PENDING', '');
    };

    await startDedicatedServices(
      {
        platform: 'win32',
        serviceMode: true,
        backendServiceName: 'InventarioArensBackend',
        printerServiceName: 'InventarioArensPrinter',
      },
      fakeExecFile,
    );

    expect(calls).toHaveLength(2);
    expect(calls[0]).toMatchObject({
      file: 'sc.exe',
      args: ['start', 'InventarioArensBackend'],
    });
    expect(calls[1].args).toEqual(['start', 'InventarioArensPrinter']);
  });

  it('does not spawn Laravel when the dedicated services are already healthy', async () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-service-runtime-'));
    const calls = [];
    const serviceCalls = [];
    const runtime = backendRuntime.createRuntimeSupervisor({
      config: {
        apiUrl: 'http://127.0.0.1:8787',
        backendRoot: path.join(dataRoot, 'backend'),
        dataRoot,
        platform: 'win32',
        serviceMode: true,
        backendServiceName: 'InventarioArensBackend',
        printerServiceName: 'InventarioArensPrinter',
      },
      spawnProcess: (...args) => calls.push(args),
      execFile: (file, args, options, callback) => {
        serviceCalls.push({ file, args, options });
        callback(null, 'SERVICE_RUNNING', '');
      },
      requestHealth: async () => true,
      waitForRuntimeLeases: async () => {},
    });

    await runtime.run();

    expect(calls).toHaveLength(0);
    expect(serviceCalls).toHaveLength(2);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

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

  it.skipIf(process.platform !== 'win32')(
    'points the Windows PHP runtime at the bundled CA bundle via PHP_INI_SCAN_DIR',
    () => {
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
    },
  );

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

  it('builds printer agent arguments on the default 17777 port', () => {
    expect(printerArguments({})).toEqual([
      'artisan',
      'printer:serve',
      '--port=17777',
      '--bind=127.0.0.1',
    ]);
  });

  it('respects a custom printer port when configured', () => {
    expect(printerArguments({ printerPort: 17778 })).toEqual([
      'artisan',
      'printer:serve',
      '--port=17778',
      '--bind=127.0.0.1',
    ]);
  });

  it('repairs windows tasks even when a compatible backend is already running', async () => {
    const calls = [];
    const fakeSpawn = (bin, args) => {
      calls.push(args);
      const child = new (require('node:events').EventEmitter)();
      child.killed = false;
      child.kill = () => {};
      child.stdout = { on: () => {} };
      child.stderr = { on: () => {} };
      process.nextTick(() => child.emit('close', 0));
      return child;
    };

    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-repair-'));
    const config = resolveRuntimeConfig({
      dataRoot,
      backendRoot: path.join(dataRoot, 'backend'),
      isPackaged: false,
      platform: 'linux',
    });
    config.phpBinary = '/usr/bin/php';

    const runtime = backendRuntime;
    // Crea el artisan del backend para que runRepairIfPossible lo detecte.
    fs.mkdirSync(config.backendRoot, { recursive: true });
    fs.writeFileSync(path.join(config.backendRoot, 'artisan'), '#!/usr/bin/env php\n');

    await runtime.runRepairIfPossible(config, fakeSpawn);

    const repairCall = calls.find(
      (args) => args.includes('local:repair-tasks') && args.includes('--printer'),
    );
    expect(repairCall).toBeDefined();
    expect(repairCall).toContain('local:repair-tasks');

    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('skips repair when the backend artisan is missing', async () => {
    const calls = [];
    const fakeSpawn = (bin, args) => {
      calls.push(args);
      const child = new (require('node:events').EventEmitter)();
      child.killed = false;
      child.kill = () => {};
      child.stdout = { on: () => {} };
      child.stderr = { on: () => {} };
      process.nextTick(() => child.emit('close', 0));
      return child;
    };

    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-repair-missing-'));
    const config = resolveRuntimeConfig({
      dataRoot,
      backendRoot: path.join(dataRoot, 'no-backend'),
      isPackaged: false,
      platform: 'linux',
    });

    await backendRuntime.runRepairIfPossible(config, fakeSpawn);

    expect(calls.length).toBe(0);
    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('reads per-tenant daemon arguments from the sync config file', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-sync-config-'));
    const configPath = path.join(dataRoot, 'storage', 'app', 'sync-worker', 'sync-config.json');
    fs.mkdirSync(path.dirname(configPath), { recursive: true });
    fs.writeFileSync(
      configPath,
      JSON.stringify({
        version: 2,
        installation_code: 'INST-01',
        cloud_url: 'https://app.example.test/api',
        tenants: {
          'oscar-cell': {
            cloud_url: 'https://app.example.test/api',
            token: 'token-a',
            node_code: 'LOCAL-01',
            node_name: 'Equipo local',
            installation_code: 'INST-01',
            interval: 15,
            limit: 100,
          },
          'oscarcell-yaracall': {
            cloud_url: 'https://app.example.test/api',
            token: 'token-b',
            node_code: 'LOCAL-01',
            node_name: 'Equipo local',
            installation_code: 'INST-01',
            interval: 15,
            limit: 100,
          },
        },
      }),
    );

    const config = resolveRuntimeConfig({ dataRoot, isPackaged: false, platform: 'linux' });
    const daemons = readSyncConfig(config);

    expect(daemons).toHaveLength(2);
    expect(daemons[0]).toMatchObject({ slug: 'oscar-cell', token: 'token-a' });
    expect(daemons[1]).toMatchObject({ slug: 'oscarcell-yaracall', token: 'token-b' });
    expect(syncDaemons(config)).toEqual([
      expect.arrayContaining(['artisan', 'sync:daemon', 'oscar-cell']),
      expect.arrayContaining(['artisan', 'sync:daemon', 'oscarcell-yaracall']),
    ]);

    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('returns no daemons when the sync config is missing or empty', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-sync-empty-'));
    const config = resolveRuntimeConfig({ dataRoot, isPackaged: false, platform: 'linux' });

    expect(readSyncConfig(config)).toEqual([]);
    expect(syncDaemons(config)).toEqual([]);

    fs.rmSync(dataRoot, { recursive: true, force: true });
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

  it('persists and reads the backend version in the shared data root', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-backend-version-'));
    const config = { dataRoot };

    expect(backendVersionPath(config)).toBe(path.join(dataRoot, 'backend.version'));
    expect(readBackendVersion(config)).toBe('');

    writeBackendVersion(config, '0.2.49');
    expect(readBackendVersion(config)).toBe('0.2.49');

    fs.rmSync(dataRoot, { recursive: true, force: true });
  });

  it('detects an outdated running backend when own version is newer', () => {
    expect(isBackendOutdated('0.2.48', '0.2.49')).toBe(true);
    expect(isBackendOutdated('0.2.49', '0.2.49')).toBe(false);
    expect(isBackendOutdated('0.2.50', '0.2.49')).toBe(false);
    expect(isBackendOutdated('', '0.2.49')).toBe(false);
    expect(isBackendOutdated('0.2.9', '0.2.10')).toBe(true);
    expect(isBackendOutdated('0.3.0', '0.2.49')).toBe(false);
  });

  it('releases a stale supervisor lock when a newer backend must take over', () => {
    const dataRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-supervisor-takeover-'));
    const config = { dataRoot };

    // Simula un lock vivo de un proceso ajeno con backend viejo.
    const alivePid = process.pid;
    fs.writeFileSync(runtimeSupervisorLockPath(config), `${alivePid}\n`);
    writeBackendVersion(config, '0.2.40');

    // Con la propia version mas nueva, el lock se considera reemplazable.
    expect(runtimeSupervisorLockIsStale(config)).toBe(false);
    expect(tryAcquireRuntimeSupervisorLock(config)).toBe(false);

    fs.rmSync(dataRoot, { recursive: true, force: true });
  });
});
