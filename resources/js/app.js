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
    productTrackingLocked: false,
    entryOptionsLoaded: false,
    entryOptionsPromise: null,
    entrySearchTimer: null,
    exitSearchTimer: null,
    entryProducts: [],
    entryWarehouses: [],
    exitAvailableUnits: [],
    entryHistory: [],
    exitHistory: [],
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
    'product_exits.create',
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
    logoutButtons: document.querySelectorAll('[data-logout-action]'),
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
    productTrackingHelp: document.querySelector('#product-tracking-help'),
    productBasePrice: document.querySelector('#product-base-price'),
    productSaleCurrency: document.querySelector('#product-sale-currency'),
    productRateType: document.querySelector('#product-rate-type'),
    productWarrantyPolicy: document.querySelector('#product-warranty-policy'),
    productIsActive: document.querySelector('#product-is-active'),
    saveProductButton: document.querySelector('#save-product-button'),
    productDetailModal: document.querySelector('#product-detail-modal'),
    closeProductDetail: document.querySelector('#close-product-detail'),
    productDetailTitle: document.querySelector('#product-detail-title'),
    productDetailSubtitle: document.querySelector('#product-detail-subtitle'),
    productDetailMessage: document.querySelector('#product-detail-message'),
    productDetailContent: document.querySelector('#product-detail-content'),
    operationTabs: document.querySelectorAll('[data-operation-tab]'),
    operationPanels: document.querySelectorAll('[data-operation-panel]'),
    refreshEntryOptions: document.querySelector('#refresh-entry-options'),
    productEntryForm: document.querySelector('#product-entry-form'),
    entryWarehouse: document.querySelector('#entry-warehouse'),
    entryProduct: document.querySelector('#entry-product'),
    entryProductSearch: document.querySelector('#entry-product-search'),
    entryProductResults: document.querySelector('#entry-product-results'),
    entryProductHelp: document.querySelector('#entry-product-help'),
    entryReason: document.querySelector('#entry-reason'),
    entryReference: document.querySelector('#entry-reference'),
    entryQuantity: document.querySelector('#entry-quantity'),
    entryQuantityHelp: document.querySelector('#entry-quantity-help'),
    entryUnitCost: document.querySelector('#entry-unit-cost'),
    entrySerials: document.querySelector('#entry-serials'),
    entrySerialsHelp: document.querySelector('#entry-serials-help'),
    entryImeiConsole: document.querySelector('#entry-imei-console'),
    entryImeiCount: document.querySelector('#entry-imei-count'),
    entryImeiStatus: document.querySelector('#entry-imei-status'),
    entryImeiFeedback: document.querySelector('#entry-imei-feedback'),
    entryImeiPreview: document.querySelector('#entry-imei-preview'),
    normalizeEntrySerials: document.querySelector('#normalize-entry-serials'),
    entryNotes: document.querySelector('#entry-notes'),
    productEntryMessage: document.querySelector('#product-entry-message'),
    clearEntryForm: document.querySelector('#clear-entry-form'),
    saveEntryButton: document.querySelector('#save-entry-button'),
    entryTrackingPill: document.querySelector('#entry-tracking-pill'),
    entrySummaryProduct: document.querySelector('#entry-summary-product'),
    entrySummaryWarehouse: document.querySelector('#entry-summary-warehouse'),
    entrySummaryQuantity: document.querySelector('#entry-summary-quantity'),
    entrySummaryTracking: document.querySelector('#entry-summary-tracking'),
    productExitForm: document.querySelector('#product-exit-form'),
    exitWarehouse: document.querySelector('#exit-warehouse'),
    exitProduct: document.querySelector('#exit-product'),
    exitProductSearch: document.querySelector('#exit-product-search'),
    exitProductResults: document.querySelector('#exit-product-results'),
    exitProductHelp: document.querySelector('#exit-product-help'),
    exitReason: document.querySelector('#exit-reason'),
    exitReference: document.querySelector('#exit-reference'),
    exitQuantity: document.querySelector('#exit-quantity'),
    exitQuantityHelp: document.querySelector('#exit-quantity-help'),
    exitSerialPicker: document.querySelector('#exit-serial-picker'),
    exitSerialsHelp: document.querySelector('#exit-serials-help'),
    exitNotes: document.querySelector('#exit-notes'),
    productExitMessage: document.querySelector('#product-exit-message'),
    clearExitForm: document.querySelector('#clear-exit-form'),
    saveExitButton: document.querySelector('#save-exit-button'),
    exitTrackingPill: document.querySelector('#exit-tracking-pill'),
    exitSummaryProduct: document.querySelector('#exit-summary-product'),
    exitSummaryWarehouse: document.querySelector('#exit-summary-warehouse'),
    exitSummaryQuantity: document.querySelector('#exit-summary-quantity'),
    exitSummaryReason: document.querySelector('#exit-summary-reason'),
    refreshOperationHistory: document.querySelector('#refresh-operation-history'),
    operationHistoryMessage: document.querySelector('#operation-history-message'),
    entryHistoryList: document.querySelector('#entry-history-list'),
    exitHistoryList: document.querySelector('#exit-history-list'),
    operationHistoryDetail: document.querySelector('#operation-history-detail'),
    userInitials: document.querySelector('#user-initials'),
    sidebarToggle: document.querySelector('#toggle-sidebar'),
    workspace: document.querySelector('.workspace-shell'),
};

function setMessage(message, tone = 'neutral') {
    elements.message.textContent = message;
    elements.message.dataset.tone = tone;
}

