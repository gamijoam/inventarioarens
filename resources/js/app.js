const storageKey = 'inventory_system_session';

const state = {
    selectedTenant: null,
    tenants: [],
    session: null,
    activePanel: 'dashboard',
    inventoryFilter: 'all',
    inventoryTrackingType: 'all',
    inventoryView: 'cards',
    inventoryPage: 1,
    inventoryLimit: 12,
    inventorySearchTimer: null,
    productFormMode: 'create',
    productFormOptionsLoaded: false,
    productRateTypes: [],
    productWarrantyPolicies: [],
};

const navigationGroups = [
    {
        label: 'Operación',
        items: [
            { label: 'Resumen', icon: 'dashboard', permissions: ['pos.view', 'sales.view', 'products.view'] },
            { label: 'Centro de Ventas', icon: 'sales', permissions: ['sales.view', 'customers.view', 'accounts_receivable.view', 'warranties.view'] },
            { label: 'POS', icon: 'cart', permissions: ['pos.view', 'pos.checkout'] },
            { label: 'Caja', icon: 'cash', permissions: ['cash_register.view', 'cash_register.open', 'cash_register.close'] },
        ],
    },
    {
        label: 'Inventario',
        items: [
            { label: 'Centro de Inventario', icon: 'inventory', permissions: ['products.view', 'inventory.view'] },
            { label: 'Entradas y salidas', icon: 'arrows', permissions: ['product_entries.view', 'product_exits.view'] },
            { label: 'Traslados', icon: 'transfer', permissions: ['inventory_transfers.view', 'inventory_transfer_requests.view'] },
            { label: 'Kardex', icon: 'kardex', permissions: ['kardex.view'] },
        ],
    },
    {
        label: 'Finanzas',
        items: [
            { label: 'Finanzas', icon: 'dollar', permissions: ['finance_reports.view', 'accounts_receivable.view', 'accounts_payable.view'] },
            { label: 'Compras', icon: 'purchase', permissions: ['purchases.view', 'purchase_returns.view'] },
            { label: 'Proveedores', icon: 'supplier', permissions: ['suppliers.view'] },
            { label: 'Comprobantes', icon: 'receipt', permissions: ['payment_receipts.view'] },
        ],
    },
    {
        label: 'Administración',
        items: [
            { label: 'Configuración', icon: 'settings', permissions: ['settings.manage', 'currency.view', 'warranty_policies.view'] },
            { label: 'Usuarios y roles', icon: 'users', permissions: ['users.view', 'roles.view'] },
            { label: 'Panel empresarial', icon: 'business', permissions: ['settings.manage', 'ai.configure'] },
        ],
    },
];


const shortcutDefinitions = [
    { label: 'Abrir POS', detail: 'Venta rápida y pagos', permissions: ['pos.checkout'] },
    { label: 'Productos', detail: 'Catálogo, precios y seriales', permissions: ['products.view'] },
    { label: 'Recepción IMEI', detail: 'Entradas serializadas', permissions: ['product_entries.create'] },
    { label: 'Kardex', detail: 'Historial de movimientos', permissions: ['kardex.view'] },
    { label: 'Garantías', detail: 'Casos y políticas', permissions: ['warranties.view', 'warranty_policies.view'] },
    { label: 'Usuarios', detail: 'Roles y permisos', permissions: ['users.view', 'roles.view'] },
];

