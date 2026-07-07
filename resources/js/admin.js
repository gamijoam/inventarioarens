const storageKey = 'inventory_admin_session';
const requestTimeoutMs = 20000;

const state = {
    tenants: [],
    selectedTenant: null,
    session: null,
    activeSection: 'overview',
    inventory: {
        page: 1,
        loaded: false,
        selectedProduct: null,
        priceLists: [],
        productPrices: [],
    },
    access: {
        loaded: false,
        users: [],
        roles: [],
        permissions: [],
        selectedUser: null,
        selectedRole: null,
    },
};

const elements = {
    loginView: document.querySelector('[data-view="login"]'),
    dashboardView: document.querySelector('[data-view="dashboard"]'),
    form: document.querySelector('#admin-login-form'),
    email: document.querySelector('#admin-email'),
    password: document.querySelector('#admin-password'),
    loadTenants: document.querySelector('#admin-load-tenants'),
    tenantField: document.querySelector('#admin-tenant-field'),
    tenant: document.querySelector('#admin-tenant'),
    loginStatus: document.querySelector('#admin-login-status'),
    submit: document.querySelector('#admin-login-submit'),
    logout: document.querySelector('#admin-logout'),
    refresh: document.querySelector('#dashboard-refresh'),
    period: document.querySelector('#dashboard-period'),
    tenantSwitcherField: document.querySelector('#admin-tenant-switcher-field'),
    tenantSwitcher: document.querySelector('#admin-tenant-switcher'),
    dashboardStatus: document.querySelector('#dashboard-status'),
    tenantTitle: document.querySelector('#dashboard-tenant'),
    periodLabel: document.querySelector('#dashboard-period-label'),
    alertList: document.querySelector('#alert-list'),
    syncStatus: document.querySelector('#sync-status'),
    portalNavItems: document.querySelectorAll('[data-portal-section]'),
    metricBoard: document.querySelector('.metric-board'),
    toolGrid: document.querySelector('.tool-grid'),
    modulePlaceholder: document.querySelector('#module-placeholder'),
    modulePlaceholderTitle: document.querySelector('#module-placeholder-title'),
    modulePlaceholderCopy: document.querySelector('#module-placeholder-copy'),
    inventoryModule: document.querySelector('#admin-inventory-module'),
    inventoryRefresh: document.querySelector('#admin-inventory-refresh'),
    inventorySearch: document.querySelector('#admin-inventory-search'),
    inventoryTracking: document.querySelector('#admin-inventory-tracking'),
    inventoryStock: document.querySelector('#admin-inventory-stock'),
    inventoryActive: document.querySelector('#admin-inventory-active'),
    inventoryApply: document.querySelector('#admin-inventory-apply'),
    inventoryTable: document.querySelector('#admin-inventory-table'),
    inventoryCount: document.querySelector('#admin-inventory-count'),
    inventoryPrev: document.querySelector('#admin-inventory-prev'),
    inventoryNext: document.querySelector('#admin-inventory-next'),
    inventoryStatus: document.querySelector('#admin-inventory-status'),
    inventoryEditor: document.querySelector('#admin-inventory-editor'),
    inventoryEditorTitle: document.querySelector('#admin-inventory-editor-title'),
    inventoryEditorSubtitle: document.querySelector('#admin-inventory-editor-subtitle'),
    inventoryPrice: document.querySelector('#admin-inventory-price'),
    inventoryCurrency: document.querySelector('#admin-inventory-currency'),
    inventoryActiveEdit: document.querySelector('#admin-inventory-active-edit'),
    inventorySave: document.querySelector('#admin-inventory-save'),
    inventoryCancel: document.querySelector('#admin-inventory-cancel'),
    priceListRows: document.querySelector('#admin-price-list-rows'),
    priceListSave: document.querySelector('#admin-price-list-save'),
    priceCopyBase: document.querySelector('#admin-price-copy-base'),
    accessModule: document.querySelector('#admin-users-module'),
    accessRefresh: document.querySelector('#admin-access-refresh'),
    accessStatus: document.querySelector('#admin-access-status'),
    accessTabs: Array.from(document.querySelectorAll('[data-access-tab]')),
    accessPanels: Array.from(document.querySelectorAll('[data-access-panel]')),
    accessUsersCount: document.querySelector('#admin-access-users-count'),
    accessUsersTable: document.querySelector('#admin-access-users-table'),
    accessRolesTable: document.querySelector('#admin-access-roles-table'),
    accessPermissionsGrid: document.querySelector('#admin-access-permissions-grid'),
    accessUserName: document.querySelector('#admin-access-user-name'),
    accessUserEmail: document.querySelector('#admin-access-user-email'),
    accessUserPassword: document.querySelector('#admin-access-user-password'),
    accessUserRoles: document.querySelector('#admin-access-user-roles'),
    accessCreateUser: document.querySelector('#admin-access-create-user'),
    accessSelectedUserTitle: document.querySelector('#admin-access-selected-user-title'),
    accessSelectedUserRoles: document.querySelector('#admin-access-selected-user-roles'),
    accessSaveUserRoles: document.querySelector('#admin-access-save-user-roles'),
    accessToggleUserStatus: document.querySelector('#admin-access-toggle-user-status'),
    accessRoleName: document.querySelector('#admin-access-role-name'),
    accessRoleTemplate: document.querySelector('#admin-access-role-template'),
    accessCreateRole: document.querySelector('#admin-access-create-role'),
    accessSelectedRoleTitle: document.querySelector('#admin-access-selected-role-title'),
    accessSaveRolePermissions: document.querySelector('#admin-access-save-role-permissions'),
};

