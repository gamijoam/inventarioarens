const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawn } = require('node:child_process');

const API_HOST = '127.0.0.1';
const API_PORT = 8787;
const PRINTER_PORT = 17777;
const DEFAULT_SYNC_CLOUD_URL = 'https://app.miinventariofacil.com/api';
const ELECTRON_RENDERER_ORIGINS = [
  'http://127.0.0.1:8788',
  'http://127.0.0.1:8789',
  'http://127.0.0.1:8790',
  'http://localhost:8788',
  'http://localhost:8789',
  'http://localhost:8790',
];
const RUNTIME_SUPERVISOR_FLAG = '--inventario-runtime-supervisor';
const RUNTIME_LEASE_TTL_MS = 10000;

function resolveRuntimeConfig(options = {}) {
  const appRoot = options.appRoot ?? path.resolve(__dirname, '..');
  const resourcesPath = options.resourcesPath ?? appRoot;
  const isPackaged = options.isPackaged ?? false;
  const platform = options.platform ?? process.platform;
  const dataRoot =
    options.dataRoot ?? process.env.INVENTARIO_DATA_ROOT ?? path.join(resourcesPath, 'data');
  const backendRoot =
    options.backendRoot ??
    process.env.INVENTARIO_BACKEND_ROOT ??
    (isPackaged ? path.join(resourcesPath, 'backend') : path.resolve(appRoot, '..'));
  const phpBinary =
    options.phpBinary ??
    process.env.INVENTARIO_PHP_BIN ??
    (isPackaged
      ? path.join(resourcesPath, 'runtime', 'php', platform === 'win32' ? 'php.exe' : 'php')
      : 'php');
  const localServerSettings = readLocalServerSettings(dataRoot);
  const explicitApiHost = options.apiHost ?? process.env.INVENTARIO_API_HOST;
  const apiHost = options.apiClientHost ?? process.env.INVENTARIO_API_CLIENT_HOST ?? API_HOST;
  const apiBindHost =
    options.apiBindHost ??
    process.env.INVENTARIO_API_BIND_HOST ??
    explicitApiHost ??
    (localServerSettings.enabled ? localServerSettings.bind_host : API_HOST);
  const apiPort = Number(
    options.apiPort ?? process.env.INVENTARIO_API_PORT ?? localServerSettings.api_port ?? API_PORT,
  );
  const printerPort = Number(
    options.printerPort ?? process.env.INVENTARIO_PRINTER_PORT ?? PRINTER_PORT,
  );

  return {
    appRoot,
    apiHost,
    apiBindHost,
    apiPort,
    printerPort,
    apiUrl: `http://${apiHost}:${apiPort}`,
    appKey: options.appKey,
    backendRoot,
    bootstrapToken: options.bootstrapToken ?? process.env.INVENTARIO_BOOTSTRAP_TOKEN,
    databasePath: path.join(dataRoot, 'inventario.sqlite'),
    dataRoot,
    installationCode:
      options.installationCode ?? process.env.INVENTARIO_SYNC_INSTALLATION ?? 'ELECTRON-LOCAL',
    logDirectory: path.join(dataRoot, 'logs'),
    nodeCode: options.nodeCode ?? process.env.INVENTARIO_SYNC_NODE ?? 'LOCAL-01',
    nodeName: options.nodeName ?? process.env.INVENTARIO_SYNC_NODE_NAME ?? 'Electron Local',
    phpBinary,
    resourcesPath,
    storagePath: path.join(dataRoot, 'storage'),
    syncCloudUrl:
      options.syncCloudUrl ?? process.env.INVENTARIO_SYNC_CLOUD_URL ?? DEFAULT_SYNC_CLOUD_URL,
    syncTenant: options.syncTenant ?? process.env.INVENTARIO_SYNC_TENANT,
    syncToken: options.syncToken ?? process.env.INVENTARIO_SYNC_TOKEN,
  };
}

function readLocalServerSettings(dataRoot) {
  const settingsPath = path.join(dataRoot, 'local-server.json');
  if (!fs.existsSync(settingsPath)) {
    return { enabled: false, bind_host: API_HOST, api_port: API_PORT };
  }

  try {
    const settings = JSON.parse(fs.readFileSync(settingsPath, 'utf8'));
    return {
      enabled: settings.enabled === true,
      bind_host: settings.bind_host === '0.0.0.0' ? '0.0.0.0' : API_HOST,
      api_port: Number(settings.api_port) > 0 ? Number(settings.api_port) : API_PORT,
    };
  } catch {
    return { enabled: false, bind_host: API_HOST, api_port: API_PORT };
  }
}

