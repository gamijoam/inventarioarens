const path = require('node:path');

function normalizeAppMode(mode) {
  return mode === 'pos' || mode === 'technician' ? mode : 'admin';
}

function detectAppMode(options = {}) {
  const envMode = options.env ?? process.env.INVENTARIO_APP_MODE;
  if (envMode) {
    return normalizeAppMode(envMode);
  }

  const execPath = options.execPath ?? process.execPath ?? '';
  const exeName = path.basename(execPath).toLowerCase();

  if (exeName.includes('pos')) {
    return 'pos';
  }
  if (exeName.includes('administrativo')) {
    return 'admin';
  }
  if (exeName.includes('tecnico') || exeName.includes('soporte')) {
    return 'technician';
  }

  return 'admin';
}

module.exports = {
  detectAppMode,
  normalizeAppMode,
};
