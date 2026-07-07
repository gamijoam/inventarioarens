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
    inventorySave: document.querySelector('#admin-inventory-save'),
    inventoryCancel: document.querySelector('#admin-inventory-cancel'),
    accessModule: document.querySelector('#admin-users-module'),
    accessRefresh: document.querySelector('#admin-access-refresh'),
    accessStatus: document.querySelector('#admin-access-status'),
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
    accessCreateRole: document.querySelector('#admin-access-create-role'),
    accessSelectedRoleTitle: document.querySelector('#admin-access-selected-role-title'),
    accessSaveRolePermissions: document.querySelector('#admin-access-save-role-permissions'),
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
        copy: 'Esta sección permitirá administrar usuarios, roles, permisos por módulo y accesos por empresa.',
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

    return error.message || 'No se pudo completar la operación.';
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
            throw new Error(firstError || payload.message || 'No se pudo completar la operación.');
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

function saveSession(session) {
    localStorage.setItem(storageKey, JSON.stringify(session));
    renderDashboardShell(session);
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
        renderDashboardShell(session);
    } catch {
        clearSession();
    }
}

function renderTenantOptions(tenants) {
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
    elements.tenantField.hidden = tenants.length === 0;
}

async function loadTenants() {
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

        renderTenantOptions(tenants);

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
        const tenantsLoaded = await loadTenants();

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

        saveSession(session);
        await loadDashboard();
    } catch (error) {
        setStatus(elements.loginStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.submit, false);
    }
}

function renderDashboardShell(session) {
    state.session = session;
    elements.loginView.hidden = true;
    elements.dashboardView.hidden = false;
    document.body.classList.add('is-dashboard');
    elements.tenantTitle.textContent = session.tenant.name;
    activatePortalSection('overview');
    resetViewport();
    elements.dashboardView.focus({ preventScroll: true });
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
        elements.inventoryTable.innerHTML = '<tr><td colspan="7"><strong>Sin productos</strong><small>No hay productos con los filtros seleccionados.</small></td></tr>';
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
        <td>
            <button class="ghost-button" type="button" data-admin-product-edit="${product.id}" ${canEdit ? '' : 'disabled'}>
                Editar precio
            </button>
        </td>
    `;

    const button = row.querySelector('[data-admin-product-edit]');
    button?.addEventListener('click', () => selectInventoryProduct(product));

    return row;
}

function selectInventoryProduct(product) {
    state.inventory.selectedProduct = product;
    elements.inventoryEditor.hidden = false;
    elements.inventoryEditorTitle.textContent = product.name;
    elements.inventoryEditorSubtitle.textContent = `${product.sku} · ${trackingLabel(product.tracking_type)}`;
    elements.inventoryPrice.value = product.base_price ?? '';
    elements.inventoryCurrency.value = product.sale_currency || 'USD';
    setStatus(elements.inventoryStatus, 'Edita el precio base y guarda para sincronizar el cambio.', 'neutral');
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

        state.access.users = Array.isArray(users) ? users : [];
        state.access.roles = Array.isArray(roles) ? roles : [];
        state.access.permissions = Array.isArray(permissions) ? permissions : [];
        state.access.loaded = true;

        renderAccessControl();
        setStatus(elements.accessStatus, 'Usuarios y permisos actualizados.', 'success');
    } catch (error) {
        setStatus(elements.accessStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessRefresh, false);
    }
}

function renderAccessControl() {
    renderAccessUsers();
    renderAccessRoleOptions(elements.accessUserRoles, []);
    renderAccessRoleOptions(elements.accessSelectedUserRoles, state.access.selectedUser?.roles?.map((role) => role.name) || []);
    renderAccessRoles();
    renderPermissionCatalog();
    applyAccessPermissions();
}

function renderAccessUsers() {
    const users = state.access.users;

    if (elements.accessUsersCount) {
        elements.accessUsersCount.textContent = `${users.length} usuario(s)`;
        elements.accessUsersCount.dataset.tone = users.length > 0 ? 'success' : 'warning';
    }

    if (!users.length) {
        elements.accessUsersTable.innerHTML = '<tr><td colspan="4"><strong>Sin usuarios</strong><small>Esta empresa aun no tiene usuarios cargados.</small></td></tr>';
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
        <td>${roles.length ? roles.map((role) => `<span class="access-chip">${escapeHtml(role)}</span>`).join('') : '<small>Sin roles</small>'}</td>
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
        setStatus(elements.accessStatus, 'Selecciona un usuario antes de guardar roles.', 'error');
        return;
    }

    setStatus(elements.accessStatus, 'Actualizando roles del usuario...');
    setButtonLoading(elements.accessSaveUserRoles, true, 'Guardando...');

    try {
        const updated = await api(`/api/users/${user.id}/roles`, {
            method: 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify({ roles: selectedValues(elements.accessSelectedUserRoles) }),
        });

        state.access.selectedUser = updated;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Roles del usuario actualizados.', 'success');
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
        elements.accessRolesTable.innerHTML = '<tr><td colspan="4"><strong>Sin roles</strong><small>No hay roles disponibles para esta empresa.</small></td></tr>';
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
    setStatus(elements.accessStatus, `Rol seleccionado: ${role.name}.`, 'neutral');
}

async function createAccessRole() {
    const session = state.session;
    const name = elements.accessRoleName.value.trim();

    if (!session) {
        return;
    }

    if (!name) {
        setStatus(elements.accessStatus, 'Escribe el nombre del nuevo rol.', 'error');
        return;
    }

    setStatus(elements.accessStatus, 'Creando rol...');
    setButtonLoading(elements.accessCreateRole, true, 'Creando...');

    try {
        const role = await api('/api/roles', {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify({ name, permissions: [] }),
        });

        elements.accessRoleName.value = '';
        state.access.selectedRole = role;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Rol creado. Ahora puedes asignar permisos.', 'success');
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

    elements.accessSelectedRoleTitle.textContent = role ? `Permisos: ${role.name}` : 'Permisos del rol';

    if (!role) {
        elements.accessPermissionsGrid.innerHTML = '<p class="access-empty">Selecciona un rol para revisar sus permisos.</p>';
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
        setStatus(elements.accessStatus, 'Selecciona un rol antes de guardar permisos.', 'error');
        return;
    }

    const permissions = Array.from(elements.accessPermissionsGrid.querySelectorAll('[data-access-permission]:checked'))
        .map((checkbox) => checkbox.value);

    setStatus(elements.accessStatus, 'Guardando permisos del rol...');
    setButtonLoading(elements.accessSaveRolePermissions, true, 'Guardando...');

    try {
        const updated = await api(`/api/roles/${role.id}/permissions`, {
            method: 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify({ permissions }),
        });

        state.access.selectedRole = updated;
        await loadAccessControl();
        setStatus(elements.accessStatus, 'Permisos del rol actualizados.', 'success');
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
elements.inventoryCancel?.addEventListener('click', () => {
    elements.inventoryEditor.hidden = true;
    state.inventory.selectedProduct = null;
    setStatus(elements.inventoryStatus, 'Edición cancelada.');
});
elements.accessRefresh?.addEventListener('click', () => loadAccessControl());
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
