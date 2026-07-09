const storageKey = 'inventory_admin_session';
const requestTimeoutMs = 20000;

const state = {
    tenants: [],
    selectedTenant: null,
    session: null,
    activeSection: 'overview',
    sales: {
        page: 1,
        loaded: false,
        orders: [],
        selectedOrder: null,
    },
    reports: {
        title: 'Reportes operativos',
        copy: 'Ventas POS, metodos de pago, cajas y productos mas vendidos por periodo.',
        loaded: false,
        filterOptionsLoaded: false,
    },
    inventory: {
        page: 1,
        loaded: false,
        mode: 'edit',
        selectedProduct: null,
        priceLists: [],
        productPrices: [],
        detailProduct: null,
        rateTypes: [],
        warrantyPolicies: [],
    },
    rates: {
        title: 'Tasas de cambio',
        copy: 'Administra BCV, paralelo y cualquier tasa usada para precios, POS y reportes.',
        loaded: false,
        selectedType: null,
        rateTypes: [],
        rates: [],
    },
    movements: {
        page: 1,
        loaded: false,
        warehousesLoaded: false,
    },
    suppliers: {
        page: 1,
        loaded: false,
        selectedSupplier: null,
    },
    customers: {
        page: 1,
        loaded: false,
        selectedCustomer: null,
    },
    purchases: {
        page: 1,
        loaded: false,
        selectedPurchase: null,
        suppliersLoaded: false,
        productsLoaded: false,
        warehousesLoaded: false,
        suppliers: [],
        products: [],
        warehouses: [],
        items: [],
    },
    receivables: {
        page: 1,
        loaded: false,
        selectedReceivable: null,
        customersLoaded: false,
        customers: [],
    },
    payables: {
        page: 1,
        loaded: false,
        selectedPayable: null,
        suppliersLoaded: false,
        suppliers: [],
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
    salesModule: document.querySelector('#admin-sales-module'),
    salesRefresh: document.querySelector('#admin-sales-refresh'),
    salesExport: document.querySelector('#admin-sales-export'),
    salesStatus: document.querySelector('#admin-sales-status'),
    salesPeriod: document.querySelector('#admin-sales-period'),
    salesDateFrom: document.querySelector('#admin-sales-date-from'),
    salesDateTo: document.querySelector('#admin-sales-date-to'),
    salesBranch: document.querySelector('#admin-sales-branch'),
    salesCashRegister: document.querySelector('#admin-sales-cash-register'),
    salesCashier: document.querySelector('#admin-sales-cashier'),
    salesStatusFilter: document.querySelector('#admin-sales-status-filter'),
    salesSearch: document.querySelector('#admin-sales-search'),
    salesApply: document.querySelector('#admin-sales-apply'),
    salesClear: document.querySelector('#admin-sales-clear'),
    salesTable: document.querySelector('#admin-sales-table'),
    salesCount: document.querySelector('#admin-sales-count'),
    salesPrev: document.querySelector('#admin-sales-prev'),
    salesNext: document.querySelector('#admin-sales-next'),
    salesSummaryOrders: document.querySelector('#admin-sales-summary-orders'),
    salesSummaryPaid: document.querySelector('#admin-sales-summary-paid'),
    salesSummaryOpen: document.querySelector('#admin-sales-summary-open'),
    salesSummaryTotal: document.querySelector('#admin-sales-summary-total'),
    salesSummaryCollected: document.querySelector('#admin-sales-summary-collected'),
    salesByBranch: document.querySelector('#admin-sales-by-branch'),
    salesByCashier: document.querySelector('#admin-sales-by-cashier'),
    salesByPayment: document.querySelector('#admin-sales-by-payment'),
    salesTopProducts: document.querySelector('#admin-sales-top-products'),
    salesDetailTitle: document.querySelector('#admin-sales-detail-title'),
    salesDetailSubtitle: document.querySelector('#admin-sales-detail-subtitle'),
    salesDetailStatus: document.querySelector('#admin-sales-detail-status'),
    salesDetailTotals: document.querySelector('#admin-sales-detail-totals'),
    salesDetailItems: document.querySelector('#admin-sales-detail-items'),
    salesDetailPayments: document.querySelector('#admin-sales-detail-payments'),
    reportsModule: document.querySelector('#admin-reports-module'),
    reportsRefresh: document.querySelector('#admin-reports-refresh'),
    reportsStatus: document.querySelector('#admin-reports-status'),
    reportsPeriod: document.querySelector('#admin-reports-period'),
    reportsDateFrom: document.querySelector('#admin-reports-date-from'),
    reportsDateTo: document.querySelector('#admin-reports-date-to'),
    reportsBranch: document.querySelector('#admin-reports-branch'),
    reportsCashRegister: document.querySelector('#admin-reports-cash-register'),
    reportsCashier: document.querySelector('#admin-reports-cashier'),
    reportsOrderStatus: document.querySelector('#admin-reports-order-status'),
    reportsClearFilters: document.querySelector('#admin-reports-clear-filters'),
    reportsExportOrders: document.querySelector('#admin-reports-export-orders'),
    reportsExportPayments: document.querySelector('#admin-reports-export-payments'),
    reportsExportProducts: document.querySelector('#admin-reports-export-products'),
    reportsExportCash: document.querySelector('#admin-reports-export-cash'),
    reportsPosTotal: document.querySelector('#admin-reports-pos-total'),
    reportsPosCount: document.querySelector('#admin-reports-pos-count'),
    reportsTicket: document.querySelector('#admin-reports-ticket'),
    reportsPending: document.querySelector('#admin-reports-pending'),
    reportsPendingTotal: document.querySelector('#admin-reports-pending-total'),
    reportsOpenCash: document.querySelector('#admin-reports-open-cash'),
    reportsCashExpected: document.querySelector('#admin-reports-cash-expected'),
    reportsOrdersTable: document.querySelector('#admin-reports-orders-table'),
    reportsPaymentsTable: document.querySelector('#admin-reports-payments-table'),
    reportsProductsTable: document.querySelector('#admin-reports-products-table'),
    reportsCashTable: document.querySelector('#admin-reports-cash-table'),
    inventoryModule: document.querySelector('#admin-inventory-module'),
    inventoryNew: document.querySelector('#admin-inventory-new'),
    inventoryRefresh: document.querySelector('#admin-inventory-refresh'),
    inventorySearch: document.querySelector('#admin-inventory-search'),
    inventoryTracking: document.querySelector('#admin-inventory-tracking'),
    inventoryStock: document.querySelector('#admin-inventory-stock'),
    inventoryActive: document.querySelector('#admin-inventory-active'),
    inventoryQuickStatus: document.querySelector('#admin-inventory-quick-status'),
    inventoryFilterSummary: document.querySelector('#admin-inventory-filter-summary'),
    inventoryApply: document.querySelector('#admin-inventory-apply'),
    inventoryTable: document.querySelector('#admin-inventory-table'),
    inventoryCount: document.querySelector('#admin-inventory-count'),
    inventoryPrev: document.querySelector('#admin-inventory-prev'),
    inventoryNext: document.querySelector('#admin-inventory-next'),
    inventoryStatus: document.querySelector('#admin-inventory-status'),
    inventoryEditor: document.querySelector('#admin-inventory-editor'),
    inventoryEditorTitle: document.querySelector('#admin-inventory-editor-title'),
    inventoryEditorSubtitle: document.querySelector('#admin-inventory-editor-subtitle'),
    inventoryName: document.querySelector('#admin-inventory-name'),
    inventorySku: document.querySelector('#admin-inventory-sku'),
    inventoryTrackingEdit: document.querySelector('#admin-inventory-tracking-edit'),
    inventoryPrice: document.querySelector('#admin-inventory-price'),
    inventoryCurrency: document.querySelector('#admin-inventory-currency'),
    inventoryRateType: document.querySelector('#admin-inventory-rate-type'),
    inventoryWarranty: document.querySelector('#admin-inventory-warranty'),
    inventoryActiveEdit: document.querySelector('#admin-inventory-active-edit'),
    inventorySave: document.querySelector('#admin-inventory-save'),
    inventoryDeactivate: document.querySelector('#admin-inventory-deactivate'),
    inventoryCancel: document.querySelector('#admin-inventory-cancel'),
    inventoryDetail: document.querySelector('#admin-inventory-detail'),
    inventoryDetailTitle: document.querySelector('#admin-inventory-detail-title'),
    inventoryDetailSubtitle: document.querySelector('#admin-inventory-detail-subtitle'),
    inventoryDetailClose: document.querySelector('#admin-inventory-detail-close'),
    inventoryDetailEdit: document.querySelector('#admin-inventory-detail-edit'),
    inventoryDetailToggle: document.querySelector('#admin-inventory-detail-toggle'),
    inventoryDetailMeta: document.querySelector('#admin-inventory-detail-meta'),
    inventoryDetailStockTotal: document.querySelector('#admin-inventory-detail-stock-total'),
    inventoryDetailStock: document.querySelector('#admin-inventory-detail-stock'),
    inventoryDetailPriceCount: document.querySelector('#admin-inventory-detail-price-count'),
    inventoryDetailPrices: document.querySelector('#admin-inventory-detail-prices'),
    inventoryDetailChangeCount: document.querySelector('#admin-inventory-detail-change-count'),
    inventoryDetailChanges: document.querySelector('#admin-inventory-detail-changes'),
    priceListRows: document.querySelector('#admin-price-list-rows'),
    priceListSave: document.querySelector('#admin-price-list-save'),
    priceCopyBase: document.querySelector('#admin-price-copy-base'),
    ratesModule: document.querySelector('#admin-rates-module'),
    ratesRefresh: document.querySelector('#admin-rates-refresh'),
    rateTypesTable: document.querySelector('#admin-rate-types-table'),
    ratesTable: document.querySelector('#admin-rates-table'),
    ratesStatus: document.querySelector('#admin-rates-status'),
    rateTypeTitle: document.querySelector('#admin-rate-type-title'),
    rateTypeCode: document.querySelector('#admin-rate-type-code'),
    rateTypeName: document.querySelector('#admin-rate-type-name'),
    rateTypeDefault: document.querySelector('#admin-rate-type-default'),
    rateTypeActive: document.querySelector('#admin-rate-type-active'),
    rateTypeSave: document.querySelector('#admin-rate-type-save'),
    rateTypeDeactivate: document.querySelector('#admin-rate-type-deactivate'),
    rateTypeCancel: document.querySelector('#admin-rate-type-cancel'),
    rateValueType: document.querySelector('#admin-rate-value-type'),
    rateValue: document.querySelector('#admin-rate-value'),
    rateEffectiveAt: document.querySelector('#admin-rate-effective-at'),
    rateSource: document.querySelector('#admin-rate-source'),
    rateActive: document.querySelector('#admin-rate-active'),
    rateSave: document.querySelector('#admin-rate-save'),
    movementsModule: document.querySelector('#admin-movements-module'),
    movementsRefresh: document.querySelector('#admin-movements-refresh'),
    movementsSearch: document.querySelector('#admin-movements-search'),
    movementsType: document.querySelector('#admin-movements-type'),
    movementsWarehouse: document.querySelector('#admin-movements-warehouse'),
    movementsFrom: document.querySelector('#admin-movements-from'),
    movementsTo: document.querySelector('#admin-movements-to'),
    movementsApply: document.querySelector('#admin-movements-apply'),
    movementsClear: document.querySelector('#admin-movements-clear'),
    movementsTable: document.querySelector('#admin-movements-table'),
    movementsCount: document.querySelector('#admin-movements-count'),
    movementsPrev: document.querySelector('#admin-movements-prev'),
    movementsNext: document.querySelector('#admin-movements-next'),
    movementsStatus: document.querySelector('#admin-movements-status'),
    suppliersModule: document.querySelector('#admin-suppliers-module'),
    suppliersRefresh: document.querySelector('#admin-suppliers-refresh'),
    suppliersSearch: document.querySelector('#admin-suppliers-search'),
    suppliersActive: document.querySelector('#admin-suppliers-active'),
    suppliersApply: document.querySelector('#admin-suppliers-apply'),
    suppliersClear: document.querySelector('#admin-suppliers-clear'),
    suppliersTable: document.querySelector('#admin-suppliers-table'),
    suppliersCount: document.querySelector('#admin-suppliers-count'),
    suppliersPrev: document.querySelector('#admin-suppliers-prev'),
    suppliersNext: document.querySelector('#admin-suppliers-next'),
    suppliersStatus: document.querySelector('#admin-suppliers-status'),
    supplierNew: document.querySelector('#admin-supplier-new'),
    supplierEditor: document.querySelector('#admin-supplier-editor'),
    supplierEditorTitle: document.querySelector('#admin-supplier-editor-title'),
    supplierEditorSubtitle: document.querySelector('#admin-supplier-editor-subtitle'),
    supplierName: document.querySelector('#admin-supplier-name'),
    supplierDocumentType: document.querySelector('#admin-supplier-document-type'),
    supplierDocumentNumber: document.querySelector('#admin-supplier-document-number'),
    supplierPhone: document.querySelector('#admin-supplier-phone'),
    supplierEmail: document.querySelector('#admin-supplier-email'),
    supplierAddress: document.querySelector('#admin-supplier-address'),
    supplierNotes: document.querySelector('#admin-supplier-notes'),
    supplierActiveEdit: document.querySelector('#admin-supplier-active-edit'),
    supplierSave: document.querySelector('#admin-supplier-save'),
    supplierDeactivate: document.querySelector('#admin-supplier-deactivate'),
    supplierCancel: document.querySelector('#admin-supplier-cancel'),
    customersModule: document.querySelector('#admin-customers-module'),
    customersRefresh: document.querySelector('#admin-customers-refresh'),
    customersSearch: document.querySelector('#admin-customers-search'),
    customersActive: document.querySelector('#admin-customers-active'),
    customersType: document.querySelector('#admin-customers-type'),
    customersApply: document.querySelector('#admin-customers-apply'),
    customersClear: document.querySelector('#admin-customers-clear'),
    customersTable: document.querySelector('#admin-customers-table'),
    customersCount: document.querySelector('#admin-customers-count'),
    customersPrev: document.querySelector('#admin-customers-prev'),
    customersNext: document.querySelector('#admin-customers-next'),
    customersStatus: document.querySelector('#admin-customers-status'),
    customerNew: document.querySelector('#admin-customer-new'),
    customerEditor: document.querySelector('#admin-customer-editor'),
    customerEditorTitle: document.querySelector('#admin-customer-editor-title'),
    customerEditorSubtitle: document.querySelector('#admin-customer-editor-subtitle'),
    customerName: document.querySelector('#admin-customer-name'),
    customerDocumentType: document.querySelector('#admin-customer-document-type'),
    customerDocumentNumber: document.querySelector('#admin-customer-document-number'),
    customerPhone: document.querySelector('#admin-customer-phone'),
    customerEmail: document.querySelector('#admin-customer-email'),
    customerAddress: document.querySelector('#admin-customer-address'),
    customerGenericEdit: document.querySelector('#admin-customer-generic-edit'),
    customerActiveEdit: document.querySelector('#admin-customer-active-edit'),
    customerSave: document.querySelector('#admin-customer-save'),
    customerDeactivate: document.querySelector('#admin-customer-deactivate'),
    customerCancel: document.querySelector('#admin-customer-cancel'),
    purchasesModule: document.querySelector('#admin-purchases-module'),
    purchasesRefresh: document.querySelector('#admin-purchases-refresh'),
    purchaseNew: document.querySelector('#admin-purchase-new'),
    purchasesSearch: document.querySelector('#admin-purchases-search'),
    purchasesStatusFilter: document.querySelector('#admin-purchases-status-filter'),
    purchasesSupplierFilter: document.querySelector('#admin-purchases-supplier-filter'),
    purchasesApply: document.querySelector('#admin-purchases-apply'),
    purchasesClear: document.querySelector('#admin-purchases-clear'),
    purchasesTable: document.querySelector('#admin-purchases-table'),
    purchasesCount: document.querySelector('#admin-purchases-count'),
    purchasesPrev: document.querySelector('#admin-purchases-prev'),
    purchasesNext: document.querySelector('#admin-purchases-next'),
    purchasesStatus: document.querySelector('#admin-purchases-status'),
    purchaseEditor: document.querySelector('#admin-purchase-editor'),
    purchaseEditorTitle: document.querySelector('#admin-purchase-editor-title'),
    purchaseEditorSubtitle: document.querySelector('#admin-purchase-editor-subtitle'),
    purchaseSupplier: document.querySelector('#admin-purchase-supplier'),
    purchaseDocument: document.querySelector('#admin-purchase-document'),
    purchaseIssuedAt: document.querySelector('#admin-purchase-issued-at'),
    purchaseDueDate: document.querySelector('#admin-purchase-due-date'),
    purchaseCurrency: document.querySelector('#admin-purchase-currency'),
    purchaseProduct: document.querySelector('#admin-purchase-product'),
    purchaseWarehouse: document.querySelector('#admin-purchase-warehouse'),
    purchaseQuantity: document.querySelector('#admin-purchase-quantity'),
    purchaseUnitCost: document.querySelector('#admin-purchase-unit-cost'),
    purchaseAddItem: document.querySelector('#admin-purchase-add-item'),
    purchaseItemsTotal: document.querySelector('#admin-purchase-items-total'),
    purchaseItemsTable: document.querySelector('#admin-purchase-items-table'),
    purchaseSummary: document.querySelector('#admin-purchase-summary'),
    purchaseSave: document.querySelector('#admin-purchase-save'),
    purchaseReceive: document.querySelector('#admin-purchase-receive'),
    purchaseCancelOrder: document.querySelector('#admin-purchase-cancel-order'),
    purchaseClear: document.querySelector('#admin-purchase-clear'),
    receivablesModule: document.querySelector('#admin-receivables-module'),
    receivablesRefresh: document.querySelector('#admin-receivables-refresh'),
    receivablesSearch: document.querySelector('#admin-receivables-search'),
    receivablesStatusFilter: document.querySelector('#admin-receivables-status-filter'),
    receivablesCustomerFilter: document.querySelector('#admin-receivables-customer-filter'),
    receivablesDueFrom: document.querySelector('#admin-receivables-due-from'),
    receivablesDueTo: document.querySelector('#admin-receivables-due-to'),
    receivablesApply: document.querySelector('#admin-receivables-apply'),
    receivablesClear: document.querySelector('#admin-receivables-clear'),
    receivablesTable: document.querySelector('#admin-receivables-table'),
    receivablesCount: document.querySelector('#admin-receivables-count'),
    receivablesPrev: document.querySelector('#admin-receivables-prev'),
    receivablesNext: document.querySelector('#admin-receivables-next'),
    receivablesStatus: document.querySelector('#admin-receivables-status'),
    receivableEditor: document.querySelector('#admin-receivable-editor'),
    receivableTitle: document.querySelector('#admin-receivable-title'),
    receivableSubtitle: document.querySelector('#admin-receivable-subtitle'),
    receivableSummary: document.querySelector('#admin-receivable-summary'),
    receivablePaymentsCount: document.querySelector('#admin-receivable-payments-count'),
    receivablePaymentsTable: document.querySelector('#admin-receivable-payments-table'),
    receivablePaymentCurrency: document.querySelector('#admin-receivable-payment-currency'),
    receivablePaymentAmount: document.querySelector('#admin-receivable-payment-amount'),
    receivablePaymentMethod: document.querySelector('#admin-receivable-payment-method'),
    receivablePaymentReference: document.querySelector('#admin-receivable-payment-reference'),
    receivablePaymentNotes: document.querySelector('#admin-receivable-payment-notes'),
    receivableCollect: document.querySelector('#admin-receivable-collect'),
    receivableFillBalance: document.querySelector('#admin-receivable-fill-balance'),
    payablesModule: document.querySelector('#admin-payables-module'),
    payablesRefresh: document.querySelector('#admin-payables-refresh'),
    payablesSearch: document.querySelector('#admin-payables-search'),
    payablesStatusFilter: document.querySelector('#admin-payables-status-filter'),
    payablesSupplierFilter: document.querySelector('#admin-payables-supplier-filter'),
    payablesDueFrom: document.querySelector('#admin-payables-due-from'),
    payablesDueTo: document.querySelector('#admin-payables-due-to'),
    payablesApply: document.querySelector('#admin-payables-apply'),
    payablesClear: document.querySelector('#admin-payables-clear'),
    payablesTable: document.querySelector('#admin-payables-table'),
    payablesCount: document.querySelector('#admin-payables-count'),
    payablesPrev: document.querySelector('#admin-payables-prev'),
    payablesNext: document.querySelector('#admin-payables-next'),
    payablesStatus: document.querySelector('#admin-payables-status'),
    payableEditor: document.querySelector('#admin-payable-editor'),
    payableTitle: document.querySelector('#admin-payable-title'),
    payableSubtitle: document.querySelector('#admin-payable-subtitle'),
    payableSummary: document.querySelector('#admin-payable-summary'),
    payablePaymentsCount: document.querySelector('#admin-payable-payments-count'),
    payablePaymentsTable: document.querySelector('#admin-payable-payments-table'),
    payablePaymentCurrency: document.querySelector('#admin-payable-payment-currency'),
    payablePaymentAmount: document.querySelector('#admin-payable-payment-amount'),
    payablePaymentMethod: document.querySelector('#admin-payable-payment-method'),
    payablePaymentReference: document.querySelector('#admin-payable-payment-reference'),
    payablePaymentNotes: document.querySelector('#admin-payable-payment-notes'),
    payablePay: document.querySelector('#admin-payable-pay'),
    payableFillBalance: document.querySelector('#admin-payable-fill-balance'),
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
        'currency.manage',
        'inventory.view',
        'product_entries.view',
        'product_exits.view',
        'inventory_transfers.view',
        'purchases.view',
        'accounts_receivable.view',
        'accounts_receivable.collect',
        'accounts_payable.view',
        'accounts_payable.pay',
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
    reports: {
        title: 'Reportes operativos',
        copy: 'Ventas POS, metodos de pago, cajas y productos mas vendidos por periodo.',
    },
    rates: {
        title: 'Tasas de cambio',
        copy: 'Administra BCV, paralelo y cualquier tasa usada para precios, POS y reportes.',
    },
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
    movements: {
        title: 'Movimientos de inventario',
        copy: 'Historial de entradas, salidas, ventas, ajustes, devoluciones y traslados de la empresa activa.',
    },
    suppliers: {
        title: 'Proveedores',
        copy: 'Gestion de proveedores, documentos fiscales, contactos y estado operativo para compras.',
    },
    customers: {
        title: 'Clientes',
        copy: 'Gestion de clientes, documentos, contacto y estado para ventas POS, cartera y reportes.',
    },
    purchases: {
        title: 'Compras',
        copy: 'Ordenes de compra, recepcion de mercancia y costos que alimentan inventario.',
    },
    receivables: {
        title: 'Cuentas por cobrar',
        copy: 'Saldos de clientes, vencimientos y registro de abonos parciales o totales.',
    },
    payables: {
        title: 'Cuentas por pagar',
        copy: 'Saldos de proveedores, vencimientos y registro de pagos parciales o totales.',
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
    state.sales.page = 1;
    state.sales.loaded = false;
    state.sales.orders = [];
    state.sales.selectedOrder = null;

    state.inventory.page = 1;
    state.inventory.loaded = false;
    state.inventory.mode = 'edit';
    state.inventory.selectedProduct = null;
    state.inventory.priceLists = [];
    state.inventory.productPrices = [];
    state.inventory.rateTypes = [];
    state.inventory.warrantyPolicies = [];

    state.rates.loaded = false;
    state.rates.selectedType = null;
    state.rates.rateTypes = [];
    state.rates.rates = [];

    state.movements.page = 1;
    state.movements.loaded = false;
    state.movements.warehousesLoaded = false;

    state.suppliers.page = 1;
    state.suppliers.loaded = false;
    state.suppliers.selectedSupplier = null;

    state.customers.page = 1;
    state.customers.loaded = false;
    state.customers.selectedCustomer = null;

    state.purchases.page = 1;
    state.purchases.loaded = false;
    state.purchases.selectedPurchase = null;
    state.purchases.suppliersLoaded = false;
    state.purchases.productsLoaded = false;
    state.purchases.warehousesLoaded = false;
    state.purchases.suppliers = [];
    state.purchases.products = [];
    state.purchases.warehouses = [];
    state.purchases.items = [];

    state.payables.page = 1;
    state.payables.loaded = false;
    state.payables.selectedPayable = null;
    state.payables.suppliersLoaded = false;
    state.payables.suppliers = [];

    state.receivables.page = 1;
    state.receivables.loaded = false;
    state.receivables.selectedReceivable = null;
    state.receivables.customersLoaded = false;
    state.receivables.customers = [];

    state.reports.loaded = false;
    state.reports.filterOptionsLoaded = false;

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

    if (elements.movementsTable) {
        elements.movementsTable.innerHTML = '';
    }

    if (elements.suppliersTable) {
        elements.suppliersTable.innerHTML = '';
    }

    if (elements.purchasesTable) {
        elements.purchasesTable.innerHTML = '';
    }

    if (elements.purchaseItemsTable) {
        elements.purchaseItemsTable.innerHTML = '';
    }

    if (elements.payablesTable) {
        elements.payablesTable.innerHTML = '';
    }

    if (elements.payablePaymentsTable) {
        elements.payablePaymentsTable.innerHTML = '';
    }

    if (elements.receivablesTable) {
        elements.receivablesTable.innerHTML = '';
    }

    if (elements.receivablePaymentsTable) {
        elements.receivablePaymentsTable.innerHTML = '';
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

    if (elements.salesTable) {
        elements.salesTable.innerHTML = '';
    }

    renderAdminSalesAnalytics({});
    renderAdminSaleDetail(null);
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
    const isSales = selectedSection === 'sales';
    const isReports = selectedSection === 'reports';
    const isInventory = selectedSection === 'inventory';
    const isRates = selectedSection === 'rates';
    const isMovements = selectedSection === 'movements';
    const isSuppliers = selectedSection === 'suppliers';
    const isCustomers = selectedSection === 'customers';
    const isPurchases = selectedSection === 'purchases';
    const isReceivables = selectedSection === 'receivables';
    const isPayables = selectedSection === 'payables';
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

    if (elements.reportsModule) {
        elements.reportsModule.hidden = !isReports;
    }

    if (elements.salesModule) {
        elements.salesModule.hidden = !isSales;
    }

    if (elements.inventoryModule) {
        elements.inventoryModule.hidden = !isInventory;
    }

    if (elements.ratesModule) {
        elements.ratesModule.hidden = !isRates;
    }

    if (elements.movementsModule) {
        elements.movementsModule.hidden = !isMovements;
    }

    if (elements.suppliersModule) {
        elements.suppliersModule.hidden = !isSuppliers;
    }

    if (elements.customersModule) {
        elements.customersModule.hidden = !isCustomers;
    }

    if (elements.purchasesModule) {
        elements.purchasesModule.hidden = !isPurchases;
    }

    if (elements.receivablesModule) {
        elements.receivablesModule.hidden = !isReceivables;
    }

    if (elements.payablesModule) {
        elements.payablesModule.hidden = !isPayables;
    }

    if (elements.accessModule) {
        elements.accessModule.hidden = !isAccess;
    }

    if (!elements.modulePlaceholder) {
        return;
    }

    elements.modulePlaceholder.hidden = isOverview || isSales || isReports || isInventory || isRates || isMovements || isSuppliers || isCustomers || isPurchases || isReceivables || isPayables || isAccess;

    if (!isOverview && !isSales && !isReports && !isInventory && !isRates && !isMovements && !isSuppliers && !isCustomers && !isPurchases && !isReceivables && !isPayables && !isAccess) {
        elements.modulePlaceholderTitle.textContent = portalSections[selectedSection].title;
        elements.modulePlaceholderCopy.textContent = portalSections[selectedSection].copy;
    }

    if (isSales && !state.sales.loaded) {
        loadAdminSales();
    }

    if (isReports && !state.reports.loaded) {
        loadOperationalReports();
    }

    if (isInventory && !state.inventory.loaded) {
        loadInventory();
        loadInventoryPriceLists().catch((error) => {
            setStatus(elements.inventoryStatus, normalizeError(error), 'error');
        });
    }

    if (isRates && !state.rates.loaded) {
        loadRates();
    }

    if (isMovements && !state.movements.loaded) {
        loadMovements();
    }

    if (isSuppliers && !state.suppliers.loaded) {
        loadSuppliers();
    }

    if (isCustomers && !state.customers.loaded) {
        loadCustomers();
    }

    if (isPurchases && !state.purchases.loaded) {
        loadPurchases();
    }

    if (isReceivables && !state.receivables.loaded) {
        loadReceivables();
    }

    if (isPayables && !state.payables.loaded) {
        loadPayables();
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

async function loadAdminSales(page = state.sales.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.sales.page = Math.max(1, page);
    setStatus(elements.salesStatus, 'Cargando ventas POS...');
    setButtonLoading(elements.salesRefresh, true, 'Actualizando...');

    try {
        const query = buildAdminSalesQuery({ page: state.sales.page });
        const sales = await api(`/api/admin-portal/pos-sales?${query}`, {
            headers: authHeaders(session),
        });

        renderAdminSales(sales);
        state.sales.loaded = true;
        setStatus(elements.salesStatus, `Ventas actualizadas: ${formatDateTime(sales.generated_at)}.`, 'success');
    } catch (error) {
        setStatus(elements.salesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.salesRefresh, false);
    }
}

function buildAdminSalesQuery(extra = {}) {
    const query = new URLSearchParams({
        period: elements.period?.value || 'today',
        limit: '25',
        ...extra,
    });
    const filters = {
        date_from: elements.salesDateFrom?.value,
        date_to: elements.salesDateTo?.value,
        branch_id: elements.salesBranch?.value,
        cash_register_id: elements.salesCashRegister?.value,
        cashier_id: elements.salesCashier?.value,
        status: elements.salesStatusFilter?.value || 'all',
        search: elements.salesSearch?.value?.trim(),
    };

    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            query.set(key, value);
        }
    });

    return query;
}

function renderAdminSales(sales) {
    const summary = sales.summary || {};
    const pagination = sales.pagination || {};

    if (elements.salesDateFrom && !elements.salesDateFrom.value) {
        elements.salesDateFrom.value = sales.period?.from || '';
    }

    if (elements.salesDateTo && !elements.salesDateTo.value) {
        elements.salesDateTo.value = sales.period?.to || '';
    }

    renderAdminSalesFilters(sales.filters || {});
    elements.salesPeriod.textContent = `Periodo ${sales.period?.from || ''} a ${sales.period?.to || ''}.`;
    elements.salesSummaryOrders.textContent = number(summary.orders_count);
    elements.salesSummaryPaid.textContent = number(summary.paid_count);
    elements.salesSummaryOpen.textContent = number(summary.open_count);
    elements.salesSummaryTotal.textContent = money(summary.total_base_amount);
    elements.salesSummaryCollected.textContent = money(summary.paid_base_amount);
    renderAdminSalesAnalytics(sales.analytics || {});
    state.sales.orders = sales.data || [];
    renderAdminSalesTable(state.sales.orders);

    elements.salesCount.textContent = `${number(pagination.from)}-${number(pagination.to)} de ${number(pagination.total)} ordenes`;
    elements.salesPrev.disabled = !pagination.has_previous;
    elements.salesNext.disabled = !pagination.has_next;
}

function renderAdminSalesFilters(filters) {
    const options = filters.options || {};
    fillSelect(elements.salesBranch, options.branches || [], 'Todas', (branch) => branch.id, (branch) => `${branch.name} (${branch.code})`);
    fillSelect(elements.salesCashier, options.cashiers || [], 'Todos', (cashier) => cashier.id, (cashier) => `${cashier.name} - ${cashier.email}`);

    const selectedBranch = elements.salesBranch?.value || '';
    const cashRegisters = selectedBranch
        ? (options.cash_registers || []).filter((register) => String(register.branch_id) === String(selectedBranch))
        : (options.cash_registers || []);
    fillSelect(elements.salesCashRegister, cashRegisters, 'Todas', (register) => register.id, (register) => `${register.name} (${register.code})`);
}

function renderAdminSalesAnalytics(analytics) {
    renderAdminSalesRanking(elements.salesByBranch, analytics.by_branch || [], {
        empty: 'Sin ventas por sucursal.',
        title: (item) => item.name,
        meta: (item) => `${number(item.orders_count)} ordenes`,
        value: (item) => money(item.paid_base_amount),
        amount: (item) => item.paid_base_amount,
    });
    renderAdminSalesRanking(elements.salesByCashier, analytics.by_cashier || [], {
        empty: 'Sin ventas por cajero.',
        title: (item) => item.name,
        meta: (item) => `${number(item.orders_count)} ordenes`,
        value: (item) => money(item.paid_base_amount),
        amount: (item) => item.paid_base_amount,
    });
    renderAdminSalesRanking(elements.salesByPayment, analytics.by_payment_method || [], {
        empty: 'Sin pagos capturados.',
        title: (item) => item.name,
        meta: (item) => `${number(item.payments_count)} pago(s) - ${item.currency || 'USD'}`,
        value: (item) => money(item.amount_base),
        amount: (item) => item.amount_base,
    });
    renderAdminSalesRanking(elements.salesTopProducts, analytics.top_products || [], {
        empty: 'Sin productos vendidos.',
        title: (item) => item.product_name,
        meta: (item) => item.product_sku || 'Sin SKU',
        value: (item) => `${number(item.quantity)} un.`,
        amount: (item) => item.quantity,
    });
}

function renderAdminSalesRanking(container, rows, config) {
    if (!container) {
        return;
    }

    if (!rows.length) {
        container.innerHTML = `<p class="sales-admin__ranking-empty">${escapeHtml(config.empty)}</p>`;
        return;
    }

    const max = Math.max(...rows.map((item) => Number(config.amount(item) || 0)), 1);

    container.replaceChildren(
        ...rows.map((item) => {
            const amount = Number(config.amount(item) || 0);
            const width = Math.max(4, Math.round((amount / max) * 100));
            const row = document.createElement('article');
            row.className = 'sales-admin__rank-row';
            row.innerHTML = `
                <div>
                    <strong>${escapeHtml(config.title(item))}</strong>
                    <span>${escapeHtml(config.meta(item))}</span>
                </div>
                <em>${escapeHtml(config.value(item))}</em>
                <div class="sales-admin__rank-bar" aria-hidden="true"><i style="width: ${width}%"></i></div>
            `;

            return row;
        }),
    );
}

function renderAdminSalesTable(orders) {
    if (!orders.length) {
        renderEmptyTable(elements.salesTable, 'No hay ventas POS con estos filtros.', 8);
        return;
    }

    elements.salesTable.replaceChildren(
        ...orders.map((order) => {
            const row = document.createElement('tr');
            row.classList.toggle('is-selected', state.sales.selectedOrder?.id === order.id);
            row.innerHTML = `
                <td><strong>#${escapeHtml(order.id)}</strong><small>${escapeHtml(formatDateTime(order.paid_at || order.opened_at))}</small></td>
                <td><strong>${escapeHtml(order.customer_name)}</strong><small>${escapeHtml(order.customer_document || 'Sin documento')}</small></td>
                <td><strong>${escapeHtml(order.cash_register_name)}</strong><small>${escapeHtml(order.branch_name)}</small></td>
                <td>${escapeHtml(order.cashier_name)}</td>
                <td><span class="status-pill" data-tone="${posOrderStatusTone(order.status)}">${escapeHtml(order.status_label)}</span></td>
                <td><strong>${money(order.total_base_amount)}</strong><small>Bs ${number(order.total_local_amount)}</small></td>
                <td><strong>${money(order.paid_base_amount)}</strong><small>Saldo ${money(order.balance_base_amount)}</small></td>
                <td><button class="ghost-button ghost-button--compact" type="button" data-admin-sales-detail="${escapeHtml(order.id)}">Ver</button></td>
            `;

            return row;
        }),
    );
}

async function loadAdminSaleDetail(orderId) {
    const session = state.session;

    if (!session || !orderId) {
        return;
    }

    setStatus(elements.salesStatus, `Cargando detalle de orden #${orderId}...`);

    try {
        const order = await api(`/api/admin-portal/pos-sales/${orderId}`, {
            headers: authHeaders(session),
        });

        state.sales.selectedOrder = order;
        renderAdminSaleDetail(order);
        renderAdminSalesTable(state.sales.orders);
        setStatus(elements.salesStatus, `Detalle de orden #${order.id} cargado.`, 'success');
    } catch (error) {
        setStatus(elements.salesStatus, normalizeError(error), 'error');
    }
}

function renderAdminSaleDetail(order) {
    if (!elements.salesDetailTitle) {
        return;
    }

    if (!order) {
        state.sales.selectedOrder = null;
        elements.salesDetailTitle.textContent = 'Detalle de venta';
        elements.salesDetailSubtitle.textContent = 'Selecciona una orden para revisar items y pagos.';
        elements.salesDetailStatus.textContent = 'Sin seleccion';
        elements.salesDetailStatus.dataset.tone = 'neutral';
        elements.salesDetailTotals.innerHTML = `
            <div><span>Total</span><strong>USD 0.00</strong></div>
            <div><span>Pagado</span><strong>USD 0.00</strong></div>
            <div><span>Saldo</span><strong>USD 0.00</strong></div>
        `;
        elements.salesDetailItems.innerHTML = '<p>Sin orden seleccionada.</p>';
        elements.salesDetailPayments.innerHTML = '<p>Sin pagos cargados.</p>';
        return;
    }

    elements.salesDetailTitle.textContent = `Orden POS #${order.id}`;
    elements.salesDetailSubtitle.textContent = `${order.customer_name} - ${order.cash_register_name} - ${formatDateTime(order.paid_at || order.opened_at)}`;
    elements.salesDetailStatus.textContent = order.status_label;
    elements.salesDetailStatus.dataset.tone = posOrderStatusTone(order.status);
    elements.salesDetailTotals.innerHTML = `
        <div><span>Total</span><strong>${money(order.total_base_amount)}</strong></div>
        <div><span>Pagado</span><strong>${money(order.paid_base_amount)}</strong></div>
        <div><span>Saldo</span><strong>${money(order.balance_base_amount)}</strong></div>
    `;
    renderAdminSaleItems(order.items || []);
    renderAdminSalePayments(order.payments || []);
}

function renderAdminSaleItems(items) {
    if (!items.length) {
        elements.salesDetailItems.innerHTML = '<p>Sin productos registrados.</p>';
        return;
    }

    elements.salesDetailItems.replaceChildren(
        ...items.map((item) => {
            const row = document.createElement('article');
            row.className = 'sales-admin__detail-row';
            row.innerHTML = `
                <div>
                    <strong>${escapeHtml(item.product_name)}</strong>
                    <small>${escapeHtml(item.product_sku || '')} - ${escapeHtml(item.warehouse_name || '')}</small>
                    ${item.product_unit_ids?.length ? `<small>Seriales/IMEI: ${escapeHtml(item.product_unit_ids.join(', '))}</small>` : ''}
                </div>
                <div>
                    <strong>${number(item.quantity)} x ${escapeHtml(item.sale_currency)} ${number(item.unit_price)}</strong>
                    <small>Total ${money(item.base_total_amount)}</small>
                </div>
            `;
            return row;
        }),
    );
}

function renderAdminSalePayments(payments) {
    if (!payments.length) {
        elements.salesDetailPayments.innerHTML = '<p>Sin pagos capturados.</p>';
        return;
    }

    elements.salesDetailPayments.replaceChildren(
        ...payments.map((payment) => {
            const row = document.createElement('article');
            row.className = 'sales-admin__detail-row';
            row.innerHTML = `
                <div>
                    <strong>${escapeHtml(payment.payment_method_name)}</strong>
                    <small>${escapeHtml(payment.status)} ${payment.reference ? `- Ref. ${escapeHtml(payment.reference)}` : ''}</small>
                </div>
                <div>
                    <strong>${escapeHtml(payment.currency)} ${number(payment.amount)}</strong>
                    <small>${money(payment.amount_base)}</small>
                </div>
            `;
            return row;
        }),
    );
}

function clearAdminSalesFilters() {
    [
        elements.salesDateFrom,
        elements.salesDateTo,
        elements.salesBranch,
        elements.salesCashRegister,
        elements.salesCashier,
        elements.salesSearch,
    ].forEach((field) => {
        if (field) {
            field.value = '';
        }
    });

    if (elements.salesStatusFilter) {
        elements.salesStatusFilter.value = 'all';
    }

    loadAdminSales(1);
}

async function exportAdminSales() {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.salesStatus, 'Preparando CSV de ventas...');
    setButtonLoading(elements.salesExport, true, 'Exportando...');

    try {
        const query = buildAdminSalesQuery({ export: 'csv' });
        const response = await fetch(`/api/admin-portal/pos-sales?${query}`, {
            headers: {
                Accept: 'text/csv',
                ...authHeaders(session),
            },
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || 'No se pudo exportar ventas POS.');
        }

        const blob = await response.blob();
        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = csvFilenameFromResponse(response, 'ventas-pos.csv');
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(downloadUrl);
        setStatus(elements.salesStatus, 'CSV de ventas generado correctamente.', 'success');
    } catch (error) {
        setStatus(elements.salesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.salesExport, false);
    }
}

function posOrderStatusTone(status) {
    return {
        paid: 'success',
        open: 'warning',
        cancelled: 'error',
    }[status] || 'neutral';
}

async function loadOperationalReports() {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.reportsStatus, 'Cargando reportes operativos...');
    setButtonLoading(elements.reportsRefresh, true, 'Actualizando...');

    try {
        const query = buildOperationalReportQuery();
        const report = await api(`/api/admin-portal/operational-reports?${query}`, {
            headers: authHeaders(session),
        });

        renderOperationalReports(report);
        state.reports.loaded = true;
        setStatus(elements.reportsStatus, `Reportes actualizados: ${formatDateTime(report.generated_at)}.`, 'success');
    } catch (error) {
        setStatus(elements.reportsStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.reportsRefresh, false);
    }
}