const permissionProfiles = {
    cashier: [
        'products.view',
        'customers.view',
        'customers.create',
        'customers.update',
        'currency.view',
        'inventory.view',
        'sales.view',
        'sales.create',
        'sales_returns.view',
        'sales_returns.create',
        'pos.view',
        'pos.checkout',
        'pos.cancel',
        'cash_register.view',
        'cash_register.open',
        'cash_register.move',
        'payment_methods.view',
        'payment_receipts.view',
        'kardex.view',
    ],
    inventory: [
        'products.view',
        'products.create',
        'products.update',
        'branches.view',
        'warehouses.view',
        'inventory.view',
        'inventory.adjust',
        'inventory.transfer',
        'product_entries.view',
        'product_entries.create',
        'product_exits.view',
        'product_exits.create',
        'inventory_transfers.view',
        'inventory_transfers.create',
        'inventory_transfer_requests.view',
        'inventory_transfer_requests.create',
        'inventory_transfer_requests.respond',
        'inventory_transfer_requests.cancel',
        'kardex.view',
    ],
    manager: [
        'products.view',
        'products.create',
        'products.update',
        'customers.view',
        'customers.create',
        'customers.update',
        'suppliers.view',
        'currency.view',
        'inventory.view',
        'product_entries.view',
        'product_exits.view',
        'inventory_transfers.view',
        'purchases.view',
        'sales.view',
        'sales.create',
        'pos.view',
        'pos.checkout',
        'cash_register.view',
        'cash_register.open',
        'cash_register.move',
        'cash_register.close',
        'reports.view',
        'finance_reports.view',
        'kardex.view',
        'users.view',
    ],
};

const portalSections = {
    overview: {
        title: 'Vista gerencial',
        copy: 'Resumen operativo de ventas, inventario, caja y sincronización.',
    },
    sales: {
        title: 'Ventas',
        copy: 'Aquí se integrarán indicadores, órdenes POS, ventas confirmadas, pendientes de cobro y comparativos por periodo.',
    },
    inventory: {
        title: 'Inventario',
        copy: 'Esta sección reunirá productos, listas de precio, stock bajo, seriales/IMEI y movimientos críticos.',
    },
    cash: {
        title: 'Caja',
        copy: 'Aquí se revisarán cajas abiertas, cierres, diferencias, arqueos y actividad por cajero.',
    },
    users: {
        title: 'Usuarios y permisos',
        copy: 'Esta sección permitirá administrar usuarios, perfiles reutilizables, permisos por módulo y accesos por empresa.',
    },
    sync: {
        title: 'Sincronización',
        copy: 'Aquí se mostrarán nodos locales, eventos pendientes, errores y estado de sincronización por sede.',
    },
};

const metricElements = {
    posTotal: document.querySelector('#metric-pos-total'),
    salesTotal: document.querySelector('#metric-sales-total'),
    salesCount: document.querySelector('#metric-sales-count'),
    stockAvailable: document.querySelector('#metric-stock-available'),
    openCash: document.querySelector('#metric-open-cash'),
    cashExpected: document.querySelector('#metric-cash-expected'),
    pendingPos: document.querySelector('#metric-pending-pos'),
    products: document.querySelector('#metric-products'),
    lowStock: document.querySelector('#metric-low-stock'),
    withoutStock: document.querySelector('#metric-without-stock'),
    reserved: document.querySelector('#metric-reserved'),
    syncNodes: document.querySelector('#metric-sync-nodes'),
    syncPending: document.querySelector('#metric-sync-pending'),
    syncErrors: document.querySelector('#metric-sync-errors'),
};

function setStatus(element, message, tone = 'neutral') {
    if (!element) {
        return;
    }

    element.textContent = message;
    element.dataset.tone = tone;
}

function setButtonLoading(button, isLoading, loadingText = 'Procesando...') {
    if (!button) {
        return;
    }

    if (isLoading) {
        button.dataset.originalText = button.textContent;
        button.textContent = loadingText;
        button.disabled = true;
        return;
    }

    button.textContent = button.dataset.originalText || button.textContent;
    button.disabled = false;
}

function normalizeError(error) {
    if (error.name === 'AbortError') {
        return 'La solicitud tardó demasiado. Verifica que el servidor esté activo e intenta nuevamente.';
    }

    return error.message || 'No se pudo realizar esta acción. Revisa permisos, datos obligatorios o conexión.';
}

async function api(path, options = {}, returnPayload = false) {
    const { headers = {}, ...requestOptions } = options;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs);

    try {
        const response = await fetch(path, {
            ...requestOptions,
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...headers,
            },
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            const fallbackByStatus = {
                401: 'La sesión expiró. Vuelve a iniciar sesión.',
                403: 'Tu usuario no tiene permiso para realizar esta acción.',
                404: 'No se encontró el registro solicitado.',
                422: 'Hay datos incompletos o inválidos. Revisa el formulario.',
                500: 'El servidor tuvo un error interno. Revisa el log del backend.',
            };
            throw new Error(firstError || payload.message || fallbackByStatus[response.status] || 'No se pudo realizar esta acción.');
        }

        return returnPayload ? payload : payload.data;
    } finally {
        window.clearTimeout(timeout);
    }
}

function authHeaders(session) {
    return {
        Authorization: `Bearer ${session.token}`,
        'X-Tenant': session.tenant.slug,
    };
}

function sessionTenants(session) {
    return session?.available_tenants?.length ? session.available_tenants : state.tenants;
}

function saveSession(session, tenants = state.tenants) {
    const sessionWithTenants = {
        ...session,
        available_tenants: tenants.length ? tenants : session.available_tenants || [session.tenant],
    };

    localStorage.setItem(storageKey, JSON.stringify(sessionWithTenants));
    renderDashboardShell(sessionWithTenants);
}

function resetViewport() {
    window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
}

function clearSession() {
    localStorage.removeItem(storageKey);
    state.session = null;
    elements.dashboardView.hidden = true;
    elements.loginView.hidden = false;
    document.body.classList.remove('is-dashboard');
    resetViewport();
    setStatus(elements.loginStatus, 'Sesión cerrada.', 'success');
}

function restoreSession() {
    const raw = localStorage.getItem(storageKey);

    if (!raw) {
        return;
    }

    try {
        const session = JSON.parse(raw);
        state.tenants = sessionTenants(session);
        renderDashboardShell(session);
    } catch {
        clearSession();
    }
}

function renderTenantOptions(tenants, { showLoginSelector = true } = {}) {
    state.tenants = tenants;
    state.selectedTenant = tenants[0] ?? null;
    elements.tenant.replaceChildren(
        ...tenants.map((tenant) => {
            const option = document.createElement('option');
            option.value = tenant.slug;
            option.textContent = tenant.name;
            return option;
        }),
    );
    elements.tenantField.hidden = !showLoginSelector || tenants.length === 0;
}

