const UPDATE_CHANNELS = Object.freeze([
  'admin',
  'pos',
  'technician',
  'balanzapro-pos',
  'balanzapro-admin',
]);

function resolveUpdateChannel(clientIdOrMode) {
  return UPDATE_CHANNELS.includes(clientIdOrMode) ? clientIdOrMode : 'admin';
}

function shouldEnableAutoUpdater({ isPackaged, isRuntimeSupervisor }) {
  return Boolean(isPackaged && !isRuntimeSupervisor);
}

module.exports = {
  UPDATE_CHANNELS,
  resolveUpdateChannel,
  shouldEnableAutoUpdater,
};