function renderOperationalReports(report) {
    const sales = report.sales || {};
    const cash = report.cash_register || {};

    if (elements.reportsDateFrom && !elements.reportsDateFrom.value) {
        elements.reportsDateFrom.value = report.period.from;
    }

    if (elements.reportsDateTo && !elements.reportsDateTo.value) {
        elements.reportsDateTo.value = report.period.to;
    }

    renderOperationalReportFilters(report.filters || {});

    elements.reportsPeriod.textContent = `Periodo ${report.period.from} a ${report.period.to}.`;
    elements.reportsPosTotal.textContent = money(sales.pos_paid_base_amount);
    elements.reportsPosCount.textContent = `${number(sales.pos_paid_count)} venta(s) POS`;
    elements.reportsTicket.textContent = money(sales.average_ticket_base_amount);
    elements.reportsPending.textContent = number(sales.pending_pos_count);
    elements.reportsPendingTotal.textContent = `${money(sales.pending_pos_base_amount)} por cerrar`;
    elements.reportsOpenCash.textContent = number(cash.open_count);
    elements.reportsCashExpected.textContent = `${money(cash.expected_base_amount)} esperado`;

    renderReportOrders(report.recent_orders || []);
    renderReportPaymentMethods(report.payment_methods || []);
    renderReportTopProducts(report.top_products || []);
    renderReportCashSessions(cash.sessions || []);
}