const devPermissions = [
    'products.view',
    'products.create',
    'products.update',
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
    dashboardStatus: document.querySelector('#dashboard-status'),
    panels: document.querySelectorAll('[data-panel]'),
    inventorySearch: document.querySelector('#inventory-search'),
    inventoryStatus: document.querySelector('#inventory-status'),
    inventoryProducts: document.querySelector('#inventory-products'),
    inventoryFilters: document.querySelectorAll('[data-inventory-filter]'),
    inventoryTrackingFilters: document.querySelectorAll('[data-inventory-tracking]'),
    inventoryViewButtons: document.querySelectorAll('[data-inventory-view]'),
    inventoryPagination: document.querySelector('#inventory-pagination'),
    inventoryPaginationSummary: document.querySelector('#inventory-pagination-summary'),
    inventoryPrevPage: document.querySelector('#inventory-prev-page'),
    inventoryNextPage: document.querySelector('#inventory-next-page'),
    openProductForm: document.querySelector('#open-product-form'),
    productModal: document.querySelector('#product-modal'),
    closeProductForm: document.querySelector('#close-product-form'),
    cancelProductForm: document.querySelector('#cancel-product-form'),
    productForm: document.querySelector('#product-form'),
    productFormTitle: document.querySelector('#product-modal-title'),
    productFormSubtitle: document.querySelector('#product-modal-subtitle'),
    productFormMessage: document.querySelector('#product-form-message'),
    productId: document.querySelector('#product-id'),
    productName: document.querySelector('#product-name'),
    productSku: document.querySelector('#product-sku'),
    productTrackingType: document.querySelector('#product-tracking-type'),
    productBasePrice: document.querySelector('#product-base-price'),
    productSaleCurrency: document.querySelector('#product-sale-currency'),
    productRateType: document.querySelector('#product-rate-type'),
    productWarrantyPolicy: document.querySelector('#product-warranty-policy'),
    productIsActive: document.querySelector('#product-is-active'),
    saveProductButton: document.querySelector('#save-product-button'),
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

async function authenticatedApi(path, session, options = {}) {
    return api(path, {
        ...options,
        headers: {
            Authorization: `Bearer ${session.token}`,
            'X-Tenant': session.tenant.slug,
            ...(options.headers ?? {}),
        },
    });
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
    loadDashboardSummary(session);
    showPanel('dashboard');
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

function demoDashboardSummary() {
    return {
        currency: 'USD',
        period: { from: new Date().toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) },
        sales: { confirmed_count: 0, total_base_amount: 0 },
        pos: { paid_orders_count: 0, paid_base_amount: 0 },
        cash_register: { open_sessions_count: 0 },
        inventory: {
            low_stock_count: 1,
            low_stock_threshold: 3,
            low_stock_items: [
                {
                    product_name: 'Producto demo bajo stock',
                    sku: 'DEMO-001',
                    warehouse_name: 'Almacén demo',
                    quantity_available: 2,
                },
            ],
        },
        finance: {
            accounts_receivable_balance_base_amount: 0,
            accounts_payable_balance_base_amount: 0,
            accounts_receivable_count: 0,
            accounts_payable_count: 0,
        },
    };
}

function demoInventoryCenter() {
    const products = [
        {
            id: 1,
            name: 'Samsung A06 128GB',
            sku: 'A06-DEMO',
            tracking_type: 'serialized',
            base_price: 120,
            sale_currency: 'USD',
            stock: { available: 30, reserved: 2, damaged: 0, status: 'available' },
        },
        {
            id: 2,
            name: 'Adaptador Tipo C',
            sku: 'ACC-001',
            tracking_type: 'quantity',
            base_price: 5,
            sale_currency: 'USD',
            stock: { available: 18, reserved: 1, damaged: 0, status: 'available' },
        },
        {
            id: 3,
            name: 'Cable HDMI',
            sku: 'CAB-HDMI',
            tracking_type: 'quantity',
            base_price: 10,
            sale_currency: 'USD',
            stock: { available: 2, reserved: 1, damaged: 1, status: 'low' },
        },
        {
            id: 4,
            name: 'iPhone revision',
            sku: 'IMEI-DEMO',
            tracking_type: 'serialized',
            base_price: 450,
            sale_currency: 'USD',
            stock: { available: 0, reserved: 0, damaged: 0, status: 'out' },
        },
    ];

    const filteredProducts = products.filter((product) => {
        const search = elements.inventorySearch?.value.trim().toLowerCase();
        const matchesSearch = !search || `${product.name} ${product.sku}`.toLowerCase().includes(search);
        const matchesStatus = state.inventoryFilter === 'all' || product.stock.status === state.inventoryFilter;
        const matchesTracking = state.inventoryTrackingType === 'all' || product.tracking_type === state.inventoryTrackingType;

        return matchesSearch && matchesStatus && matchesTracking;
    });

    const total = filteredProducts.length;
    const lastPage = Math.max(Math.ceil(total / state.inventoryLimit), 1);
    state.inventoryPage = Math.min(state.inventoryPage, lastPage);
    const start = (state.inventoryPage - 1) * state.inventoryLimit;

    return {
        filters: {
            stock_status: state.inventoryFilter,
            tracking_type: state.inventoryTrackingType === 'all' ? null : state.inventoryTrackingType,
            low_stock_threshold: 3,
            limit: state.inventoryLimit,
            page: state.inventoryPage,
        },
        metrics: {
            total_products: 5,
            serialized_products: 2,
            quantity_products: 3,
            available_quantity: 52,
            reserved_quantity: 4,
            damaged_quantity: 1,
            low_stock_count: 1,
            without_stock_count: 1,
        },
        products: filteredProducts.slice(start, start + state.inventoryLimit),
        pagination: {
            page: state.inventoryPage,
            limit: state.inventoryLimit,
            total,
            last_page: lastPage,
            from: total === 0 ? 0 : start + 1,
            to: Math.min(start + state.inventoryLimit, total),
            has_previous: state.inventoryPage > 1,
            has_next: state.inventoryPage < lastPage,
        },
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

function iconSvg(name) {
    const icons = {
        dashboard: '<svg viewBox="0 0 24 24"><path d="M4 4h7v7H4z"></path><path d="M13 4h7v7h-7z"></path><path d="M4 13h7v7H4z"></path><path d="M13 13h7v7h-7z"></path></svg>',
        sales: '<svg viewBox="0 0 24 24"><path d="M4 19V5"></path><path d="M8 17l4-4 3 3 5-6"></path><path d="M16 10h4v4"></path></svg>',
        cart: '<svg viewBox="0 0 24 24"><path d="M6 6h15l-2 8H8L6 6Z"></path><path d="M6 6 5 3H2"></path><path d="M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2ZM18 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"></path></svg>',
        cash: '<svg viewBox="0 0 24 24"><path d="M4 7h16v10H4z"></path><path d="M8 12h8"></path><path d="M12 9v6"></path></svg>',
        inventory: '<svg viewBox="0 0 24 24"><path d="M12 3 4 7l8 4 8-4-8-4Z"></path><path d="M4 7v10l8 4 8-4V7"></path><path d="M12 11v10"></path></svg>',
        arrows: '<svg viewBox="0 0 24 24"><path d="M7 7h13"></path><path d="m17 4 3 3-3 3"></path><path d="M17 17H4"></path><path d="m7 14-3 3 3 3"></path></svg>',
        transfer: '<svg viewBox="0 0 24 24"><path d="M16 3h5v5"></path><path d="M21 3 14 10"></path><path d="M8 21H3v-5"></path><path d="M3 21l7-7"></path></svg>',
        kardex: '<svg viewBox="0 0 24 24"><path d="M6 3h12v18H6z"></path><path d="M9 8h6"></path><path d="M9 12h6"></path><path d="M9 16h4"></path></svg>',
        dollar: '<svg viewBox="0 0 24 24"><path d="M12 3v18"></path><path d="M17 7.5c-1-1-2.5-1.5-4.5-1.5-2.5 0-4 1.1-4 2.8 0 4.1 9 1.6 9 6.4 0 1.7-1.6 2.8-4.5 2.8-2 0-3.8-.6-5-1.7"></path></svg>',
        purchase: '<svg viewBox="0 0 24 24"><path d="M7 7h14l-2 7H8L7 7Z"></path><path d="M7 7 6 4H3"></path><path d="M9 20h8"></path></svg>',
        supplier: '<svg viewBox="0 0 24 24"><path d="M4 18V7l8-4 8 4v11"></path><path d="M8 21v-7h8v7"></path><path d="M9 9h6"></path></svg>',
        receipt: '<svg viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"></path><path d="M9 8h6"></path><path d="M9 12h6"></path></svg>',
        settings: '<svg viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"></path><path d="M4 12h2M18 12h2M12 4v2M12 18v2M6.6 6.6 8 8M16 16l1.4 1.4M17.4 6.6 16 8M8 16l-1.4 1.4"></path></svg>',
        users: '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path><path d="M22 21v-2a4 4 0 0 0-3-3.9"></path><path d="M16 3.1a4 4 0 0 1 0 7.8"></path></svg>',
        business: '<svg viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h8v18"></path><path d="M14 9h4a2 2 0 0 1 2 2v10"></path><path d="M8 7h2M8 11h2M8 15h2"></path></svg>',
    };

    return icons[name] ?? icons.dashboard;
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
                button.innerHTML = `<span aria-hidden="true">${iconSvg(item.icon)}</span><strong>${item.label}</strong>`;
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

    const panel = label === 'Centro de Inventario' ? 'inventory' : 'dashboard';

    showPanel(panel);

    if (panel === 'dashboard') {
        document.querySelector('#dashboard-title').textContent = label === 'Resumen' ? 'Resumen del negocio' : label;
    }
}

function showPanel(panel) {
    state.activePanel = panel;

    elements.panels.forEach((element) => {
        element.hidden = element.dataset.panel !== panel;
    });

    if (panel === 'inventory' && state.session) {
        loadInventoryCenter(state.session);
    }
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

async function loadDashboardSummary(session) {
    setDashboardStatus(session.is_local_demo ? 'Mostrando datos demo locales.' : 'Cargando resumen del negocio...');

    if (session.is_local_demo) {
        renderDashboardSummary(demoDashboardSummary(), session.permissions);
        return;
    }

    try {
        const summary = await authenticatedApi('/api/dashboard/summary?period=today&low_stock_threshold=3', session);
        renderDashboardSummary(summary, session.permissions);
        setDashboardStatus(`Periodo ${summary.period.from} a ${summary.period.to}.`);
    } catch (error) {
        setDashboardStatus(error.message, 'error');
    }
}

function renderDashboardSummary(summary, userPermissions) {
    setDashboardValue('sales_total', money(summary.sales.total_base_amount));
    setDashboardDetail('sales_count', `${summary.sales.confirmed_count} ventas confirmadas`);
    setDashboardValue('pos_paid', money(summary.pos.paid_base_amount));
    setDashboardDetail('pos_count', `${summary.pos.paid_orders_count} órdenes POS pagadas`);
    setDashboardValue('transactions', summary.sales.confirmed_count + summary.pos.paid_orders_count);
    setDashboardDetail('cash_register', `${summary.cash_register.open_sessions_count} cajas abiertas`);

    const pendingBalance = summary.finance.accounts_receivable_balance_base_amount + summary.finance.accounts_payable_balance_base_amount;
    setDashboardValue('pending_balance', money(pendingBalance));
    setDashboardDetail(
        'pending_counts',
        `${summary.finance.accounts_receivable_count} CxC / ${summary.finance.accounts_payable_count} CxP`,
    );

    renderDashboardAttention(summary, userPermissions);
}

function renderDashboardAttention(summary, userPermissions) {
    const items = [
        {
            label: 'Stock bajo',
            detail: `${summary.inventory.low_stock_count} productos en o por debajo de ${summary.inventory.low_stock_threshold}`,
            permissions: ['products.view', 'inventory.view'],
            tone: summary.inventory.low_stock_count > 0 ? 'danger' : 'neutral',
        },
        {
            label: summary.cash_register.open_sessions_count > 0 ? 'Caja abierta' : 'Caja cerrada',
            detail: `${summary.cash_register.open_sessions_count} cajas abiertas actualmente`,
            permissions: ['cash_register.view', 'cash_register.open', 'pos.checkout'],
            tone: summary.cash_register.open_sessions_count > 0 ? 'neutral' : 'warning',
        },
        {
            label: 'Pendientes financieros',
            detail: `${summary.finance.accounts_receivable_count} por cobrar y ${summary.finance.accounts_payable_count} por pagar`,
            permissions: ['finance_reports.view', 'accounts_receivable.view', 'accounts_payable.view'],
            tone: summary.finance.accounts_receivable_count + summary.finance.accounts_payable_count > 0 ? 'warning' : 'neutral',
        },
    ].filter((item) => canAccess(userPermissions, item.permissions));

    const lowStockDetails = summary.inventory.low_stock_items.map((item) => ({
        label: item.product_name,
        detail: `${item.quantity_available} disponibles en ${item.warehouse_name}${item.sku ? ` · ${item.sku}` : ''}`,
        permissions: ['products.view', 'inventory.view'],
        tone: 'danger',
    }));

    renderAttentionCards([...items, ...lowStockDetails].filter((item) => canAccess(userPermissions, item.permissions)));
}

function renderAttentionCards(items) {
    elements.attentionList.replaceChildren(
        ...items.map((item) => {
            const article = document.createElement('article');
            article.className = `attention-card attention-card--${item.tone}`;
            article.innerHTML = `<strong>${item.label}</strong><span>${item.detail}</span>`;
            return article;
        }),
    );
}

function setDashboardValue(key, value) {
    const element = document.querySelector(`[data-dashboard-value="${key}"]`);

    if (element) {
        element.textContent = value;
    }
}

function setDashboardDetail(key, value) {
    const element = document.querySelector(`[data-dashboard-detail="${key}"]`);

    if (element) {
        element.textContent = value;
    }
}

function setDashboardStatus(message, tone = 'neutral') {
    elements.dashboardStatus.textContent = message;
    elements.dashboardStatus.dataset.tone = tone;
}

async function loadInventoryCenter(session) {
    setInventoryStatus(session.is_local_demo ? 'Mostrando inventario demo local.' : 'Cargando inventario desde la base de datos...');

    if (session.is_local_demo) {
        renderInventoryCenter(demoInventoryCenter());
        return;
    }

    const params = new URLSearchParams({
        limit: String(state.inventoryLimit),
        page: String(state.inventoryPage),
        low_stock_threshold: '3',
        stock_status: state.inventoryFilter,
    });

    const search = elements.inventorySearch?.value.trim();

    if (search) {
        params.set('search', search);
    }

    if (state.inventoryTrackingType !== 'all') {
        params.set('tracking_type', state.inventoryTrackingType);
    }

    try {
        const summary = await authenticatedApi(`/api/inventory-center/summary?${params.toString()}`, session);
        state.inventoryPage = summary.pagination.page;
        renderInventoryCenter(summary);
        setInventoryStatus(`${summary.products.length} productos cargados desde la base de datos.`);
    } catch (error) {
        setInventoryStatus(error.message, 'error');
    }
}

function renderInventoryCenter(summary) {
    setInventoryMetric('total_products', summary.metrics.total_products);
    setInventoryMetric('available_quantity', stockNumber(summary.metrics.available_quantity));
    setInventoryMetric('low_stock_count', summary.metrics.low_stock_count);
    setInventoryMetric('damaged_quantity', stockNumber(summary.metrics.damaged_quantity));
    setInventoryDetail('serialized_products', `${summary.metrics.serialized_products} serializados`);
    setInventoryDetail('reserved_quantity', `${stockNumber(summary.metrics.reserved_quantity)} reservados`);
    setInventoryDetail('without_stock_count', `${summary.metrics.without_stock_count} sin stock`);
    renderInventoryPagination(summary.pagination);

    if (summary.products.length === 0) {
        elements.inventoryProducts.className = 'product-grid';
        elements.inventoryProducts.replaceChildren(emptyInventoryState());
        return;
    }

    elements.inventoryProducts.className = state.inventoryView === 'list' ? 'product-list' : 'product-grid';
    elements.inventoryProducts.replaceChildren(
        state.inventoryView === 'list' ? productList(summary.products) : productCards(summary.products),
    );
}

function productCards(products) {
    const fragment = document.createDocumentFragment();

    products.forEach((product) => {
        const article = document.createElement('article');
        const productName = escapeHtml(product.name);
        const productSku = escapeHtml(product.sku);
        const productInitials = escapeHtml(initials(product.name));
        article.className = `product-card product-card--${product.stock.status}`;
        article.innerHTML = `
            <div class="product-card__top">
                <div class="product-thumb" aria-hidden="true">${productInitials}</div>
                <div>
                    <strong>${productName}</strong>
                    <span>${productSku}</span>
                </div>
            </div>
            <div class="product-card__meta">
                <span>${trackingLabel(product.tracking_type)}</span>
                <span>${priceLabel(product)}</span>
            </div>
            <div class="stock-row">
                <div>
                    <span>Disponible</span>
                    <strong>${stockNumber(product.stock.available)}</strong>
                </div>
                <div>
                    <span>Reservado</span>
                    <strong>${stockNumber(product.stock.reserved)}</strong>
                </div>
                <div>
                    <span>Dañado</span>
                    <strong>${stockNumber(product.stock.damaged)}</strong>
                </div>
            </div>
            ${canEditProducts() ? `<button class="secondary-button compact-action product-edit-button" type="button" data-product-edit="${product.id}">Editar</button>` : ''}
        `;
        fragment.append(article);
    });

    return fragment;
}

function productList(products) {
    const wrapper = document.createElement('div');
    wrapper.className = 'product-table-wrap';
    wrapper.innerHTML = `
        <table class="product-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>SKU</th>
                    <th>Tipo</th>
                    <th>Precio</th>
                    <th>Disponible</th>
                    <th>Reservado</th>
                    <th>Dañado</th>
                    <th>Estado</th>
                    ${canEditProducts() ? '<th>Acciones</th>' : ''}
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    `;

    const tbody = wrapper.querySelector('tbody');
    tbody.replaceChildren(
        ...products.map((product) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(product.name)}</strong></td>
                <td>${escapeHtml(product.sku)}</td>
                <td>${trackingLabel(product.tracking_type)}</td>
                <td>${priceLabel(product)}</td>
                <td>${stockNumber(product.stock.available)}</td>
                <td>${stockNumber(product.stock.reserved)}</td>
                <td>${stockNumber(product.stock.damaged)}</td>
                <td><span class="stock-badge stock-badge--${product.stock.status}">${stockStatusLabel(product.stock.status)}</span></td>
                ${canEditProducts() ? `<td><button class="secondary-button compact-action table-action" type="button" data-product-edit="${product.id}">Editar</button></td>` : ''}
            `;

            return row;
        }),
    );

    return wrapper;
}

function renderInventoryPagination(pagination) {
    if (!pagination) {
        elements.inventoryPagination.hidden = true;
        return;
    }

    elements.inventoryPagination.hidden = false;
    elements.inventoryPaginationSummary.textContent = pagination.total === 0
        ? 'Sin productos para mostrar'
        : `Mostrando ${pagination.from}-${pagination.to} de ${pagination.total} productos`;
    elements.inventoryPrevPage.disabled = !pagination.has_previous;
    elements.inventoryNextPage.disabled = !pagination.has_next;
}

function emptyInventoryState() {
    const article = document.createElement('article');
    article.className = 'empty-state';
    article.innerHTML = '<strong>No hay productos para este filtro</strong><span>Prueba otra búsqueda o revisa los productos activos.</span>';

    return article;
}

function setInventoryMetric(key, value) {
    const element = document.querySelector(`[data-inventory-metric="${key}"]`);

    if (element) {
        element.textContent = value;
    }
}

function setInventoryDetail(key, value) {
    const element = document.querySelector(`[data-inventory-detail="${key}"]`);

    if (element) {
        element.textContent = value;
    }
}

function setInventoryStatus(message, tone = 'neutral') {
    elements.inventoryStatus.textContent = message;
    elements.inventoryStatus.dataset.tone = tone;
}

function setProductFormMessage(message, tone = 'neutral') {
    elements.productFormMessage.textContent = message;
    elements.productFormMessage.dataset.tone = tone;
}

function canEditProducts() {
    return state.session?.permissions?.includes('products.update') ?? false;
}

function canCreateProducts() {
    return state.session?.permissions?.includes('products.create') ?? false;
}

async function openProductForm(productId = null) {
    if (productId && !canEditProducts()) {
        setInventoryStatus('No tienes permiso para editar productos.', 'error');
        return;
    }

    if (!productId && !canCreateProducts()) {
        setInventoryStatus('No tienes permiso para crear productos.', 'error');
        return;
    }

    elements.productModal.hidden = false;
    elements.productForm.reset();
    elements.productId.value = productId ?? '';
    elements.productIsActive.checked = true;
    setProductFormMessage('Cargando opciones...');
    setProductFormBusy(true);

    try {
        await loadProductFormOptions();

        if (productId) {
            state.productFormMode = 'edit';
            elements.productFormTitle.textContent = 'Editar producto';
            elements.productFormSubtitle.textContent = 'Actualiza datos comerciales sin mezclar inventario ni empresas.';
            elements.saveProductButton.textContent = 'Guardar cambios';

            const product = await authenticatedApi(`/api/products/${productId}`, state.session);
            fillProductForm(product);
            setProductFormMessage('Editando producto existente.');
        } else {
            state.productFormMode = 'create';
            elements.productFormTitle.textContent = 'Nuevo producto';
            elements.productFormSubtitle.textContent = 'Crea un producto para venderlo, moverlo y medirlo desde inventario.';
            elements.saveProductButton.textContent = 'Guardar producto';
            elements.productTrackingType.disabled = false;
            setProductFormMessage('Listo para crear.');
        }
    } catch (error) {
        setProductFormMessage(error.message, 'error');
    } finally {
        setProductFormBusy(false);
        elements.productName.focus();
    }
}

function closeProductForm() {
    elements.productModal.hidden = true;
    setProductFormMessage('');
}

async function loadProductFormOptions() {
    if (state.productFormOptionsLoaded || state.session?.is_local_demo) {
        renderProductFormOptions();
        return;
    }

    const [rateTypes, warrantyPolicies] = await Promise.all([
        authenticatedApi('/api/currency/rate-types', state.session)
            .then((payload) => payload.data ?? payload)
            .catch(() => []),
        authenticatedApi('/api/warranty-policies', state.session)
            .then((payload) => payload.data ?? payload)
            .catch(() => []),
    ]);

    state.productRateTypes = rateTypes.filter((type) => type.is_active);
    state.productWarrantyPolicies = warrantyPolicies.filter((policy) => policy.is_active);
    state.productFormOptionsLoaded = true;
    renderProductFormOptions();
}

function renderProductFormOptions() {
    elements.productRateType.replaceChildren(
        optionElement('', 'Usar tasa predeterminada'),
        ...state.productRateTypes.map((type) => optionElement(type.id, `${type.code} - ${type.name}${type.is_default ? ' (predeterminada)' : ''}`)),
    );

    elements.productWarrantyPolicy.replaceChildren(
        optionElement('', 'Sin garantía asignada'),
        ...state.productWarrantyPolicies.map((policy) => optionElement(policy.id, `${policy.name} (${policy.duration_days} días)`)),
    );
}

function optionElement(value, label) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    return option;
}

function fillProductForm(product) {
    elements.productName.value = product.name ?? '';
    elements.productSku.value = product.sku ?? '';
    elements.productTrackingType.value = product.tracking_type ?? 'quantity';
    elements.productTrackingType.disabled = false;
    elements.productBasePrice.value = product.base_price ?? '';
    elements.productSaleCurrency.value = product.sale_currency ?? 'USD';
    elements.productRateType.value = product.sale_exchange_rate_type_id ?? '';
    elements.productWarrantyPolicy.value = product.warranty_policy_id ?? '';
    elements.productIsActive.checked = Boolean(product.is_active);
}

function productFormPayload() {
    return {
        name: elements.productName.value.trim(),
        sku: elements.productSku.value.trim(),
        tracking_type: elements.productTrackingType.value,
        base_price: elements.productBasePrice.value === '' ? null : Number(elements.productBasePrice.value),
        sale_currency: elements.productSaleCurrency.value,
        sale_exchange_rate_type_id: elements.productRateType.value === '' ? null : Number(elements.productRateType.value),
        warranty_policy_id: elements.productWarrantyPolicy.value === '' ? null : Number(elements.productWarrantyPolicy.value),
        is_active: elements.productIsActive.checked,
    };
}

async function saveProductForm() {
    const productId = elements.productId.value;
    const method = productId ? 'PATCH' : 'POST';
    const path = productId ? `/api/products/${productId}` : '/api/products';

    setProductFormBusy(true);
    setProductFormMessage(productId ? 'Guardando cambios...' : 'Creando producto...');

    try {
        await authenticatedApi(path, state.session, {
            method,
            body: JSON.stringify(productFormPayload()),
        });

        closeProductForm();
        state.inventoryPage = productId ? state.inventoryPage : 1;
        await loadInventoryCenter(state.session);
        setInventoryStatus(productId ? 'Producto actualizado correctamente.' : 'Producto creado correctamente.', 'success');
    } catch (error) {
        setProductFormMessage(error.message, 'error');
    } finally {
        setProductFormBusy(false);
    }
}

function setProductFormBusy(isBusy) {
    elements.saveProductButton.disabled = isBusy;
    elements.productForm.querySelectorAll('input, select, button').forEach((control) => {
        if (control.id !== 'close-product-form' && control.id !== 'cancel-product-form') {
            control.disabled = isBusy;
        }
    });
}

function money(value) {
    return `$${Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function stockNumber(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 4,
    });
}

function trackingLabel(type) {
    return type === 'serialized' ? 'Serializado' : 'Por cantidad';
}

function priceLabel(product) {
    return `${product.sale_currency ?? 'USD'} ${Number(product.base_price || 0).toFixed(2)}`;
}

function stockStatusLabel(status) {
    return {
        available: 'Disponible',
        low: 'Bajo stock',
        out: 'Sin stock',
    }[status] ?? 'Disponible';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
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

elements.inventorySearch?.addEventListener('input', () => {
    window.clearTimeout(state.inventorySearchTimer);
    state.inventoryPage = 1;
    state.inventorySearchTimer = window.setTimeout(() => {
        if (state.session && state.activePanel === 'inventory') {
            loadInventoryCenter(state.session);
        }
    }, 260);
});

elements.inventoryFilters.forEach((button) => {
    button.addEventListener('click', () => {
        state.inventoryFilter = button.dataset.inventoryFilter;
        state.inventoryPage = 1;
        elements.inventoryFilters.forEach((filter) => {
            filter.classList.toggle('is-active', filter === button);
        });

        if (state.session && state.activePanel === 'inventory') {
            loadInventoryCenter(state.session);
        }
    });
});

elements.inventoryTrackingFilters.forEach((button) => {
    button.addEventListener('click', () => {
        state.inventoryTrackingType = button.dataset.inventoryTracking;
        state.inventoryPage = 1;
        elements.inventoryTrackingFilters.forEach((filter) => {
            filter.classList.toggle('is-active', filter === button);
        });

        if (state.session && state.activePanel === 'inventory') {
            loadInventoryCenter(state.session);
        }
    });
});

elements.inventoryViewButtons.forEach((button) => {
    button.addEventListener('click', () => {
        state.inventoryView = button.dataset.inventoryView;
        elements.inventoryViewButtons.forEach((viewButton) => {
            viewButton.classList.toggle('is-active', viewButton === button);
        });

        if (state.session && state.activePanel === 'inventory') {
            loadInventoryCenter(state.session);
        }
    });
});

elements.inventoryPrevPage?.addEventListener('click', () => {
    if (state.session && state.inventoryPage > 1) {
        state.inventoryPage -= 1;
        loadInventoryCenter(state.session);
    }
});

elements.inventoryNextPage?.addEventListener('click', () => {
    if (state.session) {
        state.inventoryPage += 1;
        loadInventoryCenter(state.session);
    }
});

elements.openProductForm?.addEventListener('click', () => {
    openProductForm();
});

elements.closeProductForm?.addEventListener('click', closeProductForm);
elements.cancelProductForm?.addEventListener('click', closeProductForm);

elements.productModal?.addEventListener('click', (event) => {
    if (event.target === elements.productModal) {
        closeProductForm();
    }
});

elements.inventoryProducts?.addEventListener('click', (event) => {
    const editButton = event.target.closest('[data-product-edit]');

    if (editButton) {
        openProductForm(editButton.dataset.productEdit);
    }
});

elements.productForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (state.session?.is_local_demo) {
        setProductFormMessage('El modo demo local no guarda productos. Entra con un usuario real para crear o editar.', 'error');
        return;
    }

    await saveProductForm();
});

const existingSession = JSON.parse(localStorage.getItem(storageKey) || 'null');

if (existingSession?.token) {
    renderSession(existingSession);
}
