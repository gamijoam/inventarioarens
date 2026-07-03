const storageKey = 'inventory_arens_session';

const state = {
    selectedTenant: null,
    tenants: [],
};

const elements = {
    form: document.querySelector('#login-form'),
    email: document.querySelector('#email'),
    password: document.querySelector('#password'),
    tenantPicker: document.querySelector('#tenant-picker'),
    tenant: document.querySelector('#tenant'),
    message: document.querySelector('#form-message'),
    submit: document.querySelector('#submit-button'),
    togglePassword: document.querySelector('#toggle-password'),
    loginView: document.querySelector('[data-view="login"]'),
    sessionView: document.querySelector('[data-view="session"]'),
    sessionSummary: document.querySelector('#session-summary'),
    sessionTenant: document.querySelector('#session-tenant'),
    sessionPermissions: document.querySelector('#session-permissions'),
    logout: document.querySelector('#logout-button'),
};

function setMessage(message, tone = 'neutral') {
    elements.message.textContent = message;
    elements.message.dataset.tone = tone;
}

async function api(path, options = {}) {
    const response = await fetch(path, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(options.headers ?? {}),
        },
        ...options,
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(firstError || payload.message || 'No se pudo completar la operacion.');
    }

    return payload.data;
}

function renderTenantOptions(tenants) {
    elements.tenant.replaceChildren(
        ...tenants.map((tenant) => {
            const option = document.createElement('option');
            option.value = tenant.slug;
            option.textContent = tenant.name;
            return option;
        }),
    );

    elements.tenantPicker.hidden = tenants.length <= 1;
    state.selectedTenant = tenants[0] ?? null;
}

function saveSession(session) {
    localStorage.setItem(storageKey, JSON.stringify(session));
    renderSession(session);
}

function renderSession(session) {
    elements.loginView.hidden = true;
    elements.sessionView.hidden = false;
    elements.sessionSummary.textContent = `${session.user.name} ingreso como ${session.roles.join(', ') || 'usuario operativo'}.`;
    elements.sessionTenant.textContent = session.tenant.name;
    elements.sessionPermissions.textContent = `${session.permissions.length} activos`;
}

function clearSession() {
    localStorage.removeItem(storageKey);
    elements.sessionView.hidden = true;
    elements.loginView.hidden = false;
}

async function resolveTenants(email, password) {
    const tenants = await api('/api/auth/tenants', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
    });

    if (tenants.length === 0) {
        throw new Error('Este usuario no tiene empresas activas.');
    }

    state.tenants = tenants;
    renderTenantOptions(tenants);

    return tenants;
}

async function login(email, password, tenantSlug) {
    return api('/api/auth/login', {
        method: 'POST',
        headers: {
            'X-Tenant': tenantSlug,
        },
        body: JSON.stringify({
            email,
            password,
            device_name: 'Navegador web',
        }),
    });
}

elements.form?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const email = elements.email.value.trim();
    const password = elements.password.value;
    let pickedTenant = elements.tenant.value || state.selectedTenant?.slug;

    elements.submit.disabled = true;
    setMessage('Validando acceso...');

    try {
        if (state.tenants.length === 0) {
            const tenants = await resolveTenants(email, password);

            if (tenants.length > 1) {
                setMessage('Selecciona la empresa con la que deseas entrar.', 'success');
                elements.submit.innerHTML = 'Entrar a la empresa <span aria-hidden="true">-></span>';
                elements.submit.disabled = false;
                return;
            }

            pickedTenant = tenants[0].slug;
        }

        const session = await login(email, password, pickedTenant);
        saveSession(session);
    } catch (error) {
        setMessage(error.message, 'error');
    } finally {
        elements.submit.disabled = false;
    }
});

elements.tenant?.addEventListener('change', () => {
    state.selectedTenant = state.tenants.find((tenant) => tenant.slug === elements.tenant.value) ?? null;
});

function resetTenantSelection() {
    state.tenants = [];
    state.selectedTenant = null;
    elements.tenant.replaceChildren();
    elements.tenantPicker.hidden = true;
    elements.submit.innerHTML = 'Ingresar <span aria-hidden="true">-></span>';
}

elements.email?.addEventListener('input', resetTenantSelection);
elements.password?.addEventListener('input', resetTenantSelection);

elements.togglePassword?.addEventListener('click', () => {
    const isPassword = elements.password.type === 'password';
    elements.password.type = isPassword ? 'text' : 'password';
    elements.togglePassword.textContent = isPassword ? 'Ocultar' : 'Mostrar';
});

elements.logout?.addEventListener('click', async () => {
    const session = JSON.parse(localStorage.getItem(storageKey) || 'null');

    if (session?.token) {
        await api('/api/auth/logout', {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${session.token}`,
                'X-Tenant': session.tenant.slug,
            },
        }).catch(() => null);
    }

    clearSession();
});

const existingSession = JSON.parse(localStorage.getItem(storageKey) || 'null');

if (existingSession?.token) {
    renderSession(existingSession);
}