async function loadTenants(showLoginSelector = true) {
    const email = elements.email.value.trim();

    if (!email) {
        setStatus(elements.loginStatus, 'Escribe el correo para buscar empresas.', 'error');
        return false;
    }

    setStatus(elements.loginStatus, 'Buscando empresas disponibles...');
    setButtonLoading(elements.loadTenants, true, 'Buscando...');

    try {
        const tenants = await api('/api/auth/tenants', {
            method: 'POST',
            body: JSON.stringify({ email }),
        });

        renderTenantOptions(tenants, { showLoginSelector });

        if (tenants.length === 0) {
            setStatus(elements.loginStatus, 'Este correo no tiene empresas activas asociadas.', 'error');
            return false;
        }

        setStatus(elements.loginStatus, `${tenants.length} empresa(s) disponible(s). Ahora ingresa la contraseña.`, 'success');
        return true;
    } catch (error) {
        setStatus(elements.loginStatus, normalizeError(error), 'error');
        return false;
    } finally {
        setButtonLoading(elements.loadTenants, false);
    }
}

async function login(event) {
    event.preventDefault();

    if (state.tenants.length === 0) {
        const tenantsLoaded = await loadTenants(false);

        if (!tenantsLoaded) {
            return;
        }
    }

    const tenantSlug = elements.tenant.value || state.selectedTenant?.slug;

    if (!tenantSlug) {
        setStatus(elements.loginStatus, 'Selecciona una empresa para entrar.', 'error');
        return;
    }

    setStatus(elements.loginStatus, 'Validando acceso...');
    setButtonLoading(elements.submit, true, 'Entrando...');

    try {
        const session = await api('/api/auth/login', {
            method: 'POST',
            headers: {
                'X-Tenant': tenantSlug,
            },
            body: JSON.stringify({
                email: elements.email.value.trim(),
                password: elements.password.value,
                device_name: 'Portal administrativo web',
            }),
        });

        saveSession(session, state.tenants);
        await loadDashboard();
    } catch (error) {
        setStatus(elements.loginStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.submit, false);
    }
}

function renderDashboardShell(session) {
    state.session = session;
    state.tenants = sessionTenants(session);
    elements.loginView.hidden = true;
    elements.dashboardView.hidden = false;
    document.body.classList.add('is-dashboard');
    elements.tenantTitle.textContent = session.tenant.name;
    renderDashboardTenantSwitcher(session);
    activatePortalSection('overview');
    resetViewport();
    elements.dashboardView.focus({ preventScroll: true });
}

function renderDashboardTenantSwitcher(session) {
    if (!elements.tenantSwitcher || !elements.tenantSwitcherField) {
        return;
    }

    const tenants = sessionTenants(session);
    elements.tenantSwitcher.replaceChildren(
        ...tenants.map((tenant) => {
            const option = document.createElement('option');
            option.value = tenant.slug;
            option.textContent = tenant.name;
            option.selected = tenant.slug === session.tenant.slug;

            return option;
        }),
    );

    elements.tenantSwitcherField.hidden = tenants.length <= 1;
    elements.tenantSwitcher.disabled = tenants.length <= 1;
}

function resetTenantScopedState() {
    state.inventory.page = 1;
    state.inventory.loaded = false;
    state.inventory.selectedProduct = null;
    state.inventory.priceLists = [];
    state.inventory.productPrices = [];

    state.access.loaded = false;
    state.access.users = [];
    state.access.roles = [];
    state.access.permissions = [];
    state.access.selectedUser = null;
    state.access.selectedRole = null;

    if (elements.inventoryEditor) {
        elements.inventoryEditor.hidden = true;
    }

    if (elements.inventoryTable) {
        elements.inventoryTable.innerHTML = '';
    }

    if (elements.accessUsersTable) {
        elements.accessUsersTable.innerHTML = '';
    }

    if (elements.accessRolesTable) {
        elements.accessRolesTable.innerHTML = '';
    }

    if (elements.accessPermissionsGrid) {
        elements.accessPermissionsGrid.innerHTML = '';
    }
}

async function switchTenant() {
    const session = state.session;
    const tenantSlug = elements.tenantSwitcher?.value;

    if (!session || !tenantSlug || tenantSlug === session.tenant.slug) {
        return;
    }

    setStatus(elements.dashboardStatus, 'Cambiando empresa activa...');
    if (elements.tenantSwitcher) {
        elements.tenantSwitcher.disabled = true;
    }

    try {
        const switchedSession = await api('/api/auth/switch-tenant', {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify({
                tenant_slug: tenantSlug,
                device_name: 'Portal administrativo web',
            }),
        });

        resetTenantScopedState();
        saveSession(switchedSession, sessionTenants(session));
        await loadDashboard();
        setStatus(elements.dashboardStatus, `Empresa activa: ${switchedSession.tenant.name}.`, 'success');
    } catch (error) {
        if (elements.tenantSwitcher) {
            elements.tenantSwitcher.value = session.tenant.slug;
        }
        setStatus(elements.dashboardStatus, normalizeError(error), 'error');
    } finally {
        if (elements.tenantSwitcher) {
            elements.tenantSwitcher.disabled = sessionTenants(state.session).length <= 1;
        }
    }
}

function activatePortalSection(section) {
    const selectedSection = portalSections[section] ? section : 'overview';
    const isOverview = selectedSection === 'overview';
    const isInventory = selectedSection === 'inventory';
    const isAccess = selectedSection === 'users';

    state.activeSection = selectedSection;

    elements.portalNavItems.forEach((item) => {
        item.classList.toggle('is-active', item.dataset.portalSection === selectedSection);
    });

    if (elements.metricBoard) {
        elements.metricBoard.hidden = !isOverview;
    }

    if (elements.toolGrid) {
        elements.toolGrid.hidden = !isOverview;
    }

    if (elements.inventoryModule) {
        elements.inventoryModule.hidden = !isInventory;
    }

    if (elements.accessModule) {
        elements.accessModule.hidden = !isAccess;
    }

    if (!elements.modulePlaceholder) {
        return;
    }

    elements.modulePlaceholder.hidden = isOverview || isInventory || isAccess;

    if (!isOverview && !isInventory && !isAccess) {
        elements.modulePlaceholderTitle.textContent = portalSections[selectedSection].title;
        elements.modulePlaceholderCopy.textContent = portalSections[selectedSection].copy;
    }

    if (isInventory && !state.inventory.loaded) {
        loadInventory();
        loadInventoryPriceLists().catch((error) => {
            setStatus(elements.inventoryStatus, normalizeError(error), 'error');
        });
    }

    if (isAccess && !state.access.loaded) {
        loadAccessControl();
    }
}