function ensureAppKey(config) {
  return ensureSecret(
    config,
    'app.key',
    config.appKey,
    () => `base64:${crypto.randomBytes(32).toString('base64')}`,
  );
}

function ensureBootstrapToken(config) {
  return ensureSecret(config, 'bootstrap.token', config.bootstrapToken, () =>
    crypto.randomBytes(32).toString('hex'),
  );
}

function ensureSecret(config, fileName, configuredValue, generate) {
  if (configuredValue) return configuredValue;

  const keyPath = path.join(config.dataRoot, fileName);
  fs.mkdirSync(config.dataRoot, { recursive: true });

  if (fs.existsSync(keyPath)) {
    return fs.readFileSync(keyPath, 'utf8').trim();
  }

  const secret = generate();
  fs.writeFileSync(keyPath, `${secret}\n`, { encoding: 'utf8', mode: 0o600 });
  return secret;
}

function buildLaravelEnvironment(config, rendererOrigin) {
  const allowedOrigins = [
    rendererOrigin,
    ...ELECTRON_RENDERER_ORIGINS,
    'http://127.0.0.1:5173',
    'http://localhost:5173',
  ]
    .filter(Boolean)
    .join(',');

  const env = {
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_KEY: config.appKey,
    APP_NAME: 'Sistema de Inventario',
    APP_URL: config.apiUrl,
    APP_ALLOWED_ORIGINS_FOR_CSRF: allowedOrigins,
    APP_BOOTSTRAP_TOKEN: config.bootstrapToken ?? '',
    CORS_ALLOWED_ORIGINS_LOCAL: rendererOrigin ?? 'http://127.0.0.1:5173',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: config.databasePath,
    DB_FOREIGN_KEYS: 'true',
    DB_BUSY_TIMEOUT: '5000',
    DB_JOURNAL_MODE: 'WAL',
    DB_SYNCHRONOUS: 'NORMAL',
    DB_TRANSACTION_MODE: 'IMMEDIATE',
    FILESYSTEM_DISK: 'local',
    LARAVEL_STORAGE_PATH: config.storagePath,
    LOCAL_TECHNICAL_CONSOLE_ENABLED: 'true',
    LOG_CHANNEL: 'stack',
    LOG_LEVEL: 'warning',
    QUEUE_CONNECTION: 'database',
    SESSION_DRIVER: 'database',
    SESSION_SECURE_COOKIE: 'false',
    SYNC_CLOUD_URL: config.syncCloudUrl ?? '',
    SYNC_CLOUD_TOKEN: config.syncToken ?? '',
    // Base pública de la NUBE para construir cloud_url de imágenes en eventos
    // de sync. Sin esto, un nodo local emitiría http://127.0.0.1:8787/... y la
    // nube guardaría una URL rota (imágenes en blanco en el VPS).
    SYNC_PUBLIC_BASE: config.syncCloudUrl ? String(config.syncCloudUrl).replace(/\/api\/?$/, '') : '',
  };

  if (process.platform === 'win32') {
    const phpDir = path.dirname(config.phpBinary);
    const certPath = path.join(phpDir, 'cacert.pem');
    env.SSL_CERT_FILE = certPath;
    env.CURL_CA_BUNDLE = certPath;

    if (config.dataRoot) {
      const scanDir = path.join(config.dataRoot, 'php-cert-scan');
      fs.mkdirSync(scanDir, { recursive: true });
      const escaped = certPath.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
      fs.writeFileSync(
        path.join(scanDir, 'zz-cacert.ini'),
        `curl.cainfo = "${escaped}"\nopenssl.cafile = "${escaped}"\n`,
        'utf8',
      );
      env.PHP_INI_SCAN_DIR = scanDir;
    }
  }

  return env;
}

