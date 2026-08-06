const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawn } = require('node:child_process');

const API_HOST = '127.0.0.1';
const API_PORT = 8787;
const ELECTRON_RENDERER_ORIGINS = [
  'http://127.0.0.1:8788',
  'http://127.0.0.1:8789',
  'http://localhost:8788',
  'http://localhost:8789',
];

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
  const apiHost = options.apiHost ?? process.env.INVENTARIO_API_HOST ?? API_HOST;
  const apiPort = Number(options.apiPort ?? process.env.INVENTARIO_API_PORT ?? API_PORT);

  return {
    apiHost,
    apiPort,
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
    storagePath: path.join(dataRoot, 'storage'),
    syncCloudUrl: options.syncCloudUrl ?? process.env.INVENTARIO_SYNC_CLOUD_URL,
    syncTenant: options.syncTenant ?? process.env.INVENTARIO_SYNC_TENANT,
    syncToken: options.syncToken ?? process.env.INVENTARIO_SYNC_TOKEN,
  };
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

  return {
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
  };
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

function waitForHealth(url, attempts = 30) {
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

function runCommand(config, args, environment) {
  return new Promise((resolve, reject) => {
    const child = spawn(config.phpBinary, args, {
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

function createLocalRuntime(options = {}) {
  const config = options.config ?? resolveRuntimeConfig(options);
  const spawnProcess = options.spawnProcess ?? spawn;
  let apiProcess = null;
  let syncProcess = null;
  let ownsApi = false;
  let ownsSync = false;

  return {
    config,
    async start(rendererOrigin) {
      if (await requestHealth(config.apiUrl)) {
        return { external: true, apiUrl: config.apiUrl };
      }

      const ownsStartupLock = await acquireRuntimeStartupLock(config);

      try {
        if (await requestHealth(config.apiUrl)) {
          return { external: true, apiUrl: config.apiUrl };
        }

        if (!fs.existsSync(path.join(config.backendRoot, 'artisan'))) {
          throw new Error(`No se encontro Laravel en ${config.backendRoot}`);
        }

        fs.mkdirSync(config.storagePath, { recursive: true });
        ensureStorageDirectories(config.storagePath);
        fs.mkdirSync(config.logDirectory, { recursive: true });
        const appKey = ensureAppKey(config);
        const bootstrapToken = ensureBootstrapToken(config);
        const environment = buildLaravelEnvironment(
          { ...config, appKey, bootstrapToken },
          rendererOrigin,
        );

        await runCommand(
          config,
          ['artisan', 'local:install-sqlite', `--database=${config.databasePath}`],
          environment,
        );

        apiProcess = spawnProcess(
          config.phpBinary,
          ['artisan', 'serve', '--host', config.apiHost, '--port', String(config.apiPort)],
          {
            cwd: config.backendRoot,
            env: { ...process.env, ...environment },
            windowsHide: true,
            stdio: ['ignore', 'pipe', 'pipe'],
          },
        );
        ownsApi = true;
        attachLogStream(apiProcess, path.join(config.logDirectory, 'api.log'));

        try {
          await waitForHealth(config.apiUrl);
        } catch (error) {
          apiProcess.kill();
          apiProcess = null;
          ownsApi = false;
          throw error;
        }

        const workerArgs = syncArguments(config);
        if (workerArgs) {
          syncProcess = spawnProcess(config.phpBinary, workerArgs, {
            cwd: config.backendRoot,
            env: { ...process.env, ...environment },
            windowsHide: true,
            stdio: ['ignore', 'pipe', 'pipe'],
          });
          ownsSync = true;
          attachLogStream(syncProcess, path.join(config.logDirectory, 'sync.log'));
        }

        return { external: false, apiUrl: config.apiUrl, syncStarted: Boolean(workerArgs) };
      } finally {
        if (ownsStartupLock) releaseRuntimeStartupLock(config);
      }
    },
    async stop() {
      if (ownsSync && syncProcess && !syncProcess.killed) syncProcess.kill();
      if (ownsApi && apiProcess && !apiProcess.killed) apiProcess.kill();
      syncProcess = null;
      apiProcess = null;
      ownsSync = false;
      ownsApi = false;
    },
  };
}

module.exports = {
  buildLaravelEnvironment,
  createLocalRuntime,
  releaseRuntimeStartupLock,
  resolveRuntimeConfig,
  runtimeStartupLockPath,
  syncArguments,
  tryAcquireRuntimeStartupLock,
};