function buildOperationalReportQuery(extra = {}) {
    const query = new URLSearchParams({
        period: elements.period.value,
        ...extra,
    });
    const filters = {
        date_from: elements.reportsDateFrom?.value,
        date_to: elements.reportsDateTo?.value,
        branch_id: elements.reportsBranch?.value,
        cash_register_id: elements.reportsCashRegister?.value,
        cashier_id: elements.reportsCashier?.value,
        status: elements.reportsOrderStatus?.value || 'all',
    };

    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            query.set(key, value);
        }
    });

    return query;
}

function renderOperationalReportFilters(filters) {
    const options = filters.options || {};

    fillSelect(elements.reportsBranch, options.branches || [], 'Todas', (branch) => branch.id, (branch) => `${branch.name} (${branch.code})`);
    fillSelect(elements.reportsCashier, options.cashiers || [], 'Todos', (cashier) => cashier.id, (cashier) => `${cashier.name} - ${cashier.email}`);

    const selectedBranch = elements.reportsBranch?.value || '';
    const cashRegisters = selectedBranch
        ? (options.cash_registers || []).filter((register) => String(register.branch_id) === String(selectedBranch))
        : (options.cash_registers || []);
    fillSelect(elements.reportsCashRegister, cashRegisters, 'Todas', (register) => register.id, (register) => `${register.name} (${register.code})`);

    state.reports.filterOptionsLoaded = true;
}

function fillSelect(select, items, emptyLabel, valueGetter, labelGetter) {
    if (!select) {
        return;
    }

    const currentValue = select.value;
    select.replaceChildren(new Option(emptyLabel, ''));

    items.forEach((item) => {
        select.appendChild(new Option(labelGetter(item), valueGetter(item)));
    });

    select.value = [...select.options].some((option) => option.value === currentValue) ? currentValue : '';
}

async function exportOperationalReport(section, button) {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.reportsStatus, 'Preparando archivo CSV...');
    setButtonLoading(button, true, 'Exportando...');

    try {
        const query = buildOperationalReportQuery({
            export: 'csv',
            section,
        });
        const response = await fetch(`/api/admin-portal/operational-reports?${query}`, {
            headers: {
                Accept: 'text/csv',
                ...authHeaders(session),
            },
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || 'No se pudo exportar el reporte.');
        }

        const blob = await response.blob();
        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = csvFilenameFromResponse(response, `reporte-${section}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(downloadUrl);
        setStatus(elements.reportsStatus, 'Archivo CSV generado correctamente.', 'success');
    } catch (error) {
        setStatus(elements.reportsStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(button, false);
    }
}

function csvFilenameFromResponse(response, fallback) {
    const disposition = response.headers.get('Content-Disposition') || '';
    const match = disposition.match(/filename="?([^"]+)"?/i);

    return match ? match[1] : fallback;
}

function clearOperationalReportFilters() {
    [
        elements.reportsDateFrom,
        elements.reportsDateTo,
        elements.reportsBranch,
        elements.reportsCashRegister,
        elements.reportsCashier,
    ].forEach((field) => {
        if (field) {
            field.value = '';
        }
    });

    if (elements.reportsOrderStatus) {
        elements.reportsOrderStatus.value = 'all';
    }

    loadOperationalReports();
}

function renderReportOrders(orders) {
    if (!orders.length) {
        renderEmptyTable(elements.reportsOrdersTable, 'No hay ordenes POS en el periodo.', 7);
        return;
    }

    elements.reportsOrdersTable.replaceChildren(
        ...orders.map((order) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>#${escapeHtml(order.id)}</strong><small>${escapeHtml(formatDateTime(order.opened_at))}</small></td>
                <td>${escapeHtml(order.customer_name || 'Consumidor final')}</td>
                <td>${escapeHtml(order.cash_register_name || 'Sin caja')}</td>
                <td><span class="status-pill" data-tone="${order.status === 'paid' ? 'success' : 'warning'}">${escapeHtml(posOrderStatusLabel(order.status))}</span></td>
                <td><strong>${money(order.total_base_amount)}</strong></td>
                <td><strong>${money(order.paid_base_amount)}</strong><small>Saldo ${money(order.balance_base_amount)}</small></td>
                <td>${escapeHtml(formatDateTime(order.paid_at || order.opened_at))}</td>
            `;
            return row;
        }),
    );
}

function renderReportPaymentMethods(methods) {
    if (!methods.length) {
        renderEmptyTable(elements.reportsPaymentsTable, 'Sin pagos capturados.', 3);
        return;
    }

    elements.reportsPaymentsTable.replaceChildren(
        ...methods.map((method) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(method.name || 'Metodo')}</strong><small>${escapeHtml(method.currency || 'USD')}</small></td>
                <td>${number(method.payments_count)}</td>
                <td><strong>${money(method.amount_base)}</strong><small>Bs ${number(method.amount_local)}</small></td>
            `;
            return row;
        }),
    );
}

function renderReportTopProducts(products) {
    if (!products.length) {
        renderEmptyTable(elements.reportsProductsTable, 'Sin productos vendidos.', 3);
        return;
    }

    elements.reportsProductsTable.replaceChildren(
        ...products.map((product) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(product.product_name)}</strong><small>${escapeHtml(product.product_sku || '')}</small></td>
                <td>${number(product.quantity)}</td>
                <td><strong>${money(product.total_base_amount)}</strong></td>
            `;
            return row;
        }),
    );
}

