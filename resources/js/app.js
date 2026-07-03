const storageKey = 'inventory_system_session';

const state = {
    selectedTenant: null,
    tenants: [],
    session: null,
};

const navigationGroups = [
    {
        label: 'Operacion',
        items: [
            { label: 'Resumen', icon: '▦', permissions: ['pos.view', 'sales.view', 'products.view'] },
            { label: 'Centro de Ventas', icon: '▱', permissions: ['sales.view', 'customers.view', 'accounts_receivable.view', 'warranties.view'] },
            { label: 'POS', icon: '▣', permissions: ['pos.view', 'pos.checkout'] },
            { label: 'Caja', icon: '◫', permissions: ['cash_register.view', 'cash_register.open', 'cash_register.close'] },
        ],
    },
    {
        label: 'Inventario',
        items: [
            { label: 'Centro de Inventario', icon: '◈', permissions: ['products.view', 'inventory.view'] },
            { label: 'Entradas y salidas', icon: '⇅', permissions: ['product_entries.view', 'product_exits.view'] },
            { label: 'Traslados', icon: '⇄', permissions: ['inventory_transfers.view', 'inventory_transfer_requests.view'] },
            { label: 'Kardex', icon: '▤', permissions: ['kardex.view'] },
        ],
    },
    {
        label: 'Finanzas',
        items: [
            { label: 'Finanzas', icon: '$', permissions: ['finance_reports.view', 'accounts_receivable.view', 'accounts_payable.view'] },
            { label: 'Compras', icon: '▧', permissions: ['purchases.view', 'purchase_returns.view'] },
            { label: 'Proveedores', icon: '◇', permissions: ['suppliers.view'] },
            { label: 'Comprobantes', icon: '▩', permissions: ['payment_receipts.view'] },
        ],
    },
    {
        label: 'Administracion',
        items: [
            { label: 'Configuracion', icon: '⚙', permissions: ['settings.manage', 'currency.view', 'warranty_policies.view'] },
            { label: 'Usuarios y roles', icon: '◉', permissions: ['users.view', 'roles.view'] },
            { label: 'Panel empresarial', icon: '▥', permissions: ['settings.manage', 'ai.configure'] },
        ],
    },
];

const shortcutDefinitions = [
    { label: 'Abrir POS', detail: 'Venta rapida y pagos', permissions: ['pos.checkout'] },
    { label: 'Productos', detail: 'Catalogo, precios y seriales', permissions: ['products.view'] },
    { label: 'Recepcion IMEI', detail: 'Entradas serializadas', permissions: ['product_entries.create'] },
    { label: 'Kardex', detail: 'Historial de movimientos', permissions: ['kardex.view'] },
    { label: 'Garantias', detail: 'Casos y politicas', permissions: ['warranties.view', 'warranty_policies.view'] },
    { label: 'Usuarios', detail: 'Roles y permisos', permissions: ['users.view', 'roles.view'] },
];

const devPermissions = [
    'products.view',
    'products.create',
    'branches.view',
    'warehouses.view',
    'customers.view',
    'suppliers.view',
    'currency.view',
    'inventory.view',
    'product_entries.view',
    'product_entries.create',
    'product_exits.view',
    'inventory_transfers.view',
    'inventory_transfer_requests.view',
    'purchases.view',
    'purchase_returns.view',
    'accounts_payable.view',
    'accounts_receivable.view',
    'payment_receipts.view',
    'financial_adjustments.view',
    'finance_reports.view',
    'sales.view',
    'sales.create',
    'sales_returns.view',
    'pos.view',
    'pos.checkout',
    'cash_register.view',
    'cash_register.open',
    'reports.view',
    'kardex.view',
    'warranty_policies.view',
    'warranties.view',
    'users.view',
    'roles.view',
    'settings.manage',
    'ai.use',
];

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
    sessionUser: document.querySelector('#session-user'),
    sessionRoles: document.querySelector('#session-roles'),
    sessionTenant: document.querySelector('#session-tenant'),
    sessionPermissions: document.querySelector('#session-permissions'),
    logout: document.querySelector('#logout-button'),
    devAccess: document.querySelector('#dev-access-button'),
    loginWorkspace: document.querySelector('.login-workspace'),
    mainNav: document.querySelector('#main-nav'),
    shortcuts: document.querySelector('#module-shortcuts'),
    attentionList: document.querySelector('#attention-list'),
    userInitials: document.querySelector('#user-initials'),
    sidebarToggle: document.querySelector('#toggle-sidebar'),
    workspace: document.querySelector('.workspace-shell'),
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
    state.session = session;
    elements.loginView.hidden = true;
    elements.sessionView.hidden = false;
    document.body.classList.add('has-workspace');
    elements.sessionSummary.textContent = `${session.user.name} trabaja en ${session.tenant.name}.`;
    elements.sessionUser.textContent = session.user.email;
    elements.sessionRoles.textContent = session.roles.join(', ') || 'Usuario operativo';
    elements.sessionTenant.textContent = session.tenant.name;
    elements.sessionPermissions.textContent = `${session.permissions.length} activos`;
    elements.userInitials.textContent = initials(session.user.name);
    renderNavigation(session.permissions);
    renderShortcuts(session.permissions);
    renderAttention(session.permissions);
    applyPermissionVisibility(session.permissions);
}

