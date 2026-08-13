const path = require('node:path');

const { app, BrowserWindow } = require('electron');

const { getAppConfig, rendererDirectory, userDataDirectory } = require('./app-config.cjs');
const { detectAppMode } = require('./app-mode.cjs');
const {
  createLocalRuntime,
  createRuntimeSupervisor,
  resolveRuntimeConfig,
} = require('./backend-runtime.cjs');
const { startRendererServer } = require('./renderer-server.cjs');
let setupAutoUpdater = () => false;
try {
  ({ setupAutoUpdater } = require('./auto-updater.cjs'));
} catch (error) {
  console.warn('[main] auto-updater module not bundled:', error.message);
}

const appMode = detectAppMode();
const appConfig = getAppConfig(appMode);
const isRuntimeSupervisor = process.argv.includes('--inventario-runtime-supervisor');
let rendererServer = null;
let localRuntime = null;
let isStopping = false;

function rendererRoot() {
  return rendererDirectory(app.getAppPath(), appMode);
}

function localDataRoot() {
  return process.env.INVENTARIO_DATA_ROOT ?? path.join(app.getPath('appData'), 'InventarioArens');
}

async function prepareServices() {
  const rendererUrl = process.env.ELECTRON_RENDERER_URL;
  let url = rendererUrl;

  localRuntime ??= createLocalRuntime({
    config: resolveRuntimeConfig({
      appRoot: app.getAppPath(),
      resourcesPath: process.resourcesPath,
      isPackaged: app.isPackaged,
      dataRoot: localDataRoot(),
      appVersion: app.getVersion(),
    }),
    clientId: appMode,
    isPackaged: app.isPackaged,
    supervisorAppPath: app.getAppPath(),
    supervisorExecutable: process.execPath,
  });

  if (!url) {
    rendererServer ??= await startRendererServer(rendererRoot(), {
      host: localRuntime.config.apiBindHost,
      port: appConfig.rendererPort,
      apiTarget: localRuntime.config.apiUrl,
    });
    url = rendererServer.url;
  }

  await localRuntime.start(new URL(url).origin);

  return url;
}

async function runRuntimeSupervisor() {
  const supervisor = createRuntimeSupervisor({
    config: resolveRuntimeConfig({
      appRoot: app.getAppPath(),
      resourcesPath: process.resourcesPath,
      isPackaged: app.isPackaged,
      dataRoot: localDataRoot(),
    }),
  });

  await supervisor.run();
}

async function stopServices() {
  await localRuntime?.stop();
  rendererServer?.server.close();
}

async function createWindow() {
  const url = await prepareServices();

  const window = new BrowserWindow({
    title: appConfig.productName,
    width: appMode === 'pos' ? 1440 : 1366,
    height: 900,
    minWidth: appMode === 'pos' ? 1024 : 1100,
    minHeight: 700,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  });

  await window.loadURL(url);
  return window;
}

if (isRuntimeSupervisor) {
  app.whenReady().then(async () => {
    try {
      await runRuntimeSupervisor();
      app.exit(0);
    } catch (error) {
      console.error('No se pudo iniciar el supervisor local:', error);
      app.exit(1);
    }
  });
} else {
  app.setName(appConfig.productName);
  app.setPath('userData', userDataDirectory(app.getPath('appData'), appMode));

  const hasLock = app.requestSingleInstanceLock();

  if (!hasLock) {
    app.quit();
  } else {
    app.on('second-instance', () => {
      const [window] = BrowserWindow.getAllWindows();

      if (window) {
        if (window.isMinimized()) window.restore();
        window.focus();
      }
    });

    app.whenReady().then(async () => {
      try {
        if (process.env.INVENTARIO_ELECTRON_SMOKE === '1') {
          await prepareServices();
          await stopServices();
          app.exit(0);
          return;
        }

        await createWindow();
        setupAutoUpdater({ app, appMode, isRuntimeSupervisor });

        app.on('activate', async () => {
          if (BrowserWindow.getAllWindows().length === 0) {
            await createWindow();
          }
        });
      } catch (error) {
        console.error('No se pudo iniciar el runtime local:', error);
        await stopServices();
        app.exit(1);
      }
    });
  }
}

app.on('before-quit', (event) => {
  if (isStopping) return;

  event.preventDefault();
  isStopping = true;
  Promise.resolve(stopServices()).finally(() => app.quit());
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