function syncArguments(config) {
  if (!config.syncTenant || !config.syncToken || !config.syncCloudUrl) return null;

  const nodeCode = config.nodeCode ?? 'LOCAL-01';
  const nodeName = config.nodeName ?? 'Electron Local';
  const installationCode = config.installationCode ?? 'ELECTRON-LOCAL';

  return [
    'artisan',
    'sync:daemon',
    config.syncTenant,
    `--cloud-url=${config.syncCloudUrl}`,
    `--token=${config.syncToken}`,
    `--node=${nodeCode}`,
    `--name=${nodeName}`,
    `--installation=${installationCode}`,
  ];
}

function printerArguments(config) {
  const port = Number(config.printerPort ?? PRINTER_PORT);
  const bind = config.printerBindHost ?? API_HOST;

  return ['artisan', 'printer:serve', `--port=${port}`, `--bind=${bind}`];
}

function syncConfigPath(config) {
  return path.join(config.storagePath, 'app', 'sync-worker', 'sync-config.json');
}

function readSyncConfig(config) {
  const configPath = syncConfigPath(config);
  if (!fs.existsSync(configPath)) return [];

  try {
    const data = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    const tenants = data?.tenants && typeof data.tenants === 'object' ? data.tenants : {};

    return Object.entries(tenants)
      .map(([slug, tenant]) => ({
        slug,
        cloudUrl: tenant.cloud_url || config.syncCloudUrl || '',
        token: tenant.token || '',
        nodeCode: tenant.node_code || 'LOCAL-01',
        nodeName: tenant.node_name || tenant.node_code || 'Equipo local',
        installationCode: tenant.installation_code || data.installation_code || slug,
        interval: Number(tenant.interval) > 0 ? Number(tenant.interval) : 30,
        limit: Number(tenant.limit) > 0 ? Number(tenant.limit) : 50,
      }))
      .filter((tenant) => tenant.token !== '');
  } catch (error) {
    console.error(`No se pudo leer ${configPath}:`, error.message);
    return [];
  }
}

function syncDaemons(config) {
  return readSyncConfig(config).map((tenant) => [
    'artisan',
    'sync:daemon',
    tenant.slug,
    `--cloud-url=${tenant.cloudUrl}`,
    `--token=${tenant.token}`,
    `--node=${tenant.nodeCode}`,
    `--name=${tenant.nodeName}`,
    `--installation=${tenant.installationCode}`,
    `--interval=${tenant.interval}`,
    `--limit=${tenant.limit}`,
  ]);
}

function requestHealth(url, timeout = 750) {
  return new Promise((resolve) => {
    const request = http.get(`${url}/up`, (response) => {
      response.resume();
      resolve(response.statusCode >= 200 && response.statusCode < 400);
    });

    request.setTimeout(timeout, () => {
      request.destroy();
      resolve(false);
    });
    request.on('error', () => resolve(false));
  });
}

function waitForHealth(url, attempts = 120) {
  return new Promise(async (resolve, reject) => {
    for (let attempt = 0; attempt < attempts; attempt += 1) {
      if (await requestHealth(url)) {
        resolve();
        return;
      }

      await new Promise((done) => setTimeout(done, 500));
    }

    reject(new Error(`La API local no respondio en ${url}/up`));
  });
}

function runtimeLeaseDirectory(config) {
  return path.join(config.dataRoot, 'runtime-leases');
}

function runtimeLeasePath(config, clientId, pid = process.pid) {
  const safeClientId = String(clientId || 'electron-client').replace(/[^a-z0-9_-]/gi, '-');
  return path.join(runtimeLeaseDirectory(config), `${safeClientId}-${pid}.lease`);
}

function createRuntimeLease(config, clientId) {
  const leasePath = runtimeLeasePath(config, clientId);
  fs.mkdirSync(runtimeLeaseDirectory(config), { recursive: true });
  fs.writeFileSync(
    leasePath,
    JSON.stringify({ clientId, pid: process.pid, startedAt: new Date().toISOString() }),
    { encoding: 'utf8', mode: 0o600 },
  );
  return leasePath;
}

