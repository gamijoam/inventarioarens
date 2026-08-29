'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('printConnector', {
  getState: () => ipcRenderer.invoke('connector:get-state'),
  register: (payload) => ipcRenderer.invoke('connector:register', payload),
  checkConnection: () => ipcRenderer.invoke('connector:check-connection'),
  start: () => ipcRenderer.invoke('connector:start'),
  stop: () => ipcRenderer.invoke('connector:stop'),
  openData: () => ipcRenderer.invoke('connector:open-data'),
  onState: (callback) => {
    const listener = (_event, state) => callback(state);
    ipcRenderer.on('connector:state', listener);
    return () => ipcRenderer.removeListener('connector:state', listener);
  },
});