async function loadDashboard() {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.dashboardStatus, 'Cargando métricas administrativas...');
    setButtonLoading(elements.refresh, true, 'Actualizando...');

    try {
        const query = new URLSearchParams({
            period: elements.period.value,
            low_stock_threshold: '3',
        });
        const summary = await api(`/api/admin-portal/dashboard?${query}`, {
            headers: authHeaders(session),
        });

        renderSummary(summary);
        setStatus(elements.dashboardStatus, `Dashboard actualizado: ${formatDateTime(summary.generated_at)}.`, 'success');
    } catch (error) {
        setStatus(elements.dashboardStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.refresh, false);
    }
}

function renderSummary(summary) {
    elements.tenantTitle.textContent = summary.tenant.name;
    elements.periodLabel.textContent = `Periodo ${summary.period.from} a ${summary.period.to}.`;

    metricElements.posTotal.textContent = money(summary.sales.pos_paid_base_amount);
    metricElements.salesTotal.textContent = money(summary.sales.confirmed_base_amount);
    metricElements.salesCount.textContent = `${summary.sales.confirmed_count} venta(s) confirmada(s)`;
    metricElements.stockAvailable.textContent = number(summary.inventory.available_quantity);
    metricElements.openCash.textContent = number(summary.cash_register.open_sessions_count);
    metricElements.cashExpected.textContent = `${money(summary.cash_register.expected_base_amount)} esperado`;
    metricElements.pendingPos.textContent = number(summary.sales.pending_pos_count);
    metricElements.products.textContent = number(summary.inventory.active_products_count);
    metricElements.lowStock.textContent = number(summary.inventory.low_stock_count);
    metricElements.withoutStock.textContent = number(summary.inventory.without_stock_count);
    metricElements.reserved.textContent = number(summary.inventory.reserved_quantity);
    metricElements.syncNodes.textContent = number(summary.sync.nodes_count);
    metricElements.syncPending.textContent = number(summary.sync.pending_outbox_count);
    metricElements.syncErrors.textContent = number(summary.sync.failed_outbox_count + summary.sync.failed_inbox_count);

    renderSyncStatus(summary.sync);
    renderAlerts(summary.alerts);
}

function renderSyncStatus(sync) {
    const hasErrors = sync.failed_outbox_count + sync.failed_inbox_count > 0;
    const hasPending = sync.pending_outbox_count > 0;

    if (hasErrors) {
        elements.syncStatus.textContent = 'Con errores';
        elements.syncStatus.dataset.tone = 'error';
        return;
    }

    if (hasPending) {
        elements.syncStatus.textContent = 'Pendiente';
        elements.syncStatus.dataset.tone = 'warning';
        return;
    }

    if (sync.readiness_status === 'ready') {
        elements.syncStatus.textContent = 'Sincronizado';
        elements.syncStatus.dataset.tone = 'success';
        return;
    }

    elements.syncStatus.textContent = 'No configurado';
    elements.syncStatus.dataset.tone = 'warning';
}

function renderAlerts(alerts) {
    if (!alerts.length) {
        elements.alertList.innerHTML = '<article class="alert-item"><strong>Sin alertas críticas</strong><span>La empresa no tiene alertas operativas para este resumen.</span></article>';
        return;
    }

    elements.alertList.replaceChildren(
        ...alerts.map((alert) => {
            const item = document.createElement('article');
            item.className = 'alert-item';
            item.innerHTML = `<strong>${alert.count} - ${alertLabel(alert.type)}</strong><span>${alert.message}</span>`;
            return item;
        }),
    );
}

async function loadInventory(page = state.inventory.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.inventory.page = page;
    setStatus(elements.inventoryStatus, 'Cargando inventario...');
    setButtonLoading(elements.inventoryRefresh, true, 'Actualizando...');
    setButtonLoading(elements.inventoryApply, true, 'Aplicando...');

    try {
        const query = new URLSearchParams({
            stock_status: elements.inventoryStock.value || 'all',
            active_status: elements.inventoryActive?.value || 'all',
            low_stock_threshold: '3',
            limit: '24',
            page: String(page),
        });
        const search = elements.inventorySearch.value.trim();
        const tracking = elements.inventoryTracking.value;

        if (search) {
            query.set('search', search);
        }

        if (tracking) {
            query.set('tracking_type', tracking);
        }

        const summary = await api(`/api/inventory-center/summary?${query}`, {
            headers: authHeaders(session),
        });

        state.inventory.loaded = true;
        renderInventory(summary);
        setStatus(elements.inventoryStatus, `Inventario actualizado. ${summary.products.length} producto(s) en vista.`, 'success');
    } catch (error) {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.inventoryRefresh, false);
        setButtonLoading(elements.inventoryApply, false);
    }
}

function renderInventory(summary) {
    const products = summary.products || [];

    if (!products.length) {
        elements.inventoryTable.innerHTML = '<tr><td colspan="8"><strong>Sin productos</strong><small>No hay productos con los filtros seleccionados.</small></td></tr>';
    } else {
        elements.inventoryTable.replaceChildren(...products.map(inventoryRow));
    }

    const pagination = summary.pagination || {};
    elements.inventoryCount.textContent = pagination.total === 0
        ? 'Sin productos para mostrar.'
        : `${pagination.from}-${pagination.to} de ${pagination.total} productos.`;
    elements.inventoryPrev.disabled = !pagination.has_previous;
    elements.inventoryNext.disabled = !pagination.has_next;
    state.inventory.page = pagination.page || 1;
}

function inventoryRow(product) {
    const row = document.createElement('tr');
    const canEdit = canUpdateProducts();

    row.innerHTML = `
        <td><strong>${escapeHtml(product.name)}</strong><small>${escapeHtml(product.sku)}</small></td>
        <td>${trackingLabel(product.tracking_type)}</td>
        <td><strong>${priceLabel(product)}</strong><small>${escapeHtml(product.sale_currency || 'USD')}</small></td>
        <td>${stockNumber(product.stock?.available)}</td>
        <td>${stockNumber(product.stock?.reserved)}</td>
        <td><span class="stock-pill stock-pill--${escapeHtml(product.stock?.status || 'available')}">${stockStatusLabel(product.stock?.status)}</span></td>
        <td><span class="status-pill" data-tone="${product.is_active ? 'success' : 'warning'}">${product.is_active ? 'Activo' : 'Inactivo'}</span></td>
        <td>
            <button class="ghost-button" type="button" data-admin-product-edit="${product.id}" ${canEdit ? '' : 'disabled'}>
                Editar
            </button>
        </td>
    `;

    const button = row.querySelector('[data-admin-product-edit]');
    button?.addEventListener('click', () => {
        selectInventoryProduct(product).catch((error) => {
            setStatus(elements.inventoryStatus, normalizeError(error), 'error');
        });
    });

    return row;
}