function removeRuntimeLease(leasePath) {
  try {
    fs.unlinkSync(leasePath);
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
}

function touchRuntimeLease(leasePath) {
  try {
    const now = new Date();
    fs.utimesSync(leasePath, now, now);
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
}

function listLiveRuntimeLeases(config) {
  const directory = runtimeLeaseDirectory(config);
  if (!fs.existsSync(directory)) return [];

  const liveLeases = [];
  for (const fileName of fs.readdirSync(directory)) {
    if (!fileName.endsWith('.lease')) continue;

    const leasePath = path.join(directory, fileName);
    try {
      const lease = JSON.parse(fs.readFileSync(leasePath, 'utf8'));
      const isFresh = Date.now() - fs.statSync(leasePath).mtimeMs < RUNTIME_LEASE_TTL_MS;
      if (isFresh && Number.isInteger(lease.pid)) {
        liveLeases.push({ ...lease, path: leasePath });
      } else {
        removeRuntimeLease(leasePath);
      }
    } catch {
      removeRuntimeLease(leasePath);
    }
  }

  return liveLeases;
}

function runtimeSupervisorPidPath(config) {
  return path.join(config.dataRoot, '.runtime-supervisor.pid');
}

function runtimeSupervisorLockPath(config) {
  return path.join(config.dataRoot, '.runtime-supervisor.lock');
}

function runtimeSupervisorLockIsStale(config) {
  try {
    const pid = Number.parseInt(
      fs.readFileSync(runtimeSupervisorLockPath(config), 'utf8').trim(),
      10,
    );
    if (!pid) return true;
    process.kill(pid, 0);
    return false;
  } catch (error) {
    return error.code === 'ESRCH' || error.code === 'ENOENT' || error.code === 'EINVAL';
  }
}

function tryAcquireRuntimeSupervisorLock(config) {
  fs.mkdirSync(config.dataRoot, { recursive: true });

  if (fs.existsSync(runtimeSupervisorLockPath(config)) && runtimeSupervisorLockIsStale(config)) {
    releaseRuntimeSupervisorLock(config);
  }

  try {
    fs.writeFileSync(runtimeSupervisorLockPath(config), `${process.pid}\n`, {
      encoding: 'utf8',
      flag: 'wx',
      mode: 0o600,
    });
    return true;
  } catch (error) {
    if (error.code === 'EEXIST') return false;
    throw error;
  }
}

function releaseRuntimeSupervisorLock(config) {
  try {
    fs.unlinkSync(runtimeSupervisorLockPath(config));
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
}

function writeRuntimeSupervisorPid(config) {
  fs.writeFileSync(runtimeSupervisorPidPath(config), `${process.pid}\n`, {
    encoding: 'utf8',
    mode: 0o600,
  });
}

function removeRuntimeSupervisorPid(config) {
  try {
    const pid = Number.parseInt(
      fs.readFileSync(runtimeSupervisorPidPath(config), 'utf8').trim(),
      10,
    );
    if (pid === process.pid) fs.unlinkSync(runtimeSupervisorPidPath(config));
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
}

function runtimeStartupLockPath(config) {
  return path.join(config.dataRoot, '.runtime-startup.lock');
}

function tryAcquireRuntimeStartupLock(config) {
  fs.mkdirSync(config.dataRoot, { recursive: true });

  try {
    fs.writeFileSync(runtimeStartupLockPath(config), `${process.pid}\n`, {
      encoding: 'utf8',
      flag: 'wx',
      mode: 0o600,
    });
    return true;
  } catch (error) {
    if (error.code === 'EEXIST') return false;
    throw error;
  }
}

function releaseRuntimeStartupLock(config) {
  try {
    fs.unlinkSync(runtimeStartupLockPath(config));
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
}

function runtimeStartupLockIsStale(config) {
  try {
    const pid = Number.parseInt(fs.readFileSync(runtimeStartupLockPath(config), 'utf8').trim(), 10);
    if (!pid) return true;
    process.kill(pid, 0);
    return false;
  } catch (error) {
    return error.code === 'ESRCH' || error.code === 'ENOENT' || error.code === 'EINVAL';
  }
}

async function acquireRuntimeStartupLock(config) {
  const deadline = Date.now() + 120000;

  while (Date.now() < deadline) {
    if (tryAcquireRuntimeStartupLock(config)) return true;
    if (await requestHealth(config.apiUrl)) return false;

    if (runtimeStartupLockIsStale(config)) releaseRuntimeStartupLock(config);
    await new Promise((done) => setTimeout(done, 250));
  }

  throw new Error('La API local no inicio porque otro proceso retuvo el lock de arranque');
}

function runCommand(config, args, environment, spawnProcess = spawn) {
  return new Promise((resolve, reject) => {
    const child = spawnProcess(config.phpBinary, args, {
      cwd: config.backendRoot,
      env: { ...process.env, ...environment },
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let output = '';

    child.stdout.on('data', (chunk) => {
      output += chunk.toString();
    });
    child.stderr.on('data', (chunk) => {
      output += chunk.toString();
    });
    child.once('error', reject);
    child.once('close', (code) => {
      if (code === 0) {
        resolve(output);
        return;
      }

      reject(new Error(`El comando PHP fallo (${code}): ${output.trim()}`));
    });
  });
}

function attachLogStream(child, filePath) {
  const stream = fs.createWriteStream(filePath, { flags: 'a' });
  child.stdout?.pipe(stream);
  child.stderr?.pipe(stream);
  child.once('close', () => stream.end());
}

function ensureStorageDirectories(storagePath) {
  for (const directory of [
    'app',
    'app/imports',
    'app/public',
    'app/sync-worker',
    'framework/cache',
    'framework/data',
    'framework/sessions',
    'framework/testing',
    'framework/views',
    'logs',
  ]) {
    fs.mkdirSync(path.join(storagePath, directory), { recursive: true });
  }
}

function waitForRuntimeLeases(config, idleGraceMs = 5000, pollMs = 500) {
  return new Promise(async (resolve) => {
    let emptySince = null;

    while (true) {
      const leases = listLiveRuntimeLeases(config);
      if (leases.length > 0) {
        emptySince = null;
      } else {
        emptySince ??= Date.now();
        if (Date.now() - emptySince >= idleGraceMs) {
          resolve();
          return;
        }
      }

      await new Promise((done) => setTimeout(done, pollMs));
    }
  });
}

function createRuntimeSupervisor(options = {}) {
  const config = options.config ?? resolveRuntimeConfig(options);
  const spawnProcess = options.spawnProcess ?? spawn;
  let apiProcess = null;
  let printerProcess = null;

  async function stopOwnedProcesses() {
    if (apiProcess && !apiProcess.killed) apiProcess.kill();
    apiProcess = null;
    if (printerProcess && !printerProcess.killed) printerProcess.kill();
    printerProcess = null;
  }

  function ensurePrinterAgent() {
    if (printerProcess && !printerProcess.killed) return;

    printerProcess = spawnProcess(
      config.phpBinary,
      printerArguments(config),
      {
        cwd: config.backendRoot,
        env: { ...process.env, ...buildLaravelEnvironment(config, null) },
        windowsHide: true,
        stdio: ['ignore', 'pipe', 'pipe'],
      },
    );
    attachLogStream(printerProcess, path.join(config.logDirectory, 'printer.log'));
  }

  return {
    config,
    async run() {
      if (!tryAcquireRuntimeSupervisorLock(config)) return false;

      writeRuntimeSupervisorPid(config);
      let ownsApi = false;

      try {
        if (!(await requestHealth(config.apiUrl))) {
          if (!fs.existsSync(path.join(config.backendRoot, 'artisan'))) {
            throw new Error(`No se encontro Laravel en ${config.backendRoot}`);
          }

          fs.mkdirSync(config.storagePath, { recursive: true });
          ensureStorageDirectories(config.storagePath);
          fs.mkdirSync(config.logDirectory, { recursive: true });
          const appKey = ensureAppKey(config);
          const bootstrapToken = ensureBootstrapToken(config);
          const environment = buildLaravelEnvironment({ ...config, appKey, bootstrapToken }, null);

          await runCommand(
            config,
            ['artisan', 'local:install-sqlite', `--database=${config.databasePath}`],
            environment,
            spawnProcess,
          );

          apiProcess = spawnProcess(
            config.phpBinary,
            ['artisan', 'serve', '--host', config.apiBindHost, '--port', String(config.apiPort)],
            {
              cwd: config.backendRoot,
              env: { ...process.env, ...environment },
              windowsHide: true,
              stdio: ['ignore', 'pipe', 'pipe'],
            },
          );
          ownsApi = true;
          attachLogStream(apiProcess, path.join(config.logDirectory, 'api.log'));

          await waitForHealth(config.apiUrl);
        }

        ensurePrinterAgent();

        // Auto-reparador de tareas de Windows: tras una actualizacion las tareas
        // de sync y del agente pueden quedar apuntando a rutas viejas. Se
        // re-registran con las rutas actuales (idempotente, schtasks /F).
        try {
          const repairEnvironment = buildLaravelEnvironment(config, null);
          await runCommand(
            config,
            ['artisan', 'local:repair-tasks', '--printer'],
            repairEnvironment,
            spawnProcess,
          );
        } catch (error) {
          console.error('No se pudo reparar las tareas locales:', error.message);
        }

        await waitForRuntimeLeases(config);
        return ownsApi;
      } finally {
        await stopOwnedProcesses();
        removeRuntimeSupervisorPid(config);
        releaseRuntimeSupervisorLock(config);
      }
    },
  };
}

function spawnRuntimeSupervisor(config, options = {}) {
  const executable = options.supervisorExecutable ?? process.execPath;
  const appPath = options.supervisorAppPath ?? config.appRoot;
  const args = options.isPackaged ? [RUNTIME_SUPERVISOR_FLAG] : [appPath, RUNTIME_SUPERVISOR_FLAG];
  const environment = {
    ...process.env,
    INVENTARIO_RUNTIME_SUPERVISOR: '1',
    INVENTARIO_DATA_ROOT: config.dataRoot,
    INVENTARIO_API_HOST: config.apiHost,
    INVENTARIO_API_BIND_HOST: config.apiBindHost,
    INVENTARIO_API_CLIENT_HOST: config.apiHost,
    INVENTARIO_API_PORT: String(config.apiPort),
    INVENTARIO_BACKEND_ROOT: config.backendRoot,
    INVENTARIO_PHP_BIN: config.phpBinary,
    INVENTARIO_SYNC_CLOUD_URL: config.syncCloudUrl ?? '',
    INVENTARIO_SYNC_TENANT: config.syncTenant ?? '',
    INVENTARIO_SYNC_TOKEN: config.syncToken ?? '',
  };
  delete environment.INVENTARIO_ELECTRON_SMOKE;

  const child = (options.spawnProcess ?? spawn)(executable, args, {
    cwd: config.resourcesPath,
    detached: true,
    env: environment,
    stdio: 'ignore',
    windowsHide: true,
  });
  child.unref?.();
  return child;
}

function createLocalRuntime(options = {}) {
  const config = options.config ?? resolveRuntimeConfig(options);
  const clientId = options.clientId ?? config.clientId ?? 'electron-client';
  let leasePath = null;
  let leaseHeartbeat = null;

  return {
    config,
    async start(rendererOrigin) {
      leasePath ??= createRuntimeLease(config, clientId);
      leaseHeartbeat ??= setInterval(() => touchRuntimeLease(leasePath), 2000).unref();

      try {
        if (!(await requestHealth(config.apiUrl))) {
          spawnRuntimeSupervisor(config, options);
        }
        await waitForHealth(config.apiUrl);
        return { external: true, apiUrl: config.apiUrl };
      } catch (error) {
        if (leaseHeartbeat) clearInterval(leaseHeartbeat);
        leaseHeartbeat = null;
        removeRuntimeLease(leasePath);
        leasePath = null;
        throw error;
      }
    },
    async stop() {
      if (leaseHeartbeat) clearInterval(leaseHeartbeat);
      leaseHeartbeat = null;
      if (leasePath) removeRuntimeLease(leasePath);
      leasePath = null;
    },
  };
}

module.exports = {
  buildLaravelEnvironment,
  createRuntimeLease,
  createLocalRuntime,
  createRuntimeSupervisor,
  listLiveRuntimeLeases,
  printerArguments,
  readSyncConfig,
  removeRuntimeLease,
  releaseRuntimeStartupLock,
  releaseRuntimeSupervisorLock,
  resolveRuntimeConfig,
  runtimeLeaseDirectory,
  runtimeSupervisorLockIsStale,
  runtimeSupervisorLockPath,
  runtimeSupervisorPidPath,
  spawnRuntimeSupervisor,
  runtimeStartupLockPath,
  syncArguments,
  syncDaemons,
  tryAcquireRuntimeStartupLock,
  tryAcquireRuntimeSupervisorLock,
};
