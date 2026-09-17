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

/**
 * Detecta la marca del cliente. El branding BalanzaPro usa instaladores,
 * appIds y canales de actualizacion propios aunque comparta el modo (POS o
 * Administrativo) del producto generico.
 */
function detectAppBrand(options = {}) {
  const envBrand = options.brand ?? process.env.INVENTARIO_APP_BRAND;
  if (envBrand) {
    return String(envBrand).toLowerCase() === 'balanzapro' ? 'balanzapro' : 'default';
  }

  const execPath = options.execPath ?? process.execPath ?? '';
  const exeName = path.basename(execPath).toLowerCase();

  return exeName.includes('balanzapro') ? 'balanzapro' : 'default';
}

/**
 * Devuelve el id de cliente completo (brand + modo) usado para config,
 * directorio de datos y canal de actualizacion.
 */
function detectAppClient(options = {}) {
  const mode = detectAppMode(options);
  const brand = detectAppBrand(options);

  if (brand === 'balanzapro' && mode !== 'technician') {
    return `balanzapro-${mode}`;
  }

  return mode;
}

module.exports = {
  detectAppBrand,
  detectAppClient,
  detectAppMode,
  normalizeAppMode,
};