async function selectInventoryProduct(product) {
    state.inventory.selectedProduct = product;
    elements.inventoryEditor.hidden = false;
    elements.inventoryEditorTitle.textContent = product.name;
    elements.inventoryEditorSubtitle.textContent = `${product.sku} · ${trackingLabel(product.tracking_type)}`;
    elements.inventoryPrice.value = product.base_price ?? '';
    elements.inventoryCurrency.value = product.sale_currency || 'USD';
    elements.inventoryActiveEdit.value = product.is_active ? '1' : '0';
    renderProductPriceListRows([], true);
    setStatus(elements.inventoryStatus, 'Cargando precios por lista del producto...', 'neutral');

    await Promise.all([
        loadInventoryPriceLists(),
        loadProductPriceLists(product),
    ]);

    if (state.inventory.selectedProduct?.id !== product.id) {
        return;
    }

    renderProductPriceListRows();
    setStatus(elements.inventoryStatus, 'Edita precio base, moneda, estado o precios por lista. Todo quedará listo para sincronizarse.', 'neutral');
}

async function saveInventoryProductPrice() {
    const product = state.inventory.selectedProduct;
    const session = state.session;

    if (!product || !session) {
        setStatus(elements.inventoryStatus, 'Selecciona un producto antes de guardar.', 'error');
        return;
    }

    if (elements.inventoryPrice.value === '' || Number(elements.inventoryPrice.value) < 0) {
        setStatus(elements.inventoryStatus, 'El precio base debe ser mayor o igual a cero.', 'error');
        return;
    }

    setStatus(elements.inventoryStatus, 'Guardando precio y preparando sincronización...');
    setButtonLoading(elements.inventorySave, true, 'Guardando...');

    try {
        await api(`/api/products/${product.id}`, {
            method: 'PUT',
            headers: authHeaders(session),
            body: JSON.stringify({
                base_price: Number(elements.inventoryPrice.value),
                sale_currency: elements.inventoryCurrency.value,
                is_active: elements.inventoryActiveEdit.value === '1',
            }),
        });

        elements.inventoryEditor.hidden = true;
        state.inventory.selectedProduct = null;
        state.inventory.loaded = false;
        await loadInventory();
        await loadDashboard();
        setStatus(elements.inventoryStatus, 'Precio actualizado. El cambio quedó listo para sincronizarse.', 'success');
    } catch (error) {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.inventorySave, false);
    }
}

async function loadInventoryPriceLists() {
    const session = state.session;

    if (!session || state.inventory.priceLists.length > 0) {
        return;
    }

    const lists = await api('/api/price-lists?active_only=1', {
        headers: authHeaders(session),
    });

    state.inventory.priceLists = collectionData(lists);
}

async function loadProductPriceLists(product) {
    const session = state.session;

    if (!session || !product) {
        state.inventory.productPrices = [];
        return;
    }

    const prices = await api(`/api/products/${product.id}/prices`, {
        headers: authHeaders(session),
    });

    state.inventory.productPrices = collectionData(prices);
}

function renderProductPriceListRows(rows = null, isLoading = false) {
    if (!elements.priceListRows) {
        return;
    }

    if (isLoading) {
        elements.priceListRows.innerHTML = '<p class="price-list-empty">Cargando listas de precio...</p>';
        return;
    }

    const priceLists = rows ?? state.inventory.priceLists;

    if (!priceLists.length) {
        elements.priceListRows.innerHTML = '<p class="price-list-empty">No hay listas activas para esta empresa.</p>';
        return;
    }

    const pricesByList = new Map(state.inventory.productPrices.map((price) => [Number(price.price_list_id), price]));

    elements.priceListRows.replaceChildren(...priceLists.map((priceList) => {
        const productPrice = pricesByList.get(Number(priceList.id));
        const selectedCurrency = productPrice?.currency || state.inventory.selectedProduct?.sale_currency || 'USD';
        const row = document.createElement('article');
        row.className = 'price-list-row';
        row.dataset.priceListId = priceList.id;
        row.dataset.exchangeRateTypeId = productPrice?.exchange_rate_type_id ?? '';

        row.innerHTML = `
            <div class="price-list-row__title">
                <strong>${escapeHtml(priceList.name)}</strong>
                <small>${escapeHtml(priceList.code)}${priceList.is_default ? ' - Predeterminada' : ''}</small>
            </div>
            <label class="field">
                <span>Precio</span>
                <input data-price-list-price type="number" min="0" step="0.01" placeholder="Sin precio" value="${productPrice ? Number(productPrice.price).toFixed(2) : ''}">
            </label>
            <label class="field">
                <span>Moneda</span>
                <select data-price-list-currency>
                    <option value="USD" ${selectedCurrency === 'USD' ? 'selected' : ''}>USD</option>
                    <option value="VES" ${selectedCurrency === 'VES' ? 'selected' : ''}>VES</option>
                </select>
            </label>
            <label class="price-list-row__active">
                <input data-price-list-active type="checkbox" ${productPrice?.is_active === false ? '' : 'checked'}>
                <span>Activa</span>
            </label>
            <span class="status-pill" data-tone="${productPrice ? 'success' : 'warning'}">${productPrice ? 'Configurada' : 'Falta precio'}</span>
        `;

        return row;
    }));
}

function copyBasePriceToEmptyLists() {
    if (!state.inventory.selectedProduct) {
        setStatus(elements.inventoryStatus, 'Selecciona un producto antes de copiar el precio base.', 'error');
        return;
    }

    const basePrice = elements.inventoryPrice.value;
    const baseCurrency = elements.inventoryCurrency.value || 'USD';

    if (basePrice === '' || Number(basePrice) < 0) {
        setStatus(elements.inventoryStatus, 'Coloca un precio base válido antes de copiarlo.', 'error');
        return;
    }

    elements.priceListRows?.querySelectorAll('.price-list-row').forEach((row) => {
        const priceInput = row.querySelector('[data-price-list-price]');
        const currencySelect = row.querySelector('[data-price-list-currency]');

        if (priceInput && priceInput.value === '') {
            priceInput.value = Number(basePrice).toFixed(2);
        }

        if (currencySelect) {
            currencySelect.value = baseCurrency;
        }
    });

    setStatus(elements.inventoryStatus, 'Precio base copiado en listas sin precio.', 'success');
}

