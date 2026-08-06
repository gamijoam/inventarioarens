const path = require('node:path');

function normalizeAppMode(mode) {
  return mode === 'pos' ? 'pos' : 'admin';
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

  return 'admin';
}

module.exports = {
  detectAppMode,
  normalizeAppMode,
};
