'use strict';

const api = window.printConnector;
const elements = {
  form: document.querySelector('#register-form'),
  registerButton: document.querySelector('#register-button'),
  cloudUrl: document.querySelector('#cloud-url'),
  pairingCode: document.querySelector('#pairing-code'),
  connectorName: document.querySelector('#connector-name'),
  checkButton: document.querySelector('#check-button'),
  toggleButton: document.querySelector('#toggle-button'),
  dataButton: document.querySelector('#data-button'),
  notice: document.querySelector('#notice'),
  headerStatus: document.querySelector('#header-status'),
  statusDot: document.querySelector('#status-dot'),
  connectionTitle: document.querySelector('#connection-title'),
  stateUrl: document.querySelector('#state-url'),
  stateName: document.querySelector('#state-name'),
  stateProcess: document.querySelector('#state-process'),
  stateLastConnection: document.querySelector('#state-last-connection'),
  appVersion: document.querySelector('#app-version'),
};

function showNotice(message, success = false) {
  elements.notice.textContent = message;
  elements.notice.classList.toggle('hidden', !message);
  elements.notice.classList.toggle('success', success);
}

function formatDate(value) {
  if (!value) return 'Aún no comprobada';
  return new Date(value).toLocaleString('es-VE');
}

function render(state) {
  const active = state.configured && state.running && !state.lastError;
  const hasError = Boolean(state.lastError);
  elements.headerStatus.textContent = active
    ? 'Conectado'
    : hasError
      ? 'Revisar conexión'
      : state.configured
        ? 'Detenido'
        : 'Sin configurar';
  elements.headerStatus.className = `status-pill ${active ? 'status-active' : hasError ? 'status-error' : 'status-neutral'}`;
  elements.statusDot.className = `status-dot ${active ? 'active' : hasError ? 'error' : 'neutral'}`;
  elements.connectionTitle.textContent = active
    ? 'Conector activo'
    : state.configured
      ? 'Conector vinculado'
      : 'Conector no vinculado';
  elements.stateUrl.textContent = state.cloudApiUrl || '-';
  elements.stateName.textContent = state.connectorName || '-';
  elements.stateProcess.textContent = state.running ? 'Activo en segundo plano' : 'Detenido';
  elements.stateLastConnection.textContent = formatDate(state.lastConnectionAt);
  elements.appVersion.textContent = state.connectorVersion || '-';
  elements.toggleButton.textContent = state.running ? 'Detener' : 'Activar';
  elements.checkButton.disabled = !state.configured;
  elements.toggleButton.disabled = !state.configured;
  if (state.connectorName && !elements.connectorName.value)
    elements.connectorName.value = state.connectorName;
  if (state.cloudApiUrl) elements.cloudUrl.value = state.cloudApiUrl;
  if (state.lastError) showNotice(state.lastError);
}

elements.form.addEventListener('submit', async (event) => {
  event.preventDefault();
  elements.registerButton.disabled = true;
  showNotice('Vinculando esta computadora...', true);
  try {
    const state = await api.register({
      code: elements.pairingCode.value,
      name: elements.connectorName.value,
      cloudApiUrl: elements.cloudUrl.value,
    });
    elements.pairingCode.value = '';
    render(state);
    showNotice('Computadora vinculada. El conector ya está consultando la nube.', true);
  } catch (error) {
    showNotice(error.message || 'No se pudo vincular la computadora.');
  } finally {
    elements.registerButton.disabled = false;
  }
});

elements.checkButton.addEventListener('click', async () => {
  elements.checkButton.disabled = true;
  try {
    await api.checkConnection();
    showNotice('Conexión correcta con la nube.', true);
    render(await api.getState());
  } catch (error) {
    showNotice(error.message || 'No se pudo comprobar la conexión.');
  } finally {
    elements.checkButton.disabled = false;
  }
});

elements.toggleButton.addEventListener('click', async () => {
  elements.toggleButton.disabled = true;
  try {
    render(await (elements.toggleButton.textContent === 'Detener' ? api.stop() : api.start()));
  } catch (error) {
    showNotice(error.message || 'No se pudo cambiar el estado del conector.');
  } finally {
    elements.toggleButton.disabled = false;
  }
});

elements.dataButton.addEventListener('click', () => void api.openData());
api.onState(render);
void api
  .getState()
  .then(render)
  .catch((error) => showNotice(error.message));