async function saveProductPriceLists() {
    const product = state.inventory.selectedProduct;
    const session = state.session;

    if (!product || !session) {
        setStatus(elements.inventoryStatus, 'Selecciona un producto antes de guardar listas.', 'error');
        return;
    }

    const prices = Array.from(elements.priceListRows?.querySelectorAll('.price-list-row') || [])
        .map((row) => {
            const priceInput = row.querySelector('[data-price-list-price]');
            const currencySelect = row.querySelector('[data-price-list-currency]');
            const activeInput = row.querySelector('[data-price-list-active]');
            const price = priceInput?.value;

            if (price === '') {
                return null;
            }

            return {
                price_list_id: Number(row.dataset.priceListId),
                price: Number(price),
                currency: currencySelect?.value || 'USD',
                exchange_rate_type_id: row.dataset.exchangeRateTypeId ? Number(row.dataset.exchangeRateTypeId) : null,
                is_active: Boolean(activeInput?.checked),
            };
        })
        .filter(Boolean);

    if (!prices.length) {
        setStatus(elements.inventoryStatus, 'Completa al menos un precio por lista antes de guardar.', 'error');
        return;
    }

    if (prices.some((price) => Number.isNaN(price.price) || price.price < 0)) {
        setStatus(elements.inventoryStatus, 'Los precios por lista deben ser números mayores o iguales a cero.', 'error');
        return;
    }

    setStatus(elements.inventoryStatus, 'Guardando precios por lista y preparando sincronización...');
    setButtonLoading(elements.priceListSave, true, 'Guardando...');

    try {
        const updated = await api(`/api/products/${product.id}/prices`, {
            method: 'PUT',
            headers: authHeaders(session),
            body: JSON.stringify({ prices }),
        });

        state.inventory.productPrices = collectionData(updated);
        renderProductPriceListRows();
        await loadDashboard();
        setStatus(elements.inventoryStatus, 'Precios por lista actualizados. Los cambios quedaron listos para sincronizarse.', 'success');
    } catch (error) {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.priceListSave, false);
    }
}

async function loadAccessControl() {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.accessStatus, 'Cargando usuarios, roles y permisos...');
    setButtonLoading(elements.accessRefresh, true, 'Actualizando...');

    try {
        const [users, roles, permissions] = await Promise.all([
            api('/api/users', { headers: authHeaders(session) }),
            api('/api/roles', { headers: authHeaders(session) }),
            api('/api/permissions', { headers: authHeaders(session) }),
        ]);

        state.access.users = collectionData(users);
        state.access.roles = collectionData(roles);
        state.access.permissions = collectionData(permissions);
        state.access.selectedUser = keepSelectedOrFirst(state.access.users, state.access.selectedUser);
        state.access.selectedRole = keepSelectedOrFirst(state.access.roles, state.access.selectedRole);
        state.access.loaded = true;

        renderAccessControl();
        setStatus(elements.accessStatus, 'Usuarios y perfiles actualizados.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessRefresh, false);
    }
}

function renderAccessControl() {
    setAccessTab('users');
    renderAccessUsers();
    renderAccessRoleOptions(elements.accessUserRoles, []);
    renderAccessRoleOptions(elements.accessSelectedUserRoles, state.access.selectedUser?.roles?.map((role) => role.name) || []);
    renderAccessRoles();
    renderPermissionCatalog();
    applyAccessPermissions();
}

function setAccessTab(tab) {
    elements.accessTabs.forEach((button) => {
        button.classList.toggle('is-active', button.dataset.accessTab === tab);
    });

    elements.accessPanels.forEach((panel) => {
        panel.classList.toggle('is-active', panel.dataset.accessPanel === tab);
    });
}

function renderAccessUsers() {
    const users = state.access.users;

    if (elements.accessUsersCount) {
        elements.accessUsersCount.textContent = `${users.length} usuario(s)`;
        elements.accessUsersCount.dataset.tone = users.length > 0 ? 'success' : 'warning';
    }

    if (!users.length) {
        elements.accessUsersTable.innerHTML = '<tr><td colspan="4"><strong>Sin usuarios visibles</strong><small>No hay usuarios cargados o tu usuario no tiene permiso para verlos.</small></td></tr>';
        return;
    }

    elements.accessUsersTable.replaceChildren(...users.map(accessUserRow));
}

function accessUserRow(user) {
    const row = document.createElement('tr');
    const roles = (user.roles || []).map((role) => role.name);
    const isSelected = state.access.selectedUser?.id === user.id;

    row.className = isSelected ? 'is-selected' : '';
    row.innerHTML = `
        <td><strong>${escapeHtml(user.name)}</strong><small>${escapeHtml(user.email)}</small></td>
        <td><span class="status-pill" data-tone="${user.status === 'active' ? 'success' : 'warning'}">${user.status === 'active' ? 'Activo' : 'Inactivo'}</span></td>
        <td>${roles.length ? roles.map((role) => `<span class="access-chip">${escapeHtml(role)}</span>`).join('') : '<small>Sin perfiles</small>'}</td>
        <td><button class="ghost-button" type="button" data-access-user="${user.id}">Seleccionar</button></td>
    `;

    row.querySelector('[data-access-user]')?.addEventListener('click', () => selectAccessUser(user));

    return row;
}

function renderAccessRoleOptions(select, selectedRoles = []) {
    if (!select) {
        return;
    }

    const selected = new Set(selectedRoles);
    select.replaceChildren(
        ...state.access.roles.map((role) => {
            const option = document.createElement('option');
            option.value = role.name;
            option.textContent = role.name;
            option.selected = selected.has(role.name);
            return option;
        }),
    );
}

function selectAccessUser(user) {
    state.access.selectedUser = user;
    elements.accessSelectedUserTitle.textContent = user.name;
    renderAccessRoleOptions(elements.accessSelectedUserRoles, (user.roles || []).map((role) => role.name));
    elements.accessToggleUserStatus.textContent = user.status === 'active' ? 'Inactivar usuario' : 'Activar usuario';
    renderAccessUsers();
    setStatus(elements.accessStatus, `Usuario seleccionado: ${user.email}.`, 'neutral');
}

