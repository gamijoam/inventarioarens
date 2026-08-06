const path = require('node:path');

const { app, BrowserWindow } = require('electron');

const {
  getAppConfig,
  normalizeAppMode,
  rendererDirectory,
  userDataDirectory,
} = require('./app-config.cjs');
const { createLocalRuntime, resolveRuntimeConfig } = require('./backend-runtime.cjs');
const { startRendererServer } = require('./renderer-server.cjs');

const appMode = normalizeAppMode(
  process.env.INVENTARIO_APP_MODE ??
    require(path.join(__dirname, '..', 'package.json')).inventarioAppMode,
);
const appConfig = getAppConfig(appMode);
let rendererServer = null;
let localRuntime = null;
let isStopping = false;

function rendererRoot() {
  return rendererDirectory(app.getAppPath(), appMode);
}

async function prepareServices() {
  const rendererUrl = process.env.ELECTRON_RENDERER_URL;
  let url = rendererUrl;

  if (!url) {
    rendererServer ??= await startRendererServer(rendererRoot(), {
      port: appConfig.rendererPort,
    });
    url = rendererServer.url;
  }

  localRuntime ??= createLocalRuntime(
    resolveRuntimeConfig({
      appRoot: app.getAppPath(),
      resourcesPath: process.resourcesPath,
      isPackaged: app.isPackaged,
      dataRoot: path.join(app.getPath('appData'), 'InventarioArens'),
    }),
  );
  await localRuntime.start(new URL(url).origin);

  return url;
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

app.on('before-quit', (event) => {
  if (isStopping) return;

  event.preventDefault();
  isStopping = true;
  Promise.resolve(stopServices()).finally(() => app.quit());
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
