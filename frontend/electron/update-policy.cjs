const UPDATE_CHANNELS = Object.freeze(['admin', 'pos', 'technician']);

function resolveUpdateChannel(mode) {
  return UPDATE_CHANNELS.includes(mode) ? mode : 'admin';
}

function shouldEnableAutoUpdater({ isPackaged, isRuntimeSupervisor }) {
  return Boolean(isPackaged && !isRuntimeSupervisor);
}

module.exports = {
  UPDATE_CHANNELS,
  resolveUpdateChannel,
  shouldEnableAutoUpdater,
};
