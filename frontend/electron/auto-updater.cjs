const { BrowserWindow, dialog } = require('electron');
const fs = require('node:fs');
const path = require('node:path');

const { resolveUpdateChannel, shouldEnableAutoUpdater } = require('./update-policy.cjs');

const UPDATE_CHECK_INTERVAL_MS = 1 * 60 * 1000;

function createUpdaterLogger(logPath, logger = console) {
  const write = (level, message) => {
    logger[level]?.(message);

    if (!logPath) return;

    try {
      fs.mkdirSync(path.dirname(logPath), { recursive: true });
      fs.appendFileSync(logPath, `[${new Date().toISOString()}] ${message}\n`, 'utf8');
    } catch (error) {
      logger.warn?.(`[UPDATER] could not persist log: ${error.message}`);
    }
  };

  return {
    info: (message) => write('info', message),
    warn: (message) => write('warn', message),
    error: (message) => write('error', message),
  };
}

function createUpdateCheckScheduler(autoUpdater, { logger = console } = {}) {
  let inFlight = null;

  const checkForUpdates = () => {
    if (inFlight) return inFlight;

    inFlight = Promise.resolve()
      .then(() => autoUpdater.checkForUpdates())
      .catch((error) => {
        logger.warn?.(`[UPDATER] check failed: ${error.message}`);
        return null;
      })
      .finally(() => {
        inFlight = null;
      });

    return inFlight;
  };

  return { checkForUpdates };
}

function loadAutoUpdater(logger) {
  try {
    return require('electron-updater').autoUpdater;
  } catch (error) {
    logger.warn?.(
      `[UPDATER] electron-updater is not bundled (${error.message}). Auto-update disabled.`,
    );
    return null;
  }
}

function setupAutoUpdater({ app, appMode, isRuntimeSupervisor, logger = console }) {
  if (!shouldEnableAutoUpdater({ isPackaged: app.isPackaged, isRuntimeSupervisor })) {
    return false;
  }

  const autoUpdater = loadAutoUpdater(logger);
  if (!autoUpdater) {
    return false;
  }

  const userDataPath = app.getPath?.('userData');
  const updaterLogger = createUpdaterLogger(
    userDataPath ? path.join(userDataPath, 'updater.log') : null,
    logger,
  );
  const checkScheduler = createUpdateCheckScheduler(autoUpdater, { logger: updaterLogger });

  autoUpdater.channel = resolveUpdateChannel(appMode);
  autoUpdater.autoDownload = true;
  autoUpdater.autoInstallOnAppQuit = true;
  autoUpdater.allowDowngrade = false;

  autoUpdater.on('checking-for-update', () => {
    updaterLogger.info(`[UPDATER] checking channel=${autoUpdater.channel}`);
  });
  let updateNoticeShown = false;
  autoUpdater.on('update-available', async (info) => {
    updaterLogger.info(`[UPDATER] update available version=${info.version}`);
    if (updateNoticeShown) return;
    updateNoticeShown = true;

    try {
      await dialog.showMessageBox(BrowserWindow.getAllWindows()[0], {
        type: 'info',
        buttons: ['Entendido'],
        title: 'Actualización encontrada',
        message: `Se encontró la versión ${info.version}.`,
        detail: 'Se descargará en segundo plano y luego podrás reiniciar para instalarla.',
      });
    } catch (error) {
      updaterLogger.warn(`[UPDATER] update notice failed: ${error.message}`);
    }
  });
  autoUpdater.on('update-not-available', () => {
    updaterLogger.info(`[UPDATER] already current channel=${autoUpdater.channel}`);
  });
  autoUpdater.on('download-progress', (progress) => {
    updaterLogger.info(`[UPDATER] download ${Math.round(progress.percent)}%`);
  });
  autoUpdater.on('error', (error) => {
    updaterLogger.warn(`[UPDATER] ${error.message}`);
  });
  let updateDialogOpen = false;
  autoUpdater.on('update-downloaded', async (info) => {
    if (updateDialogOpen) return;
    updateDialogOpen = true;
    const window = BrowserWindow.getAllWindows()[0];
    try {
      const result = await dialog.showMessageBox(window, {
        type: 'info',
        buttons: ['Reiniciar ahora', 'Más tarde'],
        defaultId: 0,
        cancelId: 1,
        title: 'Actualización lista',
        message: `Hay una actualización disponible: ${info.version}.`,
        detail:
          'La actualización se instalará al cerrar la aplicación. Puedes reiniciar ahora o continuar trabajando.',
      });

      if (result.response === 0) {
        autoUpdater.quitAndInstall(false, true);
      }
    } catch (error) {
      updaterLogger.warn(`[UPDATER] downloaded update notice failed: ${error.message}`);
    } finally {
      updateDialogOpen = false;
    }
  });

  const checkForUpdates = () => void checkScheduler.checkForUpdates();
  const startupTimer = setTimeout(checkForUpdates, 5000);
  const periodicTimer = setInterval(checkForUpdates, UPDATE_CHECK_INTERVAL_MS);
  startupTimer.unref?.();
  periodicTimer.unref?.();

  return true;
}

module.exports = {
  createUpdateCheckScheduler,
  createUpdaterLogger,
  setupAutoUpdater,
};
