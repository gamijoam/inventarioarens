'use strict';

const crypto = require('node:crypto');
const path = require('node:path');
const { app, BrowserWindow, Menu, Tray, ipcMain, nativeImage, shell } = require('electron');

const {
  PrintConnector,
  loadConfig,
  resolveCloudApiUrl,
  saveConfigFile,
} = require('./connector.cjs');

const PRODUCT_NAME = 'Inventario Arens Print Connector';
const isBackgroundLaunch = process.argv.includes('--background');
const TRAY_ICON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#41c7b6"/><path d="M8 10h16v12H8z" fill="#07151a"/><path d="M11 13h10M11 16h7M11 19h10" stroke="#41c7b6" stroke-linecap="round" stroke-width="2"/></svg>`;
let mainWindow = null;
let tray = null;
let isQuitting = false;
let connector = null;
let controller = null;
let runPromise = null;
let configPath = null;
let lastError = null;
let lastConnectionAt = null;

function dataDirectory() {
  return path.join(app.getPath('userData'), 'data');
}

function configFile() {
  return path.join(dataDirectory(), 'config.json');
}

async function readConfig() {
  configPath ??= configFile();
  const config = await loadConfig(configPath);
  config.dataDir = dataDirectory();
  return config;
}

function publicState(config, running = Boolean(runPromise)) {
  return {
    configured: Boolean(config.token && config.cloudApiUrl),
    running,
    cloudApiUrl: config.cloudApiUrl || 'https://app.miinventariofacil.com/api',
    connectorName: config.name || config.connector?.name || '',
    connectorVersion: app.getVersion(),
    connectorStatus: config.connector?.status || (config.token ? 'active' : 'not_configured'),
    lastConnectionAt,
    lastError,
    dataPath: dataDirectory(),
  };
}

function broadcastState(state) {
  if (!mainWindow || mainWindow.isDestroyed()) return;
  mainWindow.webContents.send('connector:state', state);
}

async function currentState() {
  const config = await readConfig();
  return publicState(config);
}

async function stopConnector() {
  controller?.abort();
  await runPromise;
  controller = null;
  runPromise = null;
  connector = null;
  broadcastState(await currentState());
}

function sleepWithSignal(milliseconds, signal) {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, milliseconds);
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer);
        resolve();
      },
      { once: true },
    );
  });
}

async function startConnector() {
  if (runPromise) return;
  const config = await readConfig();
  if (!config.token || !config.cloudApiUrl) {
    broadcastState(publicState(config, false));
    return;
  }

  lastError = null;
  controller = new AbortController();
  connector = new PrintConnector({
    config,
    saveConfig: (nextConfig) => saveConfigFile(nextConfig, configPath),
    onError: (error) => {
      lastError = error.message;
      broadcastState(publicState(config));
    },
    sleep: (milliseconds) => sleepWithSignal(milliseconds, controller.signal),
  });
  runPromise = connector.run({ signal: controller.signal }).finally(() => {
    runPromise = null;
    connector = null;
    controller = null;
    broadcastState(publicState(config, false));
  });
  broadcastState(publicState(config, true));
}

async function registerConnector({ code, name, cloudApiUrl }) {
  const config = await readConfig();
  config.cloudApiUrl = resolveCloudApiUrl(config, cloudApiUrl);
  config.dataDir = dataDirectory();
  const installationId = config.installationId || crypto.randomUUID();
  const registrationConnector = new PrintConnector({
    config,
    saveConfig: (nextConfig) => saveConfigFile(nextConfig, configPath),
  });
  await registrationConnector.register({
    code: code.trim().toUpperCase(),
    name: name.trim(),
    installationId,
    version: app.getVersion(),
  });
  lastError = null;
  lastConnectionAt = new Date().toISOString();
  await startConnector();
  return currentState();
}

async function checkConnection() {
  const config = await readConfig();
  if (!config.token || !config.cloudApiUrl) {
    throw new Error('Primero vincula este conector con una empresa.');
  }
  const probe = new PrintConnector({ config });
  const result = await probe.heartbeat();
  lastConnectionAt = new Date().toISOString();
  lastError = null;
  broadcastState(publicState(config));
  return result;
}

function createWindow() {
  mainWindow = new BrowserWindow({
    title: PRODUCT_NAME,
    width: 820,
    height: 720,
    minWidth: 680,
    minHeight: 600,
    show: !isBackgroundLaunch,
    backgroundColor: '#0b1220',
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      preload: path.join(__dirname, 'preload.cjs'),
    },
  });
  void mainWindow.loadFile(path.join(__dirname, 'renderer', 'index.html'));
  mainWindow.on('close', (event) => {
    if (!isQuitting) {
      event.preventDefault();
      mainWindow.hide();
    }
  });
  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

function createTray() {
  const trayIcon = nativeImage
    .createFromDataURL(`data:image/svg+xml;base64,${Buffer.from(TRAY_ICON_SVG).toString('base64')}`)
    .resize({ width: 16, height: 16 });
  tray = new Tray(trayIcon);
  tray.setToolTip(PRODUCT_NAME);
  tray.setContextMenu(
    Menu.buildFromTemplate([
      { label: 'Abrir conector', click: () => mainWindow?.show() },
      { type: 'separator' },
      {
        label: 'Salir',
        click: quitApplication,
      },
    ]),
  );
  tray.on('click', () => mainWindow?.show());
}

function quitApplication() {
  if (isQuitting) return;
  isQuitting = true;
  void stopConnector().finally(() => app.quit());
}

function registerIpc() {
  ipcMain.handle('connector:get-state', currentState);
  ipcMain.handle('connector:register', (_event, payload) => registerConnector(payload));
  ipcMain.handle('connector:check-connection', checkConnection);
  ipcMain.handle('connector:start', async () => {
    await startConnector();
    return currentState();
  });
  ipcMain.handle('connector:stop', async () => {
    await stopConnector();
    return currentState();
  });
  ipcMain.handle('connector:open-data', async () => shell.openPath(dataDirectory()));
}

app.setName(PRODUCT_NAME);
app.setAppUserModelId('com.inventarioarens.printconnector');

const hasLock = app.requestSingleInstanceLock();

if (!hasLock) {
  app.quit();
} else {
  app.on('second-instance', () => {
    mainWindow?.show();
    mainWindow?.focus();
  });

  app.whenReady().then(async () => {
    configPath = configFile();
    registerIpc();
    createWindow();
    createTray();
    app.setLoginItemSettings({ openAtLogin: true, args: ['--background'] });
    await startConnector();
  });
}

app.on('before-quit', (event) => {
  if (isQuitting) return;
  event.preventDefault();
  quitApplication();
});

app.on('window-all-closed', () => {});