async function createAccessUser() {
    const session = state.session;
    const name = elements.accessUserName.value.trim();
    const email = elements.accessUserEmail.value.trim();
    const password = elements.accessUserPassword.value;
    const roles = selectedValues(elements.accessUserRoles);

    if (!session) {
        return;
    }

    if (!name || !email) {
        setStatus(elements.accessStatus, 'Nombre y correo son obligatorios.', 'error');
        return;
    }

    if (password && password.length < 8) {
        setStatus(elements.accessStatus, 'La clave debe tener al menos 8 caracteres.', 'error');
        return;
    }

    setStatus(elements.accessStatus, 'Creando o vinculando usuario...');
    setButtonLoading(elements.accessCreateUser, true, 'Creando...');

    try {
        const body = { name, email, roles };

        if (password) {
            body.password = password;
        }

        const user = await api('/api/users', {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify(body),
        });

        elements.accessUserName.value = '';
        elements.accessUserEmail.value = '';
        elements.accessUserPassword.value = '';
        state.access.selectedUser = user;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Usuario guardado correctamente.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessCreateUser, false);
    }
}

async function saveAccessUserRoles() {
    const user = state.access.selectedUser;
    const session = state.session;

    if (!user || !session) {
        setStatus(elements.accessStatus, 'Selecciona un usuario antes de guardar perfiles.', 'error');
        return;
    }

    setStatus(elements.accessStatus, 'Actualizando perfiles del usuario...');
    setButtonLoading(elements.accessSaveUserRoles, true, 'Guardando...');

    try {
        const updated = await api(`/api/users/${user.id}/roles`, {
            method: 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify({ roles: selectedValues(elements.accessSelectedUserRoles) }),
        });

        state.access.selectedUser = updated;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Perfiles del usuario actualizados.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessSaveUserRoles, false);
    }
}

async function toggleAccessUserStatus() {
    const user = state.access.selectedUser;
    const session = state.session;

    if (!user || !session) {
        setStatus(elements.accessStatus, 'Selecciona un usuario antes de cambiar su estado.', 'error');
        return;
    }

    const nextStatus = user.status === 'active' ? 'inactive' : 'active';
    setStatus(elements.accessStatus, `${nextStatus === 'active' ? 'Activando' : 'Inactivando'} usuario...`);
    setButtonLoading(elements.accessToggleUserStatus, true, 'Procesando...');

    try {
        const updated = await api(`/api/users/${user.id}/status`, {
            method: 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify({ status: nextStatus }),
        });

        state.access.selectedUser = updated;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Estado del usuario actualizado.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessToggleUserStatus, false);
    }
}

function renderAccessRoles() {
    const roles = state.access.roles;

    if (!roles.length) {
        elements.accessRolesTable.innerHTML = '<tr><td colspan="4"><strong>Sin perfiles</strong><small>No hay perfiles disponibles para esta empresa o no tienes permiso para verlos.</small></td></tr>';
        return;
    }

    elements.accessRolesTable.replaceChildren(...roles.map(accessRoleRow));
}

function accessRoleRow(role) {
    const row = document.createElement('tr');
    const isSelected = state.access.selectedRole?.id === role.id;

    row.className = isSelected ? 'is-selected' : '';
    row.innerHTML = `
        <td><strong>${escapeHtml(role.name)}</strong></td>
        <td>${number((role.permissions || []).length)}</td>
        <td>${role.is_protected ? '<span class="access-chip access-chip--locked">Base</span>' : '<span class="access-chip">Personalizado</span>'}</td>
        <td><button class="ghost-button" type="button" data-access-role="${role.id}">Permisos</button></td>
    `;

    row.querySelector('[data-access-role]')?.addEventListener('click', () => selectAccessRole(role));

    return row;
}

function selectAccessRole(role) {
    state.access.selectedRole = role;
    renderAccessRoles();
    renderPermissionCatalog();
    setAccessTab('permissions');
    setStatus(elements.accessStatus, `Perfil seleccionado: ${role.name}.`, 'neutral');
}

async function createAccessRole() {
    const session = state.session;
    const name = elements.accessRoleName.value.trim();
    const template = elements.accessRoleTemplate.value;

    if (!session) {
        return;
    }

    if (!name) {
        setStatus(elements.accessStatus, 'Escribe el nombre del nuevo perfil.', 'error');
        return;
    }

    setStatus(elements.accessStatus, 'Creando perfil...');
    setButtonLoading(elements.accessCreateRole, true, 'Creando...');

    try {
        const role = await api('/api/roles', {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify({ name, permissions: permissionProfiles[template] || [] }),
        });

        elements.accessRoleName.value = '';
        elements.accessRoleTemplate.value = '';
        state.access.selectedRole = role;
        await loadAccessControl();
        setAccessTab('profiles');
        setStatus(elements.accessStatus, 'Perfil creado. Ahora puedes ajustar permisos.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessCreateRole, false);
    }
}

function renderPermissionCatalog() {
    if (!elements.accessPermissionsGrid) {
        return;
    }

    const role = state.access.selectedRole;
    const selectedPermissions = new Set(role?.permissions || []);

    elements.accessSelectedRoleTitle.textContent = role ? `Permisos: ${role.name}` : 'Permisos del perfil';

    if (!role) {
        elements.accessPermissionsGrid.innerHTML = '<p class="access-empty">Selecciona un perfil para revisar sus permisos.</p>';
        return;
    }

    elements.accessPermissionsGrid.replaceChildren(
        ...state.access.permissions.map((group) => {
            const section = document.createElement('section');
            section.className = 'permission-group';
            section.innerHTML = `<h5>${permissionModuleLabel(group.module)}</h5>`;

            const list = document.createElement('div');
            list.className = 'permission-list';
            (group.permissions || []).forEach((permission) => {
                const label = document.createElement('label');
                label.className = 'permission-check';
                label.innerHTML = `
                    <input type="checkbox" value="${escapeHtml(permission)}" data-access-permission ${selectedPermissions.has(permission) ? 'checked' : ''}>
                    <span>${permissionLabel(permission)}</span>
                `;
                list.append(label);
            });
            section.append(list);

            return section;
        }),
    );
}

async function saveAccessRolePermissions() {
    const role = state.access.selectedRole;
    const session = state.session;

    if (!role || !session) {
        setStatus(elements.accessStatus, 'Selecciona un perfil antes de guardar permisos.', 'error');
        return;
    }

    const permissions = Array.from(elements.accessPermissionsGrid.querySelectorAll('[data-access-permission]:checked'))
        .map((checkbox) => checkbox.value);

    setStatus(elements.accessStatus, 'Guardando permisos del perfil...');
    setButtonLoading(elements.accessSaveRolePermissions, true, 'Guardando...');

    try {
        const updated = await api(`/api/roles/${role.id}/permissions`, {
            method: 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify({ permissions }),
        });

        state.access.selectedRole = updated;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Permisos del perfil actualizados.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessSaveRolePermissions, false);
    }
}

