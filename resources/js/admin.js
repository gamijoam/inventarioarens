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

    if (!elements.modulePlaceholder) {
        return;
    }

    elements.modulePlaceholder.hidden = isOverview || isInventory;

    if (!isOverview && !isInventory) {
        elements.modulePlaceholderTitle.textContent = portalSections[selectedSection].title;
        elements.modulePlaceholderCopy.textContent = portalSections[selectedSection].copy;
    }

    if (isInventory && !state.inventory.loaded) {
        loadInventory();
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
    return state.session?.permissions?.includes('products.update') ?? false;
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
elements.tenant?.addEventListener('change', () => {
    state.selectedTenant = state.tenants.find((tenant) => tenant.slug === elements.tenant.value) ?? null;
});

restoreSession();

if (state.session) {
    loadDashboard();
}