function renderReportCashSessions(sessions) {
    if (!sessions.length) {
        renderEmptyTable(elements.reportsCashTable, 'Sin turnos de caja en el periodo.', 7);
        return;
    }

    elements.reportsCashTable.replaceChildren(
        ...sessions.map((session) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(session.cash_register_name)}</strong><small>#${escapeHtml(session.id)}</small></td>
                <td>${escapeHtml(session.branch_name)}</td>
                <td>${escapeHtml(session.cashier_name)}</td>
                <td><span class="status-pill" data-tone="${session.status === 'open' ? 'warning' : 'success'}">${escapeHtml(cashSessionStatusLabel(session.status))}</span></td>
                <td><strong>${money(session.expected_base_amount)}</strong></td>
                <td><strong>${money(session.difference_base_amount)}</strong></td>
                <td>${escapeHtml(formatDateTime(session.opened_at))}</td>
            `;
            return row;
        }),
    );
}

function renderEmptyTable(table, message, colspan) {
    if (!table) {
        return;
    }

    table.innerHTML = `<tr><td colspan="${colspan}"><small>${escapeHtml(message)}</small></td></tr>`;
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
    renderInventoryQuickFilters();
    renderInventoryFilterSummary(summary);

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

function renderInventoryQuickFilters() {
    const activeStatus = elements.inventoryActive?.value || 'all';

    elements.inventoryQuickStatus?.querySelectorAll('[data-inventory-active-filter]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.inventoryActiveFilter === activeStatus);
    });
}

function renderInventoryFilterSummary(summary = {}) {
    if (!elements.inventoryFilterSummary) {
        return;
    }

    const filters = [];
    const activeStatus = elements.inventoryActive?.value || 'all';
    const stockStatus = elements.inventoryStock?.value || 'all';
    const tracking = elements.inventoryTracking?.value || '';
    const search = elements.inventorySearch?.value.trim() || '';
    const pagination = summary.pagination || {};

    if (activeStatus !== 'all') {
        filters.push(activeStatus === 'active' ? 'solo activos' : 'solo inactivos');
    }

    if (stockStatus !== 'all') {
        filters.push(`stock: ${stockStatusLabel(stockStatus).toLowerCase()}`);
    }

    if (tracking) {
        filters.push(`control: ${trackingLabel(tracking).toLowerCase()}`);
    }

    if (search) {
        filters.push(`busqueda: "${search}"`);
    }

    const totalLabel = Number.isFinite(Number(pagination.total))
        ? `${pagination.total} producto(s)`
        : 'catalogo';

    elements.inventoryFilterSummary.textContent = filters.length
        ? `${totalLabel} con ${filters.join(', ')}.`
        : `${totalLabel} en vista completa del catalogo.`;
}

async function loadMovements(page = state.movements.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.movements.page = page;
    setStatus(elements.movementsStatus, 'Cargando movimientos...');
    setButtonLoading(elements.movementsRefresh, true, 'Actualizando...');
    setButtonLoading(elements.movementsApply, true, 'Aplicando...');

    try {
        await loadMovementWarehouses();

        const query = new URLSearchParams({
            limit: '50',
            page: String(page),
        });
        const search = elements.movementsSearch?.value.trim();
        const type = elements.movementsType?.value || 'all';
        const warehouseId = elements.movementsWarehouse?.value;
        const dateFrom = elements.movementsFrom?.value;
        const dateTo = elements.movementsTo?.value;

        if (search) {
            query.set('search', search);
        }

        if (type && type !== 'all') {
            query.set('type', type);
        }

        if (warehouseId) {
            query.set('warehouse_id', warehouseId);
        }

        if (dateFrom) {
            query.set('date_from', dateFrom);
        }

        if (dateTo) {
            query.set('date_to', dateTo);
        }

        const pageData = await api(`/api/inventory-center/movements?${query}`, {
            headers: authHeaders(session),
        });

        state.movements.loaded = true;
        renderMovements(pageData);
        setStatus(elements.movementsStatus, `Movimientos actualizados. ${pageData.pagination?.total || 0} registro(s) encontrados.`, 'success');
    } catch (error) {
        setStatus(elements.movementsStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.movementsRefresh, false);
        setButtonLoading(elements.movementsApply, false);
    }
}

async function loadMovementWarehouses() {
    const session = state.session;

    if (!session || state.movements.warehousesLoaded || !elements.movementsWarehouse) {
        return;
    }

    try {
        const warehouses = await api('/api/warehouses?per_page=100', {
            headers: authHeaders(session),
        });
        const options = [new Option('Todos', '')];

        collectionData(warehouses).forEach((warehouse) => {
            const label = `${warehouse.name}${warehouse.code ? ` (${warehouse.code})` : ''}`;
            options.push(new Option(label, String(warehouse.id)));
        });

        elements.movementsWarehouse.replaceChildren(...options);
    } catch {
        elements.movementsWarehouse.replaceChildren(new Option('Todos', ''));
    } finally {
        state.movements.warehousesLoaded = true;
    }
}

function renderMovements(pageData = {}) {
    const movements = pageData.data || [];
    const pagination = pageData.pagination || {};

    if (!movements.length) {
        elements.movementsTable.innerHTML = '<tr><td colspan="7"><strong>Sin movimientos</strong><small>No hay actividad con los filtros seleccionados.</small></td></tr>';
    } else {
        elements.movementsTable.replaceChildren(...movements.map(movementRow));
    }

    elements.movementsCount.textContent = pagination.total === 0
        ? 'Sin movimientos para mostrar.'
        : `${pagination.from}-${pagination.to} de ${pagination.total} movimiento(s).`;
    elements.movementsPrev.disabled = !pagination.has_previous;
    elements.movementsNext.disabled = !pagination.has_next;
    state.movements.page = pagination.page || 1;
}

function movementRow(movement) {
    const row = document.createElement('tr');
    const reference = [movement.reference_type, movement.reference_id].filter(Boolean).join(' #');
    const warehouse = [
        movement.warehouse_name || 'Sin almacen',
        movement.warehouse_code ? `(${movement.warehouse_code})` : '',
    ].filter(Boolean).join(' ');

    row.innerHTML = `
        <td><strong>${escapeHtml(formatDateTime(movement.created_at))}</strong><small>#${escapeHtml(movement.id)}</small></td>
        <td><strong>${escapeHtml(movement.product_name || 'Producto eliminado')}</strong><small>${escapeHtml(movement.product_sku || '')}</small></td>
        <td><span class="status-pill" data-tone="neutral">${escapeHtml(movementTypeLabel(movement.type))}</span></td>
        <td><strong>${stockNumber(movement.quantity)}</strong><small>${movement.unit_cost === null ? '' : `Costo ${escapeHtml(money(movement.unit_cost))}`}</small></td>
        <td><strong>${escapeHtml(warehouse)}</strong><small>${escapeHtml(movement.branch_name || '')}</small></td>
        <td><strong>${escapeHtml(movement.reason || 'Sin motivo')}</strong><small>${escapeHtml(reference || 'Sin referencia')}</small></td>
        <td><strong>${escapeHtml(movement.created_by_name || 'Sistema')}</strong><small>${escapeHtml(movement.created_by_email || '')}</small></td>
    `;

    return row;
}

function clearMovementFilters() {
    if (elements.movementsSearch) {
        elements.movementsSearch.value = '';
    }

    if (elements.movementsType) {
        elements.movementsType.value = 'all';
    }

    if (elements.movementsWarehouse) {
        elements.movementsWarehouse.value = '';
    }

    if (elements.movementsFrom) {
        elements.movementsFrom.value = '';
    }

    if (elements.movementsTo) {
        elements.movementsTo.value = '';
    }

    loadMovements(1);
}

async function loadSuppliers(page = state.suppliers.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.suppliers.page = page;
    setStatus(elements.suppliersStatus, 'Cargando proveedores...');
    setButtonLoading(elements.suppliersRefresh, true, 'Actualizando...');
    setButtonLoading(elements.suppliersApply, true, 'Aplicando...');

    try {
        const query = new URLSearchParams({
            active_status: elements.suppliersActive?.value || 'all',
            limit: '50',
            page: String(page),
        });
        const search = elements.suppliersSearch?.value.trim();

        if (search) {
            query.set('search', search);
        }

        const pageData = await api(`/api/suppliers?${query}`, {
            headers: authHeaders(session),
        }, true);

        state.suppliers.loaded = true;
        renderSuppliers(pageData);
        setStatus(elements.suppliersStatus, `Proveedores actualizados. ${pageData.meta?.total || 0} registro(s).`, 'success');
    } catch (error) {
        setStatus(elements.suppliersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.suppliersRefresh, false);
        setButtonLoading(elements.suppliersApply, false);
    }
}

function renderSuppliers(pageData = {}) {
    const suppliers = pageData.data || [];
    const meta = pageData.meta || {};

    if (!suppliers.length) {
        elements.suppliersTable.innerHTML = '<tr><td colspan="6"><strong>Sin proveedores</strong><small>No hay proveedores con los filtros seleccionados.</small></td></tr>';
    } else {
        elements.suppliersTable.replaceChildren(...suppliers.map(supplierRow));
    }

    elements.suppliersCount.textContent = (meta.total || 0) === 0
        ? 'Sin proveedores para mostrar.'
        : `${meta.from || 1}-${meta.to || suppliers.length} de ${meta.total} proveedor(es).`;
    elements.suppliersPrev.disabled = !meta.current_page || meta.current_page <= 1;
    elements.suppliersNext.disabled = !meta.current_page || meta.current_page >= meta.last_page;
    state.suppliers.page = meta.current_page || 1;
}

function supplierRow(supplier) {
    const row = document.createElement('tr');
    row.className = supplier.is_active ? '' : 'admin-data-table__row--inactive';
    row.dataset.supplierId = String(supplier.id);
    row.classList.toggle('is-selected', state.suppliers.selectedSupplier?.id === supplier.id);
    const documentLabel = [supplier.document_type, supplier.document_number].filter(Boolean).join('-') || 'Sin documento';
    const contact = [supplier.phone, supplier.email].filter(Boolean).join(' / ') || 'Sin contacto';

    row.innerHTML = `
        <td><strong>${escapeHtml(supplier.name)}</strong><small>${escapeHtml(supplier.fiscal_address || 'Sin direccion fiscal')}</small></td>
        <td><strong>${escapeHtml(documentLabel)}</strong><small>${escapeHtml(supplier.notes || '')}</small></td>
        <td><strong>${escapeHtml(contact)}</strong><small>${escapeHtml(supplier.email && supplier.phone ? 'Telefono y correo' : '')}</small></td>
        <td><span class="status-pill" data-tone="${supplier.is_active ? 'success' : 'warning'}">${supplier.is_active ? 'Activo' : 'Inactivo'}</span></td>
        <td>${escapeHtml(formatDateTime(supplier.updated_at))}</td>
        <td><button class="ghost-button ghost-button--compact" type="button" data-admin-supplier-edit="${supplier.id}">Editar</button></td>
    `;

    row.querySelector('[data-admin-supplier-edit]')?.addEventListener('click', () => {
        selectSupplier(supplier);
    });

    row.addEventListener('dblclick', () => selectSupplier(supplier));

    return row;
}

function selectSupplier(supplier) {
    state.suppliers.selectedSupplier = supplier;
    fillSupplierForm(supplier);
    elements.supplierEditorTitle.textContent = supplier.name;
    elements.supplierEditorSubtitle.textContent = 'Edita datos de contacto, documento fiscal o estado operativo.';
    elements.supplierDeactivate.textContent = supplier.is_active ? 'Desactivar' : 'Reactivar';
    elements.supplierDeactivate.classList.toggle('danger-button', supplier.is_active);
    elements.supplierDeactivate.classList.toggle('ghost-button', !supplier.is_active);
    elements.suppliersTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    elements.suppliersTable?.querySelector(`[data-supplier-id="${supplier.id}"]`)?.classList.add('is-selected');
    setStatus(elements.suppliersStatus, `Proveedor seleccionado: ${supplier.name}.`, 'neutral');
}

function fillSupplierForm(supplier = {}) {
    elements.supplierName.value = supplier.name || '';
    elements.supplierDocumentType.value = supplier.document_type || 'J';
    elements.supplierDocumentNumber.value = supplier.document_number || '';
    elements.supplierPhone.value = supplier.phone || '';
    elements.supplierEmail.value = supplier.email || '';
    elements.supplierAddress.value = supplier.fiscal_address || '';
    elements.supplierNotes.value = supplier.notes || '';
    elements.supplierActiveEdit.checked = supplier.is_active !== false;
}

function clearSupplierForm() {
    state.suppliers.selectedSupplier = null;
    fillSupplierForm({
        document_type: 'J',
        is_active: true,
    });
    elements.supplierEditorTitle.textContent = 'Nuevo proveedor';
    elements.supplierEditorSubtitle.textContent = 'Completa los datos principales. El documento es unico por empresa.';
    elements.supplierDeactivate.textContent = 'Desactivar';
    elements.supplierDeactivate.classList.add('danger-button');
    elements.supplierDeactivate.classList.remove('ghost-button');
    elements.suppliersTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    setStatus(elements.suppliersStatus, 'Formulario listo para crear proveedor.', 'neutral');
}

async function saveSupplier() {
    const session = state.session;

    if (!session) {
        return;
    }

    const supplier = state.suppliers.selectedSupplier;
    const isCreate = !supplier;
    const permission = isCreate ? 'suppliers.create' : 'suppliers.update';

    if (!can(permission)) {
        setStatus(elements.suppliersStatus, 'Tu usuario no tiene permiso para guardar proveedores.', 'error');
        return;
    }

    const payload = {
        name: elements.supplierName.value.trim(),
        document_type: elements.supplierDocumentType.value,
        document_number: elements.supplierDocumentNumber.value.trim(),
        phone: elements.supplierPhone.value.trim(),
        email: elements.supplierEmail.value.trim(),
        fiscal_address: elements.supplierAddress.value.trim(),
        notes: elements.supplierNotes.value.trim(),
        is_active: elements.supplierActiveEdit.checked,
    };

    if (!payload.name) {
        setStatus(elements.suppliersStatus, 'El nombre del proveedor es obligatorio.', 'error');
        return;
    }

    setStatus(elements.suppliersStatus, 'Guardando proveedor...');
    setButtonLoading(elements.supplierSave, true, 'Guardando...');

    try {
        const saved = await api(isCreate ? '/api/suppliers' : `/api/suppliers/${supplier.id}`, {
            method: isCreate ? 'POST' : 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });

        state.suppliers.selectedSupplier = saved;
        await loadSuppliers(isCreate ? 1 : state.suppliers.page);
        selectSupplier(saved);
        setStatus(elements.suppliersStatus, isCreate ? 'Proveedor creado correctamente.' : 'Proveedor actualizado correctamente.', 'success');
    } catch (error) {
        setStatus(elements.suppliersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.supplierSave, false);
    }
}

async function toggleSupplierActive() {
    const session = state.session;
    const supplier = state.suppliers.selectedSupplier;

    if (!session || !supplier) {
        setStatus(elements.suppliersStatus, 'Selecciona un proveedor antes de cambiar su estado.', 'error');
        return;
    }

    const reactivating = !supplier.is_active;
    const permission = reactivating ? 'suppliers.update' : 'suppliers.delete';

    if (!can(permission)) {
        setStatus(elements.suppliersStatus, 'Tu usuario no tiene permiso para cambiar el estado del proveedor.', 'error');
        return;
    }

    setStatus(elements.suppliersStatus, reactivating ? 'Reactivando proveedor...' : 'Desactivando proveedor...');
    setButtonLoading(elements.supplierDeactivate, true, reactivating ? 'Reactivando...' : 'Desactivando...');

    try {
        if (reactivating) {
            const updated = await api(`/api/suppliers/${supplier.id}`, {
                method: 'PATCH',
                headers: authHeaders(session),
                body: JSON.stringify({ is_active: true }),
            });
            state.suppliers.selectedSupplier = updated;
        } else {
            await api(`/api/suppliers/${supplier.id}`, {
                method: 'DELETE',
                headers: authHeaders(session),
            });
            state.suppliers.selectedSupplier = { ...supplier, is_active: false };
        }

        await loadSuppliers(state.suppliers.page);
        if (state.suppliers.selectedSupplier) {
            selectSupplier(state.suppliers.selectedSupplier);
        }
        setStatus(elements.suppliersStatus, reactivating ? 'Proveedor reactivado.' : 'Proveedor desactivado.', 'success');
    } catch (error) {
        setStatus(elements.suppliersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.supplierDeactivate, false);
    }
}

function clearSupplierFilters() {
    if (elements.suppliersSearch) {
        elements.suppliersSearch.value = '';
    }

    if (elements.suppliersActive) {
        elements.suppliersActive.value = 'all';
    }

    loadSuppliers(1);
}

async function loadCustomers(page = state.customers.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    if (!can('customers.view')) {
        setStatus(elements.customersStatus, 'Tu usuario no tiene permiso para ver clientes.', 'error');
        return;
    }

    state.customers.page = page;
    setStatus(elements.customersStatus, 'Cargando clientes...');
    setButtonLoading(elements.customersRefresh, true, 'Actualizando...');
    setButtonLoading(elements.customersApply, true, 'Aplicando...');

    try {
        const query = new URLSearchParams({
            active_status: elements.customersActive?.value || 'all',
            limit: '50',
            page: String(page),
        });
        const search = elements.customersSearch?.value.trim();

        if (search) {
            query.set('search', search);
        }

        const pageData = await api(`/api/customers?${query}`, {
            headers: authHeaders(session),
        }, true);

        state.customers.loaded = true;
        renderCustomers(pageData);
        setStatus(elements.customersStatus, `Clientes actualizados. ${pageData.meta?.total || 0} registro(s).`, 'success');
    } catch (error) {
        setStatus(elements.customersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.customersRefresh, false);
        setButtonLoading(elements.customersApply, false);
    }
}

function renderCustomers(pageData = {}) {
    const typeFilter = elements.customersType?.value || 'all';
    const customers = (pageData.data || []).filter((customer) => {
        if (typeFilter === 'generic') {
            return customer.is_generic === true;
        }

        if (typeFilter === 'regular') {
            return customer.is_generic !== true;
        }

        return true;
    });
    const meta = pageData.meta || {};

    if (!elements.customersTable) {
        return;
    }

    if (!customers.length) {
        elements.customersTable.innerHTML = '<tr><td colspan="7"><strong>Sin clientes</strong><small>No hay clientes con los filtros seleccionados.</small></td></tr>';
    } else {
        elements.customersTable.replaceChildren(...customers.map(customerRow));
    }

    elements.customersCount.textContent = (meta.total || 0) === 0
        ? 'Sin clientes para mostrar.'
        : `${meta.from || 1}-${meta.to || customers.length} de ${meta.total} cliente(s).`;
    elements.customersPrev.disabled = !meta.current_page || meta.current_page <= 1;
    elements.customersNext.disabled = !meta.current_page || meta.current_page >= meta.last_page;
    state.customers.page = meta.current_page || 1;
}

function customerRow(customer) {
    const row = document.createElement('tr');
    row.className = customer.is_active ? '' : 'admin-data-table__row--inactive';
    row.dataset.customerId = String(customer.id);
    row.classList.toggle('is-selected', state.customers.selectedCustomer?.id === customer.id);
    const documentLabel = [customer.document_type, customer.document_number].filter(Boolean).join('-') || 'Sin documento';
    const contact = [customer.phone, customer.email].filter(Boolean).join(' / ') || 'Sin contacto';
    const customerType = customer.is_generic ? 'Consumidor final' : 'Cliente';

    row.innerHTML = `
        <td><strong>${escapeHtml(customer.name)}</strong><small>${escapeHtml(customer.fiscal_address || 'Sin direccion fiscal')}</small></td>
        <td><strong>${escapeHtml(documentLabel)}</strong><small>${escapeHtml(customer.document_type || '')}</small></td>
        <td><strong>${escapeHtml(contact)}</strong><small>${escapeHtml(customer.email && customer.phone ? 'Telefono y correo' : '')}</small></td>
        <td><span class="status-pill" data-tone="${customer.is_generic ? 'warning' : 'info'}">${customerType}</span></td>
        <td><span class="status-pill" data-tone="${customer.is_active ? 'success' : 'warning'}">${customer.is_active ? 'Activo' : 'Inactivo'}</span></td>
        <td>${escapeHtml(formatDateTime(customer.updated_at))}</td>
        <td><button class="ghost-button ghost-button--compact" type="button" data-admin-customer-edit="${customer.id}">Editar</button></td>
    `;

    row.querySelector('[data-admin-customer-edit]')?.addEventListener('click', () => {
        selectCustomer(customer);
    });

    row.addEventListener('dblclick', () => selectCustomer(customer));

    return row;
}

function selectCustomer(customer) {
    state.customers.selectedCustomer = customer;
    fillCustomerForm(customer);
    elements.customerEditorTitle.textContent = customer.name;
    elements.customerEditorSubtitle.textContent = 'Edita datos de contacto, documento fiscal o estado del cliente.';
    elements.customerDeactivate.textContent = customer.is_active ? 'Desactivar' : 'Reactivar';
    elements.customerDeactivate.classList.toggle('danger-button', customer.is_active);
    elements.customerDeactivate.classList.toggle('ghost-button', !customer.is_active);
    elements.customersTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    elements.customersTable?.querySelector(`[data-customer-id="${customer.id}"]`)?.classList.add('is-selected');
    setStatus(elements.customersStatus, `Cliente seleccionado: ${customer.name}.`, 'neutral');
}

function fillCustomerForm(customer = {}) {
    elements.customerName.value = customer.name || '';
    elements.customerDocumentType.value = customer.document_type || 'V';
    elements.customerDocumentNumber.value = customer.document_number || '';
    elements.customerPhone.value = customer.phone || '';
    elements.customerEmail.value = customer.email || '';
    elements.customerAddress.value = customer.fiscal_address || '';
    elements.customerGenericEdit.checked = customer.is_generic === true;
    elements.customerActiveEdit.checked = customer.is_active !== false;
}

function clearCustomerForm() {
    state.customers.selectedCustomer = null;
    fillCustomerForm({
        document_type: 'V',
        is_active: true,
        is_generic: false,
    });
    elements.customerEditorTitle.textContent = 'Nuevo cliente';
    elements.customerEditorSubtitle.textContent = 'Completa datos de identificacion y contacto. El documento es unico por empresa.';
    elements.customerDeactivate.textContent = 'Desactivar';
    elements.customerDeactivate.classList.add('danger-button');
    elements.customerDeactivate.classList.remove('ghost-button');
    elements.customersTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    setStatus(elements.customersStatus, 'Formulario listo para crear cliente.', 'neutral');
}

async function saveCustomer() {
    const session = state.session;

    if (!session) {
        return;
    }

    const customer = state.customers.selectedCustomer;
    const isCreate = !customer;
    const permission = isCreate ? 'customers.create' : 'customers.update';

    if (!can(permission)) {
        setStatus(elements.customersStatus, 'Tu usuario no tiene permiso para guardar clientes.', 'error');
        return;
    }

    const payload = {
        name: elements.customerName.value.trim(),
        document_type: elements.customerDocumentType.value,
        document_number: elements.customerDocumentNumber.value.trim(),
        phone: elements.customerPhone.value.trim(),
        email: elements.customerEmail.value.trim(),
        fiscal_address: elements.customerAddress.value.trim(),
        is_generic: elements.customerGenericEdit.checked,
        is_active: elements.customerActiveEdit.checked,
    };

    if (!payload.name) {
        setStatus(elements.customersStatus, 'El nombre del cliente es obligatorio.', 'error');
        return;
    }

    if (!payload.document_number) {
        setStatus(elements.customersStatus, 'El documento del cliente es obligatorio.', 'error');
        return;
    }

    setStatus(elements.customersStatus, 'Guardando cliente...');
    setButtonLoading(elements.customerSave, true, 'Guardando...');

    try {
        const saved = await api(isCreate ? '/api/customers' : `/api/customers/${customer.id}`, {
            method: isCreate ? 'POST' : 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });

        state.customers.selectedCustomer = saved;
        await loadCustomers(isCreate ? 1 : state.customers.page);
        selectCustomer(saved);
        setStatus(elements.customersStatus, isCreate ? 'Cliente creado. El cambio quedo listo para sincronizarse.' : 'Cliente actualizado. El cambio quedo listo para sincronizarse.', 'success');
    } catch (error) {
        setStatus(elements.customersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.customerSave, false);
    }
}

async function toggleCustomerActive() {
    const session = state.session;
    const customer = state.customers.selectedCustomer;

    if (!session || !customer) {
        setStatus(elements.customersStatus, 'Selecciona un cliente antes de cambiar su estado.', 'error');
        return;
    }

    const reactivating = !customer.is_active;
    const permission = reactivating ? 'customers.update' : 'customers.delete';

    if (!can(permission)) {
        setStatus(elements.customersStatus, 'Tu usuario no tiene permiso para cambiar el estado del cliente.', 'error');
        return;
    }

    setStatus(elements.customersStatus, reactivating ? 'Reactivando cliente...' : 'Desactivando cliente...');
    setButtonLoading(elements.customerDeactivate, true, reactivating ? 'Reactivando...' : 'Desactivando...');

    try {
        if (reactivating) {
            const updated = await api(`/api/customers/${customer.id}`, {
                method: 'PATCH',
                headers: authHeaders(session),
                body: JSON.stringify({ is_active: true }),
            });
            state.customers.selectedCustomer = updated;
        } else {
            await api(`/api/customers/${customer.id}`, {
                method: 'DELETE',
                headers: authHeaders(session),
            });
            state.customers.selectedCustomer = { ...customer, is_active: false };
        }

        await loadCustomers(state.customers.page);
        if (state.customers.selectedCustomer) {
            selectCustomer(state.customers.selectedCustomer);
        }
        setStatus(elements.customersStatus, reactivating ? 'Cliente reactivado.' : 'Cliente desactivado.', 'success');
    } catch (error) {
        setStatus(elements.customersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.customerDeactivate, false);
    }
}

function clearCustomerFilters() {
    if (elements.customersSearch) {
        elements.customersSearch.value = '';
    }

    if (elements.customersActive) {
        elements.customersActive.value = 'all';
    }

    if (elements.customersType) {
        elements.customersType.value = 'all';
    }

    loadCustomers(1);
}

async function loadPurchaseOptions() {
    const session = state.session;

    if (!session) {
        return;
    }

    const requests = [];

    if (!state.purchases.suppliersLoaded && can('suppliers.view')) {
        requests.push(api('/api/suppliers?active_status=active&limit=100', {
            headers: authHeaders(session),
        }, true).then((payload) => {
            state.purchases.suppliers = collectionData(payload);
            state.purchases.suppliersLoaded = true;
        }));
    }

    if (!state.purchases.productsLoaded && can('products.view')) {
        requests.push(api('/api/products?limit=100', {
            headers: authHeaders(session),
        }, true).then((payload) => {
            state.purchases.products = collectionData(payload).filter((product) => product.is_active !== false);
            state.purchases.productsLoaded = true;
        }));
    }

    if (!state.purchases.warehousesLoaded && can('warehouses.view')) {
        requests.push(api('/api/warehouses?per_page=100', {
            headers: authHeaders(session),
        }, true).then((payload) => {
            state.purchases.warehouses = collectionData(payload).filter((warehouse) => warehouse.status !== 'inactive');
            state.purchases.warehousesLoaded = true;
        }));
    }

    await Promise.all(requests);
    renderPurchaseOptions();
}

function renderPurchaseOptions() {
    const supplierOptions = [new Option('Todos', '')];
    const supplierFormOptions = [new Option('Sin proveedor', '')];

    state.purchases.suppliers.forEach((supplier) => {
        const documentLabel = [supplier.document_type, supplier.document_number].filter(Boolean).join('-');
        const label = documentLabel ? `${supplier.name} (${documentLabel})` : supplier.name;
        supplierOptions.push(new Option(label, String(supplier.id)));
        supplierFormOptions.push(new Option(label, String(supplier.id)));
    });

    elements.purchasesSupplierFilter?.replaceChildren(...supplierOptions);
    elements.purchaseSupplier?.replaceChildren(...supplierFormOptions);

    const productOptions = state.purchases.products.map((product) => {
        const option = new Option(`${product.name} (${product.sku})`, String(product.id));
        option.dataset.trackingType = product.tracking_type;
        return option;
    });
    elements.purchaseProduct?.replaceChildren(...productOptions);

    const warehouseOptions = state.purchases.warehouses.map((warehouse) => {
        const label = `${warehouse.name}${warehouse.code ? ` (${warehouse.code})` : ''}`;
        return new Option(label, String(warehouse.id));
    });
    elements.purchaseWarehouse?.replaceChildren(...warehouseOptions);
}

async function loadPurchases(page = state.purchases.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.purchases.page = page;
    setStatus(elements.purchasesStatus, 'Cargando compras...');
    setButtonLoading(elements.purchasesRefresh, true, 'Actualizando...');
    setButtonLoading(elements.purchasesApply, true, 'Aplicando...');

    try {
        await loadPurchaseOptions();

        const query = new URLSearchParams({
            status: elements.purchasesStatusFilter?.value || 'all',
            limit: '50',
            page: String(page),
        });
        const search = elements.purchasesSearch?.value.trim();
        const supplierId = elements.purchasesSupplierFilter?.value;

        if (search) {
            query.set('search', search);
        }

        if (supplierId) {
            query.set('supplier_id', supplierId);
        }

        const pageData = await api(`/api/purchases?${query}`, {
            headers: authHeaders(session),
        }, true);

        state.purchases.loaded = true;
        renderPurchases(pageData);
        setStatus(elements.purchasesStatus, `Compras actualizadas. ${pageData.meta?.total || 0} registro(s).`, 'success');
    } catch (error) {
        setStatus(elements.purchasesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.purchasesRefresh, false);
        setButtonLoading(elements.purchasesApply, false);
    }
}

function renderPurchases(pageData = {}) {
    const purchases = pageData.data || [];
    const meta = pageData.meta || {};

    if (!purchases.length) {
        elements.purchasesTable.innerHTML = '<tr><td colspan="7"><strong>Sin compras</strong><small>No hay ordenes con los filtros seleccionados.</small></td></tr>';
    } else {
        elements.purchasesTable.replaceChildren(...purchases.map(purchaseRow));
    }

    elements.purchasesCount.textContent = (meta.total || 0) === 0
        ? 'Sin compras para mostrar.'
        : `${meta.from || 1}-${meta.to || purchases.length} de ${meta.total} compra(s).`;
    elements.purchasesPrev.disabled = !meta.current_page || meta.current_page <= 1;
    elements.purchasesNext.disabled = !meta.current_page || meta.current_page >= meta.last_page;
    state.purchases.page = meta.current_page || 1;
}

function purchaseRow(purchase) {
    const row = document.createElement('tr');
    row.dataset.purchaseId = String(purchase.id);
    row.classList.toggle('is-selected', state.purchases.selectedPurchase?.id === purchase.id);
    const supplierName = purchase.supplier?.name || 'Sin proveedor';
    const status = purchaseStatusLabel(purchase.status);
    const tone = purchaseStatusTone(purchase.status);

    row.innerHTML = `
        <td><strong>#${purchase.id} ${escapeHtml(purchase.document_number || 'Sin documento')}</strong><small>${escapeHtml(purchase.issued_at || 'sin emision')} / vence ${escapeHtml(purchase.due_date || 'sin fecha')}</small></td>
        <td><strong>${escapeHtml(supplierName)}</strong><small>${escapeHtml(purchase.supplier?.document_number || '')}</small></td>
        <td><span class="status-pill" data-tone="${tone}">${escapeHtml(status)}</span></td>
        <td><strong>${purchase.purchase_currency} ${number(purchase.purchase_currency === 'USD' ? purchase.total_base_amount : purchase.total_local_amount)}</strong><small>Base ${money(purchase.total_base_amount)}</small></td>
        <td><strong>${money(purchase.received_base_amount)}</strong><small>${number(purchase.received_local_amount)} Bs</small></td>
        <td>${number(purchase.items_count || purchase.items?.length || 0)}</td>
        <td><button class="ghost-button ghost-button--compact" type="button" data-admin-purchase-select="${purchase.id}">Ver</button></td>
    `;

    row.querySelector('[data-admin-purchase-select]')?.addEventListener('click', () => selectPurchaseById(purchase.id));
    row.addEventListener('dblclick', () => selectPurchaseById(purchase.id));

    return row;
}

async function selectPurchaseById(purchaseId) {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.purchasesStatus, 'Cargando detalle de compra...');

    try {
        const purchase = await api(`/api/purchases/${purchaseId}`, {
            headers: authHeaders(session),
        });
        selectPurchase(purchase);
    } catch (error) {
        setStatus(elements.purchasesStatus, normalizeError(error), 'error');
    }
}

function selectPurchase(purchase) {
    state.purchases.selectedPurchase = purchase;
    state.purchases.items = (purchase.items || []).map((item) => ({
        id: item.id,
        product_id: item.product_id,
        product_name: item.product?.name || `Producto #${item.product_id}`,
        warehouse_id: item.warehouse_id,
        warehouse_name: item.warehouse?.name || `Almacen #${item.warehouse_id}`,
        quantity: Number(item.quantity || 0),
        unit_cost: Number(item.unit_cost || 0),
        received_quantity: Number(item.received_quantity || 0),
    }));

    elements.purchaseEditorTitle.textContent = `Compra #${purchase.id}`;
    elements.purchaseEditorSubtitle.textContent = `${purchaseStatusLabel(purchase.status)} - ${purchase.items_count || state.purchases.items.length} item(s).`;
    elements.purchaseSupplier.value = purchase.supplier_id ? String(purchase.supplier_id) : '';
    elements.purchaseDocument.value = purchase.document_number || '';
    elements.purchaseIssuedAt.value = purchase.issued_at || '';
    elements.purchaseDueDate.value = purchase.due_date || '';
    elements.purchaseCurrency.value = purchase.purchase_currency || 'USD';
    renderPurchaseItems();
    updatePurchaseActionState();

    elements.purchasesTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    elements.purchasesTable?.querySelector(`[data-purchase-id="${purchase.id}"]`)?.classList.add('is-selected');
    setStatus(elements.purchasesStatus, `Compra seleccionada: #${purchase.id}.`, 'neutral');
}

