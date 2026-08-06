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
    productName: 'POS',
    appId: 'com.inventarioarens.pos',
    rendererPort: 8789,
  }),
});

function normalizeAppMode(mode) {
  return mode === 'pos' ? 'pos' : 'admin';
}

function getAppConfig(mode) {
  return APP_CONFIGS[normalizeAppMode(mode)];
}

function rendererDirectory(appRoot, mode) {
  return path.join(appRoot, 'dist', normalizeAppMode(mode));
}

function userDataDirectory(appDataPath, mode) {
  const suffix =
    normalizeAppMode(mode) === 'pos' ? 'InventarioArens-POS' : 'InventarioArens-Administrativo';
  return path.join(appDataPath, suffix);
}

module.exports = {
  APP_CONFIGS,
  getAppConfig,
  normalizeAppMode,
  rendererDirectory,
  userDataDirectory,
};