async function api(path, options = {}) {
    const { headers = {}, ...requestOptions } = options;

    const response = await fetch(path, {
        ...requestOptions,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...headers,
        },
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

    showPanelForLabel(label);
}

function showPanelForLabel(label) {
    const panel = {
        'Centro de Inventario': 'inventory',
        'Entradas y salidas': 'stock-operations',
    }[label] ?? 'dashboard';

    showPanel(panel);

    if (panel === 'dashboard') {
        document.querySelector('#dashboard-title').textContent = label === 'Resumen' ? 'Resumen del negocio' : label;
    }
}

function activateNavigationLabel(label) {
    const navItems = Array.from(document.querySelectorAll('.nav-item'));
    const activeButton = navItems.find((button) => button.textContent.trim() === label);

    if (activeButton) {
        setActiveNav(activeButton, label);
        return;
    }

    showPanelForLabel(label);
}

function showPanel(panel) {
    state.activePanel = panel;

    elements.panels.forEach((element) => {
        element.hidden = element.dataset.panel !== panel;
    });

    if (panel === 'inventory' && state.session) {
        loadInventoryCenter(state.session);
    }

    if (panel === 'stock-operations' && state.session) {
        loadEntryOptions(state.session);
        loadOperationHistory(state.session);
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
            <div class="product-card__actions">
                <button class="secondary-button compact-action product-edit-button" type="button" data-product-detail="${product.id}">Detalle</button>
                ${canCreateProductEntries() ? `<button class="secondary-button compact-action product-edit-button product-stock-button" type="button" data-product-receive="${product.id}">Recibir stock</button>` : ''}
                ${canEditProducts() ? `<button class="secondary-button compact-action product-edit-button" type="button" data-product-edit="${product.id}">Editar</button>` : ''}
            </div>
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
                    <th>Acciones</th>
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
                <td>
                    <div class="table-actions">
                        <button class="secondary-button compact-action table-action" type="button" data-product-detail="${product.id}">Detalle</button>
                        ${canCreateProductEntries() ? `<button class="secondary-button compact-action table-action product-stock-button" type="button" data-product-receive="${product.id}">Recibir</button>` : ''}
                        ${canEditProducts() ? `<button class="secondary-button compact-action table-action" type="button" data-product-edit="${product.id}">Editar</button>` : ''}
                    </div>
                </td>
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

function setProductEntryMessage(message, tone = 'neutral') {
    elements.productEntryMessage.textContent = message;
    elements.productEntryMessage.dataset.tone = tone;
}

function setProductExitMessage(message, tone = 'neutral') {
    elements.productExitMessage.textContent = message;
    elements.productExitMessage.dataset.tone = tone;
}

function setOperationHistoryMessage(message, tone = 'neutral') {
    elements.operationHistoryMessage.textContent = message;
    elements.operationHistoryMessage.dataset.tone = tone;
}

function activateOperationTab(targetTab) {
    elements.operationTabs.forEach((tab) => {
        const isActive = tab.dataset.operationTab === targetTab;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', String(isActive));
        tab.tabIndex = isActive ? 0 : -1;
    });

    elements.operationPanels.forEach((panel) => {
        const isActive = panel.dataset.operationPanel === targetTab;
        panel.classList.toggle('is-active', isActive);
        panel.hidden = !isActive;
    });
}

function demoEntryProducts() {
    return [
        { id: 1, name: 'Samsung A06 128GB', sku: 'A06-DEMO', tracking_type: 'serialized' },
        { id: 2, name: 'Adaptador Tipo C', sku: 'ACC-001', tracking_type: 'quantity' },
    ];
}

function demoEntryWarehouses() {
    return [
        { id: 1, name: 'Almacén Principal Demo', code: 'WH-DEMO', branch_name: 'Sucursal Demo', status: 'active' },
    ];
}

async function loadEntryOptions(session, force = false) {
    if (!elements.productEntryForm) {
        return;
    }

    if (state.entryOptionsPromise && !force) {
        await state.entryOptionsPromise;
        return;
    }

    if (state.entryOptionsLoaded && !force) {
        renderEntryOptions();
        updateEntryFormMode();
        return;
    }

    setProductEntryMessage(session.is_local_demo ? 'Mostrando opciones demo locales.' : 'Cargando productos y almacenes...');
    setProductEntryBusy(true);

    state.entryOptionsPromise = (async () => {
        if (session.is_local_demo) {
            state.entryProducts = demoEntryProducts();
            state.entryWarehouses = demoEntryWarehouses();
        } else {
            const [products, warehouses] = await Promise.all([
                authenticatedApi('/api/products?limit=20', session),
                authenticatedApi('/api/warehouses', session),
            ]);

            state.entryProducts = products.filter((product) => product.is_active !== false);
            state.entryWarehouses = warehouses.filter((warehouse) => warehouse.status !== 'inactive');
        }

        state.entryOptionsLoaded = true;
        renderEntryOptions();
        updateEntryFormMode();
        await updateExitFormMode();
        setProductEntryMessage('Listo para registrar una entrada.');
    })();

    try {
        await state.entryOptionsPromise;
    } catch (error) {
        setProductEntryMessage(error.message, 'error');
    } finally {
        state.entryOptionsPromise = null;
        setProductEntryBusy(false);
    }
}

async function loadOperationHistory(session) {
    if (!elements.entryHistoryList) {
        return;
    }

    setOperationHistoryMessage(session.is_local_demo ? 'Mostrando historial demo local.' : 'Cargando historial operativo...');

    if (session.is_local_demo) {
        state.entryHistory = [];
        state.exitHistory = [];
        renderOperationHistory();
        setOperationHistoryMessage('El historial real aparece al entrar con un usuario conectado a la base de datos.');
        return;
    }

    try {
        const [entries, exits] = await Promise.all([
            authenticatedApi('/api/product-entries', session),
            authenticatedApi('/api/product-exits', session),
        ]);

        state.entryHistory = entries;
        state.exitHistory = exits;
        renderOperationHistory();
        setOperationHistoryMessage(`${entries.length} entradas y ${exits.length} salidas recientes cargadas.`);
    } catch (error) {
        setOperationHistoryMessage(error.message, 'error');
    }
}

function renderOperationHistory() {
    elements.entryHistoryList.replaceChildren(
        ...(state.entryHistory.length > 0
            ? state.entryHistory.slice(0, 8).map((entry) => historyCard('entry', entry))
            : [emptyHistoryCard('No hay entradas recientes.')]),
    );

    elements.exitHistoryList.replaceChildren(
        ...(state.exitHistory.length > 0
            ? state.exitHistory.slice(0, 8).map((exit) => historyCard('exit', exit))
            : [emptyHistoryCard('No hay salidas recientes.')]),
    );
}

function historyCard(type, record) {
    const button = document.createElement('button');
    const totalQuantity = record.items.reduce((total, item) => total + Number(item.quantity || 0), 0);
    button.type = 'button';
    button.className = `history-card history-card--${type}`;
    button.dataset.historyType = type;
    button.dataset.historyId = record.id;
    button.innerHTML = `
        <span>${type === 'entry' ? 'Entrada' : 'Salida'} · ${escapeHtml(record.document_number)}</span>
        <strong>${type === 'entry' ? escapeHtml(record.reason) : exitReasonLabel(record.reason)}</strong>
        <small>${dateLabel(record.processed_at)} · ${record.items.length} item(s) · ${stockNumber(totalQuantity)} un.</small>
        ${record.reference ? `<em>${escapeHtml(record.reference)}</em>` : ''}
    `;

    return button;
}

function emptyHistoryCard(message) {
    const article = document.createElement('article');
    article.className = 'history-empty';
    article.textContent = message;
    return article;
}

function showOperationHistoryDetail(type, recordId) {
    const records = type === 'entry' ? state.entryHistory : state.exitHistory;
    const record = records.find((item) => String(item.id) === String(recordId));

    if (!record) {
        return;
    }

    const title = type === 'entry' ? 'Entrada' : 'Salida';
    elements.operationHistoryDetail.innerHTML = `
        <div class="history-detail__header">
            <div>
                <span>${title}</span>
                <strong>${escapeHtml(record.document_number)}</strong>
            </div>
            <small>${dateLabel(record.processed_at)}</small>
        </div>
        <dl class="history-detail__facts">
            <div><dt>Motivo</dt><dd>${type === 'entry' ? escapeHtml(record.reason) : exitReasonLabel(record.reason)}</dd></div>
            <div><dt>Referencia</dt><dd>${escapeHtml(record.reference || 'Sin referencia')}</dd></div>
            <div><dt>Notas</dt><dd>${escapeHtml(record.notes || 'Sin notas')}</dd></div>
        </dl>
        <div class="history-items">
            ${record.items.map((item) => historyItemHtml(type, item)).join('')}
        </div>
    `;
}

function historyItemHtml(type, item) {
    const product = item.product?.name ?? `Producto #${item.product_id}`;
    const sku = item.product?.sku ?? '';
    const warehouse = item.warehouse?.name ?? `Almacén #${item.warehouse_id}`;
    const serials = type === 'entry'
        ? (item.serial_units ?? []).map((unit) => unit.serial_number)
        : (item.product_unit_ids ?? []).map((id) => `Unidad #${id}`);

    return `
        <article class="history-item">
            <div>
                <strong>${escapeHtml(product)}</strong>
                <span>${escapeHtml(sku)} · ${escapeHtml(warehouse)}</span>
            </div>
            <div>
                <strong>${stockNumber(item.quantity)}</strong>
                <span>${type === 'entry' && item.unit_cost !== null ? `Costo ${money(item.unit_cost)}` : 'unidades'}</span>
            </div>
            ${serials.length > 0 ? `<p>${serials.map(escapeHtml).join(', ')}</p>` : ''}
        </article>
    `;
}

function renderEntryOptions() {
    elements.entryWarehouse.replaceChildren(
        optionElement('', 'Selecciona un almacén'),
        ...state.entryWarehouses.map((warehouse) => optionElement(warehouse.id, `${warehouse.name} · ${warehouse.code}${warehouse.branch_name ? ` · ${warehouse.branch_name}` : ''}`)),
    );

    elements.exitWarehouse.replaceChildren(
        optionElement('', 'Selecciona un almacén'),
        ...state.entryWarehouses.map((warehouse) => optionElement(warehouse.id, `${warehouse.name} · ${warehouse.code}${warehouse.branch_name ? ` · ${warehouse.branch_name}` : ''}`)),
    );

    renderProductSearchResults('entry', state.entryProducts);
    renderProductSearchResults('exit', state.entryProducts);
}

function selectedEntryProduct() {
    return state.entryProducts.find((product) => String(product.id) === String(elements.entryProduct.value)) ?? null;
}

function selectedEntryWarehouse() {
    return state.entryWarehouses.find((warehouse) => String(warehouse.id) === String(elements.entryWarehouse.value)) ?? null;
}

function selectedExitProduct() {
    return state.entryProducts.find((product) => String(product.id) === String(elements.exitProduct.value)) ?? null;
}

function selectedExitWarehouse() {
    return state.entryWarehouses.find((warehouse) => String(warehouse.id) === String(elements.exitWarehouse.value)) ?? null;
}

async function searchProductsForOperation(kind, term) {
    const cleanTerm = term.trim();

    if (cleanTerm.length < 2) {
        renderProductSearchResults(kind, state.entryProducts.slice(0, 8));
        return;
    }

    const target = kind === 'entry' ? elements.entryProductHelp : elements.exitProductHelp;
    target.textContent = 'Buscando productos...';

    try {
        const products = state.session?.is_local_demo
            ? demoEntryProducts().filter((product) => productSearchText(product).includes(cleanTerm.toLowerCase()))
            : await authenticatedApi(`/api/products?search=${encodeURIComponent(cleanTerm)}&limit=8`, state.session);

        products.forEach((product) => {
            if (!state.entryProducts.some((known) => String(known.id) === String(product.id))) {
                state.entryProducts.push(product);
            }
        });

        renderProductSearchResults(kind, products);
        target.textContent = products.length > 0 ? `${products.length} resultado(s).` : 'Sin resultados para esa búsqueda.';
    } catch (error) {
        target.textContent = error.message;
    }
}

function productSearchText(product) {
    return `${product.name ?? ''} ${product.sku ?? ''}`.toLowerCase();
}

function renderProductSearchResults(kind, products) {
    const results = kind === 'entry' ? elements.entryProductResults : elements.exitProductResults;
    const selectedId = kind === 'entry' ? elements.entryProduct.value : elements.exitProduct.value;

    results.replaceChildren(
        ...(products.length > 0
            ? products.slice(0, 8).map((product) => productSearchResultButton(kind, product, selectedId))
            : [emptyProductSearchResult()]),
    );
}

function productSearchResultButton(kind, product, selectedId) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'product-search__option';
    button.dataset.productSearchKind = kind;
    button.dataset.productId = product.id;
    button.dataset.selected = String(product.id) === String(selectedId) ? 'true' : 'false';
    button.innerHTML = `
        <strong>${escapeHtml(product.name)}</strong>
        <span>${escapeHtml(product.sku)} · ${trackingLabel(product.tracking_type)}</span>
    `;

    return button;
}

function emptyProductSearchResult() {
    const article = document.createElement('article');
    article.className = 'product-search__empty';
    article.textContent = 'No hay productos para mostrar.';
    return article;
}

async function selectProductForOperation(kind, productId) {
    const product = state.entryProducts.find((item) => String(item.id) === String(productId));

    if (!product) {
        return;
    }

    if (kind === 'entry') {
        elements.entryProduct.value = String(product.id);
        elements.entryProductSearch.value = `${product.name} · ${product.sku}`;
        renderProductSearchResults('entry', [product]);
        updateEntryFormMode();
        return;
    }

    elements.exitProduct.value = String(product.id);
    elements.exitProductSearch.value = `${product.name} · ${product.sku}`;
    renderProductSearchResults('exit', [product]);
    await updateExitFormMode();
}

function serialLines() {
    return elements.entrySerials.value
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
}

function entrySerialAnalysis() {
    const rawLines = elements.entrySerials.value.split(/\r?\n/);
    const lines = rawLines
        .map((raw, index) => ({ line: index + 1, value: raw.trim() }))
        .filter((item) => item.value !== '');
    const duplicateValues = lines
        .map((item) => item.value)
        .filter((value, index, values) => values.indexOf(value) !== index);
    const duplicates = lines.filter((item) => duplicateValues.includes(item.value));
    const invalid = lines.filter((item) => {
        const value = item.value;
        const isNumeric = /^\d+$/.test(value);

        if (/\s/.test(value)) {
            return true;
        }

        if (!/^[A-Za-z0-9._-]+$/.test(value)) {
            return true;
        }

        return isNumeric && (value.length < 14 || value.length > 18);
    });

    return {
        lines,
        validLines: lines.filter((item) => !duplicates.some((duplicate) => duplicate.line === item.line) && !invalid.some((bad) => bad.line === item.line)),
        duplicates,
        invalid,
    };
}

function renderEntrySerialAssistant(isSerialized) {
    const analysis = entrySerialAnalysis();
    const total = analysis.lines.length;
    const duplicateCount = analysis.duplicates.length;
    const invalidCount = analysis.invalid.length;

    elements.entryImeiConsole?.classList.toggle('is-disabled', !isSerialized);
    elements.entryImeiConsole?.classList.toggle('has-error', isSerialized && (duplicateCount > 0 || invalidCount > 0));
    elements.normalizeEntrySerials.disabled = !isSerialized || total === 0;
    elements.entryImeiCount.textContent = `${total} ${total === 1 ? 'IMEI' : 'IMEIs'}`;

    if (!isSerialized) {
        elements.entryImeiStatus.textContent = 'La carga masiva se activa solo para productos serializados.';
        elements.entryImeiFeedback.replaceChildren();
        elements.entryImeiPreview.replaceChildren();
        return;
    }

    elements.entryImeiStatus.textContent = total > 0
        ? `${analysis.validLines.length} listos, ${duplicateCount} repetidos, ${invalidCount} por revisar.`
        : 'Pega una lista de IMEIs. La cantidad se calcula automáticamente.';

    const messages = [];

    if (duplicateCount > 0) {
        messages.push(feedbackBadge('error', `${duplicateCount} repetido${duplicateCount === 1 ? '' : 's'}: líneas ${analysis.duplicates.map((item) => item.line).join(', ')}`));
    }

    if (invalidCount > 0) {
        messages.push(feedbackBadge('warning', `${invalidCount} por revisar: líneas ${analysis.invalid.map((item) => item.line).join(', ')}`));
    }

    if (total > 0 && duplicateCount === 0 && invalidCount === 0) {
        messages.push(feedbackBadge('success', 'Lista válida para registrar.'));
    }

    elements.entryImeiFeedback.replaceChildren(...messages);
    elements.entryImeiPreview.replaceChildren(
        ...analysis.lines.slice(0, 36).map((item) => {
            const chip = document.createElement('span');
            const isDuplicate = analysis.duplicates.some((duplicate) => duplicate.line === item.line);
            const isInvalid = analysis.invalid.some((bad) => bad.line === item.line);

            chip.className = 'imei-chip';
            chip.dataset.tone = isDuplicate ? 'error' : (isInvalid ? 'warning' : 'success');
            chip.textContent = item.value;
            return chip;
        }),
        ...(analysis.lines.length > 36 ? [feedbackBadge('neutral', `+${analysis.lines.length - 36} más`)] : []),
    );
}

function feedbackBadge(tone, message) {
    const badge = document.createElement('span');
    badge.className = 'inline-feedback';
    badge.dataset.tone = tone;
    badge.textContent = message;
    return badge;
}

function normalizeEntrySerialList() {
    const unique = [];

    serialLines().forEach((serial) => {
        if (!unique.includes(serial)) {
            unique.push(serial);
        }
    });

    elements.entrySerials.value = unique.join('\n');
    updateEntryFormMode();
    setProductEntryMessage(unique.length > 0
        ? `Lista limpiada: ${unique.length} IMEIs únicos.`
        : 'Lista de IMEIs vacía.');
}

function updateEntryFormMode() {
    const product = selectedEntryProduct();
    const warehouse = selectedEntryWarehouse();
    const isSerialized = product?.tracking_type === 'serialized';
    const serialCount = serialLines().length;

    elements.entrySerials.disabled = !isSerialized;
    elements.entrySerialsHelp.textContent = isSerialized
        ? 'Escribe un IMEI o serial por línea. La cantidad se ajusta automáticamente.'
        : 'Solo se habilita para productos serializados/IMEI.';
    elements.entryQuantity.readOnly = isSerialized;
    elements.entryQuantityHelp.textContent = isSerialized
        ? 'Cantidad calculada por la cantidad de IMEIs escritos.'
        : 'Indica la cantidad recibida en el almacén.';

    if (isSerialized) {
        elements.entryQuantity.value = serialCount > 0 ? String(serialCount) : '';
    }

    renderEntrySerialAssistant(isSerialized);
    elements.entryTrackingPill.textContent = isSerialized ? 'Serializado / IMEI' : 'Cantidad';
    elements.entrySummaryProduct.textContent = product ? `${product.name} · ${product.sku}` : 'Selecciona un producto';
    elements.entrySummaryWarehouse.textContent = warehouse ? `${warehouse.name} · ${warehouse.code}` : 'Selecciona un almacén';
    elements.entrySummaryQuantity.textContent = stockNumber(Number(elements.entryQuantity.value || 0));
    elements.entrySummaryTracking.textContent = product ? trackingLabel(product.tracking_type) : 'Por cantidad';
}

async function updateExitFormMode() {
    const product = selectedExitProduct();
    const warehouse = selectedExitWarehouse();
    const isSerialized = product?.tracking_type === 'serialized';

    elements.exitQuantity.readOnly = isSerialized;
    elements.exitQuantityHelp.textContent = isSerialized
        ? 'Cantidad calculada por los IMEIs seleccionados.'
        : 'Indica la cantidad que saldrá del almacén.';
    elements.exitSerialsHelp.textContent = isSerialized
        ? 'Selecciona las unidades disponibles que salen del almacén.'
        : 'Solo se habilita para productos serializados/IMEI.';
    elements.exitTrackingPill.textContent = isSerialized ? 'Serializado / IMEI' : 'Cantidad';

    if (!isSerialized) {
        state.exitAvailableUnits = [];
        elements.exitSerialPicker.innerHTML = '<p>Este producto se descuenta por cantidad.</p>';
    } else if (product && warehouse) {
        await loadExitSerialUnits(product.id, warehouse.id);
    } else {
        state.exitAvailableUnits = [];
        elements.exitSerialPicker.innerHTML = '<p>Selecciona producto y almacén para ver IMEIs disponibles.</p>';
    }

    updateExitSummary();
}

async function loadExitSerialUnits(productId, warehouseId) {
    setProductExitMessage('Cargando IMEIs disponibles...');

    try {
        const detail = state.session?.is_local_demo
            ? { serials: { items: [] } }
            : await authenticatedApi(`/api/inventory-center/products/${productId}`, state.session);

        state.exitAvailableUnits = detail.serials.items.filter((unit) => (
            unit.status === 'available'
            && String(unit.warehouse_id) === String(warehouseId)
        ));

        renderExitSerialPicker();
        setProductExitMessage(state.exitAvailableUnits.length > 0
            ? `${state.exitAvailableUnits.length} IMEIs disponibles para salida.`
            : 'No hay IMEIs disponibles para este producto en el almacén seleccionado.');
    } catch (error) {
        state.exitAvailableUnits = [];
        elements.exitSerialPicker.innerHTML = '<p>No se pudieron cargar los IMEIs.</p>';
        setProductExitMessage(error.message, 'error');
    }
}

function renderExitSerialPicker() {
    if (state.exitAvailableUnits.length === 0) {
        elements.exitSerialPicker.innerHTML = '<p>No hay IMEIs disponibles para este producto en el almacén seleccionado.</p>';
        elements.exitQuantity.value = '';
        return;
    }

    elements.exitSerialPicker.replaceChildren(
        ...state.exitAvailableUnits.map((unit) => {
            const label = document.createElement('label');
            label.className = 'serial-choice';
            label.innerHTML = `
                <input type="checkbox" value="${unit.id}" data-exit-unit>
                <span>
                    <strong>${escapeHtml(unit.serial_number)}</strong>
                    <small>${serialStatusLabel(unit.status)} · ${escapeHtml(unit.warehouse_name ?? 'Sin almacén')}</small>
                </span>
            `;
            return label;
        }),
    );

    updateExitSummary();
}

function selectedExitUnitIds() {
    return Array.from(elements.exitSerialPicker.querySelectorAll('[data-exit-unit]:checked'))
        .map((checkbox) => Number(checkbox.value));
}

function updateExitSummary() {
    const product = selectedExitProduct();
    const warehouse = selectedExitWarehouse();
    const isSerialized = product?.tracking_type === 'serialized';
    const quantity = isSerialized ? selectedExitUnitIds().length : Number(elements.exitQuantity.value || 0);

    if (isSerialized) {
        elements.exitQuantity.value = quantity > 0 ? String(quantity) : '';
    }

    elements.exitSummaryProduct.textContent = product ? `${product.name} · ${product.sku}` : 'Selecciona un producto';
    elements.exitSummaryWarehouse.textContent = warehouse ? `${warehouse.name} · ${warehouse.code}` : 'Selecciona un almacén';
    elements.exitSummaryQuantity.textContent = stockNumber(quantity);
    elements.exitSummaryReason.textContent = exitReasonLabel(elements.exitReason.value);
}

function productEntryPayload() {
    const product = selectedEntryProduct();
    const isSerialized = product?.tracking_type === 'serialized';
    const serials = serialLines();

    return {
        reason: elements.entryReason.value.trim(),
        reference: elements.entryReference.value.trim() || null,
        notes: elements.entryNotes.value.trim() || null,
        items: [
            {
                warehouse_id: Number(elements.entryWarehouse.value),
                product_id: Number(elements.entryProduct.value),
                quantity: isSerialized ? serials.length : Number(elements.entryQuantity.value),
                unit_cost: elements.entryUnitCost.value === '' ? null : Number(elements.entryUnitCost.value),
                ...(isSerialized ? {
                    serial_units: serials.map((serial) => ({
                        serial_type: 'imei',
                        serial_number: serial,
                    })),
                } : {}),
            },
        ],
    };
}

function productExitPayload() {
    const product = selectedExitProduct();
    const unitIds = selectedExitUnitIds();
    const isSerialized = product?.tracking_type === 'serialized';

    return {
        reason: elements.exitReason.value,
        reference: elements.exitReference.value.trim() || null,
        notes: elements.exitNotes.value.trim() || null,
        items: [
            {
                warehouse_id: Number(elements.exitWarehouse.value),
                product_id: Number(elements.exitProduct.value),
                quantity: isSerialized ? unitIds.length : Number(elements.exitQuantity.value),
                ...(isSerialized ? { product_unit_ids: unitIds } : {}),
            },
        ],
    };
}

function validateProductEntryForm() {
    const product = selectedEntryProduct();
    const serials = serialLines();
    const serialAnalysis = entrySerialAnalysis();

    if (!elements.entryWarehouse.value) {
        return 'Selecciona el almacén destino.';
    }

    if (!product) {
        return 'Selecciona el producto recibido.';
    }

    if (!elements.entryReason.value.trim()) {
        return 'Indica el motivo de la entrada.';
    }

    if (product.tracking_type === 'serialized') {
        if (serials.length === 0) {
            return 'Escribe al menos un IMEI o serial para este producto.';
        }

        if (serialAnalysis.duplicates.length > 0) {
            return `Hay IMEIs o seriales repetidos en las líneas ${serialAnalysis.duplicates.map((item) => item.line).join(', ')}.`;
        }

        if (serialAnalysis.invalid.length > 0) {
            return `Revisa el formato de los IMEIs o seriales en las líneas ${serialAnalysis.invalid.map((item) => item.line).join(', ')}.`;
        }
    } else if (Number(elements.entryQuantity.value) <= 0) {
        return 'Indica una cantidad mayor a cero.';
    }

    return null;
}

function validateProductExitForm() {
    const product = selectedExitProduct();
    const unitIds = selectedExitUnitIds();

    if (!elements.exitWarehouse.value) {
        return 'Selecciona el almacén origen.';
    }

    if (!product) {
        return 'Selecciona el producto que saldrá.';
    }

    if (product.tracking_type === 'serialized') {
        if (unitIds.length === 0) {
            return 'Selecciona al menos un IMEI disponible para esta salida.';
        }
    } else if (Number(elements.exitQuantity.value) <= 0) {
        return 'Indica una cantidad mayor a cero.';
    }

    return null;
}

async function saveProductEntry() {
    const validationMessage = validateProductEntryForm();

    if (validationMessage) {
        setProductEntryMessage(validationMessage, 'error');
        return;
    }

    setProductEntryBusy(true);
    setProductEntryMessage('Registrando entrada...');

    try {
        const entry = await authenticatedApi('/api/product-entries', state.session, {
            method: 'POST',
            body: JSON.stringify(productEntryPayload()),
        });

        resetProductEntryForm(false);
        state.inventoryPage = 1;
        await loadInventoryCenter(state.session);
        await loadOperationHistory(state.session);
        setProductEntryMessage(`Entrada ${entry.document_number} registrada correctamente.`, 'success');
    } catch (error) {
        setProductEntryMessage(error.message, 'error');
    } finally {
        setProductEntryBusy(false);
    }
}

async function saveProductExit() {
    const validationMessage = validateProductExitForm();

    if (validationMessage) {
        setProductExitMessage(validationMessage, 'error');
        return;
    }

    setProductExitBusy(true);
    setProductExitMessage('Registrando salida...');

    try {
        const exit = await authenticatedApi('/api/product-exits', state.session, {
            method: 'POST',
            body: JSON.stringify(productExitPayload()),
        });

        resetProductExitForm(false);
        state.inventoryPage = 1;
        await loadInventoryCenter(state.session);
        await loadOperationHistory(state.session);
        setProductExitMessage(`Salida ${exit.document_number} registrada correctamente.`, 'success');
    } catch (error) {
        setProductExitMessage(error.message, 'error');
    } finally {
        setProductExitBusy(false);
    }
}

function resetProductEntryForm(clearMessage = true) {
    elements.productEntryForm.reset();
    elements.entryProduct.value = '';
    elements.entryProductSearch.value = '';
    elements.entryReason.value = 'Recepción de mercancía';
    elements.entrySerials.value = '';
    renderProductSearchResults('entry', state.entryProducts.slice(0, 8));
    updateEntryFormMode();

    if (clearMessage) {
        setProductEntryMessage('Formulario limpio.');
    }
}

function resetProductExitForm(clearMessage = true) {
    elements.productExitForm.reset();
    elements.exitProduct.value = '';
    elements.exitProductSearch.value = '';
    state.exitAvailableUnits = [];
    elements.exitSerialPicker.innerHTML = '<p>Selecciona un producto serializado y un almacén.</p>';
    renderProductSearchResults('exit', state.entryProducts.slice(0, 8));
    updateExitSummary();

    if (clearMessage) {
        setProductExitMessage('Formulario de salida limpio.');
    }
}

function setProductEntryBusy(isBusy) {
    elements.productEntryForm.querySelectorAll('input, select, textarea, button').forEach((control) => {
        if (control.id !== 'refresh-entry-options') {
            control.disabled = isBusy;
        }
    });

    elements.saveEntryButton.disabled = isBusy;

    if (!isBusy) {
        updateEntryFormMode();
    }
}

function setProductExitBusy(isBusy) {
    elements.productExitForm.querySelectorAll('input, select, textarea, button').forEach((control) => {
        control.disabled = isBusy;
    });

    elements.saveExitButton.disabled = isBusy;

    if (!isBusy) {
        updateExitSummary();
    }
}

function setProductFormMessage(message, tone = 'neutral') {
    elements.productFormMessage.textContent = message;
    elements.productFormMessage.dataset.tone = tone;
}

function setProductDetailMessage(message, tone = 'neutral') {
    elements.productDetailMessage.textContent = message;
    elements.productDetailMessage.dataset.tone = tone;
}

function canEditProducts() {
    return state.session?.permissions?.includes('products.update') ?? false;
}

function canCreateProducts() {
    return state.session?.permissions?.includes('products.create') ?? false;
}

function canCreateProductEntries() {
    return state.session?.permissions?.includes('product_entries.create') ?? false;
}

async function openProductEntryForProduct(productId) {
    if (!canCreateProductEntries()) {
        setInventoryStatus('No tienes permiso para registrar entradas de producto.', 'error');
        return;
    }

    activateNavigationLabel('Entradas y salidas');
    setProductEntryMessage('Preparando recepción del producto...');
    await loadEntryOptions(state.session);

    await selectProductForOperation('entry', productId);

    elements.entryReason.value = 'Recepción de mercancía';
    updateEntryFormMode();
    elements.entryWarehouse.focus();
    setProductEntryMessage('Producto seleccionado. Completa almacén, cantidad o IMEIs para registrar la entrada.', 'success');
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
    state.productTrackingLocked = false;
    elements.productTrackingType.disabled = false;
    elements.productTrackingHelp.textContent = '';
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
            state.productTrackingLocked = false;
            elements.productTrackingType.disabled = false;
            elements.productTrackingHelp.textContent = 'Puedes elegir si el producto se maneja por cantidad o por serial/IMEI.';
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

async function openProductDetail(productId) {
    elements.productDetailModal.hidden = false;
    elements.productDetailTitle.textContent = 'Producto';
    elements.productDetailSubtitle.textContent = 'Consulta stock por almacén, seriales y movimientos recientes.';
    elements.productDetailContent.replaceChildren();
    setProductDetailMessage('Cargando detalle...');

    try {
        const detail = await authenticatedApi(`/api/inventory-center/products/${productId}`, state.session);
        renderProductDetail(detail);
        setProductDetailMessage('');
    } catch (error) {
        setProductDetailMessage(error.message, 'error');
    }
}

function closeProductDetail() {
    elements.productDetailModal.hidden = true;
    elements.productDetailContent.replaceChildren();
    setProductDetailMessage('');
}

function renderProductDetail(detail) {
    const product = detail.product;
    elements.productDetailTitle.textContent = product.name;
    elements.productDetailSubtitle.textContent = `${product.sku} · ${trackingLabel(product.tracking_type)} · ${product.is_active ? 'Activo' : 'Inactivo'}`;

    const wrapper = document.createElement('div');
    wrapper.className = 'product-detail-grid';
    wrapper.innerHTML = `
        <nav class="detail-tabs" aria-label="Secciones del detalle">
            <a href="#detail-summary">Resumen</a>
            <a href="#detail-warehouses">Almacenes</a>
            <a href="#detail-serials">Seriales</a>
            <a href="#detail-movements">Movimientos</a>
        </nav>

        <section class="detail-section detail-section--summary">
            <div class="detail-stat">
                <span>Disponible</span>
                <strong>${stockNumber(detail.stock.totals.available)}</strong>
            </div>
            <div class="detail-stat">
                <span>Reservado</span>
                <strong>${stockNumber(detail.stock.totals.reserved)}</strong>
            </div>
            <div class="detail-stat">
                <span>Dañado</span>
                <strong>${stockNumber(detail.stock.totals.damaged)}</strong>
            </div>
        </section>

        <section class="detail-section" id="detail-summary">
            <h3>Datos generales</h3>
            <dl class="detail-list">
                <div><dt>Precio</dt><dd>${priceLabel(product)}</dd></div>
                <div><dt>Tasa</dt><dd>${product.sale_exchange_rate_type ? `${escapeHtml(product.sale_exchange_rate_type.code)} · ${escapeHtml(product.sale_exchange_rate_type.name)}` : 'Predeterminada'}</dd></div>
                <div><dt>Garantía</dt><dd>${product.warranty_policy ? `${escapeHtml(product.warranty_policy.name)} · ${product.warranty_policy.duration_days} días` : 'Sin garantía'}</dd></div>
                <div><dt>Actualizado</dt><dd>${dateLabel(product.updated_at)}</dd></div>
            </dl>
        </section>

        <section class="detail-section" id="detail-warehouses">
            <h3>Stock por almacén</h3>
            ${warehouseStockHtml(detail.stock.by_warehouse)}
        </section>

        <section class="detail-section" id="detail-serials">
            <h3>Seriales / IMEIs</h3>
            ${serialsHtml(detail.serials, product.tracking_type)}
        </section>

        <section class="detail-section detail-section--wide" id="detail-movements">
            <h3>Movimientos recientes</h3>
            ${movementsHtml(detail.recent_movements)}
        </section>
    `;

    elements.productDetailContent.replaceChildren(wrapper);
}

function warehouseStockHtml(rows) {
    if (rows.length === 0) {
        return '<p class="detail-empty">Este producto todavía no tiene stock por almacén.</p>';
    }

    return `
        <div class="detail-table-wrap">
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Almacén</th>
                        <th>Sucursal</th>
                        <th>Disp.</th>
                        <th>Res.</th>
                        <th>Dañ.</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.map((row) => `
                        <tr>
                            <td><strong>${escapeHtml(row.warehouse_name ?? 'Sin almacén')}</strong><span>${escapeHtml(row.warehouse_code ?? '')}</span></td>
                            <td>${escapeHtml(row.branch_name ?? 'Sin sucursal')}</td>
                            <td>${stockNumber(row.available)}</td>
                            <td>${stockNumber(row.reserved)}</td>
                            <td>${stockNumber(row.damaged)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function serialsHtml(serials, trackingType) {
    if (trackingType !== 'serialized') {
        return '<p class="detail-empty">Este producto se controla por cantidad.</p>';
    }

    if (serials.items.length === 0) {
        return '<p class="detail-empty">No hay seriales registrados para este producto.</p>';
    }

    return `
        <p class="detail-caption">${serials.total} seriales registrados. Mostrando hasta 50.</p>
        <div class="serial-list">
            ${serials.items.map((unit) => `
                <div class="serial-row">
                    <strong>${escapeHtml(unit.serial_number)}</strong>
                    <span>${serialStatusLabel(unit.status)} · ${escapeHtml(unit.warehouse_name ?? 'Sin almacén')}</span>
                </div>
            `).join('')}
        </div>
    `;
}

function movementsHtml(movements) {
    if (movements.length === 0) {
        return '<p class="detail-empty">Todavía no hay movimientos para este producto.</p>';
    }

    return `
        <div class="detail-table-wrap">
            <table class="detail-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Cant.</th>
                        <th>Almacén</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    ${movements.map((movement) => `
                        <tr>
                            <td>${dateLabel(movement.created_at)}</td>
                            <td>${movementTypeLabel(movement.type)}</td>
                            <td>${stockNumber(movement.quantity)}</td>
                            <td>${escapeHtml(movement.warehouse_name ?? 'Sin almacén')}</td>
                            <td>${escapeHtml(movement.reason ?? '')}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
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
    state.productTrackingLocked = product.can_change_tracking_type === false;
    elements.productTrackingType.disabled = state.productTrackingLocked;
    elements.productTrackingHelp.textContent = state.productTrackingLocked
        ? `Este control no se puede cambiar porque el producto ya tiene ${product.units_count ?? 0} unidad(es) asociada(s).`
        : 'Puedes elegir si el producto se maneja por cantidad o por serial/IMEI.';
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
        const savedProduct = await authenticatedApi(path, state.session, {
            method,
            body: JSON.stringify(productFormPayload()),
        });

        closeProductForm();
        focusInventoryOnSavedProduct(savedProduct, Boolean(productId));
        await loadInventoryCenter(state.session);
        setInventoryStatus(
            productId ? 'Producto actualizado correctamente. Mostrando el producto editado.' : 'Producto creado correctamente. Mostrando el producto creado.',
            'success',
        );
    } catch (error) {
        setProductFormMessage(error.message, 'error');
    } finally {
        setProductFormBusy(false);
    }
}

function focusInventoryOnSavedProduct(product, isEditing) {
    state.inventoryPage = 1;

    if (!isEditing) {
        state.inventoryFilter = 'all';
        state.inventoryTrackingType = 'all';
    }

    if (elements.inventorySearch) {
        elements.inventorySearch.value = product.sku ?? product.name ?? '';
    }

    syncInventoryFilterControls();
}

function syncInventoryFilterControls() {
    elements.inventoryFilters.forEach((filter) => {
        filter.classList.toggle('is-active', filter.dataset.inventoryFilter === state.inventoryFilter);
    });

    elements.inventoryTrackingFilters.forEach((filter) => {
        filter.classList.toggle('is-active', filter.dataset.inventoryTracking === state.inventoryTrackingType);
    });
}

function setProductFormBusy(isBusy) {
    elements.saveProductButton.disabled = isBusy;
    elements.productForm.querySelectorAll('input, select, button').forEach((control) => {
        if (control.id !== 'close-product-form' && control.id !== 'cancel-product-form') {
            control.disabled = isBusy || (control === elements.productTrackingType && state.productTrackingLocked);
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

function serialStatusLabel(status) {
    return {
        available: 'Disponible',
        reserved: 'Reservado',
        sold: 'Vendido',
        damaged: 'Dañado',
        removed: 'Removido',
        warranty_hold: 'En garantía',
    }[status] ?? status;
}

function movementTypeLabel(type) {
    return {
        purchase: 'Entrada',
        purchase_return: 'Dev. proveedor',
        sale: 'Venta',
        sale_return: 'Dev. venta',
        adjustment_in: 'Ajuste entrada',
        adjustment_out: 'Ajuste salida',
        transfer_in: 'Traslado entrada',
        transfer_out: 'Traslado salida',
        return_in: 'Retorno entrada',
        return_out: 'Retorno salida',
        damaged: 'Dañado',
        reserved: 'Reservado',
        released: 'Liberado',
    }[type] ?? type;
}

function exitReasonLabel(reason) {
    return {
        damaged: 'Dañado',
        lost: 'Pérdida',
        internal_use: 'Uso interno',
        warranty: 'Garantía',
        administrative: 'Administrativo',
        other: 'Otro',
    }[reason] ?? reason;
}

function dateLabel(value) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Date(value).toLocaleString('es-VE', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
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

async function logoutCurrentSession() {
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
    resetTenantSelection();
    setMessage('Sesión cerrada. Entra con un usuario real para guardar cambios.', 'success');
}

elements.logoutButtons.forEach((button) => {
    button.addEventListener('click', logoutCurrentSession);
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

elements.refreshEntryOptions?.addEventListener('click', () => {
    if (state.session) {
        state.entryOptionsLoaded = false;
        loadEntryOptions(state.session, true);
    }
});

elements.operationTabs?.forEach((tab, index) => {
    tab.addEventListener('click', () => activateOperationTab(tab.dataset.operationTab));
    tab.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const tabs = Array.from(elements.operationTabs);
        const lastIndex = tabs.length - 1;
        let nextIndex = index;

        if (event.key === 'ArrowRight') {
            nextIndex = index === lastIndex ? 0 : index + 1;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = index === 0 ? lastIndex : index - 1;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = lastIndex;
        }

        activateOperationTab(tabs[nextIndex].dataset.operationTab);
        tabs[nextIndex].focus();
    });
});

elements.entryProduct?.addEventListener('change', updateEntryFormMode);
elements.entryWarehouse?.addEventListener('change', updateEntryFormMode);
elements.entryQuantity?.addEventListener('input', updateEntryFormMode);
elements.entrySerials?.addEventListener('input', updateEntryFormMode);
elements.normalizeEntrySerials?.addEventListener('click', normalizeEntrySerialList);

elements.entryProductSearch?.addEventListener('input', () => {
    elements.entryProduct.value = '';
    updateEntryFormMode();
    window.clearTimeout(state.entrySearchTimer);
    state.entrySearchTimer = window.setTimeout(() => {
        searchProductsForOperation('entry', elements.entryProductSearch.value);
    }, 220);
});

elements.entryProductSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
    }
});

elements.entryProductResults?.addEventListener('click', (event) => {
    const option = event.target.closest('[data-product-search-kind]');

    if (option) {
        selectProductForOperation('entry', option.dataset.productId);
    }
});

elements.clearEntryForm?.addEventListener('click', () => resetProductEntryForm());

elements.exitProduct?.addEventListener('change', () => {
    updateExitFormMode();
});
elements.exitWarehouse?.addEventListener('change', () => {
    updateExitFormMode();
});
elements.exitReason?.addEventListener('change', updateExitSummary);
elements.exitQuantity?.addEventListener('input', updateExitSummary);
elements.exitSerialPicker?.addEventListener('change', (event) => {
    if (event.target.closest('[data-exit-unit]')) {
        updateExitSummary();
    }
});

elements.exitProductSearch?.addEventListener('input', () => {
    elements.exitProduct.value = '';
    updateExitSummary();
    window.clearTimeout(state.exitSearchTimer);
    state.exitSearchTimer = window.setTimeout(() => {
        searchProductsForOperation('exit', elements.exitProductSearch.value);
    }, 220);
});

elements.exitProductSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
    }
});

elements.exitProductResults?.addEventListener('click', (event) => {
    const option = event.target.closest('[data-product-search-kind]');

    if (option) {
        selectProductForOperation('exit', option.dataset.productId);
    }
});

elements.clearExitForm?.addEventListener('click', () => resetProductExitForm());

elements.refreshOperationHistory?.addEventListener('click', () => {
    if (state.session) {
        loadOperationHistory(state.session);
    }
});

elements.entryHistoryList?.addEventListener('click', (event) => {
    const card = event.target.closest('[data-history-type]');

    if (card) {
        showOperationHistoryDetail(card.dataset.historyType, card.dataset.historyId);
    }
});

elements.exitHistoryList?.addEventListener('click', (event) => {
    const card = event.target.closest('[data-history-type]');

    if (card) {
        showOperationHistoryDetail(card.dataset.historyType, card.dataset.historyId);
    }
});

elements.productEntryForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (state.session?.is_local_demo) {
        setProductEntryMessage('El modo demo local no guarda entradas. Entra con un usuario real para registrar stock.', 'error');
        return;
    }

    await saveProductEntry();
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

elements.closeProductDetail?.addEventListener('click', closeProductDetail);

elements.productDetailModal?.addEventListener('click', (event) => {
    if (event.target === elements.productDetailModal) {
        closeProductDetail();
    }
});

elements.inventoryProducts?.addEventListener('click', (event) => {
    const detailButton = event.target.closest('[data-product-detail]');
    const editButton = event.target.closest('[data-product-edit]');
    const receiveButton = event.target.closest('[data-product-receive]');

    if (detailButton) {
        openProductDetail(detailButton.dataset.productDetail);
        return;
    }

    if (receiveButton) {
        openProductEntryForProduct(receiveButton.dataset.productReceive);
        return;
    }

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

elements.productExitForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (state.session?.is_local_demo) {
        setProductExitMessage('El modo demo local no guarda salidas. Entra con un usuario real para descontar stock.', 'error');
        return;
    }

    await saveProductExit();
});

const existingSession = JSON.parse(localStorage.getItem(storageKey) || 'null');

if (existingSession?.token) {
    renderSession(existingSession);
}
