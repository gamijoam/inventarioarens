const { BrowserWindow, dialog } = require('electron');
const { autoUpdater } = require('electron-updater');

const { resolveUpdateChannel, shouldEnableAutoUpdater } = require('./update-policy.cjs');

const UPDATE_CHECK_INTERVAL_MS = 6 * 60 * 60 * 1000;

function setupAutoUpdater({ app, appMode, isRuntimeSupervisor, logger = console }) {
  if (!shouldEnableAutoUpdater({ isPackaged: app.isPackaged, isRuntimeSupervisor })) {
    return false;
  }

  autoUpdater.channel = resolveUpdateChannel(appMode);
  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.allowDowngrade = false;

  autoUpdater.on('checking-for-update', () => {
    logger.info(`[UPDATER] checking channel=${autoUpdater.channel}`);
  });
  autoUpdater.on('update-available', (info) => {
    logger.info(`[UPDATER] update available version=${info.version}`);
  });
  autoUpdater.on('update-not-available', () => {
    logger.info(`[UPDATER] already current channel=${autoUpdater.channel}`);
  });
  autoUpdater.on('download-progress', (progress) => {
    logger.info(`[UPDATER] download ${Math.round(progress.percent)}%`);
  });
  autoUpdater.on('error', (error) => {
    logger.warn(`[UPDATER] ${error.message}`);
  });
  autoUpdater.on('update-downloaded', async (info) => {
    const window = BrowserWindow.getAllWindows()[0];
    const result = await dialog.showMessageBox(window, {
      type: 'info',
      buttons: ['Reiniciar ahora', 'Mas tarde'],
      defaultId: 0,
      cancelId: 1,
      title: 'Actualizacion lista',
      message: `Hay una actualizacion disponible: ${info.version}.`,
      detail:
        'La actualizacion se instalara al cerrar la aplicacion. Puedes reiniciar ahora o continuar trabajando.',
    });

    if (result.response === 0) {
      autoUpdater.quitAndInstall(false, true);
    }
  });

  const checkForUpdates = () => {
    void autoUpdater.checkForUpdates().catch((error) => {
      logger.warn(`[UPDATER] check failed: ${error.message}`);
    });
  };
  const startupTimer = setTimeout(checkForUpdates, 5000);
  const periodicTimer = setInterval(checkForUpdates, UPDATE_CHECK_INTERVAL_MS);
  startupTimer.unref?.();
  periodicTimer.unref?.();

  return true;
}

module.exports = { setupAutoUpdater };
