const fs = require('node:fs');
const path = require('node:path');

const APP_CONFIGS = Object.freeze({
  admin: Object.freeze({
    mode: 'admin',
    productName: 'Sistema de Inventario (Administrativo)',
    appId: 'com.inventarioarens.admin',
    rendererPort: 8788,
  }),
  pos: Object.freeze({
    mode: 'pos',
    productName: 'Sistema de Inventario (POS)',
    appId: 'com.inventarioarens.pos',
    rendererPort: 8789,
  }),
  technician: Object.freeze({
    mode: 'technician',
    productName: 'Soporte Técnico',
    appId: 'com.inventarioarens.technician',
    rendererPort: 8790,
  }),
});

function normalizeAppMode(mode) {
  return mode === 'pos' || mode === 'technician' ? mode : 'admin';
}

function getAppConfig(mode) {
  return APP_CONFIGS[normalizeAppMode(mode)];
}

function rendererDirectory(appRoot, mode) {
  return path.join(appRoot, 'dist', normalizeAppMode(mode));
}

function userDataDirectory(appDataPath, mode) {
  const normalizedMode = normalizeAppMode(mode);
  const suffix =
    normalizedMode === 'pos'
      ? 'InventarioArens-POS'
      : normalizedMode === 'technician'
        ? 'InventarioArens-Soporte'
        : 'InventarioArens-Administrativo';
  return path.join(appDataPath, suffix);
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
  rendererDirectory,
  userDataDirectory,
};
