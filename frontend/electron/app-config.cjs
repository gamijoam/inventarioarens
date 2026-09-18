const fs = require('node:fs');
const path = require('node:path');

const APP_CONFIGS = Object.freeze({
  admin: Object.freeze({
    mode: 'admin',
    brand: 'default',
    productName: 'Sistema de Inventario (Administrativo)',
    appId: 'com.inventarioarens.admin',
    rendererPort: 8788,
    userDataSuffix: 'InventarioArens-Administrativo',
  }),
  pos: Object.freeze({
    mode: 'pos',
    brand: 'default',
    productName: 'Sistema de Inventario (POS)',
    appId: 'com.inventarioarens.pos',
    rendererPort: 8789,
    userDataSuffix: 'InventarioArens-POS',
  }),
  technician: Object.freeze({
    mode: 'technician',
    brand: 'default',
    productName: 'Soporte Técnico',
    appId: 'com.inventarioarens.technician',
    rendererPort: 8790,
    userDataSuffix: 'InventarioArens-Soporte',
  }),
  'balanzapro-pos': Object.freeze({
    mode: 'pos',
    brand: 'balanzapro',
    productName: 'BalanzaPro POS',
    appId: 'com.balanzapro.pos',
    rendererPort: 8791,
    userDataSuffix: 'BalanzaPro-POS',
  }),
  'balanzapro-admin': Object.freeze({
    mode: 'admin',
    brand: 'balanzapro',
    productName: 'BalanzaPro (Administrativo)',
    appId: 'com.balanzapro.admin',
    rendererPort: 8792,
    userDataSuffix: 'BalanzaPro-Administrativo',
  }),
  'balanzapro-technician': Object.freeze({
    mode: 'technician',
    brand: 'balanzapro',
    productName: 'BalanzaPro Soporte Técnico',
    appId: 'com.balanzapro.technician',
    rendererPort: 8793,
    userDataSuffix: 'BalanzaPro-Soporte',
  }),
});

function normalizeAppMode(mode) {
  return mode === 'pos' || mode === 'technician' ? mode : 'admin';
}

function normalizeClientId(value) {
  return Object.prototype.hasOwnProperty.call(APP_CONFIGS, value) ? value : 'admin';
}

function getAppConfig(clientIdOrMode) {
  return APP_CONFIGS[normalizeClientId(clientIdOrMode)];
}

function rendererDirectory(appRoot, clientIdOrMode) {
  return path.join(appRoot, 'dist', getAppConfig(clientIdOrMode).mode);
}

function userDataDirectory(appDataPath, clientIdOrMode) {
  return path.join(appDataPath, getAppConfig(clientIdOrMode).userDataSuffix);
}

function localDataDirectory(appDataPath, options = {}) {
  const environment = options.environment ?? process.env;
  const explicitRoot = environment.INVENTARIO_DATA_ROOT;
  if (explicitRoot) return explicitRoot;

  const platform = options.platform ?? process.platform;
  const programDataPath =
    options.programDataPath ?? environment.ProgramData ?? environment.PROGRAMDATA;
  const fileExists = options.fileExists ?? fs.existsSync;

  if (platform === 'win32' && programDataPath) {
    const sharedRoot = path.join(programDataPath, 'InventarioArens');
    if (fileExists(path.join(sharedRoot, 'backend-service.json'))) {
      return sharedRoot;
    }
  }

  return path.join(appDataPath, 'InventarioArens');
}

module.exports = {
  APP_CONFIGS,
  getAppConfig,
  localDataDirectory,
  normalizeAppMode,
  normalizeClientId,
  rendererDirectory,
  userDataDirectory,
};