function applyAccessPermissions() {
    const canCreateUser = can('users.create');
    const canUpdateUser = can('users.update');
    const canCreateRole = can('roles.create');
    const canUpdateRole = can('roles.update');

    elements.accessCreateUser.disabled = !canCreateUser;
    elements.accessSaveUserRoles.disabled = !canUpdateUser || !state.access.selectedUser;
    elements.accessToggleUserStatus.disabled = !canUpdateUser || !state.access.selectedUser;
    elements.accessCreateRole.disabled = !canCreateRole;
    elements.accessSaveRolePermissions.disabled = !canUpdateRole || !state.access.selectedRole;
}

function collectionData(payload) {
    if (Array.isArray(payload)) {
        return payload;
    }

    if (Array.isArray(payload?.data)) {
        return payload.data;
    }

    return [];
}

function keepSelectedOrFirst(items, selected) {
    if (!items.length) {
        return null;
    }

    if (!selected) {
        return items[0];
    }

    return items.find((item) => item.id === selected.id) || items[0];
}

function selectedValues(select) {
    return Array.from(select?.selectedOptions || []).map((option) => option.value);
}

function can(permission) {
    return state.session?.permissions?.includes(permission) ?? false;
}

function permissionModuleLabel(module) {
    return {
        users: 'Usuarios',
        roles: 'Roles',
        products: 'Productos',
        inventory: 'Inventario',
        pos: 'POS',
        cash_register: 'Caja',
        reports: 'Reportes',
        kardex: 'Kardex',
        settings: 'Configuracion',
        sync: 'Sincronizacion',
    }[module] ?? module.replaceAll('_', ' ');
}

function permissionLabel(permission) {
    const action = permission.split('.').pop();
    const labels = {
        view: 'Ver',
        create: 'Crear',
        update: 'Editar',
        delete: 'Eliminar',
        checkout: 'Cobrar',
        close: 'Cerrar',
        open: 'Abrir',
        export: 'Exportar',
        receive: 'Recibir',
        approve: 'Aprobar',
    };

    return `${labels[action] || action} (${permission})`;
}

function alertLabel(type) {
    return {
        without_stock: 'Productos sin stock',
        low_stock: 'Stock bajo',
        sync_errors: 'Errores de sincronización',
        sync_pending: 'Sincronización pendiente',
    }[type] ?? 'Alerta';
}

function money(value) {
    return `USD ${Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function number(value) {
    return Number(value || 0).toLocaleString('en-US', {
        maximumFractionDigits: 2,
    });
}

function formatDateTime(value) {
    if (!value) {
        return 'sin fecha';
    }

    return new Date(value).toLocaleString('es-VE', {
        dateStyle: 'short',
        timeStyle: 'short',
    });
}

function canUpdateProducts() {
    return can('products.update');
}

function trackingLabel(type) {
    return type === 'serialized' ? 'Serializado / IMEI' : 'Por cantidad';
}

function priceLabel(product) {
    if (product.base_price === null || product.base_price === undefined) {
        return 'Sin precio';
    }

    return `${product.sale_currency || 'USD'} ${Number(product.base_price || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function stockNumber(value) {
    return Number(value || 0).toLocaleString('en-US', {
        maximumFractionDigits: 4,
    });
}

function stockStatusLabel(status) {
    return {
        available: 'Disponible',
        low: 'Stock bajo',
        out: 'Sin stock',
    }[status] ?? 'Disponible';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

elements.loadTenants?.addEventListener('click', loadTenants);
elements.form?.addEventListener('submit', login);
elements.refresh?.addEventListener('click', loadDashboard);
elements.period?.addEventListener('change', loadDashboard);
elements.tenantSwitcher?.addEventListener('change', switchTenant);
elements.logout?.addEventListener('click', clearSession);
elements.portalNavItems.forEach((item) => {
    item.addEventListener('click', () => activatePortalSection(item.dataset.portalSection));
});
elements.inventoryRefresh?.addEventListener('click', () => loadInventory());
elements.inventoryApply?.addEventListener('click', () => loadInventory(1));
elements.inventorySearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadInventory(1);
    }
});
elements.inventoryPrev?.addEventListener('click', () => loadInventory(Math.max(state.inventory.page - 1, 1)));
elements.inventoryNext?.addEventListener('click', () => loadInventory(state.inventory.page + 1));
elements.inventorySave?.addEventListener('click', saveInventoryProductPrice);
elements.priceListSave?.addEventListener('click', saveProductPriceLists);
elements.priceCopyBase?.addEventListener('click', copyBasePriceToEmptyLists);
elements.inventoryCancel?.addEventListener('click', () => {
    elements.inventoryEditor.hidden = true;
    state.inventory.selectedProduct = null;
    state.inventory.productPrices = [];
    renderProductPriceListRows([]);
    setStatus(elements.inventoryStatus, 'Edición cancelada.');
});
elements.accessRefresh?.addEventListener('click', () => loadAccessControl());
elements.accessTabs.forEach((button) => {
    button.addEventListener('click', () => setAccessTab(button.dataset.accessTab));
});
elements.accessCreateUser?.addEventListener('click', createAccessUser);
elements.accessSaveUserRoles?.addEventListener('click', saveAccessUserRoles);
elements.accessToggleUserStatus?.addEventListener('click', toggleAccessUserStatus);
elements.accessCreateRole?.addEventListener('click', createAccessRole);
elements.accessSaveRolePermissions?.addEventListener('click', saveAccessRolePermissions);
elements.tenant?.addEventListener('change', () => {
    state.selectedTenant = state.tenants.find((tenant) => tenant.slug === elements.tenant.value) ?? null;
});

restoreSession();

if (state.session) {
    loadDashboard();
}