function clearSession() {
    localStorage.removeItem(storageKey);
    state.session = null;
    elements.sessionView.hidden = true;
    elements.loginView.hidden = false;
    document.body.classList.remove('has-workspace');
}

function createDevSession() {
    return {
        token: 'local-demo-session',
        token_type: 'Bearer',
        expires_at: null,
        user: {
            name: 'Usuario Demo',
            email: 'demo.local@sistema.test',
        },
        tenant: {
            name: 'Empresa Demo Local',
            slug: 'demo-local',
        },
        roles: ['Demo local'],
        permissions: devPermissions,
        is_local_demo: true,
    };
}

function initials(name) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function canAccess(userPermissions, requiredPermissions = []) {
    return requiredPermissions.length === 0 || requiredPermissions.some((permission) => userPermissions.includes(permission));
}

function renderNavigation(userPermissions) {
    const groups = navigationGroups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => canAccess(userPermissions, item.permissions)),
        }))
        .filter((group) => group.items.length > 0);

    elements.mainNav.replaceChildren(
        ...groups.map((group, groupIndex) => {
            const section = document.createElement('section');
            const title = document.createElement('h2');
            title.textContent = group.label;
            section.append(title);

            group.items.forEach((item, itemIndex) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'nav-item';
                button.dataset.active = groupIndex === 0 && itemIndex === 0 ? 'true' : 'false';
                button.innerHTML = `<span aria-hidden="true">${item.icon}</span><strong>${item.label}</strong>`;
                button.addEventListener('click', () => setActiveNav(button, item.label));
                section.append(button);
            });

            return section;
        }),
    );
}

function setActiveNav(activeButton, label) {
    document.querySelectorAll('.nav-item').forEach((button) => {
        button.dataset.active = button === activeButton ? 'true' : 'false';
    });

    document.querySelector('#dashboard-title').textContent = label === 'Resumen' ? 'Resumen del negocio' : label;
}

function renderShortcuts(userPermissions) {
    const shortcuts = shortcutDefinitions.filter((shortcut) => canAccess(userPermissions, shortcut.permissions));

    elements.shortcuts.replaceChildren(
        ...shortcuts.map((shortcut) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'shortcut-card';
            button.innerHTML = `<strong>${shortcut.label}</strong><span>${shortcut.detail}</span>`;
            return button;
        }),
    );
}

function renderAttention(userPermissions) {
    const items = [
        {
            label: 'Stock bajo',
            detail: 'Productos por debajo del minimo',
            permissions: ['products.view', 'inventory.view'],
            tone: 'danger',
        },
        {
            label: 'Caja cerrada',
            detail: 'Abre una caja antes de vender',
            permissions: ['cash_register.open', 'pos.checkout'],
            tone: 'warning',
        },
        {
            label: 'Permisos activos',
            detail: 'Menu generado desde roles del usuario',
            permissions: [],
            tone: 'neutral',
        },
    ].filter((item) => canAccess(userPermissions, item.permissions));

    elements.attentionList.replaceChildren(
        ...items.map((item) => {
            const article = document.createElement('article');
            article.className = `attention-card attention-card--${item.tone}`;
            article.innerHTML = `<strong>${item.label}</strong><span>${item.detail}</span>`;
            return article;
        }),
    );
}

function applyPermissionVisibility(userPermissions) {
    document.querySelectorAll('[data-requires-any]').forEach((element) => {
        const required = element.dataset.requiresAny.split(' ').filter(Boolean);
        element.hidden = !canAccess(userPermissions, required);
    });
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

elements.devAccess?.addEventListener('click', () => {
    if (elements.loginWorkspace?.dataset.devBypassLogin === 'true') {
        saveSession(createDevSession());
    }
});

elements.sidebarToggle?.addEventListener('click', () => {
    elements.workspace.classList.toggle('is-sidebar-open');
});

const existingSession = JSON.parse(localStorage.getItem(storageKey) || 'null');

if (existingSession?.token) {
    renderSession(existingSession);
}