function clearPurchaseForm() {
    state.purchases.selectedPurchase = null;
    state.purchases.items = [];
    elements.purchaseEditorTitle.textContent = 'Nueva compra';
    elements.purchaseEditorSubtitle.textContent = 'Agrega proveedor, documento e items. Recibir mueve stock al inventario.';
    elements.purchaseSupplier.value = '';
    elements.purchaseDocument.value = '';
    elements.purchaseIssuedAt.valueAsDate = new Date();
    elements.purchaseDueDate.value = '';
    elements.purchaseCurrency.value = 'USD';
    elements.purchaseQuantity.value = '1';
    elements.purchaseUnitCost.value = '0';
    elements.purchasesTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    renderPurchaseItems();
    updatePurchaseActionState();
    setStatus(elements.purchasesStatus, 'Formulario listo para crear compra.', 'neutral');
}

function addPurchaseItem() {
    const productId = Number(elements.purchaseProduct?.value || 0);
    const warehouseId = Number(elements.purchaseWarehouse?.value || 0);
    const quantity = Number(elements.purchaseQuantity?.value || 0);
    const unitCost = Number(elements.purchaseUnitCost?.value || 0);
    const product = state.purchases.products.find((item) => item.id === productId);
    const warehouse = state.purchases.warehouses.find((item) => item.id === warehouseId);

    if (!product || !warehouse) {
        setStatus(elements.purchasesStatus, 'Selecciona producto y almacen para agregar el item.', 'error');
        return;
    }

    if (product.tracking_type === 'serialized') {
        setStatus(elements.purchasesStatus, 'Los productos serializados se recibiran en la fase de IMEI web. Por ahora usa el escritorio.', 'error');
        return;
    }

    if (quantity <= 0 || Number.isNaN(quantity)) {
        setStatus(elements.purchasesStatus, 'La cantidad debe ser mayor que cero.', 'error');
        return;
    }

    if (unitCost < 0 || Number.isNaN(unitCost)) {
        setStatus(elements.purchasesStatus, 'El costo no puede ser negativo.', 'error');
        return;
    }

    state.purchases.items.push({
        product_id: product.id,
        product_name: product.name,
        warehouse_id: warehouse.id,
        warehouse_name: warehouse.name,
        quantity,
        unit_cost: unitCost,
        received_quantity: 0,
    });

    renderPurchaseItems();
    setStatus(elements.purchasesStatus, `${product.name} agregado a la compra.`, 'success');
}

function removePurchaseItem(index) {
    state.purchases.items.splice(index, 1);
    renderPurchaseItems();
}

function renderPurchaseItems() {
    const items = state.purchases.items;
    const currency = elements.purchaseCurrency?.value || 'USD';
    const total = items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.unit_cost || 0)), 0);

    if (!items.length) {
        elements.purchaseItemsTable.innerHTML = '<tr><td colspan="5"><strong>Sin items</strong><small>Agrega productos para guardar la compra.</small></td></tr>';
    } else {
        elements.purchaseItemsTable.replaceChildren(...items.map((item, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(item.product_name)}</strong><small>${escapeHtml(item.id ? `Recibido: ${number(item.received_quantity || 0)}` : 'Nuevo item')}</small></td>
                <td>${escapeHtml(item.warehouse_name)}</td>
                <td>${number(item.quantity)}</td>
                <td>${currency} ${number(item.unit_cost)}</td>
                <td><button class="ghost-button ghost-button--compact" type="button" data-purchase-item-remove="${index}">Quitar</button></td>
            `;
            row.querySelector('[data-purchase-item-remove]')?.addEventListener('click', () => removePurchaseItem(index));
            return row;
        }));
    }

    elements.purchaseItemsTotal.textContent = `${items.length} item(s)`;
    elements.purchaseSummary.innerHTML = `<span>Total estimado</span><strong>${currency} ${number(total)}</strong>`;
}

function purchasePayload() {
    return {
        supplier_id: elements.purchaseSupplier.value ? Number(elements.purchaseSupplier.value) : null,
        document_number: elements.purchaseDocument.value.trim() || null,
        issued_at: elements.purchaseIssuedAt.value || null,
        due_date: elements.purchaseDueDate.value || null,
        purchase_currency: elements.purchaseCurrency.value || 'USD',
        items: state.purchases.items.map((item) => ({
            warehouse_id: Number(item.warehouse_id),
            product_id: Number(item.product_id),
            quantity: Number(item.quantity),
            unit_cost: Number(item.unit_cost),
        })),
    };
}

async function savePurchase() {
    const session = state.session;

    if (!session) {
        return;
    }

    if (!can('purchases.create')) {
        setStatus(elements.purchasesStatus, 'Tu usuario no tiene permiso para crear compras.', 'error');
        return;
    }

    const payload = purchasePayload();

    if (!payload.items.length) {
        setStatus(elements.purchasesStatus, 'Agrega al menos un item antes de guardar la compra.', 'error');
        return;
    }

    setStatus(elements.purchasesStatus, 'Guardando compra...');
    setButtonLoading(elements.purchaseSave, true, 'Guardando...');

    try {
        const purchase = await api('/api/purchases', {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });
        await loadPurchases(1);
        selectPurchase(purchase);
        setStatus(elements.purchasesStatus, 'Compra guardada como pendiente de recepcion.', 'success');
    } catch (error) {
        setStatus(elements.purchasesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.purchaseSave, false);
    }
}

async function receivePurchase() {
    const session = state.session;
    const purchase = state.purchases.selectedPurchase;

    if (!session || !purchase) {
        setStatus(elements.purchasesStatus, 'Selecciona una compra pendiente para recibir.', 'error');
        return;
    }

    if (!can('purchases.approve')) {
        setStatus(elements.purchasesStatus, 'Tu usuario no tiene permiso para recibir compras.', 'error');
        return;
    }

    setStatus(elements.purchasesStatus, 'Recibiendo compra y actualizando inventario...');
    setButtonLoading(elements.purchaseReceive, true, 'Recibiendo...');

    try {
        const received = await api(`/api/purchases/${purchase.id}/receive`, {
            method: 'PATCH',
            headers: authHeaders(session),
        });
        await loadPurchases(state.purchases.page);
        selectPurchase(received);
        await loadDashboard();
        setStatus(elements.purchasesStatus, 'Compra recibida. El inventario fue actualizado.', 'success');
    } catch (error) {
        setStatus(elements.purchasesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.purchaseReceive, false);
    }
}

async function cancelPurchaseOrder() {
    const session = state.session;
    const purchase = state.purchases.selectedPurchase;

    if (!session || !purchase) {
        setStatus(elements.purchasesStatus, 'Selecciona una compra en borrador para anular.', 'error');
        return;
    }

    if (!can('purchases.create')) {
        setStatus(elements.purchasesStatus, 'Tu usuario no tiene permiso para anular compras.', 'error');
        return;
    }

    if (!window.confirm(`Anular compra #${purchase.id}? Solo se permite si aun no fue recibida.`)) {
        return;
    }

    setStatus(elements.purchasesStatus, 'Anulando compra...');
    setButtonLoading(elements.purchaseCancelOrder, true, 'Anulando...');

    try {
        const cancelled = await api(`/api/purchases/${purchase.id}/cancel`, {
            method: 'PATCH',
            headers: authHeaders(session),
        });
        await loadPurchases(state.purchases.page);
        selectPurchase(cancelled);
        setStatus(elements.purchasesStatus, 'Compra anulada correctamente.', 'success');
    } catch (error) {
        setStatus(elements.purchasesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.purchaseCancelOrder, false);
    }
}

function clearPurchaseFilters() {
    if (elements.purchasesSearch) {
        elements.purchasesSearch.value = '';
    }

    if (elements.purchasesStatusFilter) {
        elements.purchasesStatusFilter.value = 'all';
    }

    if (elements.purchasesSupplierFilter) {
        elements.purchasesSupplierFilter.value = '';
    }

    loadPurchases(1);
}

function updatePurchaseActionState() {
    const purchase = state.purchases.selectedPurchase;
    const status = purchase?.status;
    const canReceive = Boolean(purchase && ['draft', 'partially_received'].includes(status) && can('purchases.approve'));
    const canCancel = Boolean(purchase && status === 'draft' && can('purchases.create'));

    if (elements.purchaseReceive) {
        elements.purchaseReceive.disabled = !canReceive;
    }

    if (elements.purchaseCancelOrder) {
        elements.purchaseCancelOrder.disabled = !canCancel;
    }

    if (elements.purchaseSave) {
        elements.purchaseSave.disabled = !can('purchases.create') || Boolean(purchase);
    }
}

function purchaseStatusLabel(status) {
    return {
        draft: 'Pendiente',
        partially_received: 'Parcial',
        received: 'Recibida',
        cancelled: 'Anulada',
    }[status] || status || 'Sin estado';
}

function purchaseStatusTone(status) {
    return {
        draft: 'warning',
        partially_received: 'warning',
        received: 'success',
        cancelled: 'danger',
    }[status] || 'neutral';
}

async function loadPayableOptions() {
    const session = state.session;

    if (!session || state.payables.suppliersLoaded || !can('suppliers.view')) {
        return;
    }

    const suppliers = await api('/api/suppliers?active_status=active&limit=100', {
        headers: authHeaders(session),
    }, true);

    state.payables.suppliers = collectionData(suppliers);
    state.payables.suppliersLoaded = true;
    renderPayableSupplierOptions();
}

function renderPayableSupplierOptions() {
    const options = [new Option('Todos', '')];

    state.payables.suppliers.forEach((supplier) => {
        const documentLabel = [supplier.document_type, supplier.document_number].filter(Boolean).join('-');
        const label = documentLabel ? `${supplier.name} (${documentLabel})` : supplier.name;
        options.push(new Option(label, String(supplier.id)));
    });

    elements.payablesSupplierFilter?.replaceChildren(...options);
}

async function loadPayables(page = state.payables.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.payables.page = page;
    setStatus(elements.payablesStatus, 'Cargando cuentas por pagar...');
    setButtonLoading(elements.payablesRefresh, true, 'Actualizando...');
    setButtonLoading(elements.payablesApply, true, 'Aplicando...');

    try {
        await loadPayableOptions();

        const query = new URLSearchParams({
            status: elements.payablesStatusFilter?.value || 'all',
            limit: '50',
            page: String(page),
        });
        const search = elements.payablesSearch?.value.trim();
        const supplierId = elements.payablesSupplierFilter?.value;
        const dueFrom = elements.payablesDueFrom?.value;
        const dueTo = elements.payablesDueTo?.value;

        if (search) {
            query.set('search', search);
        }

        if (supplierId) {
            query.set('supplier_id', supplierId);
        }

        if (dueFrom) {
            query.set('due_from', dueFrom);
        }

        if (dueTo) {
            query.set('due_to', dueTo);
        }

        const pageData = await api(`/api/accounts-payable?${query}`, {
            headers: authHeaders(session),
        }, true);

        state.payables.loaded = true;
        renderPayables(pageData);
        setStatus(elements.payablesStatus, `Cuentas actualizadas. ${pageData.meta?.total || 0} registro(s).`, 'success');
    } catch (error) {
        setStatus(elements.payablesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.payablesRefresh, false);
        setButtonLoading(elements.payablesApply, false);
    }
}

function renderPayables(pageData = {}) {
    const payables = pageData.data || [];
    const meta = pageData.meta || {};

    if (!payables.length) {
        if (elements.payablesTable) {
            elements.payablesTable.innerHTML = '<tr><td colspan="7"><strong>Sin cuentas por pagar</strong><small>No hay saldos con los filtros seleccionados.</small></td></tr>';
        }
    } else {
        elements.payablesTable?.replaceChildren(...payables.map(payableRow));
    }

    if (elements.payablesCount) {
        elements.payablesCount.textContent = (meta.total || 0) === 0
            ? 'Sin cuentas para mostrar.'
            : `${meta.from || 1}-${meta.to || payables.length} de ${meta.total} cuenta(s).`;
    }

    if (elements.payablesPrev) {
        elements.payablesPrev.disabled = !meta.current_page || meta.current_page <= 1;
    }

    if (elements.payablesNext) {
        elements.payablesNext.disabled = !meta.current_page || meta.current_page >= meta.last_page;
    }

    state.payables.page = meta.current_page || 1;
}

function payableRow(payable) {
    const row = document.createElement('tr');
    row.dataset.payableId = String(payable.id);
    row.classList.toggle('is-selected', state.payables.selectedPayable?.id === payable.id);
    const supplierName = payable.supplier?.name || 'Sin proveedor';
    const tone = payableStatusTone(payable.status);

    row.innerHTML = `
        <td><strong>#${payable.id} ${escapeHtml(payable.document_number || 'Sin documento')}</strong><small>Vence ${escapeHtml(payable.due_date || 'sin fecha')}</small></td>
        <td><strong>${escapeHtml(supplierName)}</strong><small>${escapeHtml(payable.supplier?.document_number || '')}</small></td>
        <td><span class="status-pill" data-tone="${tone}">${escapeHtml(payableStatusLabel(payable.status))}</span></td>
        <td><strong>${money(payable.original_base_amount)}</strong><small>${number(payable.original_local_amount)} Bs</small></td>
        <td><strong>${money(payable.paid_base_amount)}</strong><small>${number(payable.paid_local_amount)} Bs</small></td>
        <td><strong>${money(payable.balance_base_amount)}</strong><small>${number(payable.balance_local_amount)} Bs</small></td>
        <td><button class="ghost-button ghost-button--compact" type="button" data-admin-payable-select="${payable.id}">Ver</button></td>
    `;

    row.querySelector('[data-admin-payable-select]')?.addEventListener('click', () => selectPayableById(payable.id));
    row.addEventListener('dblclick', () => selectPayableById(payable.id));

    return row;
}

async function selectPayableById(payableId) {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.payablesStatus, 'Cargando detalle de cuenta...');

    try {
        const payable = await api(`/api/accounts-payable/${payableId}`, {
            headers: authHeaders(session),
        });
        selectPayable(payable);
    } catch (error) {
        setStatus(elements.payablesStatus, normalizeError(error), 'error');
    }
}

