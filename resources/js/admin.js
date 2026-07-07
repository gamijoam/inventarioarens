const storageKey = 'inventory_admin_session';

const state = {
    tenants: [],
    selectedTenant: null,
    session: null,
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
    element.textContent = message;
    element.dataset.tone = tone;
}

async function api(path, options = {}, returnPayload = false) {
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
        throw new Error(firstError || payload.message || 'No se pudo completar la operación.');
    }

    return returnPayload ? payload : payload.data;
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

function clearSession() {
    localStorage.removeItem(storageKey);
    state.session = null;
    elements.dashboardView.hidden = true;
    elements.loginView.hidden = false;
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
        return;
    }

    setStatus(elements.loginStatus, 'Buscando empresas disponibles...');
    elements.loadTenants.disabled = true;

    try {
        const tenants = await api('/api/auth/tenants', {
            method: 'POST',
            body: JSON.stringify({ email }),
        });

        renderTenantOptions(tenants);

        if (tenants.length === 0) {
            setStatus(elements.loginStatus, 'Este correo no tiene empresas activas asociadas.', 'error');
            return;
        }

        setStatus(elements.loginStatus, `${tenants.length} empresa(s) disponible(s). Ahora ingresa la contraseña.`, 'success');
    } catch (error) {
        setStatus(elements.loginStatus, error.message, 'error');
    } finally {
        elements.loadTenants.disabled = false;
    }
}

async function login(event) {
    event.preventDefault();

    if (state.tenants.length === 0) {
        await loadTenants();
    }

    const tenantSlug = elements.tenant.value || state.selectedTenant?.slug;

    if (!tenantSlug) {
        setStatus(elements.loginStatus, 'Selecciona una empresa para entrar.', 'error');
        return;
    }

    setStatus(elements.loginStatus, 'Validando acceso...');
    elements.submit.disabled = true;

    try {
        const session = await api('/api/auth/login', {
            method: 'POST',
            headers: {
                'X-Tenant': tenantSlug,
            },
            body: JSON.stringify({
                email: elements.email.value.trim(),
                password: elements.password.value,
            }),
        });

        saveSession(session);
        await loadDashboard();
    } catch (error) {
        setStatus(elements.loginStatus, error.message, 'error');
    } finally {
        elements.submit.disabled = false;
    }
}

function renderDashboardShell(session) {
    state.session = session;
    elements.loginView.hidden = true;
    elements.dashboardView.hidden = false;
    elements.tenantTitle.textContent = session.tenant.name;
}

async function loadDashboard() {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.dashboardStatus, 'Cargando métricas administrativas...');
    elements.refresh.disabled = true;

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
        setStatus(elements.dashboardStatus, error.message, 'error');
    } finally {
        elements.refresh.disabled = false;
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

elements.loadTenants?.addEventListener('click', loadTenants);
elements.form?.addEventListener('submit', login);
elements.refresh?.addEventListener('click', loadDashboard);
elements.period?.addEventListener('change', loadDashboard);
elements.logout?.addEventListener('click', clearSession);
elements.tenant?.addEventListener('change', () => {
    state.selectedTenant = state.tenants.find((tenant) => tenant.slug === elements.tenant.value) ?? null;
});

restoreSession();

if (state.session) {
    loadDashboard();
}