function selectPayable(payable) {
    state.payables.selectedPayable = payable;
    elements.payableTitle.textContent = `Cuenta #${payable.id}`;
    elements.payableSubtitle.textContent = `${payable.supplier?.name || 'Sin proveedor'} - ${payableStatusLabel(payable.status)} - ${payable.document_number || 'sin documento'}.`;
    elements.payableSummary.innerHTML = `
        <div><span>Total</span><strong>${money(payable.original_base_amount)}</strong></div>
        <div><span>Pagado</span><strong>${money(payable.paid_base_amount)}</strong></div>
        <div><span>Saldo</span><strong>${money(payable.balance_base_amount)}</strong></div>
    `;
    elements.payablePaymentCurrency.value = payable.currency || 'USD';
    clearPayablePaymentForm(false);
    renderPayablePayments(payable.payments || []);
    updatePayablePaymentState();
    elements.payablesTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    elements.payablesTable?.querySelector(`[data-payable-id="${payable.id}"]`)?.classList.add('is-selected');
    setStatus(elements.payablesStatus, `Cuenta seleccionada: #${payable.id}.`, 'neutral');
}

function renderPayablePayments(payments) {
    if (!payments.length) {
        elements.payablePaymentsTable.innerHTML = '<tr><td colspan="3"><strong>Sin pagos</strong><small>Registra el primer abono desde el formulario.</small></td></tr>';
    } else {
        elements.payablePaymentsTable.replaceChildren(...payments.map((payment) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(formatDateTime(payment.paid_at || payment.created_at))}</strong><small>${escapeHtml(payment.reference || 'Sin referencia')}</small></td>
                <td><strong>${payment.payment_currency} ${number(payment.amount)}</strong><small>Base ${money(payment.amount_base)}</small></td>
                <td><strong>${escapeHtml(payment.method || 'Sin metodo')}</strong><small>${escapeHtml(payment.notes || '')}</small></td>
            `;
            return row;
        }));
    }

    elements.payablePaymentsCount.textContent = `${payments.length} pago(s)`;
}

function clearPayablePaymentForm(clearStatus = true) {
    if (elements.payablePaymentAmount) {
        elements.payablePaymentAmount.value = '';
    }

    if (elements.payablePaymentMethod) {
        elements.payablePaymentMethod.value = '';
    }

    if (elements.payablePaymentReference) {
        elements.payablePaymentReference.value = '';
    }

    if (elements.payablePaymentNotes) {
        elements.payablePaymentNotes.value = '';
    }

    if (clearStatus) {
        setStatus(elements.payablesStatus, 'Formulario de pago limpio.', 'neutral');
    }
}

function fillPayableBalance() {
    const payable = state.payables.selectedPayable;

    if (!payable) {
        setStatus(elements.payablesStatus, 'Selecciona una cuenta por pagar primero.', 'error');
        return;
    }

    const currency = elements.payablePaymentCurrency?.value || 'USD';
    const amount = currency === 'VES' ? payable.balance_local_amount : payable.balance_base_amount;
    if (elements.payablePaymentAmount) {
        elements.payablePaymentAmount.value = Number(amount || 0).toFixed(2);
    }
}

async function registerPayablePayment() {
    const session = state.session;
    const payable = state.payables.selectedPayable;

    if (!session || !payable) {
        setStatus(elements.payablesStatus, 'Selecciona una cuenta por pagar antes de registrar pago.', 'error');
        return;
    }

    if (!can('accounts_payable.pay')) {
        setStatus(elements.payablesStatus, 'Tu usuario no tiene permiso para pagar cuentas.', 'error');
        return;
    }

    const amount = Number(elements.payablePaymentAmount?.value || 0);

    if (amount <= 0 || Number.isNaN(amount)) {
        setStatus(elements.payablesStatus, 'El monto del pago debe ser mayor que cero.', 'error');
        return;
    }

    const payload = {
        payment_currency: elements.payablePaymentCurrency?.value || 'USD',
        amount,
        method: elements.payablePaymentMethod?.value.trim() || null,
        reference: elements.payablePaymentReference?.value.trim() || null,
        notes: elements.payablePaymentNotes?.value.trim() || null,
    };

    setStatus(elements.payablesStatus, 'Registrando pago a proveedor...');
    setButtonLoading(elements.payablePay, true, 'Registrando...');

    try {
        await api(`/api/accounts-payable/${payable.id}/payments`, {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });
        const updated = await api(`/api/accounts-payable/${payable.id}`, {
            headers: authHeaders(session),
        });
        await loadPayables(state.payables.page);
        selectPayable(updated);
        await loadDashboard();
        setStatus(elements.payablesStatus, 'Pago registrado correctamente.', 'success');
    } catch (error) {
        setStatus(elements.payablesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.payablePay, false);
    }
}

function updatePayablePaymentState() {
    const payable = state.payables.selectedPayable;
    const canPay = Boolean(payable && payable.status !== 'paid' && Number(payable.balance_base_amount || 0) > 0 && can('accounts_payable.pay'));

    if (elements.payablePay) {
        elements.payablePay.disabled = !canPay;
    }

    if (elements.payableFillBalance) {
        elements.payableFillBalance.disabled = !payable;
    }
}

function clearPayableFilters() {
    if (elements.payablesSearch) {
        elements.payablesSearch.value = '';
    }

    if (elements.payablesStatusFilter) {
        elements.payablesStatusFilter.value = 'all';
    }

    if (elements.payablesSupplierFilter) {
        elements.payablesSupplierFilter.value = '';
    }

    if (elements.payablesDueFrom) {
        elements.payablesDueFrom.value = '';
    }

    if (elements.payablesDueTo) {
        elements.payablesDueTo.value = '';
    }

    loadPayables(1);
}

function payableStatusLabel(status) {
    return {
        pending: 'Pendiente',
        partial: 'Parcial',
        paid: 'Pagada',
        overdue: 'Vencida',
    }[status] || status || 'Sin estado';
}

function payableStatusTone(status) {
    return {
        pending: 'warning',
        partial: 'warning',
        paid: 'success',
        overdue: 'error',
    }[status] || 'neutral';
}

async function loadReceivableOptions() {
    const session = state.session;

    if (!session || state.receivables.customersLoaded || !can('customers.view')) {
        return;
    }

    const customers = await api('/api/customers?active_only=1&limit=100', {
        headers: authHeaders(session),
    }, true);

    state.receivables.customers = collectionData(customers);
    state.receivables.customersLoaded = true;
    renderReceivableCustomerOptions();
}

function renderReceivableCustomerOptions() {
    const options = [new Option('Todos', '')];

    state.receivables.customers.forEach((customer) => {
        const documentLabel = [customer.document_type, customer.document_number].filter(Boolean).join('-');
        const label = documentLabel ? `${customer.name} (${documentLabel})` : customer.name;
        options.push(new Option(label, String(customer.id)));
    });

    elements.receivablesCustomerFilter?.replaceChildren(...options);
}

async function loadReceivables(page = state.receivables.page) {
    const session = state.session;

    if (!session) {
        return;
    }

    state.receivables.page = page;
    setStatus(elements.receivablesStatus, 'Cargando cuentas por cobrar...');
    setButtonLoading(elements.receivablesRefresh, true, 'Actualizando...');
    setButtonLoading(elements.receivablesApply, true, 'Aplicando...');

    try {
        await loadReceivableOptions();

        const query = new URLSearchParams({
            status: elements.receivablesStatusFilter?.value || 'all',
            limit: '50',
            page: String(page),
        });
        const search = elements.receivablesSearch?.value.trim();
        const customerId = elements.receivablesCustomerFilter?.value;
        const dueFrom = elements.receivablesDueFrom?.value;
        const dueTo = elements.receivablesDueTo?.value;

        if (search) {
            query.set('search', search);
        }

        if (customerId) {
            query.set('customer_id', customerId);
        }

        if (dueFrom) {
            query.set('due_from', dueFrom);
        }

        if (dueTo) {
            query.set('due_to', dueTo);
        }

        const pageData = await api(`/api/accounts-receivable?${query}`, {
            headers: authHeaders(session),
        }, true);

        state.receivables.loaded = true;
        renderReceivables(pageData);
        setStatus(elements.receivablesStatus, `Cuentas actualizadas. ${pageData.meta?.total || 0} registro(s).`, 'success');
    } catch (error) {
        setStatus(elements.receivablesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.receivablesRefresh, false);
        setButtonLoading(elements.receivablesApply, false);
    }
}

function renderReceivables(pageData = {}) {
    const receivables = pageData.data || [];
    const meta = pageData.meta || {};

    if (!receivables.length) {
        if (elements.receivablesTable) {
            elements.receivablesTable.innerHTML = '<tr><td colspan="7"><strong>Sin cuentas por cobrar</strong><small>No hay saldos con los filtros seleccionados.</small></td></tr>';
        }
    } else {
        elements.receivablesTable?.replaceChildren(...receivables.map(receivableRow));
    }

    if (elements.receivablesCount) {
        elements.receivablesCount.textContent = (meta.total || 0) === 0
            ? 'Sin cuentas para mostrar.'
            : `${meta.from || 1}-${meta.to || receivables.length} de ${meta.total} cuenta(s).`;
    }

    if (elements.receivablesPrev) {
        elements.receivablesPrev.disabled = !meta.current_page || meta.current_page <= 1;
    }

    if (elements.receivablesNext) {
        elements.receivablesNext.disabled = !meta.current_page || meta.current_page >= meta.last_page;
    }

    state.receivables.page = meta.current_page || 1;
}

function receivableRow(receivable) {
    const row = document.createElement('tr');
    row.dataset.receivableId = String(receivable.id);
    row.classList.toggle('is-selected', state.receivables.selectedReceivable?.id === receivable.id);
    const customerName = receivable.customer?.name || 'Consumidor final';
    const tone = receivableStatusTone(receivable.status);

    row.innerHTML = `
        <td><strong>#${receivable.id} ${escapeHtml(receivable.document_number || 'Sin documento')}</strong><small>Vence ${escapeHtml(receivable.due_date || 'sin fecha')}</small></td>
        <td><strong>${escapeHtml(customerName)}</strong><small>${escapeHtml(receivable.customer?.document_number || '')}</small></td>
        <td><span class="status-pill" data-tone="${tone}">${escapeHtml(receivableStatusLabel(receivable.status))}</span></td>
        <td><strong>${money(receivable.original_base_amount)}</strong><small>${number(receivable.original_local_amount)} Bs</small></td>
        <td><strong>${money(receivable.paid_base_amount)}</strong><small>${number(receivable.paid_local_amount)} Bs</small></td>
        <td><strong>${money(receivable.balance_base_amount)}</strong><small>${number(receivable.balance_local_amount)} Bs</small></td>
        <td><button class="ghost-button ghost-button--compact" type="button" data-admin-receivable-select="${receivable.id}">Ver</button></td>
    `;

    row.querySelector('[data-admin-receivable-select]')?.addEventListener('click', () => selectReceivableById(receivable.id));
    row.addEventListener('dblclick', () => selectReceivableById(receivable.id));

    return row;
}

async function selectReceivableById(receivableId) {
    const session = state.session;

    if (!session) {
        return;
    }

    setStatus(elements.receivablesStatus, 'Cargando detalle de cuenta...');

    try {
        const receivable = await api(`/api/accounts-receivable/${receivableId}`, {
            headers: authHeaders(session),
        });
        selectReceivable(receivable);
    } catch (error) {
        setStatus(elements.receivablesStatus, normalizeError(error), 'error');
    }
}

function selectReceivable(receivable) {
    state.receivables.selectedReceivable = receivable;
    elements.receivableTitle.textContent = `Cuenta #${receivable.id}`;
    elements.receivableSubtitle.textContent = `${receivable.customer?.name || 'Consumidor final'} - ${receivableStatusLabel(receivable.status)} - ${receivable.document_number || 'sin documento'}.`;
    elements.receivableSummary.innerHTML = `
        <div><span>Total</span><strong>${money(receivable.original_base_amount)}</strong></div>
        <div><span>Cobrado</span><strong>${money(receivable.paid_base_amount)}</strong></div>
        <div><span>Saldo</span><strong>${money(receivable.balance_base_amount)}</strong></div>
    `;
    elements.receivablePaymentCurrency.value = receivable.currency || 'USD';
    clearReceivablePaymentForm(false);
    renderReceivablePayments(receivable.payments || []);
    updateReceivablePaymentState();
    elements.receivablesTable?.querySelectorAll('tr').forEach((row) => row.classList.remove('is-selected'));
    elements.receivablesTable?.querySelector(`[data-receivable-id="${receivable.id}"]`)?.classList.add('is-selected');
    setStatus(elements.receivablesStatus, `Cuenta seleccionada: #${receivable.id}.`, 'neutral');
}

function renderReceivablePayments(payments) {
    if (!payments.length) {
        elements.receivablePaymentsTable.innerHTML = '<tr><td colspan="3"><strong>Sin cobros</strong><small>Registra el primer abono desde el formulario.</small></td></tr>';
    } else {
        elements.receivablePaymentsTable.replaceChildren(...payments.map((payment) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(formatDateTime(payment.paid_at || payment.created_at))}</strong><small>${escapeHtml(payment.reference || 'Sin referencia')}</small></td>
                <td><strong>${payment.payment_currency} ${number(payment.amount)}</strong><small>Base ${money(payment.amount_base)}</small></td>
                <td><strong>${escapeHtml(payment.method || 'Sin metodo')}</strong><small>${escapeHtml(payment.notes || '')}</small></td>
            `;
            return row;
        }));
    }

    elements.receivablePaymentsCount.textContent = `${payments.length} cobro(s)`;
}

function clearReceivablePaymentForm(clearStatus = true) {
    if (elements.receivablePaymentAmount) {
        elements.receivablePaymentAmount.value = '';
    }

    if (elements.receivablePaymentMethod) {
        elements.receivablePaymentMethod.value = '';
    }

    if (elements.receivablePaymentReference) {
        elements.receivablePaymentReference.value = '';
    }

    if (elements.receivablePaymentNotes) {
        elements.receivablePaymentNotes.value = '';
    }

    if (clearStatus) {
        setStatus(elements.receivablesStatus, 'Formulario de cobro limpio.', 'neutral');
    }
}

function fillReceivableBalance() {
    const receivable = state.receivables.selectedReceivable;

    if (!receivable) {
        setStatus(elements.receivablesStatus, 'Selecciona una cuenta por cobrar primero.', 'error');
        return;
    }

    const currency = elements.receivablePaymentCurrency?.value || 'USD';
    const amount = currency === 'VES' ? receivable.balance_local_amount : receivable.balance_base_amount;
    if (elements.receivablePaymentAmount) {
        elements.receivablePaymentAmount.value = Number(amount || 0).toFixed(2);
    }
}

async function registerReceivablePayment() {
    const session = state.session;
    const receivable = state.receivables.selectedReceivable;

    if (!session || !receivable) {
        setStatus(elements.receivablesStatus, 'Selecciona una cuenta por cobrar antes de registrar cobro.', 'error');
        return;
    }

    if (!can('accounts_receivable.collect')) {
        setStatus(elements.receivablesStatus, 'Tu usuario no tiene permiso para cobrar cuentas.', 'error');
        return;
    }

    const amount = Number(elements.receivablePaymentAmount?.value || 0);

    if (amount <= 0 || Number.isNaN(amount)) {
        setStatus(elements.receivablesStatus, 'El monto del cobro debe ser mayor que cero.', 'error');
        return;
    }

    const payload = {
        payment_currency: elements.receivablePaymentCurrency?.value || 'USD',
        amount,
        method: elements.receivablePaymentMethod?.value.trim() || null,
        reference: elements.receivablePaymentReference?.value.trim() || null,
        notes: elements.receivablePaymentNotes?.value.trim() || null,
    };

    setStatus(elements.receivablesStatus, 'Registrando cobro a cliente...');
    setButtonLoading(elements.receivableCollect, true, 'Registrando...');

    try {
        await api(`/api/accounts-receivable/${receivable.id}/payments`, {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });
        const updated = await api(`/api/accounts-receivable/${receivable.id}`, {
            headers: authHeaders(session),
        });
        await loadReceivables(state.receivables.page);
        selectReceivable(updated);
        await loadDashboard();
        setStatus(elements.receivablesStatus, 'Cobro registrado correctamente.', 'success');
    } catch (error) {
        setStatus(elements.receivablesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.receivableCollect, false);
    }
}

function updateReceivablePaymentState() {
    const receivable = state.receivables.selectedReceivable;
    const canCollect = Boolean(receivable && receivable.status !== 'paid' && Number(receivable.balance_base_amount || 0) > 0 && can('accounts_receivable.collect'));

    if (elements.receivableCollect) {
        elements.receivableCollect.disabled = !canCollect;
    }

    if (elements.receivableFillBalance) {
        elements.receivableFillBalance.disabled = !receivable;
    }
}

function clearReceivableFilters() {
    if (elements.receivablesSearch) {
        elements.receivablesSearch.value = '';
    }

    if (elements.receivablesStatusFilter) {
        elements.receivablesStatusFilter.value = 'all';
    }

    if (elements.receivablesCustomerFilter) {
        elements.receivablesCustomerFilter.value = '';
    }

    if (elements.receivablesDueFrom) {
        elements.receivablesDueFrom.value = '';
    }

    if (elements.receivablesDueTo) {
        elements.receivablesDueTo.value = '';
    }

    loadReceivables(1);
}

function receivableStatusLabel(status) {
    return {
        pending: 'Pendiente',
        partial: 'Parcial',
        paid: 'Cobrada',
        overdue: 'Vencida',
    }[status] || status || 'Sin estado';
}

function receivableStatusTone(status) {
    return {
        pending: 'warning',
        partial: 'warning',
        paid: 'success',
        overdue: 'error',
    }[status] || 'neutral';
}

function inventoryRow(product) {
    const row = document.createElement('tr');
    row.className = product.is_active ? '' : 'admin-data-table__row--inactive';
    const canEdit = canUpdateProducts();
    const canDeactivate = canDeleteProducts() && product.is_active;
    const canReactivate = canUpdateProducts() && !product.is_active;
    const toggleButton = product.is_active
        ? `<button class="danger-button ghost-button--compact" type="button" data-admin-product-deactivate="${product.id}" ${canDeactivate ? '' : 'disabled'}>Desactivar</button>`
        : `<button class="ghost-button ghost-button--compact" type="button" data-admin-product-activate="${product.id}" ${canReactivate ? '' : 'disabled'}>Activar</button>`;

    row.innerHTML = `
        <td><strong>${escapeHtml(product.name)}</strong><small>${escapeHtml(product.sku)}</small></td>
        <td>${trackingLabel(product.tracking_type)}</td>
        <td><strong>${priceLabel(product)}</strong><small>${escapeHtml(product.sale_currency || 'USD')}</small></td>
        <td>${stockNumber(product.stock?.available)}</td>
        <td>${stockNumber(product.stock?.reserved)}</td>
        <td><span class="stock-pill stock-pill--${escapeHtml(product.stock?.status || 'available')}">${stockStatusLabel(product.stock?.status)}</span></td>
        <td><span class="status-pill" data-tone="${product.is_active ? 'success' : 'warning'}">${product.is_active ? 'Activo' : 'Inactivo'}</span></td>
        <td>
            <div class="table-actions">
                <button class="ghost-button ghost-button--compact" type="button" data-admin-product-detail="${product.id}">Detalle</button>
                <button class="ghost-button ghost-button--compact" type="button" data-admin-product-edit="${product.id}" ${canEdit ? '' : 'disabled'}>Editar</button>
                ${toggleButton}
            </div>
        </td>
    `;

    row.querySelector('[data-admin-product-detail]')?.addEventListener('click', () => {
        openInventoryProductDetail(product).catch((error) => {
            setStatus(elements.inventoryStatus, normalizeError(error), 'error');
        });
    });

    const button = row.querySelector('[data-admin-product-edit]');
    button?.addEventListener('click', () => {
        selectInventoryProduct(product).catch((error) => {
            setStatus(elements.inventoryStatus, normalizeError(error), 'error');
        });
    });
    row.querySelector('[data-admin-product-deactivate]')?.addEventListener('click', () => {
        deactivateInventoryProduct(product);
    });
    row.querySelector('[data-admin-product-activate]')?.addEventListener('click', () => {
        activateInventoryProduct(product);
    });

    return row;
}

async function openNewInventoryProduct() {
    if (!can('products.create')) {
        setStatus(elements.inventoryStatus, 'Tu usuario no tiene permiso para crear productos.', 'error');
        return;
    }

    state.inventory.mode = 'create';
    state.inventory.selectedProduct = null;
    hideInventoryDetail();
    elements.inventoryEditor.hidden = false;
    elements.inventoryEditorTitle.textContent = 'Nuevo producto';
    elements.inventoryEditorSubtitle.textContent = 'Se creara en esta empresa y quedara listo para sincronizar locales.';
    fillInventoryProductForm({
        name: '',
        sku: '',
        tracking_type: 'quantity',
        base_price: '',
        sale_currency: 'USD',
        sale_exchange_rate_type_id: '',
        warranty_policy_id: '',
        is_active: true,
        can_change_tracking_type: true,
    });
    elements.inventoryDeactivate.hidden = true;
    renderProductPriceListRows([]);
    await loadInventoryCatalogOptions();
    setStatus(elements.inventoryStatus, 'Completa nombre, SKU y precio base. El stock se carga luego por entradas.', 'neutral');
}

async function selectInventoryProduct(product) {
    state.inventory.mode = 'edit';
    state.inventory.selectedProduct = product;
    hideInventoryDetail();
    elements.inventoryEditor.hidden = false;
    elements.inventoryEditorTitle.textContent = product.name;
    elements.inventoryEditorSubtitle.textContent = `${product.sku} - ${trackingLabel(product.tracking_type)}`;
    fillInventoryProductForm(product);
    elements.inventoryDeactivate.hidden = !product.is_active || !canDeleteProducts();
    renderProductPriceListRows([], true);
    setStatus(elements.inventoryStatus, 'Cargando precios por lista del producto...', 'neutral');

    const [detail] = await Promise.all([
        api(`/api/products/${product.id}`, {
            headers: authHeaders(state.session),
        }),
        loadInventoryCatalogOptions(),
        loadInventoryPriceLists(),
        loadProductPriceLists(product),
    ]);

    if (state.inventory.selectedProduct?.id !== product.id) {
        return;
    }

    state.inventory.selectedProduct = { ...product, ...detail };
    elements.inventoryEditorTitle.textContent = state.inventory.selectedProduct.name;
    elements.inventoryEditorSubtitle.textContent = `${state.inventory.selectedProduct.sku} - ${trackingLabel(state.inventory.selectedProduct.tracking_type)}`;
    fillInventoryProductForm(state.inventory.selectedProduct);
    elements.inventoryDeactivate.hidden = !state.inventory.selectedProduct.is_active || !canDeleteProducts();
    renderProductPriceListRows();
    setStatus(elements.inventoryStatus, 'Edita precio base, moneda, estado o precios por lista. Todo queda listo para sincronizarse.', 'neutral');
}

async function openInventoryProductDetail(product) {
    const session = state.session;

    if (!session || !product) {
        return;
    }

    elements.inventoryEditor.hidden = true;
    elements.inventoryDetail.hidden = false;
    state.inventory.selectedProduct = null;
    state.inventory.productPrices = [];
    state.inventory.detailProduct = product;

    renderInventoryDetailLoading(product);
    setStatus(elements.inventoryStatus, `Cargando detalle de ${product.name}...`, 'neutral');

    const [detail, prices] = await Promise.all([
        api(`/api/inventory-center/products/${product.id}`, {
            headers: authHeaders(session),
        }),
        api(`/api/products/${product.id}/prices`, {
            headers: authHeaders(session),
        }).catch(() => []),
    ]);

    if (state.inventory.detailProduct?.id !== product.id) {
        return;
    }

    const productDetail = {
        ...product,
        ...(detail.product || {}),
    };

    state.inventory.detailProduct = productDetail;
    renderInventoryDetail(productDetail, detail, collectionData(prices));
    setStatus(elements.inventoryStatus, `Detalle cargado para ${productDetail.name}.`, 'success');
}

function renderInventoryDetailLoading(product) {
    elements.inventoryDetailTitle.textContent = product.name;
    elements.inventoryDetailSubtitle.textContent = `${product.sku} - cargando detalle operativo...`;
    elements.inventoryDetailMeta.innerHTML = '<span>Cargando datos...</span>';
    elements.inventoryDetailStockTotal.textContent = '...';
    elements.inventoryDetailStock.innerHTML = '<p class="inventory-detail__empty">Cargando stock por almacen...</p>';
    elements.inventoryDetailPriceCount.textContent = '...';
    elements.inventoryDetailPrices.innerHTML = '<p class="inventory-detail__empty">Cargando precios por lista...</p>';
    elements.inventoryDetailChangeCount.textContent = '...';
    elements.inventoryDetailChanges.innerHTML = '<p class="inventory-detail__empty">Cargando actividad reciente...</p>';
}

function renderInventoryDetail(product, detail = {}, prices = []) {
    const stock = detail.stock || {};
    const totals = stock.totals || {};
    const warehouses = stock.by_warehouse || [];
    const movements = detail.recent_movements || [];
    const audits = detail.recent_audits || [];
    const changes = [...movements.map((item) => ({ ...item, detail_type: 'movement' })), ...audits.map((item) => ({ ...item, detail_type: 'audit' }))]
        .sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))
        .slice(0, 8);

    elements.inventoryDetailTitle.textContent = product.name;
    elements.inventoryDetailSubtitle.textContent = `${product.sku} - ${trackingLabel(product.tracking_type)}`;
    elements.inventoryDetailEdit.disabled = !canUpdateProducts();
    elements.inventoryDetailToggle.disabled = product.is_active ? !canDeleteProducts() : !canUpdateProducts();
    elements.inventoryDetailToggle.textContent = product.is_active ? 'Desactivar' : 'Activar';
    elements.inventoryDetailToggle.className = product.is_active ? 'danger-button' : 'ghost-button';

    elements.inventoryDetailMeta.replaceChildren(
        detailMetric('Precio base', priceLabel(product)),
        detailMetric('Estado', product.is_active ? 'Activo' : 'Inactivo'),
        detailMetric('Tasa', product.sale_exchange_rate_type?.name || 'Sin tasa'),
        detailMetric('Garantia', warrantyLabel(product.warranty_policy)),
        detailMetric('Actualizado', formatDateTime(product.updated_at)),
    );

    elements.inventoryDetailStockTotal.textContent = `${stockNumber(totals.available)} disp.`;
    renderInventoryDetailStock(warehouses);
    renderInventoryDetailPrices(prices);
    renderInventoryDetailChanges(changes);
}

function detailMetric(label, value) {
    const item = document.createElement('article');
    item.className = 'inventory-detail__metric';
    item.innerHTML = `<span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong>`;
    return item;
}

function renderInventoryDetailStock(warehouses) {
    if (!warehouses.length) {
        elements.inventoryDetailStock.innerHTML = '<p class="inventory-detail__empty">Sin stock por almacen.</p>';
        return;
    }

    elements.inventoryDetailStock.replaceChildren(...warehouses.map((warehouse) => {
        const item = document.createElement('article');
        item.className = 'inventory-detail__line';
        item.innerHTML = `
            <div>
                <strong>${escapeHtml(warehouse.warehouse_name || 'Almacen')}</strong>
                <small>${escapeHtml(warehouse.branch_name || 'Sin sucursal')} ${warehouse.warehouse_code ? `- ${escapeHtml(warehouse.warehouse_code)}` : ''}</small>
            </div>
            <span>${stockNumber(warehouse.available)} / ${stockNumber(warehouse.reserved)} / ${stockNumber(warehouse.damaged)}</span>
        `;
        return item;
    }));
}

function renderInventoryDetailPrices(prices) {
    elements.inventoryDetailPriceCount.textContent = `${prices.length}`;

    if (!prices.length) {
        elements.inventoryDetailPrices.innerHTML = '<p class="inventory-detail__empty">Sin precios por lista configurados.</p>';
        return;
    }

    elements.inventoryDetailPrices.replaceChildren(...prices.map((price) => {
        const item = document.createElement('article');
        item.className = 'inventory-detail__line';
        item.innerHTML = `
            <div>
                <strong>${escapeHtml(price.price_list?.name || 'Lista')}</strong>
                <small>${escapeHtml(price.price_list?.code || '')}${price.is_active === false ? ' - inactiva' : ''}</small>
            </div>
            <span>${escapeHtml(price.currency || 'USD')} ${Number(price.price || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
        `;
        return item;
    }));
}

function renderInventoryDetailChanges(changes) {
    elements.inventoryDetailChangeCount.textContent = `${changes.length}`;

    if (!changes.length) {
        elements.inventoryDetailChanges.innerHTML = '<p class="inventory-detail__empty">Sin movimientos recientes.</p>';
        return;
    }

    elements.inventoryDetailChanges.replaceChildren(...changes.map((change) => {
        const isAudit = change.detail_type === 'audit';
        const item = document.createElement('article');
        item.className = 'inventory-detail__line';
        item.innerHTML = `
            <div>
                <strong>${escapeHtml(isAudit ? auditActionLabel(change.action) : movementTypeLabel(change.type))}</strong>
                <small>${escapeHtml(change.reason || change.created_by_name || 'Actividad')} - ${formatDateTime(change.created_at)}</small>
            </div>
            <span>${isAudit ? 'Auditoria' : stockNumber(change.quantity)}</span>
        `;
        return item;
    }));
}

function hideInventoryDetail() {
    if (!elements.inventoryDetail) {
        return;
    }

    elements.inventoryDetail.hidden = true;
    state.inventory.detailProduct = null;
}

function fillInventoryProductForm(product) {
    elements.inventoryName.value = product.name || '';
    elements.inventorySku.value = product.sku || '';
    elements.inventoryTrackingEdit.value = product.tracking_type || 'quantity';
    elements.inventoryTrackingEdit.disabled = product.can_change_tracking_type === false;
    elements.inventoryPrice.value = product.base_price ?? '';
    elements.inventoryCurrency.value = product.sale_currency || 'USD';
    elements.inventoryActiveEdit.value = product.is_active === false ? '0' : '1';
    renderInventoryCatalogOptions(product);
}

function renderInventoryCatalogOptions(product = {}) {
    renderSelectOptions(
        elements.inventoryRateType,
        state.inventory.rateTypes,
        'Sin tasa asignada',
        product.sale_exchange_rate_type_id,
        (item) => `${item.name}${item.code ? ` (${item.code})` : ''}${item.is_default ? ' - predeterminada' : ''}`,
    );

    renderSelectOptions(
        elements.inventoryWarranty,
        state.inventory.warrantyPolicies,
        'Sin política asignada',
        product.warranty_policy_id,
        (item) => `${item.name}${item.days ? ` - ${item.days} días` : ''}`,
    );
}

function renderSelectOptions(select, items, emptyLabel, selectedValue, labelCallback) {
    if (!select) {
        return;
    }

    const normalizedSelected = selectedValue === null || selectedValue === undefined ? '' : String(selectedValue);
    select.replaceChildren(new Option(emptyLabel, ''));
    items.forEach((item) => {
        const option = new Option(labelCallback(item), String(item.id));
        option.selected = String(item.id) === normalizedSelected;
        select.add(option);
    });
}

async function loadInventoryCatalogOptions() {
    const session = state.session;

    if (!session) {
        return;
    }

    const requests = [];

    if (!state.inventory.rateTypes.length && can('currency.view')) {
        requests.push(api('/api/currency/rate-types', {
            headers: authHeaders(session),
        }).then((rateTypes) => {
            state.inventory.rateTypes = collectionData(rateTypes).filter((rateType) => rateType.is_active !== false);
        }));
    }

    if (!state.inventory.warrantyPolicies.length && can('warranty_policies.view')) {
        requests.push(api('/api/warranty-policies', {
            headers: authHeaders(session),
        }).then((policies) => {
            state.inventory.warrantyPolicies = collectionData(policies).filter((policy) => policy.is_active !== false);
        }));
    }

    await Promise.all(requests);
    renderInventoryCatalogOptions(state.inventory.selectedProduct || {});
}

function inventoryProductPayload() {
    const rateType = elements.inventoryRateType.value;
    const warranty = elements.inventoryWarranty.value;

    return {
        name: elements.inventoryName.value.trim(),
        sku: elements.inventorySku.value.trim(),
        tracking_type: elements.inventoryTrackingEdit.value || 'quantity',
        base_price: elements.inventoryPrice.value === '' ? 0 : Number(elements.inventoryPrice.value),
        sale_currency: elements.inventoryCurrency.value || 'USD',
        sale_exchange_rate_type_id: rateType ? Number(rateType) : null,
        warranty_policy_id: warranty ? Number(warranty) : null,
        is_active: elements.inventoryActiveEdit.value === '1',
    };
}

function validateInventoryProductPayload(payload) {
    if (!payload.name) {
        return 'El nombre del producto es obligatorio.';
    }

    if (!payload.sku) {
        return 'El SKU del producto es obligatorio.';
    }

    if (Number.isNaN(payload.base_price) || payload.base_price < 0) {
        return 'El precio base debe ser mayor o igual a cero.';
    }

    return null;
}

async function saveInventoryProductPrice() {
    const product = state.inventory.selectedProduct;
    const session = state.session;
    const isCreate = state.inventory.mode === 'create';

    if ((!product && !isCreate) || !session) {
        setStatus(elements.inventoryStatus, 'Selecciona un producto antes de guardar.', 'error');
        return;
    }

    const payload = inventoryProductPayload();
    const validationError = validateInventoryProductPayload(payload);

    if (validationError) {
        setStatus(elements.inventoryStatus, validationError, 'error');
        return;
    }

    setStatus(elements.inventoryStatus, 'Guardando producto y preparando sincronización...');
    setButtonLoading(elements.inventorySave, true, 'Guardando...');

    try {
        await api(isCreate ? '/api/products' : `/api/products/${product.id}`, {
            method: isCreate ? 'POST' : 'PUT',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });

        elements.inventoryEditor.hidden = true;
        state.inventory.mode = 'edit';
        state.inventory.selectedProduct = null;
        hideInventoryDetail();
        state.inventory.loaded = false;
        await loadInventory();
        await loadDashboard();
        setStatus(elements.inventoryStatus, isCreate ? 'Producto creado. El cambio quedo listo para sincronizarse.' : 'Producto actualizado. El cambio quedo listo para sincronizarse.', 'success');
    } catch (error) {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.inventorySave, false);
    }
}

async function deactivateInventoryProduct(product) {
    const session = state.session;

    if (!product || !session) {
        return;
    }

    if (!canDeleteProducts()) {
        setStatus(elements.inventoryStatus, 'Tu usuario no tiene permiso para desactivar productos.', 'error');
        return;
    }

    const confirmed = window.confirm(`¿Desactivar ${product.name}? No se eliminará histórico ni movimientos.`);

    if (!confirmed) {
        return;
    }

    setStatus(elements.inventoryStatus, 'Desactivando producto y preparando sincronización...');

    try {
        await api(`/api/products/${product.id}`, {
            method: 'DELETE',
            headers: authHeaders(session),
        });

        elements.inventoryEditor.hidden = true;
        state.inventory.mode = 'edit';
        state.inventory.selectedProduct = null;
        state.inventory.productPrices = [];
        hideInventoryDetail();
        state.inventory.loaded = false;
        await loadInventory();
        await loadDashboard();
        setStatus(elements.inventoryStatus, 'Producto desactivado. El cambio quedo listo para sincronizarse.', 'success');
    } catch (error) {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    }
}

async function activateInventoryProduct(product) {
    const session = state.session;

    if (!product || !session) {
        return;
    }

    if (!canUpdateProducts()) {
        setStatus(elements.inventoryStatus, 'Tu usuario no tiene permiso para activar productos.', 'error');
        return;
    }

    setStatus(elements.inventoryStatus, 'Activando producto y preparando sincronización...');

    try {
        await api(`/api/products/${product.id}`, {
            method: 'PATCH',
            headers: authHeaders(session),
            body: JSON.stringify({ is_active: true }),
        });

        elements.inventoryEditor.hidden = true;
        state.inventory.mode = 'edit';
        state.inventory.selectedProduct = null;
        state.inventory.productPrices = [];
        hideInventoryDetail();
        state.inventory.loaded = false;
        await loadInventory();
        await loadDashboard();
        setStatus(elements.inventoryStatus, 'Producto activado. El cambio quedó listo para sincronizarse.', 'success');
    } catch (error) {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
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

async function loadRates() {
    const session = state.session;

    if (!session) {
        return;
    }

    if (!can('currency.view')) {
        setStatus(elements.ratesStatus, 'Tu usuario no tiene permiso para ver tasas.', 'error');
        return;
    }

    setStatus(elements.ratesStatus, 'Cargando tasas de cambio...');
    setButtonLoading(elements.ratesRefresh, true, 'Actualizando...');

    try {
        const [typesPayload, ratesPayload] = await Promise.all([
            api('/api/currency/rate-types', { headers: authHeaders(session) }),
            api('/api/currency/rates', { headers: authHeaders(session) }),
        ]);

        state.rates.rateTypes = collectionData(typesPayload);
        state.rates.rates = collectionData(ratesPayload);
        state.rates.selectedType = keepSelectedOrFirst(state.rates.rateTypes, state.rates.selectedType);
        state.rates.loaded = true;

        renderRates();
        setStatus(elements.ratesStatus, 'Tasas actualizadas.', 'success');
    } catch (error) {
        setStatus(elements.ratesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.ratesRefresh, false);
    }
}

function renderRates() {
    renderRateTypes();
    renderRateHistory();
    renderRateTypeForm();
    renderRateValueTypes();
}

function renderRateTypes() {
    if (!elements.rateTypesTable) {
        return;
    }

    if (!state.rates.rateTypes.length) {
        elements.rateTypesTable.innerHTML = '<tr><td colspan="6"><strong>Sin tipos de tasa</strong><small>Crea BCV, paralelo u otra tasa antes de registrar valores.</small></td></tr>';
        return;
    }

    elements.rateTypesTable.replaceChildren(...state.rates.rateTypes.map((type) => {
        const activeRate = state.rates.rates.find((rate) => rate.exchange_rate_type_id === type.id && rate.is_active);
        const row = document.createElement('tr');
        row.className = state.rates.selectedType?.id === type.id ? 'is-selected' : '';
        row.innerHTML = `
            <td><strong>${escapeHtml(type.code)}</strong></td>
            <td>${escapeHtml(type.name)}</td>
            <td>${type.is_default ? 'Si' : 'No'}</td>
            <td>${type.is_active ? '<span class="access-chip">Activa</span>' : '<span class="access-chip access-chip--locked">Inactiva</span>'}</td>
            <td>${activeRate ? `${escapeHtml(activeRate.quote_currency)} ${number(activeRate.rate)}` : 'Sin tasa'}</td>
            <td><button class="ghost-button ghost-button--compact" type="button" data-rate-type="${type.id}">Editar</button></td>
        `;
        row.querySelector('[data-rate-type]')?.addEventListener('click', () => selectRateType(type));
        row.addEventListener('dblclick', () => selectRateType(type));

        return row;
    }));
}

function renderRateHistory() {
    if (!elements.ratesTable) {
        return;
    }

    if (!state.rates.rates.length) {
        elements.ratesTable.innerHTML = '<tr><td colspan="5"><strong>Sin tasas registradas</strong><small>Registra el primer valor para comenzar.</small></td></tr>';
        return;
    }

    elements.ratesTable.replaceChildren(...state.rates.rates.map((rate) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><strong>${escapeHtml(rate.exchange_rate_type_code || 'N/D')}</strong><small>${escapeHtml(rate.exchange_rate_type_name || '')}</small></td>
            <td>${escapeHtml(rate.quote_currency)} ${number(rate.rate)}</td>
            <td>${formatDateTime(rate.effective_at)}</td>
            <td>${rate.is_active ? '<span class="access-chip">Vigente</span>' : '<span class="access-chip access-chip--locked">Historial</span>'}</td>
            <td>${escapeHtml(rate.source || 'Manual')}</td>
        `;

        return row;
    }));
}

function selectRateType(type) {
    state.rates.selectedType = type;
    renderRates();
    setStatus(elements.ratesStatus, `Editando tipo de tasa ${type.code}.`, 'neutral');
}

function renderRateTypeForm() {
    const type = state.rates.selectedType;

    if (!elements.rateTypeTitle) {
        return;
    }

    elements.rateTypeTitle.textContent = type ? `Editar ${type.code}` : 'Nuevo tipo de tasa';
    elements.rateTypeCode.value = type?.code || '';
    elements.rateTypeName.value = type?.name || '';
    elements.rateTypeDefault.checked = Boolean(type?.is_default);
    elements.rateTypeActive.checked = type ? Boolean(type.is_active) : true;
    elements.rateTypeDeactivate.disabled = !type;
}

function renderRateValueTypes() {
    if (!elements.rateValueType) {
        return;
    }

    const selectedValue = elements.rateValueType.value || state.rates.selectedType?.id || '';
    elements.rateValueType.innerHTML = state.rates.rateTypes
        .filter((type) => type.is_active)
        .map((type) => `<option value="${type.id}">${escapeHtml(type.name)} (${escapeHtml(type.code)})</option>`)
        .join('');

    if (selectedValue) {
        elements.rateValueType.value = String(selectedValue);
    }

    if (!elements.rateEffectiveAt.value) {
        elements.rateEffectiveAt.value = localDateTimeValue();
    }
}

function clearRateTypeForm() {
    state.rates.selectedType = null;
    renderRates();
    setStatus(elements.ratesStatus, 'Formulario listo para crear un tipo de tasa.', 'neutral');
}

async function saveRateType() {
    const session = state.session;
    const type = state.rates.selectedType;

    if (!session) {
        return;
    }

    if (!can('currency.manage')) {
        setStatus(elements.ratesStatus, 'Tu usuario no tiene permiso para administrar tasas.', 'error');
        return;
    }

    const payload = {
        code: elements.rateTypeCode.value.trim().toUpperCase(),
        name: elements.rateTypeName.value.trim(),
        is_default: elements.rateTypeDefault.checked,
        is_active: elements.rateTypeActive.checked,
    };

    if (!payload.code || !payload.name) {
        setStatus(elements.ratesStatus, 'Completa codigo y nombre del tipo de tasa.', 'error');
        return;
    }

    setStatus(elements.ratesStatus, type ? 'Actualizando tipo de tasa...' : 'Creando tipo de tasa...');
    setButtonLoading(elements.rateTypeSave, true, 'Guardando...');

    try {
        const saved = await api(type ? `/api/currency/rate-types/${type.id}` : '/api/currency/rate-types', {
            method: type ? 'PUT' : 'POST',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });

        state.rates.selectedType = saved;
        await loadRates();
        setStatus(elements.ratesStatus, 'Tipo de tasa guardado y listo para sincronizar.', 'success');
    } catch (error) {
        setStatus(elements.ratesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.rateTypeSave, false);
    }
}

async function deactivateRateType() {
    const session = state.session;
    const type = state.rates.selectedType;

    if (!session || !type) {
        setStatus(elements.ratesStatus, 'Selecciona un tipo de tasa antes de desactivar.', 'error');
        return;
    }

    if (!can('currency.manage')) {
        setStatus(elements.ratesStatus, 'Tu usuario no tiene permiso para administrar tasas.', 'error');
        return;
    }

    setStatus(elements.ratesStatus, 'Desactivando tipo de tasa...');
    setButtonLoading(elements.rateTypeDeactivate, true, 'Desactivando...');

    try {
        await api(`/api/currency/rate-types/${type.id}`, {
            method: 'DELETE',
            headers: authHeaders(session),
        });

        state.rates.selectedType = null;
        await loadRates();
        setStatus(elements.ratesStatus, 'Tipo de tasa desactivado y listo para sincronizar.', 'success');
    } catch (error) {
        setStatus(elements.ratesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.rateTypeDeactivate, false);
    }
}

async function saveExchangeRate() {
    const session = state.session;

    if (!session) {
        return;
    }

    if (!can('currency.manage')) {
        setStatus(elements.ratesStatus, 'Tu usuario no tiene permiso para registrar tasas.', 'error');
        return;
    }

    const payload = {
        exchange_rate_type_id: Number(elements.rateValueType.value),
        base_currency: 'USD',
        quote_currency: 'VES',
        rate: Number(elements.rateValue.value),
        effective_at: elements.rateEffectiveAt.value,
        source: elements.rateSource.value.trim() || 'Manual',
        is_active: elements.rateActive.checked,
    };

    if (!payload.exchange_rate_type_id || Number.isNaN(payload.rate) || payload.rate <= 0 || !payload.effective_at) {
        setStatus(elements.ratesStatus, 'Selecciona tipo, valor y fecha vigente.', 'error');
        return;
    }

    setStatus(elements.ratesStatus, 'Registrando tasa y preparando sincronizacion...');
    setButtonLoading(elements.rateSave, true, 'Registrando...');

    try {
        await api('/api/currency/rates', {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify(payload),
        });

        elements.rateValue.value = '';
        elements.rateSource.value = '';
        elements.rateEffectiveAt.value = localDateTimeValue();
        await loadRates();
        setStatus(elements.ratesStatus, 'Tasa registrada. Los locales la recibiran por sincronizacion.', 'success');
    } catch (error) {
        setStatus(elements.ratesStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.rateSave, false);
    }
}

function localDateTimeValue() {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());

    return date.toISOString().slice(0, 16);
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
        suppliers: 'Proveedores',
        customers: 'Clientes',
        settings: 'Configuracion',
        sync: 'Sincronizacion',
        accounts_receivable: 'Cuentas por cobrar',
        accounts_payable: 'Cuentas por pagar',
        currency: 'Tasas',
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
        collect: 'Cobrar',
        pay: 'Pagar',
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

function posOrderStatusLabel(status) {
    return {
        paid: 'Pagada',
        open: 'Pendiente',
        cancelled: 'Cancelada',
    }[status] ?? status;
}

function cashSessionStatusLabel(status) {
    return {
        open: 'Abierta',
        closed: 'Cerrada',
        cancelled: 'Cancelada',
    }[status] ?? status;
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

function canDeleteProducts() {
    return can('products.delete');
}

function trackingLabel(type) {
    return type === 'serialized' ? 'Serializado / IMEI' : 'Por cantidad';
}

function warrantyLabel(policy) {
    if (!policy) {
        return 'Sin garantia';
    }

    const days = policy.duration_days ? `${policy.duration_days} dias` : 'sin dias';

    return `${policy.name} - ${days}`;
}

function movementTypeLabel(type) {
    return {
        purchase: 'Entrada compra',
        purchase_return: 'Dev. proveedor',
        sale: 'Venta',
        sale_return: 'Dev. venta',
        adjustment_in: 'Ajuste entrada',
        adjustment_out: 'Ajuste salida',
        transfer_in: 'Traslado entrada',
        transfer_out: 'Traslado salida',
        return_in: 'Retorno entrada',
        return_out: 'Retorno salida',
        damaged: 'Danado',
        reserved: 'Reservado',
        released: 'Liberado',
        in: 'Entrada',
        out: 'Salida',
        return: 'Devolucion',
        adjustment: 'Ajuste',
    }[type] ?? (type || 'Movimiento');
}

function auditActionLabel(action) {
    return {
        created: 'Producto creado',
        updated: 'Producto actualizado',
        deleted: 'Producto desactivado',
        activated: 'Producto activado',
        price_lists_updated: 'Listas actualizadas',
    }[action] ?? (action || 'Auditoria');
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
elements.period?.addEventListener('change', () => {
    state.sales.loaded = false;
    state.reports.loaded = false;
    if (elements.salesDateFrom) {
        elements.salesDateFrom.value = '';
    }
    if (elements.salesDateTo) {
        elements.salesDateTo.value = '';
    }
    if (elements.reportsDateFrom) {
        elements.reportsDateFrom.value = '';
    }
    if (elements.reportsDateTo) {
        elements.reportsDateTo.value = '';
    }
    loadDashboard();

    if (state.activeSection === 'sales') {
        loadAdminSales(1);
    }

    if (state.activeSection === 'reports') {
        loadOperationalReports();
    }
});
elements.tenantSwitcher?.addEventListener('change', switchTenant);
elements.logout?.addEventListener('click', clearSession);
elements.portalNavItems.forEach((item) => {
    item.addEventListener('click', () => activatePortalSection(item.dataset.portalSection));
});
elements.salesRefresh?.addEventListener('click', () => loadAdminSales());
elements.salesApply?.addEventListener('click', () => loadAdminSales(1));
elements.salesClear?.addEventListener('click', clearAdminSalesFilters);
elements.salesExport?.addEventListener('click', exportAdminSales);
elements.salesPrev?.addEventListener('click', () => loadAdminSales(Math.max(state.sales.page - 1, 1)));
elements.salesNext?.addEventListener('click', () => loadAdminSales(state.sales.page + 1));
elements.salesSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadAdminSales(1);
    }
});
[
    elements.salesBranch,
    elements.salesCashRegister,
    elements.salesCashier,
    elements.salesStatusFilter,
].forEach((filter) => {
    filter?.addEventListener('change', () => {
        if (filter === elements.salesBranch && elements.salesCashRegister) {
            elements.salesCashRegister.value = '';
        }

        state.sales.loaded = false;
        loadAdminSales(1);
    });
});
elements.salesTable?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-admin-sales-detail]');

    if (!button) {
        return;
    }

    loadAdminSaleDetail(button.dataset.adminSalesDetail);
});
elements.reportsRefresh?.addEventListener('click', loadOperationalReports);
[
    elements.reportsBranch,
    elements.reportsCashRegister,
    elements.reportsCashier,
    elements.reportsOrderStatus,
].forEach((filter) => {
    filter?.addEventListener('change', () => {
        if (filter === elements.reportsBranch && elements.reportsCashRegister) {
            elements.reportsCashRegister.value = '';
        }

        state.reports.loaded = false;
        loadOperationalReports();
    });
});
elements.reportsDateFrom?.addEventListener('change', () => {
    state.reports.loaded = false;
});
elements.reportsDateTo?.addEventListener('change', () => {
    state.reports.loaded = false;
});
elements.reportsClearFilters?.addEventListener('click', clearOperationalReportFilters);
elements.reportsExportOrders?.addEventListener('click', () => exportOperationalReport('recent_orders', elements.reportsExportOrders));
elements.reportsExportPayments?.addEventListener('click', () => exportOperationalReport('payment_methods', elements.reportsExportPayments));
elements.reportsExportProducts?.addEventListener('click', () => exportOperationalReport('top_products', elements.reportsExportProducts));
elements.reportsExportCash?.addEventListener('click', () => exportOperationalReport('cash_sessions', elements.reportsExportCash));
elements.inventoryRefresh?.addEventListener('click', () => loadInventory());
elements.inventoryNew?.addEventListener('click', () => {
    openNewInventoryProduct().catch((error) => {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    });
});
elements.inventoryApply?.addEventListener('click', () => loadInventory(1));
elements.inventoryQuickStatus?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-inventory-active-filter]');

    if (!button || !elements.inventoryActive) {
        return;
    }

    elements.inventoryActive.value = button.dataset.inventoryActiveFilter || 'all';
    loadInventory(1);
});
elements.inventorySearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadInventory(1);
    }
});
elements.inventoryPrev?.addEventListener('click', () => loadInventory(Math.max(state.inventory.page - 1, 1)));
elements.inventoryNext?.addEventListener('click', () => loadInventory(state.inventory.page + 1));
elements.inventorySave?.addEventListener('click', saveInventoryProductPrice);
elements.inventoryDeactivate?.addEventListener('click', () => deactivateInventoryProduct(state.inventory.selectedProduct));
elements.inventoryDetailClose?.addEventListener('click', () => {
    hideInventoryDetail();
    setStatus(elements.inventoryStatus, 'Detalle cerrado.');
});
elements.inventoryDetailEdit?.addEventListener('click', () => {
    const product = state.inventory.detailProduct;

    if (!product) {
        return;
    }

    selectInventoryProduct(product).catch((error) => {
        setStatus(elements.inventoryStatus, normalizeError(error), 'error');
    });
});
elements.inventoryDetailToggle?.addEventListener('click', () => {
    const product = state.inventory.detailProduct;

    if (!product) {
        return;
    }

    if (product.is_active) {
        deactivateInventoryProduct(product);
        return;
    }

    activateInventoryProduct(product);
});
elements.priceListSave?.addEventListener('click', saveProductPriceLists);
elements.priceCopyBase?.addEventListener('click', copyBasePriceToEmptyLists);
elements.inventoryCancel?.addEventListener('click', () => {
    elements.inventoryEditor.hidden = true;
    state.inventory.mode = 'edit';
    state.inventory.selectedProduct = null;
    state.inventory.productPrices = [];
    elements.inventoryDeactivate.hidden = true;
    renderProductPriceListRows([]);
    setStatus(elements.inventoryStatus, 'Edición cancelada.');
});
elements.ratesRefresh?.addEventListener('click', loadRates);
elements.rateTypeSave?.addEventListener('click', saveRateType);
elements.rateTypeDeactivate?.addEventListener('click', deactivateRateType);
elements.rateTypeCancel?.addEventListener('click', clearRateTypeForm);
elements.rateSave?.addEventListener('click', saveExchangeRate);
elements.movementsRefresh?.addEventListener('click', () => loadMovements());
elements.movementsApply?.addEventListener('click', () => loadMovements(1));
elements.movementsClear?.addEventListener('click', clearMovementFilters);
elements.movementsPrev?.addEventListener('click', () => loadMovements(Math.max(state.movements.page - 1, 1)));
elements.movementsNext?.addEventListener('click', () => loadMovements(state.movements.page + 1));
elements.movementsSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadMovements(1);
    }
});
elements.suppliersRefresh?.addEventListener('click', () => loadSuppliers());
elements.supplierNew?.addEventListener('click', clearSupplierForm);
elements.suppliersApply?.addEventListener('click', () => loadSuppliers(1));
elements.suppliersClear?.addEventListener('click', clearSupplierFilters);
elements.suppliersPrev?.addEventListener('click', () => loadSuppliers(Math.max(state.suppliers.page - 1, 1)));
elements.suppliersNext?.addEventListener('click', () => loadSuppliers(state.suppliers.page + 1));
elements.suppliersSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadSuppliers(1);
    }
});
elements.supplierSave?.addEventListener('click', saveSupplier);
elements.supplierDeactivate?.addEventListener('click', toggleSupplierActive);
elements.supplierCancel?.addEventListener('click', clearSupplierForm);
elements.customersRefresh?.addEventListener('click', () => loadCustomers());
elements.customerNew?.addEventListener('click', clearCustomerForm);
elements.customersApply?.addEventListener('click', () => loadCustomers(1));
elements.customersClear?.addEventListener('click', clearCustomerFilters);
elements.customersPrev?.addEventListener('click', () => loadCustomers(Math.max(state.customers.page - 1, 1)));
elements.customersNext?.addEventListener('click', () => loadCustomers(state.customers.page + 1));
elements.customersSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadCustomers(1);
    }
});
elements.customerSave?.addEventListener('click', saveCustomer);
elements.customerDeactivate?.addEventListener('click', toggleCustomerActive);
elements.customerCancel?.addEventListener('click', clearCustomerForm);
elements.purchasesRefresh?.addEventListener('click', () => loadPurchases());
elements.purchaseNew?.addEventListener('click', () => {
    loadPurchaseOptions().then(clearPurchaseForm).catch((error) => setStatus(elements.purchasesStatus, normalizeError(error), 'error'));
});
elements.purchasesApply?.addEventListener('click', () => loadPurchases(1));
elements.purchasesClear?.addEventListener('click', clearPurchaseFilters);
elements.purchasesPrev?.addEventListener('click', () => loadPurchases(Math.max(state.purchases.page - 1, 1)));
elements.purchasesNext?.addEventListener('click', () => loadPurchases(state.purchases.page + 1));
elements.purchasesSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadPurchases(1);
    }
});
elements.purchaseCurrency?.addEventListener('change', renderPurchaseItems);
elements.purchaseAddItem?.addEventListener('click', addPurchaseItem);
elements.purchaseSave?.addEventListener('click', savePurchase);
elements.purchaseReceive?.addEventListener('click', receivePurchase);
elements.purchaseCancelOrder?.addEventListener('click', cancelPurchaseOrder);
elements.purchaseClear?.addEventListener('click', clearPurchaseForm);
elements.receivablesRefresh?.addEventListener('click', () => loadReceivables());
elements.receivablesApply?.addEventListener('click', () => loadReceivables(1));
elements.receivablesClear?.addEventListener('click', clearReceivableFilters);
elements.receivablesPrev?.addEventListener('click', () => loadReceivables(Math.max(state.receivables.page - 1, 1)));
elements.receivablesNext?.addEventListener('click', () => loadReceivables(state.receivables.page + 1));
elements.receivablesSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadReceivables(1);
    }
});
elements.receivableFillBalance?.addEventListener('click', fillReceivableBalance);
elements.receivableCollect?.addEventListener('click', registerReceivablePayment);
elements.receivablePaymentCurrency?.addEventListener('change', () => {
    if (elements.receivablePaymentAmount?.value) {
        fillReceivableBalance();
    }
});
elements.payablesRefresh?.addEventListener('click', () => loadPayables());
elements.payablesApply?.addEventListener('click', () => loadPayables(1));
elements.payablesClear?.addEventListener('click', clearPayableFilters);
elements.payablesPrev?.addEventListener('click', () => loadPayables(Math.max(state.payables.page - 1, 1)));
elements.payablesNext?.addEventListener('click', () => loadPayables(state.payables.page + 1));
elements.payablesSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        loadPayables(1);
    }
});
elements.payableFillBalance?.addEventListener('click', fillPayableBalance);
elements.payablePay?.addEventListener('click', registerPayablePayment);
elements.payablePaymentCurrency?.addEventListener('change', () => {
    if (elements.payablePaymentAmount?.value) {
        fillPayableBalance();
    }
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

