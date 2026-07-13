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
    transfers: {
        page: 1,
        loaded: false,
        summaryLoaded: false,
        summary: null,
        filters: {
            status: [],
            warehouse_id: '',
            date_from: '',
            date_to: '',
            search: '',
        },
        transfers: [],
        detail: {
            id: null,
            data: null,
            activeAction: null,
            loading: false,
            imei: {
                itemId: null,
                productId: null,
                warehouseId: null,
                action: null,
                maxCount: 0,
                serials: [],
                selected: new Set(),
                loading: false,
            },
        },
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
    salesSummaryTicket: document.querySelector('#admin-sales-summary-ticket'),
    salesByBranch: document.querySelector('#admin-sales-by-branch'),
    salesByCashRegister: document.querySelector('#admin-sales-by-cash-register'),
    salesByCashier: document.querySelector('#admin-sales-by-cashier'),
    salesByPayment: document.querySelector('#admin-sales-by-payment'),
    salesTopProducts: document.querySelector('#admin-sales-top-products'),
    salesDetailTitle: document.querySelector('#admin-sales-detail-title'),
    salesDetailSubtitle: document.querySelector('#admin-sales-detail-subtitle'),
    salesDetailStatus: document.querySelector('#admin-sales-detail-status'),
    salesDetailTotals: document.querySelector('#admin-sales-detail-totals'),
    salesDetailContext: document.querySelector('#admin-sales-detail-context'),
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
    transfersModule: document.querySelector('#admin-transfers-module'),
    transfersRefresh: document.querySelector('#admin-transfers-refresh'),
    transfersSearch: document.querySelector('#admin-transfers-search'),
    transfersWarehouse: document.querySelector('#admin-transfers-warehouse'),
    transfersDateFrom: document.querySelector('#admin-transfers-date-from'),
    transfersDateTo: document.querySelector('#admin-transfers-date-to'),
    transfersStatusOptions: document.querySelector('#admin-transfers-status-options'),
    transfersApply: document.querySelector('#admin-transfers-apply'),
    transfersClear: document.querySelector('#admin-transfers-clear'),
    transfersTable: document.querySelector('#admin-transfers-table'),
    transfersCount: document.querySelector('#admin-transfers-count'),
    transfersPrev: document.querySelector('#admin-transfers-prev'),
    transfersNext: document.querySelector('#admin-transfers-next'),
    transfersStatus: document.querySelector('#admin-transfers-status'),
    transfersChips: document.querySelectorAll('[data-admin-transfer-chip]'),
    transfersChipTotal: document.querySelector('#admin-transfers-chip-total'),
    transfersChipInFlight: document.querySelector('#admin-transfers-chip-in-flight'),
    transfersChipDifferences: document.querySelector('#admin-transfers-chip-differences'),
    transfersChipRequested: document.querySelector('#admin-transfers-chip-requested'),
    transfersChipDispatched: document.querySelector('#admin-transfers-chip-dispatched'),
    transfersChipCompletedDifferences: document.querySelector('#admin-transfers-chip-completed-differences'),
    transfersExport: document.querySelector('#admin-transfers-export'),
    transferDrawer: document.querySelector('#admin-transfer-drawer'),
    transferDrawerTitle: document.querySelector('#admin-transfer-drawer-title'),
    transferDrawerSubtitle: document.querySelector('#admin-transfer-drawer-subtitle'),
    transferDrawerStatusPill: document.querySelector('#admin-transfer-drawer-status-pill'),
    transferDrawerFrom: document.querySelector('#admin-transfer-drawer-from'),
    transferDrawerTo: document.querySelector('#admin-transfer-drawer-to'),
    transferDrawerReference: document.querySelector('#admin-transfer-drawer-reference'),
    transferDrawerReason: document.querySelector('#admin-transfer-drawer-reason'),
    transferDrawerRequestedAt: document.querySelector('#admin-transfer-drawer-requested-at'),
    transferDrawerPreparedAt: document.querySelector('#admin-transfer-drawer-prepared-at'),
    transferDrawerDispatchedAt: document.querySelector('#admin-transfer-drawer-dispatched-at'),
    transferDrawerReceivedAt: document.querySelector('#admin-transfer-drawer-received-at'),
    transferDrawerCancelledAt: document.querySelector('#admin-transfer-drawer-cancelled-at'),
    transferDrawerItems: document.querySelector('#admin-transfer-drawer-items'),
    transferDrawerAudit: document.querySelector('#admin-transfer-drawer-audit'),
    transferDrawerActions: document.querySelector('#admin-transfer-drawer-actions'),
    transferDrawerForm: document.querySelector('#admin-transfer-drawer-form'),
    transferDrawerFeedback: document.querySelector('#admin-transfer-drawer-feedback'),
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
    accessUserSubtabs: Array.from(document.querySelectorAll('[data-user-subtab]')),
    accessUserSubpanels: Array.from(document.querySelectorAll('[data-user-subpanel]')),
    accessOverridesAdd: document.querySelector('#admin-access-overrides-add'),
    accessOverridesAllowBtn: document.querySelector('#admin-access-overrides-allow-btn'),
    accessOverridesDenyBtn: document.querySelector('#admin-access-overrides-deny-btn'),
    accessOverridesExtras: document.querySelector('#admin-access-overrides-extras'),
    accessOverridesExtrasCount: document.querySelector('#admin-access-overrides-extras-count'),
    accessOverridesDenied: document.querySelector('#admin-access-overrides-denied'),
    accessOverridesDeniedCount: document.querySelector('#admin-access-overrides-denied-count'),
    accessCapabilitiesSummary: document.querySelector('#admin-access-capabilities-summary'),
    accessCapabilitiesJson: document.querySelector('#admin-access-capabilities-json'),
    accessScopeStatusBanner: document.querySelector('#admin-access-scope-status-banner'),
    accessScopeBranchesList: document.querySelector('#admin-access-scope-branches-list'),
    accessScopeBranchesCount: document.querySelector('#admin-access-scope-branches-count'),
    accessScopeWarehousesList: document.querySelector('#admin-access-scope-warehouses-list'),
    accessScopeWarehousesCount: document.querySelector('#admin-access-scope-warehouses-count'),
    accessScopeCustomerGroupsList: document.querySelector('#admin-access-scope-customer-groups-list'),
    accessScopeCustomerGroupsCount: document.querySelector('#admin-access-scope-customer-groups-count'),
    accessScopeVendorOfList: document.querySelector('#admin-access-scope-vendor-of-list'),
    accessScopeVendorOfCount: document.querySelector('#admin-access-scope-vendor-of-count'),
    accessSaveScopes: document.querySelector('#admin-access-save-scopes'),
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
    transfers: {
        title: 'Traslados entre almacenes',
        copy: 'Listado administrativo de traslados: busca por codigo, filtra por estado, almacen o periodo y detecta diferencias pendientes.',
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
    state.movements.lastData = null;

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
    state.reports.lastData = null;

    state.access.loaded = false;
    state.access.users = [];
    state.access.roles = [];
    state.access.permissions = [];
    state.access.selectedUser = null;
    state.access.selectedRole = null;
    state.access.permissionCatalog = null;
    state.access.permissionCatalogLoaded = false;
    state.access.userOverrides = null;
    state.access.userEffective = null;
    state.access.userScopes = null;
    state.access.scopeCatalog = null;
    state.access.activeUserSubtab = 'roles';

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
    const isTransfers = selectedSection === 'transfers';
    const isCustomers = selectedSection === 'customers';
    const isPurchases = selectedSection === 'purchases';
    const isReceivables = selectedSection === 'receivables';
    const isPayables = selectedSection === 'payables';
    const isAccess = selectedSection === 'users';

    state.activeSection = selectedSection;

    // v2: toggle v2-page sections so only the matching one is visible.
    // If the section has no v2 page yet (e.g. sales, transfers), fall back
    // to overview so the workspace doesn't render empty.
    const hasV2Page = !!document.querySelector(`.v2-page[data-portal-section="${selectedSection}"]`);
    const v2SectionToShow = hasV2Page ? selectedSection : 'overview';
    document.querySelectorAll('.v2-page[data-portal-section]').forEach((page) => {
        page.hidden = page.dataset.portalSection !== v2SectionToShow;
    });

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

    if (elements.transfersModule) {
        elements.transfersModule.hidden = !isTransfers;
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

    elements.modulePlaceholder.hidden = isOverview || isSales || isReports || isInventory || isRates || isMovements || isSuppliers || isTransfers || isCustomers || isPurchases || isReceivables || isPayables || isAccess;

    if (!isOverview && !isSales && !isReports && !isInventory && !isRates && !isMovements && !isSuppliers && !isTransfers && !isCustomers && !isPurchases && !isReceivables && !isPayables && !isAccess) {
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

    if (isTransfers) {
        if (!state.transfers.summaryLoaded) {
            loadTransferSummary();
        }
        if (!state.transfers.loaded) {
            loadTransfers();
        }
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
    elements.salesSummaryTicket.textContent = money(summary.average_ticket_base_amount);
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
    renderAdminSalesRanking(elements.salesByCashRegister, analytics.by_cash_register || [], {
        empty: 'Sin ventas por caja.',
        title: (item) => item.name,
        meta: (item) => `${item.branch_name || 'Sin sucursal'} - ${number(item.orders_count)} ordenes`,
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
        if (elements.salesDetailContext) {
            elements.salesDetailContext.innerHTML = `
                <span><strong>Cliente</strong> Sin orden seleccionada</span>
                <span><strong>Caja</strong> Sin orden seleccionada</span>
                <span><strong>Cajero</strong> Sin orden seleccionada</span>
            `;
        }
        elements.salesDetailItems.innerHTML = '<p>Sin orden seleccionada.</p>';
        elements.salesDetailPayments.innerHTML = '<p>Sin pagos cargados.</p>';
        return;
    }

    elements.salesDetailTitle.textContent = `Orden POS #${order.id}`;
    elements.salesDetailSubtitle.textContent = `${order.branch_name || 'Sin sucursal'} - ${formatDateTime(order.paid_at || order.closed_at || order.opened_at)}`;
    elements.salesDetailStatus.textContent = order.status_label;
    elements.salesDetailStatus.dataset.tone = posOrderStatusTone(order.status);
    elements.salesDetailTotals.innerHTML = `
        <div><span>Total</span><strong>${money(order.total_base_amount)}</strong></div>
        <div><span>Pagado</span><strong>${money(order.paid_base_amount)}</strong></div>
        <div><span>Saldo</span><strong>${money(order.balance_base_amount)}</strong></div>
    `;
    if (elements.salesDetailContext) {
        elements.salesDetailContext.innerHTML = `
            <span><strong>Cliente</strong> ${escapeHtml(order.customer_name || 'Consumidor final')}${order.customer_document ? ` - ${escapeHtml(order.customer_document)}` : ''}</span>
            <span><strong>Caja</strong> ${escapeHtml(order.cash_register_name || 'Sin caja')}</span>
            <span><strong>Cajero</strong> ${escapeHtml(order.cashier_name || 'Sin cajero')}</span>
        `;
    }
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
            const discount = Number(item.discount_base_amount || 0);
            row.className = 'sales-admin__detail-row sales-admin__detail-row--item';
            row.innerHTML = `
                <div>
                    <strong>${escapeHtml(item.product_name)}</strong>
                    <small>${escapeHtml(item.product_sku || 'Sin SKU')} - ${escapeHtml(item.warehouse_name || 'Sin almacen')}</small>
                    ${item.product_unit_ids?.length ? `<small>Seriales/IMEI: ${escapeHtml(item.product_unit_ids.join(', '))}</small>` : ''}
                    ${item.warranty_policy_name ? `<small>Garantia: ${escapeHtml(item.warranty_policy_name)}</small>` : ''}
                    ${discount > 0 ? `<em>Descuento: ${money(discount)}${item.discount_reason ? ` - ${escapeHtml(item.discount_reason)}` : ''}</em>` : ''}
                </div>
                <div class="sales-admin__detail-value">
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
            row.className = 'sales-admin__detail-row sales-admin__detail-row--payment';
            row.innerHTML = `
                <div>
                    <strong>${escapeHtml(payment.payment_method_name)}</strong>
                    <small>${escapeHtml(posPaymentStatusLabel(payment.status))}${payment.reference ? ` - Ref. ${escapeHtml(payment.reference)}` : ' - Sin referencia'}</small>
                    ${payment.exchange_rate ? `<small>Tasa ${escapeHtml(payment.exchange_rate_type_code || 'N/D')} ${number(payment.exchange_rate)}</small>` : '<small>Sin tasa registrada</small>'}
                </div>
                <div class="sales-admin__detail-value">
                    <strong>${escapeHtml(payment.currency)} ${number(payment.amount)}</strong>
                    <small>Base ${money(payment.amount_base)}</small>
                    <small>Equiv. Bs ${number(payment.amount_local)}</small>
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

function posPaymentStatusLabel(status) {
    return {
        captured: 'Capturado',
        pending: 'Pendiente',
        failed: 'Fallido',
        voided: 'Anulado',
    }[status] || status || 'Sin estado';
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
        // v2: also render the redesigned dashboard (no extra API call)
        v2PopulateReportsFiltersFromV1();
        v2RenderReports(report);
        state.reports.lastData = report;
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
        try { v2RenderInventory(summary); } catch (e) { console.warn('v2RenderInventory failed', e); }
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
        state.movements.lastData = pageData;
        renderMovements(pageData);
        // v2: also render the redesigned catalog + sheet (no extra API call)
        v2PopulateMovementWarehousesIntoV2();
        v2RenderMovements(pageData);
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
        <td><strong>${stockNumber(movement.quantity)}</strong><small>Costo ${formatCost(movement.unit_cost)}</small></td>
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

async function loadTransferSummary() {
    const session = state.session;
    if (!session) {
        return;
    }

    try {
        const data = await api('/api/admin-portal/transfers/summary', { headers: authHeaders(session) });
        state.transfers.summary = data;
        state.transfers.summaryLoaded = true;
        renderTransferChips(data);
        populateTransferWarehouseOptions(data.warehouses || []);
    } catch (error) {
        setStatus(elements.transfersStatus, normalizeError(error), 'error');
    }
}

function populateTransferWarehouseOptions(warehouses) {
    if (!elements.transfersWarehouse) {
        return;
    }

    const select = elements.transfersWarehouse;
    const current = state.transfers.filters.warehouse_id || '';
    select.innerHTML = '<option value="">Todos los almacenes</option>';

    warehouses.forEach((warehouse) => {
        const option = document.createElement('option');
        option.value = String(warehouse.id);
        option.textContent = warehouse.name;
        select.appendChild(option);
    });

    if (current && warehouses.some((w) => String(w.id) === current)) {
        select.value = current;
    } else {
        state.transfers.filters.warehouse_id = '';
        select.value = '';
    }
}

function renderTransferChips(summary) {
    if (!summary) {
        return;
    }

    if (elements.transfersChipTotal) {
        elements.transfersChipTotal.textContent = String(summary.total ?? 0);
    }
    if (elements.transfersChipInFlight) {
        elements.transfersChipInFlight.textContent = String(summary.in_flight ?? 0);
    }
    if (elements.transfersChipDifferences) {
        elements.transfersChipDifferences.textContent = String(summary.with_differences ?? 0);
    }
    if (elements.transfersChipRequested) {
        elements.transfersChipRequested.textContent = String(summary.by_status?.requested ?? 0);
    }
    if (elements.transfersChipDispatched) {
        elements.transfersChipDispatched.textContent = String(summary.by_status?.dispatched ?? 0);
    }
    if (elements.transfersChipCompletedDifferences) {
        elements.transfersChipCompletedDifferences.textContent = String(summary.by_status?.completed_with_differences ?? 0);
    }
}

async function loadTransfers(page = state.transfers.page) {
    const session = state.session;
    if (!session) {
        return;
    }

    if (!can('inventory_transfers.admin')) {
        setStatus(elements.transfersStatus, 'Tu usuario no tiene permiso para ver traslados.', 'error');
        return;
    }

    state.transfers.page = page;
    setStatus(elements.transfersStatus, 'Cargando traslados...');
    setButtonLoading(elements.transfersRefresh, true, 'Actualizando...');
    setButtonLoading(elements.transfersApply, true, 'Aplicando...');

    try {
        const query = new URLSearchParams({ page: String(page), limit: '25' });
        const filters = state.transfers.filters;

        if (filters.status && filters.status.length) {
            filters.status.forEach((status) => query.append('status[]', status));
        }
        if (filters.warehouse_id) {
            query.set('warehouse_id', filters.warehouse_id);
        }
        if (filters.date_from) {
            query.set('date_from', filters.date_from);
        }
        if (filters.date_to) {
            query.set('date_to', filters.date_to);
        }
        if (filters.search) {
            query.set('search', filters.search);
        }

        const data = await api(`/api/admin-portal/transfers?${query.toString()}`, { headers: authHeaders(session) });
        state.transfers.loaded = true;
        renderTransfersTable(data);
        setStatus(elements.transfersStatus, `Traslados actualizados. ${data.pagination?.total ?? 0} registro(s).`, 'success');
    } catch (error) {
        setStatus(elements.transfersStatus, normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.transfersRefresh, false);
        setButtonLoading(elements.transfersApply, false);
    }
}

function renderTransfersTable(pageData) {
    const transfers = pageData.data || [];
    const meta = pageData.pagination || {};

    if (!transfers.length) {
        elements.transfersTable.innerHTML = '<tr><td colspan="7"><strong>Sin traslados</strong><small>No hay traslados que coincidan con los filtros seleccionados.</small></td></tr>';
    } else {
        elements.transfersTable.replaceChildren(...transfers.map(transferRow));
    }

    elements.transfersCount.textContent = (meta.total || 0) === 0
        ? 'Sin traslados para mostrar.'
        : `${meta.from || 1}-${meta.to || transfers.length} de ${meta.total} traslado(s).`;
    elements.transfersPrev.disabled = !meta.page || meta.page <= 1;
    elements.transfersNext.disabled = !meta.page || meta.page * meta.limit >= (meta.total || 0);
    state.transfers.page = meta.page || 1;
}

function transferRow(transfer) {
    const row = document.createElement('tr');
    row.dataset.transferId = String(transfer.id);

    const code = document.createElement('td');
    code.innerHTML = `<strong>${escapeHtml(transfer.document_number || '-')}</strong><small>${escapeHtml(transfer.guide_number || '')}</small>`;

    const warehouses = document.createElement('td');
    warehouses.innerHTML = `<strong>${escapeHtml(transfer.from_warehouse_name || 'Origen')}</strong><small>a ${escapeHtml(transfer.to_warehouse_name || 'Destino')}</small>`;

    const status = document.createElement('td');
    status.appendChild(transferStatusPill(transfer));

    const items = document.createElement('td');
    items.textContent = String(transfer.items_count ?? 0);

    const differences = document.createElement('td');
    const diffCount = transfer.differences_count ?? 0;
    if (diffCount > 0) {
        differences.innerHTML = `<span class="status-pill" data-tone="warning">${diffCount}</span>`;
    } else {
        differences.textContent = '0';
    }

    const processed = document.createElement('td');
    processed.textContent = formatDateTime(transfer.processed_at);

    const action = document.createElement('td');
    const button = document.createElement('button');
    button.className = 'ghost-button ghost-button--compact';
    button.type = 'button';
    button.textContent = 'Ver';
    button.dataset.adminTransferView = String(transfer.id);
    button.addEventListener('click', () => openTransferDrawer(transfer.id));
    action.appendChild(button);

    row.appendChild(code);
    row.appendChild(warehouses);
    row.appendChild(status);
    row.appendChild(items);
    row.appendChild(differences);
    row.appendChild(processed);
    row.appendChild(action);

    return row;
}

function transferStatusPill(transfer) {
    const pill = document.createElement('span');
    pill.className = 'status-pill';
    const tone = transferStatusTone(transfer.status);
    pill.dataset.tone = tone;
    pill.textContent = transfer.status_label || transfer.status;
    return pill;
}

function transferStatusTone(status) {
    return {
        requested: 'neutral',
        in_preparation: 'neutral',
        prepared: 'info',
        prepared_with_differences: 'warning',
        dispatched: 'info',
        in_reception: 'info',
        completed: 'success',
        completed_with_differences: 'warning',
        rejected: 'danger',
        cancelled: 'danger',
    }[status] || 'neutral';
}

function readTransferStatusFilters() {
    const checked = elements.transfersStatusOptions
        ? Array.from(elements.transfersStatusOptions.querySelectorAll('input[type="checkbox"]:checked')).map((el) => el.value)
        : [];
    return checked;
}

function applyTransferFilters() {
    state.transfers.filters = {
        status: readTransferStatusFilters(),
        warehouse_id: elements.transfersWarehouse?.value || '',
        date_from: elements.transfersDateFrom?.value || '',
        date_to: elements.transfersDateTo?.value || '',
        search: elements.transfersSearch?.value.trim() || '',
    };
    loadTransfers(1);
}

function clearTransferFilters() {
    if (elements.transfersSearch) elements.transfersSearch.value = '';
    if (elements.transfersWarehouse) elements.transfersWarehouse.value = '';
    if (elements.transfersDateFrom) elements.transfersDateFrom.value = '';
    if (elements.transfersDateTo) elements.transfersDateTo.value = '';
    if (elements.transfersStatusOptions) {
        elements.transfersStatusOptions.querySelectorAll('input[type="checkbox"]').forEach((el) => { el.checked = false; });
    }
    state.transfers.filters = { status: [], warehouse_id: '', date_from: '', date_to: '', search: '' };
    loadTransfers(1);
}

async function exportTransfersCsv() {
    const session = state.session;
    if (!session) {
        return;
    }
    if (!can('inventory_transfers.admin')) {
        setStatus(elements.transfersStatus, 'No tienes permiso para exportar traslados.', 'error');
        return;
    }

    const filters = state.transfers.filters;
    const query = new URLSearchParams();
    if (Array.isArray(filters.status) && filters.status.length > 0) {
        filters.status.forEach((status) => query.append('status[]', status));
    }
    if (filters.warehouse_id) {
        query.set('warehouse_id', String(filters.warehouse_id));
    }
    if (filters.date_from) {
        query.set('date_from', filters.date_from);
    }
    if (filters.date_to) {
        query.set('date_to', filters.date_to);
    }
    if (filters.search) {
        query.set('search', filters.search);
    }
    query.set('export', 'csv');

    const url = `/api/admin-portal/transfers?${query.toString()}`;
    setStatus(elements.transfersStatus, 'Generando archivo CSV...', 'info');

    try {
        const response = await fetch(url, { headers: authHeaders(session) });
        if (!response.ok) {
            setStatus(elements.transfersStatus, `Error al exportar: HTTP ${response.status}.`, 'error');
            return;
        }
        const blob = await response.blob();
        const disposition = response.headers.get('content-disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/i);
        const filename = match ? match[1] : `traslados-${new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19)}.csv`;

        const downloadUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(downloadUrl);

        setStatus(elements.transfersStatus, `Archivo ${filename} descargado.`, 'success');
    } catch (error) {
        setStatus(elements.transfersStatus, normalizeError(error), 'error');
    }
}

async function openTransferDrawer(transferId) {
    if (!elements.transferDrawer) {
        return;
    }
    const session = state.session;
    if (!session) {
        return;
    }
    if (!can('inventory_transfers.admin')) {
        setStatus(elements.transfersStatus, 'No tienes permiso para ver el detalle del traslado.', 'error');
        return;
    }

    state.transfers.detail.id = transferId;
    state.transfers.detail.data = null;
    state.transfers.detail.activeAction = null;

    elements.transferDrawer.hidden = false;
    document.body.style.overflow = 'hidden';

    if (elements.transferDrawerTitle) {
        elements.transferDrawerTitle.textContent = 'Cargando traslado...';
    }
    if (elements.transferDrawerSubtitle) {
        elements.transferDrawerSubtitle.textContent = 'Obteniendo detalle.';
    }
    if (elements.transferDrawerItems) {
        elements.transferDrawerItems.replaceChildren();
    }
    if (elements.transferDrawerActions) {
        elements.transferDrawerActions.replaceChildren();
    }
    if (elements.transferDrawerForm) {
        elements.transferDrawerForm.hidden = true;
        elements.transferDrawerForm.replaceChildren();
    }
    setStatus(elements.transferDrawerFeedback, '', 'neutral');

    await loadTransferDetail();
}

function closeTransferDrawer() {
    if (!elements.transferDrawer) {
        return;
    }
    elements.transferDrawer.hidden = true;
    document.body.style.overflow = '';
    state.transfers.detail.id = null;
    state.transfers.detail.data = null;
    state.transfers.detail.activeAction = null;
}

async function loadTransferDetail() {
    const session = state.session;
    const transferId = state.transfers.detail.id;
    if (!session || !transferId) {
        return;
    }
    if (state.transfers.detail.loading) {
        return;
    }

    state.transfers.detail.loading = true;
    try {
        const data = await api(`/api/admin-portal/transfers/${transferId}`, { headers: authHeaders(session) });
        state.transfers.detail.data = data;
        renderTransferDetail(data);
    } catch (error) {
        setStatus(elements.transferDrawerFeedback, normalizeError(error), 'error');
    } finally {
        state.transfers.detail.loading = false;
    }
}

function renderTransferDetail(payload) {
    const transfer = payload.transfer || {};
    const items = payload.items || [];
    const available = payload.available_actions || [];

    if (elements.transferDrawerTitle) {
        elements.transferDrawerTitle.textContent = transfer.document_number || `Traslado #${transfer.id || ''}`;
    }
    if (elements.transferDrawerSubtitle) {
        elements.transferDrawerSubtitle.textContent = `${transfer.guide_number || ''} · Ref: ${transfer.reference || '—'}`;
    }

    if (elements.transferDrawerStatusPill) {
        const pill = document.createElement('span');
        pill.className = 'status-pill';
        pill.dataset.tone = transferStatusTone(transfer.status);
        pill.textContent = transfer.status_label || transfer.status || '—';
        elements.transferDrawerStatusPill.replaceChildren(pill);
    }

    if (elements.transferDrawerFrom) {
        elements.transferDrawerFrom.textContent = transfer.from_warehouse_name || `Almacén #${transfer.from_warehouse_id ?? '—'}`;
    }
    if (elements.transferDrawerTo) {
        elements.transferDrawerTo.textContent = transfer.to_warehouse_name || `Almacén #${transfer.to_warehouse_id ?? '—'}`;
    }
    if (elements.transferDrawerReference) {
        elements.transferDrawerReference.textContent = transfer.reference || '—';
    }
    if (elements.transferDrawerReason) {
        elements.transferDrawerReason.textContent = transfer.reason || '—';
    }
    if (elements.transferDrawerRequestedAt) {
        elements.transferDrawerRequestedAt.textContent = formatDateTime(transfer.requested_at || transfer.processed_at);
    }
    if (elements.transferDrawerPreparedAt) {
        elements.transferDrawerPreparedAt.textContent = formatDateTime(transfer.prepared_at);
    }
    if (elements.transferDrawerDispatchedAt) {
        elements.transferDrawerDispatchedAt.textContent = formatDateTime(transfer.dispatched_at);
    }
    if (elements.transferDrawerReceivedAt) {
        elements.transferDrawerReceivedAt.textContent = formatDateTime(transfer.received_at);
    }
    if (elements.transferDrawerCancelledAt) {
        elements.transferDrawerCancelledAt.textContent = formatDateTime(transfer.cancelled_at);
    }

    if (elements.transferDrawerItems) {
        elements.transferDrawerItems.replaceChildren(...items.map(drawerItemCard));
    }

    if (elements.transferDrawerAudit) {
        elements.transferDrawerAudit.replaceChildren(...(payload.audit || []).map(auditEventCard));
    }

    if (elements.transferDrawerActions) {
        elements.transferDrawerActions.replaceChildren(...buildActionButtons(available));
    }

    setStatus(elements.transferDrawerFeedback, '', 'neutral');
}

function drawerItemCard(item) {
    const card = document.createElement('div');
    card.className = 'transfers-drawer__item';
    card.dataset.itemId = String(item.id);

    const head = document.createElement('div');
    head.className = 'transfers-drawer__item-head';
    const name = document.createElement('div');
    name.innerHTML = `<div class="transfers-drawer__item-name">${escapeHtml(item.product_name || `Producto #${item.product_id ?? '—'}`)}</div><div class="transfers-drawer__item-sku">${escapeHtml(item.product_sku || '')}</div>`;
    head.appendChild(name);
    card.appendChild(head);

    const stats = document.createElement('div');
    stats.className = 'transfers-drawer__item-stats';
    const requested = item.requested_quantity ?? item.quantity ?? 0;
    const prepared = item.prepared_quantity ?? '—';
    const received = item.received_quantity ?? '—';
    const difference = item.difference_quantity ?? 0;
    stats.innerHTML = `
        <div>Solicitado <strong>${number(requested)}</strong></div>
        <div>Preparado <strong>${prepared === '—' ? '—' : number(prepared)}</strong></div>
        <div>Recibido <strong>${received === '—' ? '—' : number(received)}</strong></div>
        <div>Diferencia <strong>${number(difference)}</strong></div>
    `;
    card.appendChild(stats);

    if (Number(difference) !== 0 || item.difference_reason || item.difference_notes || item.resolution_status) {
        const diff = document.createElement('div');
        diff.className = 'transfers-drawer__item-diff';
        const lines = [];
        if (item.difference_reason) {
            lines.push(`<strong>Motivo:</strong> ${escapeHtml(item.difference_reason)}`);
        }
        if (item.difference_notes) {
            lines.push(`<strong>Notas:</strong> ${escapeHtml(item.difference_notes)}`);
        }
        if (item.resolution_status && item.resolution_status !== 'unresolved') {
            lines.push(`<strong>Resolución:</strong> ${escapeHtml(item.resolution_status)}`);
        }
        diff.innerHTML = lines.join('<br>');
        card.appendChild(diff);
    }

    return card;
}

function auditEventCard(event) {
    const row = document.createElement('div');
    row.className = 'transfers-drawer__audit-event';
    row.dataset.auditAction = event.action || '';

    const dot = document.createElement('span');
    dot.className = `transfers-drawer__audit-dot transfers-drawer__audit-dot--${(event.action || '').replace(/\./g, '-')}`;

    const body = document.createElement('div');
    body.className = 'transfers-drawer__audit-body';

    const head = document.createElement('div');
    head.className = 'transfers-drawer__audit-head';
    const label = document.createElement('strong');
    label.textContent = transferAuditActionLabel(event.action);
    const when = document.createElement('span');
    when.className = 'transfers-drawer__audit-when';
    when.textContent = formatDateTime(event.created_at);
    head.appendChild(label);
    head.appendChild(when);

    const meta = document.createElement('div');
    meta.className = 'transfers-drawer__audit-meta';
    const who = event.user ? `${escapeHtml(event.user.name)} (#${event.user.id})` : 'Sistema';
    meta.textContent = `${who} · ${when.textContent}`;

    body.appendChild(head);
    body.appendChild(meta);

    if (event.new_values && Object.keys(event.new_values).length > 0) {
        const values = document.createElement('div');
        values.className = 'transfers-drawer__audit-values';
        const entries = Object.entries(event.new_values).slice(0, 4);
        entries.forEach(([key, value]) => {
            const tag = document.createElement('span');
            tag.className = 'transfers-drawer__audit-tag';
            tag.textContent = `${key}: ${String(value)}`.slice(0, 60);
            values.appendChild(tag);
        });
        body.appendChild(values);
    }

    row.appendChild(dot);
    row.appendChild(body);
    return row;
}

function transferAuditActionLabel(action) {
    return {
        'inventory_transfer.created': 'Traslado creado',
        'inventory_transfer.prepared': 'Preparacion confirmada',
        'inventory_transfer.dispatched': 'Despacho confirmado',
        'inventory_transfer.received': 'Recepcion confirmada',
        'inventory_transfer.cancelled': 'Traslado cancelado',
        'inventory_transfer.differences_resolved': 'Diferencias resueltas',
    }[action] || action;
}

function buildActionButtons(available) {
    if (!available || available.length === 0) {
        const note = document.createElement('span');
        note.style.color = 'var(--muted)';
        note.textContent = 'Este traslado no admite acciones adicionales.';
        return [note];
    }

    const labels = {
        prepare: 'Preparar',
        dispatch: 'Despachar',
        receive: 'Recibir',
        cancel: 'Cancelar',
        resolve_differences: 'Resolver diferencias',
    };

    return available.map((action) => {
        const button = document.createElement('button');
        const destructive = action === 'cancel';
        button.className = destructive ? 'danger-button' : 'primary-button';
        button.type = 'button';
        button.textContent = labels[action] || action;
        button.dataset.transferAction = action;
        button.addEventListener('click', () => showTransferActionForm(action));
        return button;
    });
}

function showTransferActionForm(action) {
    const data = state.transfers.detail.data;
    if (!data) {
        return;
    }

    state.transfers.detail.activeAction = action;

    if (elements.transferDrawerForm) {
        elements.transferDrawerForm.hidden = false;
        elements.transferDrawerForm.replaceChildren(...buildActionForm(action, data));
    }

    setStatus(elements.transferDrawerFeedback, '', 'neutral');
}

function buildActionForm(action, data) {
    const transfer = data.transfer || {};
    const items = data.items || [];
    const nodes = [];

    const title = document.createElement('h5');
    title.className = 'transfers-drawer__form-title';
    title.textContent = actionTitle(action);
    nodes.push(title);

    const hint = document.createElement('p');
    hint.className = 'transfers-drawer__form-hint';
    hint.textContent = actionHint(action);
    nodes.push(hint);

    if (action === 'prepare' || action === 'receive') {
        items.forEach((item) => {
            nodes.push(buildQuantityItemBlock(action, item));
        });
        nodes.push(buildNotesField(`${action}_notes`, 'Notas (opcional)', ''));
    } else if (action === 'dispatch') {
        nodes.push(buildNotesField('notes', 'Notas (opcional)', ''));
    } else if (action === 'cancel') {
        nodes.push(buildNotesField('cancellation_reason', 'Motivo de cancelación', 'Detalla por qué se cancela (mínimo 5 caracteres).', { required: true, minLength: 5 }));
    } else if (action === 'resolve_differences') {
        const diffItems = items.filter((item) => Number(item.difference_quantity ?? 0) !== 0);
        if (diffItems.length === 0) {
            const note = document.createElement('p');
            note.className = 'transfers-drawer__form-hint';
            note.textContent = 'No hay items con diferencias para resolver.';
            nodes.push(note);
        } else {
            diffItems.forEach((item) => nodes.push(buildResolveItemBlock(item)));
        }
        nodes.push(buildNotesField('notes', 'Notas globales (opcional)', ''));
    }

    const actions = document.createElement('div');
    actions.className = 'transfers-drawer__form-actions';

    const cancel = document.createElement('button');
    cancel.className = 'ghost-button';
    cancel.type = 'button';
    cancel.textContent = 'Cancelar';
    cancel.addEventListener('click', () => {
        state.transfers.detail.activeAction = null;
        if (elements.transferDrawerForm) {
            elements.transferDrawerForm.hidden = true;
            elements.transferDrawerForm.replaceChildren();
        }
    });
    actions.appendChild(cancel);

    const submit = document.createElement('button');
    submit.className = 'primary-button';
    submit.type = 'button';
    submit.textContent = actionTitle(action);
    submit.dataset.transferActionSubmit = action;
    submit.addEventListener('click', () => submitTransferAction(action));
    actions.appendChild(submit);

    nodes.push(actions);
    return nodes;
}

function actionTitle(action) {
    return {
        prepare: 'Confirmar preparación',
        dispatch: 'Confirmar despacho',
        receive: 'Confirmar recepción',
        cancel: 'Cancelar traslado',
        resolve_differences: 'Resolver diferencias',
    }[action] || action;
}

function actionHint(action) {
    return {
        prepare: 'Indica cuánto preparas de cada producto. Si preparas menos, registra el motivo.',
        dispatch: 'Marca el traslado como despachado. Esta acción es irreversible.',
        receive: 'Indica cuánto recibes de cada producto. Si recibes menos, registra el motivo.',
        cancel: 'El traslado volverá a estado cancelado y se liberará el stock reservado.',
        resolve_differences: 'Asigna una acción de cierre para cada item con diferencia. Si el ajuste es manual, indica la cantidad.',
    }[action] || '';
}

function buildQuantityItemBlock(action, item) {
    const wrapper = document.createElement('div');
    wrapper.className = 'transfers-drawer__form-item';

    const name = document.createElement('div');
    name.className = 'transfers-drawer__form-item-name';
    const requested = item.requested_quantity ?? item.quantity ?? 0;
    const prepOrRecv = action === 'prepare' ? item.prepared_quantity : item.received_quantity;
    name.textContent = `${item.product_name || `Producto #${item.product_id ?? '—'}`} · Solicitado ${number(requested)}`;
    wrapper.appendChild(name);

    const grid = document.createElement('div');
    grid.className = 'transfers-drawer__form-item-grid';

    const qtyField = document.createElement('label');
    qtyField.className = 'field';
    qtyField.innerHTML = `<span>Cantidad ${action === 'prepare' ? 'preparada' : 'recibida'}</span>
        <input type="number" min="0" step="0.01" data-transfer-action-field="quantity" data-item-id="${item.id}"
            value="${prepOrRecv ?? requested}">`;
    grid.appendChild(qtyField);

    const reasonField = document.createElement('label');
    reasonField.className = 'field';
    reasonField.innerHTML = `<span>Motivo diferencia</span>
        <input type="text" maxlength="255" data-transfer-action-field="reason" data-item-id="${item.id}"
            value="${escapeHtml(item.difference_reason || '')}" placeholder="Solo si hay diferencia">`;
    grid.appendChild(reasonField);

    wrapper.appendChild(grid);

    const notesField = document.createElement('label');
    notesField.className = 'field';
    notesField.innerHTML = `<span>Notas del item</span>
        <input type="text" maxlength="1000" data-transfer-action-field="notes" data-item-id="${item.id}"
            value="${escapeHtml(item.difference_notes || '')}" placeholder="Opcional">`;
    wrapper.appendChild(notesField);

    if (item.serialized && item.product_id) {
        const serialsBlock = document.createElement('div');
        serialsBlock.className = 'transfers-drawer__item-serials';

        const summary = document.createElement('div');
        summary.className = 'transfers-drawer__item-serials-summary';

        const label = document.createElement('span');
        label.innerHTML = `IMEI / serial: <strong data-transfer-serial-summary data-item-id="${item.id}">0 seleccionados</strong>`;

        const button = document.createElement('button');
        button.className = 'ghost-button ghost-button--compact';
        button.type = 'button';
        button.textContent = 'Seleccionar seriales';
        button.addEventListener('click', () => {
            const transfer = state.transfers.detail.data?.transfer;
            const warehouseId = action === 'prepare'
                ? transfer?.from_warehouse_id
                : transfer?.to_warehouse_id;
            const preselected = action === 'prepare'
                ? (Array.isArray(item.prepared_product_unit_ids) ? item.prepared_product_unit_ids : [])
                : (Array.isArray(item.received_product_unit_ids) ? item.received_product_unit_ids : []);
            const maxCount = Number(qtyField.querySelector('input').value) || 0;
            openImeiPicker(item.id, item.product_id, warehouseId, action, preselected, maxCount);
        });

        summary.appendChild(label);
        summary.appendChild(button);
        serialsBlock.appendChild(summary);

        const preview = document.createElement('div');
        preview.className = 'transfers-drawer__item-serials-preview';
        preview.dataset.transferSerialPreview = String(item.id);
        const preselected = action === 'prepare'
            ? (Array.isArray(item.prepared_product_unit_ids) ? item.prepared_product_unit_ids : [])
            : (Array.isArray(item.received_product_unit_ids) ? item.received_product_unit_ids : []);
        if (preselected.length === 0) {
            preview.textContent = 'Todavia no se han elegido IMEIs.';
        } else {
            preview.textContent = `${preselected.length} IMEI(s) preseleccionado(s).`;
        }
        serialsBlock.appendChild(preview);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.dataset.transferActionField = action === 'prepare' ? 'prepared_product_unit_ids' : 'received_product_unit_ids';
        hidden.dataset.itemId = String(item.id);
        hidden.value = preselected.join(',');
        serialsBlock.appendChild(hidden);

        wrapper.appendChild(serialsBlock);
    }

    return wrapper;
}

function buildResolveItemBlock(item) {
    const wrapper = document.createElement('div');
    wrapper.className = 'transfers-drawer__form-item';

    const name = document.createElement('div');
    name.className = 'transfers-drawer__form-item-name';
    name.textContent = `${item.product_name || `Producto #${item.product_id ?? '—'}`} · Diferencia ${number(item.difference_quantity)}`;
    wrapper.appendChild(name);

    const grid = document.createElement('div');
    grid.className = 'transfers-drawer__form-item-grid';

    const actionField = document.createElement('label');
    actionField.className = 'field';
    actionField.innerHTML = `<span>Acción</span>
        <select data-transfer-action-field="action" data-item-id="${item.id}">
            <option value="investigating" ${item.resolution_status === 'investigating' ? 'selected' : ''}>Investigando</option>
            <option value="accepted_loss" ${item.resolution_status === 'accepted_loss' ? 'selected' : ''}>Aceptar pérdida</option>
            <option value="adjusted_manually" ${item.resolution_status === 'adjusted_manually' ? 'selected' : ''}>Ajuste manual</option>
        </select>`;
    grid.appendChild(actionField);

    const qtyField = document.createElement('label');
    qtyField.className = 'field';
    qtyField.innerHTML = `<span>Cantidad (ajuste manual)</span>
        <input type="number" min="0" step="0.01" data-transfer-action-field="quantity" data-item-id="${item.id}"
            value="${item.resolution_status === 'adjusted_manually' ? number(item.difference_quantity) : ''}">`;
    grid.appendChild(qtyField);

    wrapper.appendChild(grid);

    const notesField = document.createElement('label');
    notesField.className = 'field';
    notesField.innerHTML = `<span>Notas del item</span>
        <input type="text" maxlength="1000" data-transfer-action-field="notes" data-item-id="${item.id}"
            value="${escapeHtml(item.resolution_notes || '')}" placeholder="Opcional">`;
    wrapper.appendChild(notesField);

    return wrapper;
}

function buildNotesField(name, label, placeholder, opts = {}) {
    const wrapper = document.createElement('label');
    wrapper.className = 'field';
    const required = opts.required ? ' <span style="color:var(--red)">*</span>' : '';
    wrapper.innerHTML = `<span>${label}${required}</span>
        <textarea rows="2" maxlength="1000" data-transfer-action-field="${name}" placeholder="${escapeHtml(placeholder)}" ${opts.minLength ? `minlength="${opts.minLength}"` : ''} ${opts.required ? 'required' : ''}></textarea>`;
    return wrapper;
}

function collectActionPayload(action) {
    const data = state.transfers.detail.data;
    if (!data) {
        return null;
    }
    const items = data.items || [];

    if (action === 'prepare' || action === 'receive') {
        const qtyKey = action === 'prepare' ? 'prepared_quantity' : 'received_quantity';
        const serialsKey = action === 'prepare' ? 'prepared_product_unit_ids' : 'received_product_unit_ids';
        const arr = items.map((item) => {
            const qty = readFieldValue(action, 'quantity', item.id);
            const reason = readFieldValue(action, 'reason', item.id);
            const notes = readFieldValue(action, 'notes', item.id);
            const serialsCsv = readFieldValue(action, serialsKey, item.id);
            const serials = serialsCsv
                ? serialsCsv.split(',').map((id) => Number(id.trim())).filter((id) => Number.isFinite(id) && id > 0)
                : [];
            const entry = {
                inventory_transfer_item_id: item.id,
                [qtyKey]: qty === '' ? null : Number(qty),
                difference_reason: reason || null,
                difference_notes: notes || null,
            };
            if (serials.length > 0) {
                entry[serialsKey] = serials;
            }
            return entry;
        });
        const notes = readFieldValue(action, `${action}_notes`);
        const payload = { items: arr };
        if (notes) payload.notes = notes;
        return payload;
    }

    if (action === 'dispatch') {
        const notes = readFieldValue(action, 'notes');
        return notes ? { notes } : {};
    }

    if (action === 'cancel') {
        const reason = readFieldValue(action, 'cancellation_reason') || '';
        return { cancellation_reason: reason };
    }

    if (action === 'resolve_differences') {
        const diffItems = items.filter((item) => Number(item.difference_quantity ?? 0) !== 0);
        const arr = diffItems.map((item) => {
            const act = readFieldValue(action, 'action', item.id);
            const qty = readFieldValue(action, 'quantity', item.id);
            const notes = readFieldValue(action, 'notes', item.id);
            const entry = {
                inventory_transfer_item_id: item.id,
                action: act,
            };
            if (act === 'adjusted_manually') {
                entry.quantity = qty === '' ? null : Number(qty);
            }
            if (notes) {
                entry.notes = notes;
            }
            return entry;
        });
        const notes = readFieldValue(action, 'notes');
        const payload = { items: arr };
        if (notes) payload.notes = notes;
        return payload;
    }

    return null;
}

function readFieldValue(action, field, itemId = null) {
    if (!elements.transferDrawerForm) {
        return '';
    }
    const selector = itemId !== null
        ? `[data-transfer-action-field="${field}"][data-item-id="${itemId}"]`
        : `[data-transfer-action-field="${field}"]`;
    const el = elements.transferDrawerForm.querySelector(selector);
    return el ? el.value.trim() : '';
}

async function submitTransferAction(action) {
    const session = state.session;
    const transferId = state.transfers.detail.id;
    if (!session || !transferId) {
        return;
    }

    const payload = collectActionPayload(action);
    if (!payload) {
        return;
    }

    if (action === 'cancel' && (!payload.cancellation_reason || payload.cancellation_reason.length < 5)) {
        setStatus(elements.transferDrawerFeedback, 'Indica un motivo de cancelación de al menos 5 caracteres.', 'error');
        return;
    }

    if (action === 'resolve_differences') {
        if (!payload.items.length) {
            setStatus(elements.transferDrawerFeedback, 'No hay items con diferencias para resolver.', 'error');
            return;
        }
        for (const item of payload.items) {
            if (item.action === 'adjusted_manually' && (!item.quantity || item.quantity <= 0)) {
                setStatus(elements.transferDrawerFeedback, 'Indica la cantidad para los items con ajuste manual.', 'error');
                return;
            }
        }
    }

    if (action === 'prepare' || action === 'receive') {
        for (const item of payload.items) {
            if (item.prepared_quantity !== undefined && item.prepared_quantity !== null && Number(item.difference_quantity ?? 0) > 0 && !item.difference_reason) {
                setStatus(elements.transferDrawerFeedback, 'Indica el motivo cuando se prepara/recibe menos de lo solicitado.', 'error');
                return;
            }
        }
    }

    const submitButton = elements.transferDrawerForm?.querySelector(`[data-transfer-action-submit="${action}"]`);
    if (submitButton) {
        setButtonLoading(submitButton, true, actionTitle(action));
    }
    setStatus(elements.transferDrawerFeedback, 'Procesando...', 'info');

    try {
        await api(`/api/admin-portal/transfers/${transferId}/${actionEndpoint(action)}`, {
            method: 'POST',
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }, true);

        setStatus(elements.transferDrawerFeedback, 'Acción aplicada.', 'success');
        state.transfers.detail.activeAction = null;
        if (elements.transferDrawerForm) {
            elements.transferDrawerForm.hidden = true;
            elements.transferDrawerForm.replaceChildren();
        }
        await loadTransferDetail();
        await loadTransfers(state.transfers.page);
        if (!state.transfers.summaryLoaded) {
            await loadTransferSummary();
        } else {
            await loadTransferSummary();
        }
    } catch (error) {
        setStatus(elements.transferDrawerFeedback, normalizeError(error), 'error');
    } finally {
        if (submitButton) {
            setButtonLoading(submitButton, false, actionTitle(action));
        }
    }
}

function actionEndpoint(action) {
    return action === 'resolve_differences' ? 'resolve-differences' : action;
}

function openImeiPicker(itemId, productId, warehouseId, action, preselectedIds, maxCount) {
    if (!elements.imeiPicker || !productId) {
        return;
    }
    const item = state.transfers.detail.data?.items?.find((entry) => String(entry.id) === String(itemId));
    if (!item) {
        return;
    }

    const imei = state.transfers.detail.imei;
    imei.itemId = itemId;
    imei.productId = productId;
    imei.warehouseId = warehouseId || null;
    imei.action = action;
    imei.maxCount = Number(maxCount) || 0;
    imei.serials = [];
    imei.selected = new Set(Array.isArray(preselectedIds) ? preselectedIds.map((id) => Number(id)) : []);
    imei.loading = true;

    if (elements.imeiPickerSearch) {
        elements.imeiPickerSearch.value = '';
    }
    if (elements.imeiPickerTitle) {
        elements.imeiPickerTitle.textContent = `IMEI / serial - ${item.product_name || `Producto #${productId}`}`;
    }
    if (elements.imeiPickerSubtitle) {
        const wh = warehouseId ? `Almacen #${warehouseId}` : 'Todos los almacenes';
        elements.imeiPickerSubtitle.textContent = `${imei.maxCount} como maximo. Disponibles en ${wh}.`;
    }
    if (elements.imeiPickerStatus) {
        elements.imeiPickerStatus.textContent = 'Cargando seriales disponibles...';
    }
    if (elements.imeiPickerList) {
        elements.imeiPickerList.replaceChildren();
    }
    updateImeiPickerCounter();

    elements.imeiPicker.hidden = false;
    document.body.style.overflow = 'hidden';

    void loadImeiPickerSerials();
}

function closeImeiPicker() {
    if (!elements.imeiPicker) {
        return;
    }
    elements.imeiPicker.hidden = true;
    document.body.style.overflow = '';
    const imei = state.transfers.detail.imei;
    imei.itemId = null;
    imei.productId = null;
    imei.action = null;
    imei.serials = [];
    imei.selected = new Set();
    imei.loading = false;
}

async function loadImeiPickerSerials(search = '') {
    const session = state.session;
    const imei = state.transfers.detail.imei;
    if (!session || !imei.productId) {
        return;
    }
    if (imei.loading && !search) {
        return;
    }

    imei.loading = true;
    if (elements.imeiPickerStatus) {
        elements.imeiPickerStatus.textContent = search ? 'Buscando...' : 'Cargando seriales disponibles...';
    }

    const query = new URLSearchParams();
    query.set('status', 'available');
    query.set('limit', '100');
    if (imei.warehouseId) {
        query.set('warehouse_id', String(imei.warehouseId));
    }
    if (search) {
        query.set('search', search);
    }

    try {
        const payload = await api(`/api/inventory-center/products/${imei.productId}/serials?${query.toString()}`, {
            headers: authHeaders(session),
        }, true);
        const list = Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload?.data?.data) ? payload.data.data : []);
        imei.serials = list;
        renderImeiPickerList();
        const filtered = applyImeiSearchFilter(list, search);
        if (elements.imeiPickerStatus) {
            elements.imeiPickerStatus.textContent = filtered.length === 0
                ? 'Sin seriales disponibles con ese filtro.'
                : `${filtered.length} serial(es) disponible(s). Tildar los que se preparan/reciben.`;
        }
    } catch (error) {
        imei.serials = [];
        renderImeiPickerList();
        setStatus(elements.imeiPickerStatus, normalizeError(error), 'error');
    } finally {
        imei.loading = false;
    }
}

function applyImeiSearchFilter(list, search) {
    if (!search) {
        return list;
    }
    const needle = search.toLowerCase();
    return list.filter((entry) => {
        const serial = String(entry.serial || entry.code || '').toLowerCase();
        return serial.includes(needle);
    });
}

function renderImeiPickerList() {
    if (!elements.imeiPickerList) {
        return;
    }
    const imei = state.transfers.detail.imei;
    const search = elements.imeiPickerSearch?.value?.trim() || '';
    const list = applyImeiSearchFilter(imei.serials, search);

    if (list.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'transfers-imei-picker__item-empty';
        empty.textContent = imei.serials.length === 0
            ? 'No hay seriales disponibles para este producto en este almacen.'
            : 'Ningun serial coincide con la busqueda.';
        elements.imeiPickerList.replaceChildren(empty);
        return;
    }

    elements.imeiPickerList.replaceChildren(...list.map((serial) => {
        const row = document.createElement('label');
        row.className = 'transfers-imei-picker__item';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        const idNum = Number(serial.id);
        checkbox.checked = imei.selected.has(idNum);
        checkbox.addEventListener('change', () => onImeiSerialToggle(idNum, checkbox.checked));
        if (imei.maxCount > 0 && imei.selected.size >= imei.maxCount && !checkbox.checked) {
            checkbox.disabled = true;
        }

        const serialLabel = document.createElement('span');
        serialLabel.className = 'transfers-imei-picker__item-serial';
        serialLabel.textContent = String(serial.serial || serial.code || `ID ${serial.id}`);

        const meta = document.createElement('span');
        meta.className = 'transfers-imei-picker__item-meta';
        const wh = serial.warehouse_name || (serial.warehouse_id ? `Almacen #${serial.warehouse_id}` : '');
        const status = serial.status || '';
        meta.textContent = [wh, status].filter(Boolean).join(' · ');

        row.appendChild(checkbox);
        row.appendChild(serialLabel);
        row.appendChild(meta);
        return row;
    }));
}

function onImeiSerialToggle(serialId, checked) {
    const imei = state.transfers.detail.imei;
    if (checked) {
        if (imei.maxCount > 0 && imei.selected.size >= imei.maxCount) {
            setStatus(elements.imeiPickerStatus, `Maximo ${imei.maxCount} serial(es) para este item.`, 'error');
            return;
        }
        imei.selected.add(serialId);
    } else {
        imei.selected.delete(serialId);
    }
    updateImeiPickerCounter();
    renderImeiPickerList();
}

function updateImeiPickerCounter() {
    if (!elements.imeiPickerCounter) {
        return;
    }
    const imei = state.transfers.detail.imei;
    const count = imei.selected.size;
    const max = imei.maxCount > 0 ? ` / ${imei.maxCount}` : '';
    elements.imeiPickerCounter.textContent = `${count} seleccionados${max}`;
}

function confirmImeiSelection() {
    const imei = state.transfers.detail.imei;
    if (!imei.itemId) {
        return;
    }
    if (imei.maxCount > 0 && imei.selected.size > imei.maxCount) {
        setStatus(elements.imeiPickerStatus, `Solo puedes elegir hasta ${imei.maxCount} serial(es).`, 'error');
        return;
    }

    const itemId = String(imei.itemId);
    const fieldId = imei.action === 'prepare' ? 'prepared_product_unit_ids' : 'received_product_unit_ids';
    const action = imei.action;
    const input = elements.transferDrawerForm?.querySelector(
        `[data-transfer-action-field="${fieldId}"][data-item-id="${itemId}"]`
    );
    if (input) {
        input.value = Array.from(imei.selected).join(',');
    }

    const summary = elements.transferDrawerForm?.querySelector(
        `[data-transfer-serial-summary][data-item-id="${itemId}"]`
    );
    if (summary) {
        const serials = imei.serials.filter((entry) => imei.selected.has(Number(entry.id)));
        const labels = serials.map((entry) => entry.serial || entry.code || `#${entry.id}`);
        summary.textContent = labels.length === 0
            ? 'Sin seriales seleccionados'
            : labels.join(', ');
        summary.dataset.count = String(imei.selected.size);
    }
    closeImeiPicker();
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
        try { v2RenderRates(state.rates); } catch (e) { console.warn('v2RenderRates failed', e); }
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

// =====================================================================
// Access Control - Fase 1+2 backend features:
//   - Permission catalog (jerarquico, cacheado)
//   - User permission overrides (allow / deny individuales)
//   - Effective permissions (preview de capacidades reales)
//   - Field masking helper (costos)
// =====================================================================

const PERMISSION_CATALOG_URL = '/api/permission-catalog';
const PERMISSIONS_DANGER_EFFECTS = new Set(['cancel', 'delete', 'void']);

function formatCost(value) {
    if (value === null || value === undefined || value === '') {
        return '<span class="access-cost-masked">—</span>';
    }
    const num = Number(value);
    if (Number.isNaN(num)) {
        return escapeHtml(String(value));
    }
    return `$${num.toFixed(2)}`;
}

async function loadPermissionCatalog(force = false) {
    if (state.access.permissionCatalogLoaded && !force) {
        return state.access.permissionCatalog;
    }
    const session = state.session;
    if (!session) {
        return null;
    }
    try {
        const resp = await api(PERMISSION_CATALOG_URL, { headers: authHeaders(session) });
        const data = resp?.data || resp;
        state.access.permissionCatalog = data;
        state.access.permissionCatalogLoaded = true;
        return data;
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudo cargar el catalogo de permisos: ' + normalizeError(error), 'error');
        return null;
    }
}

function renderOverridesCatalogOptions() {
    if (!elements.accessOverridesAdd) {
        return;
    }
    const catalog = state.access.permissionCatalog;
    if (!catalog || !Array.isArray(catalog.modules)) {
        elements.accessOverridesAdd.replaceChildren();
        return;
    }
    const existing = new Set((state.access.userOverrides?.items || []).map((item) => item.permission));
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Selecciona un permiso del catalogo...';
    elements.accessOverridesAdd.replaceChildren(placeholder);
    catalog.modules.forEach((mod) => {
        const group = document.createElement('optgroup');
        group.label = mod.label || mod.module;
        (mod.actions || []).forEach((action) => {
            const opt = document.createElement('option');
            opt.value = action.permission;
            const danger = action.danger === 'high' || PERMISSIONS_DANGER_EFFECTS.has(action.verb);
            opt.textContent = `${action.label} (${action.permission})${danger ? ' - PELIGROSO' : ''}`;
            if (existing.has(action.permission)) {
                opt.disabled = true;
                opt.textContent += ' (ya asignado)';
            }
            group.append(opt);
        });
        if (group.children.length > 0) {
            elements.accessOverridesAdd.append(group);
        }
    });
}

async function loadUserOverrides(user) {
    const session = state.session;
    if (!session || !user) {
        return;
    }
    const tenantId = user.tenant_id || session.tenant?.id;
    if (!tenantId) {
        return;
    }
    try {
        const resp = await api(`/api/tenants/${tenantId}/users/${user.id}/overrides`, { headers: authHeaders(session) });
        const data = resp?.data || resp;
        state.access.userOverrides = data;
        renderOverridesEditor(data);
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudieron cargar los overrides: ' + normalizeError(error), 'error');
    }
}

function renderOverridesEditor(data) {
    if (!elements.accessOverridesExtras) {
        return;
    }
    const items = Array.isArray(data?.items) ? data.items : [];
    const extras = items.filter((i) => i.effect === 'allow');
    const denied = items.filter((i) => i.effect === 'deny');

    if (extras.length === 0) {
        elements.accessOverridesExtras.replaceChildren(emptyOverrideItem('Sin extras. Todos los permisos efectivos vienen de los perfiles.'));
    } else {
        elements.accessOverridesExtras.replaceChildren(...extras.map((i) => overrideListItem(i, 'allow')));
    }
    if (denied.length === 0) {
        elements.accessOverridesDenied.replaceChildren(emptyOverrideItem('Sin denegaciones. Los perfiles rinden todos los permisos.'));
    } else {
        elements.accessOverridesDenied.replaceChildren(...denied.map((i) => overrideListItem(i, 'deny')));
    }
    elements.accessOverridesExtrasCount.textContent = String(extras.length);
    elements.accessOverridesDeniedCount.textContent = String(denied.length);
    renderOverridesCatalogOptions();
}

function emptyOverrideItem(text) {
    const li = document.createElement('li');
    li.className = 'override-list-empty';
    li.textContent = text;
    return li;
}

function overrideListItem(item, effect) {
    const li = document.createElement('li');
    const perm = document.createElement('span');
    perm.className = 'override-perm';
    perm.textContent = item.permission;
    const badge = document.createElement('span');
    badge.className = `override-effect override-effect--${effect}`;
    badge.textContent = effect === 'allow' ? 'ALLOW' : 'DENY';
    const remove = document.createElement('button');
    remove.className = 'override-remove';
    remove.type = 'button';
    remove.title = 'Quitar override';
    remove.textContent = '×';
    remove.addEventListener('click', () => removeUserOverride(item.permission));
    li.append(perm, badge, remove);
    return li;
}

async function addUserOverride(effect) {
    const select = elements.accessOverridesAdd;
    if (!select || !select.value) {
        return;
    }
    const user = state.access.selectedUser;
    if (!user) {
        setStatus(elements.accessStatus, 'Selecciona un usuario antes de asignar overrides.', 'error');
        return;
    }
    const session = state.session;
    const tenantId = user.tenant_id || session.tenant?.id;
    const items = (state.access.userOverrides?.items || []).filter((i) => i.permission !== select.value);
    items.push({ permission: select.value, effect });
    try {
        await api(`/api/tenants/${tenantId}/users/${user.id}/overrides`, {
            method: 'PUT',
            headers: authHeaders(session),
            body: JSON.stringify({ items }),
        });
        setStatus(elements.accessStatus, `Override ${effect.toUpperCase()} aplicado a ${select.value}.`, 'success');
        await loadUserOverrides(user);
        await loadUserEffective(user);
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudo guardar el override: ' + normalizeError(error), 'error');
    } finally {
        select.value = '';
    }
}

async function removeUserOverride(permission) {
    const user = state.access.selectedUser;
    if (!user) {
        return;
    }
    const session = state.session;
    const tenantId = user.tenant_id || session.tenant?.id;
    try {
        await api(`/api/tenants/${tenantId}/users/${user.id}/overrides/${encodeURIComponent(permission)}`, {
            method: 'DELETE',
            headers: authHeaders(session),
        });
        setStatus(elements.accessStatus, `Override removido: ${permission}.`, 'success');
        await loadUserOverrides(user);
        await loadUserEffective(user);
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudo remover el override: ' + normalizeError(error), 'error');
    }
}

async function loadUserEffective(user) {
    const session = state.session;
    if (!session || !user) {
        return;
    }
    const tenantId = user.tenant_id || session.tenant?.id;
    if (!tenantId) {
        return;
    }
    try {
        const resp = await api(`/api/tenants/${tenantId}/users/${user.id}/effective-permissions`, { headers: authHeaders(session) });
        const data = resp?.data || resp;
        state.access.userEffective = data;
        renderCapabilityPreview(data);
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudieron cargar las capacidades: ' + normalizeError(error), 'error');
    }
}

function renderCapabilityPreview(data) {
    if (!elements.accessCapabilitiesSummary) {
        return;
    }
    if (!data) {
        elements.accessCapabilitiesSummary.replaceChildren(emptyAccessNode('p', 'Selecciona un usuario y abre esta pestana para ver sus capacidades.', 'access-empty'));
        return;
    }
    const fragment = document.createDocumentFragment();

    const stats = document.createElement('div');
    stats.className = 'capabilities-stats';
    stats.append(
        statBox('Permisos efectivos', data.permission_count || 0),
        statBox('De perfiles', data.base_count || 0),
        statBox('Extras (allow)', (data.extras || []).length),
        statBox('Denegados (deny)', (data.denied || []).length),
    );
    fragment.append(stats);

    if (Array.isArray(data.roles) && data.roles.length > 0) {
        const section = document.createElement('div');
        section.className = 'capabilities-section';
        const h = document.createElement('h6');
        h.textContent = `Perfiles (${data.roles.length})`;
        section.append(h);
        section.append(chipList(data.roles.map((r) => ({ label: r })), 'perfil'));
        fragment.append(section);
    }

    if (Array.isArray(data.extras) && data.extras.length > 0) {
        const section = document.createElement('div');
        section.className = 'capabilities-section';
        const h = document.createElement('h6');
        h.textContent = `Extras (${data.extras.length})`;
        section.append(h);
        section.append(chipList(data.extras.map((p) => ({ label: p })), 'extra'));
        fragment.append(section);
    }

    if (Array.isArray(data.denied) && data.denied.length > 0) {
        const section = document.createElement('div');
        section.className = 'capabilities-section';
        const h = document.createElement('h6');
        h.textContent = `Denegados (${data.denied.length})`;
        section.append(h);
        section.append(chipList(data.denied.map((p) => ({ label: p })), 'deny'));
        fragment.append(section);
    }

    elements.accessCapabilitiesSummary.replaceChildren(fragment);
    if (elements.accessCapabilitiesJson) {
        elements.accessCapabilitiesJson.textContent = JSON.stringify(data, null, 2);
    }
}

function statBox(label, value) {
    const div = document.createElement('div');
    div.className = 'capabilities-stat';
    const l = document.createElement('span');
    l.className = 'capabilities-stat__label';
    l.textContent = label;
    const v = document.createElement('span');
    v.className = 'capabilities-stat__value';
    v.textContent = String(value);
    div.append(l, v);
    return div;
}

function chipList(items, variant) {
    const ul = document.createElement('ul');
    ul.className = 'capabilities-chips';
    items.forEach(({ label }) => {
        const li = document.createElement('li');
        li.className = 'capabilities-chip';
        if (variant === 'extra') {
            li.classList.add('capabilities-chip--extra');
        } else if (variant === 'deny') {
            li.classList.add('capabilities-chip--deny');
        }
        li.textContent = label;
        ul.append(li);
    });
    return ul;
}

function emptyAccessNode(tag, text, cls) {
    const el = document.createElement(tag);
    if (cls) {
        el.className = cls;
    }
    el.textContent = text;
    return el;
}

async function loadScopeCatalog(force = false) {
    if (state.access.scopeCatalog && !force) {
        return state.access.scopeCatalog;
    }
    const session = state.session;
    if (!session) {
        return null;
    }
    try {
        const [branches, warehouses, customerGroups] = await Promise.all([
            api('/api/branches', { headers: authHeaders(session) }),
            api('/api/warehouses', { headers: authHeaders(session) }),
            api('/api/customer-groups', { headers: authHeaders(session) }),
        ]);
        state.access.scopeCatalog = {
            branches: collectionData(branches),
            warehouses: collectionData(warehouses),
            customerGroups: collectionData(customerGroups),
        };
        return state.access.scopeCatalog;
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudo cargar el catalogo de scopes: ' + normalizeError(error), 'error');
        return null;
    }
}

async function loadUserScopes(user) {
    const session = state.session;
    if (!session || !user) {
        return;
    }
    const tenantId = user.tenant_id || session.tenant?.id;
    if (!tenantId) {
        return;
    }
    try {
        const resp = await api(`/api/tenants/${tenantId}/users/${user.id}/scopes`, { headers: authHeaders(session) });
        const data = resp?.data || resp;
        state.access.userScopes = data;
        await loadScopeCatalog();
        renderUserScopes(data);
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudieron cargar los scopes: ' + normalizeError(error), 'error');
    }
}

function renderUserScopes(data) {
    if (!elements.accessScopeBranchesList) {
        return;
    }
    const user = state.access.selectedUser;
    const tenantId = user?.tenant_id || state.session?.tenant?.id;
    const catalog = state.access.scopeCatalog || { branches: [], warehouses: [], customerGroups: [] };
    const branches = Array.isArray(data?.branches) ? data.branches : [];
    const warehouses = Array.isArray(data?.warehouses) ? data.warehouses : [];
    const customerGroups = Array.isArray(data?.customer_groups) ? data.customer_groups : [];
    const vendorOf = Array.isArray(data?.vendor_of) ? data.vendor_of : [];
    const expanded = data?.expanded || {};

    renderScopeList(
        elements.accessScopeBranchesList,
        elements.accessScopeBranchesCount,
        catalog.branches,
        branches,
        expanded.branches || [],
        tenantId,
    );
    renderScopeList(
        elements.accessScopeWarehousesList,
        elements.accessScopeWarehousesCount,
        catalog.warehouses,
        warehouses,
        expanded.warehouses || [],
        tenantId,
    );
    renderScopeList(
        elements.accessScopeCustomerGroupsList,
        elements.accessScopeCustomerGroupsCount,
        catalog.customerGroups,
        customerGroups,
        expanded.customer_groups || [],
        tenantId,
    );
    renderScopeList(
        elements.accessScopeVendorOfList,
        elements.accessScopeVendorOfCount,
        catalog.customerGroups,
        vendorOf,
        expanded.vendor_of || [],
        tenantId,
    );

    if (elements.accessScopeStatusBanner) {
        const status = scopeStatusOf(data);
        if (status === 'none') {
            elements.accessScopeStatusBanner.hidden = false;
            elements.accessScopeStatusBanner.textContent = 'Sin asignacion: el usuario ve TODO. Recomendado asignar al menos 1 para restringir.';
        } else if (status === 'allow') {
            elements.accessScopeStatusBanner.hidden = false;
            elements.accessScopeStatusBanner.textContent = 'Scopes vacios: el usuario ve TODO en estas categorias.';
        } else {
            elements.accessScopeStatusBanner.hidden = true;
        }
    }
}

function renderScopeList(container, countEl, allItems, selectedIds, expanded, tenantId) {
    if (!container) {
        return;
    }
    if (!Array.isArray(allItems) || allItems.length === 0) {
        container.replaceChildren(emptyAccessNode('p', 'No hay recursos disponibles. Crea sucursales, almacenes o grupos de cliente primero.', 'access-empty'));
        if (countEl) {
            countEl.textContent = '0';
        }
        return;
    }
    const selected = new Set((selectedIds || []).map(Number));
    const codeById = new Map();
    (expanded || []).forEach((item) => {
        codeById.set(Number(item.id), item.code || '');
    });
    const fragment = document.createDocumentFragment();
    allItems.forEach((item) => {
        const id = Number(item.id);
        const label = document.createElement('label');
        label.className = 'scope-check';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.value = String(id);
        input.dataset.scopeItem = String(id);
        if (selected.has(id)) {
            input.checked = true;
        }
        const code = codeById.get(id) || item.code || '';
        const text = document.createElement('span');
        text.textContent = item.name || `Item ${id}`;
        label.append(input, text);
        if (code) {
            const codeEl = document.createElement('span');
            codeEl.className = 'scope-check__code';
            codeEl.textContent = `(${code})`;
            label.append(codeEl);
        }
        fragment.append(label);
    });
    container.replaceChildren(fragment);
    if (countEl) {
        countEl.textContent = String(selectedIds?.length || 0);
    }
}

function scopeStatusOf(data) {
    if (!data) {
        return 'none';
    }
    const total = (data.branches?.length || 0)
        + (data.warehouses?.length || 0)
        + (data.customer_groups?.length || 0)
        + (data.vendor_of?.length || 0);
    if (total === 0) {
        return 'none';
    }
    const allEmpty = ['branches', 'warehouses', 'customer_groups', 'vendor_of']
        .every((key) => Array.isArray(data[key]) && data[key].length === 0);
    return allEmpty ? 'allow' : 'restrict';
}

async function saveUserScopes() {
    const user = state.access.selectedUser;
    if (!user) {
        return;
    }
    const session = state.session;
    const tenantId = user.tenant_id || session.tenant?.id;
    if (!tenantId) {
        return;
    }
    const readIds = (containerId) => Array.from(document.querySelectorAll(`#${containerId} input[data-scope-item]:checked`))
        .map((input) => Number(input.value));
    const body = {
        branch_ids: readIds('admin-access-scope-branches-list'),
        warehouse_ids: readIds('admin-access-scope-warehouses-list'),
        customer_group_ids: readIds('admin-access-scope-customer-groups-list'),
        vendor_of_ids: readIds('admin-access-scope-vendor-of-list'),
    };
    setStatus(elements.accessStatus, 'Guardando scopes...');
    setButtonLoading(elements.accessSaveScopes, true, 'Guardando...');
    try {
        await api(`/api/tenants/${tenantId}/users/${user.id}/scopes`, {
            method: 'PUT',
            headers: authHeaders(session),
            body: JSON.stringify(body),
        });
        setStatus(elements.accessStatus, 'Scopes guardados.', 'success');
        await loadUserScopes(user);
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudieron guardar los scopes: ' + normalizeError(error), 'error');
    } finally {
        setButtonLoading(elements.accessSaveScopes, false);
    }
}

function renderScopeBadge(user) {
    const scopes = user?.scopes;
    if (!scopes) {
        return '';
    }
    const status = scopeStatusOf(scopes);
    if (status === 'none') {
        return '<span class="scope-badge scope-badge--none" title="Sin scopes asignados">Sin scope</span>';
    }
    if (status === 'allow') {
        const total = (scopes.branches?.length || 0)
            + (scopes.warehouses?.length || 0)
            + (scopes.customer_groups?.length || 0)
            + (scopes.vendor_of?.length || 0);
        return `<span class="scope-badge scope-badge--allow" title="Scopes vacios en ${total} categorias">Vacio</span>`;
    }
    const total = (scopes.branches?.length || 0)
        + (scopes.warehouses?.length || 0)
        + (scopes.customer_groups?.length || 0)
        + (scopes.vendor_of?.length || 0);
    return `<span class="scope-badge scope-badge--restrict" title="Restringido a ${total} recursos">Restringido</span>`;
}

function setActiveUserSubtab(subtab) {
    state.access.activeUserSubtab = subtab;
    elements.accessUserSubtabs.forEach((btn) => {
        btn.classList.toggle('is-active', btn.dataset.userSubtab === subtab);
    });
    elements.accessUserSubpanels.forEach((panel) => {
        panel.hidden = panel.dataset.userSubpanel !== subtab;
    });
}

async function loadUserSubtabData(subtab) {
    const user = state.access.selectedUser;
    if (!user) {
        return;
    }
    if (subtab === 'overrides') {
        await loadPermissionCatalog();
        await loadUserOverrides(user);
    } else if (subtab === 'capabilities') {
        await loadUserEffective(user);
    } else if (subtab === 'scopes') {
        await loadUserScopes(user);
    }
}

function renderAccessControl() {
    setAccessTab('users');
    renderAccessUsers();
    renderAccessRoleOptions(elements.accessUserRoles, []);
    renderAccessRoleOptions(elements.accessSelectedUserRoles, state.access.selectedUser?.roles?.map((role) => role.name) || []);
    renderAccessRoles();
    renderPermissionCatalog();
    setActiveUserSubtab(state.access.activeUserSubtab || 'roles');
    if (state.access.selectedUser) {
        loadUserSubtabData(state.access.activeUserSubtab || 'roles');
    } else {
        renderOverridesEditor(null);
        renderCapabilityPreview(null);
    }
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
        elements.accessUsersTable.innerHTML = '<tr><td colspan="5"><strong>Sin usuarios visibles</strong><small>No hay usuarios cargados o tu usuario no tiene permiso para verlos.</small></td></tr>';
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
        <td>${renderScopeBadge(user)}</td>
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
    state.access.userOverrides = null;
    state.access.userEffective = null;
    state.access.userScopes = null;
    renderOverridesEditor(null);
    renderCapabilityPreview(null);
    renderUserScopes(null);
    loadUserSubtabData(state.access.activeUserSubtab || 'roles');
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
    const actionButtons = `<button class="ghost-button ghost-button--compact" type="button" data-access-role="${role.id}">Permisos</button>`
        + `<button class="ghost-button ghost-button--compact" type="button" data-access-role-duplicate="${role.id}" title="Duplicar este perfil con un nombre nuevo">Duplicar</button>`;
    row.innerHTML = `
        <td><strong>${escapeHtml(role.name)}</strong>${role.is_protected ? ' <span class="access-chip access-chip--locked" title="Rol base del sistema, no se puede eliminar">Base</span>' : ''}</td>
        <td>${number((role.permissions || []).length)}</td>
        <td>${role.is_protected ? '<span class="access-chip">Base</span>' : '<span class="access-chip">Personalizado</span>'}</td>
        <td>${actionButtons}</td>
    `;

    row.querySelector('[data-access-role]')?.addEventListener('click', (event) => {
        if (event.target.closest('[data-access-role-duplicate]')) {
            return;
        }
        selectAccessRole(role);
    });
    row.querySelector('[data-access-role-duplicate]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        duplicateRole(role);
    });

    return row;
}

async function duplicateRole(role) {
    const session = state.session;
    if (!session || !role) {
        return;
    }
    const defaultName = role.is_protected ? `${role.name} (copia)` : `${role.name} v2`;
    const newName = (window.prompt(`Duplicar el perfil "${role.name}".\nNombre para el nuevo perfil:`, defaultName) || '').trim();
    if (!newName) {
        return;
    }
    setStatus(elements.accessStatus, `Duplicando perfil "${role.name}" como "${newName}"...`);
    try {
        const resp = await api(`/api/roles/${role.id}/duplicate`, {
            method: 'POST',
            headers: authHeaders(session),
            body: JSON.stringify({ name: newName }),
        });
        const data = resp?.data || resp;
        setStatus(elements.accessStatus, `Perfil duplicado: ${data.name}.`, 'success');
        await loadAccessControl();
        const newRole = state.access.roles.find((r) => r.id === data.id);
        if (newRole) {
            selectAccessRole(newRole);
        }
    } catch (error) {
        setStatus(elements.accessStatus, 'No se pudo duplicar el perfil: ' + normalizeError(error), 'error');
    }
}

async function loadRolePreview(role) {
    const session = state.session;
    if (!session || !role) {
        return null;
    }
    try {
        const resp = await api(`/api/roles/${role.id}/preview`, { headers: authHeaders(session) });
        return resp?.data || resp;
    } catch (error) {
        AppLogger?.warn?.(`No se pudo cargar preview del rol ${role.id}: ${error?.message || error}`);
        return null;
    }
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
    if (elements.accessOverridesAllowBtn) {
        elements.accessOverridesAllowBtn.disabled = !canUpdateUser || !state.access.selectedUser;
    }
    if (elements.accessOverridesDenyBtn) {
        elements.accessOverridesDenyBtn.disabled = !canUpdateUser || !state.access.selectedUser;
    }
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
elements.transfersRefresh?.addEventListener('click', () => loadTransfers());
elements.transfersApply?.addEventListener('click', applyTransferFilters);
elements.transfersClear?.addEventListener('click', clearTransferFilters);
elements.transfersExport?.addEventListener('click', exportTransfersCsv);
elements.transfersPrev?.addEventListener('click', () => loadTransfers(Math.max(state.transfers.page - 1, 1)));
elements.transfersNext?.addEventListener('click', () => loadTransfers(state.transfers.page + 1));
elements.transfersSearch?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        applyTransferFilters();
    }
});
elements.transfersChips?.forEach((chip) => {
    chip.addEventListener('click', () => {
        const filter = chip.dataset.adminTransferChip;
        if (!filter) return;
        if (filter === 'all') {
            clearTransferFilters();
            return;
        }
        if (filter === 'in_flight') {
            state.transfers.filters.status = ['requested', 'in_preparation', 'prepared', 'prepared_with_differences', 'dispatched', 'in_reception'];
        } else if (filter === 'with_differences') {
            state.transfers.filters.status = ['prepared_with_differences', 'completed_with_differences'];
        } else {
            state.transfers.filters.status = [filter];
        }
        if (elements.transfersStatusOptions) {
            elements.transfersStatusOptions.querySelectorAll('input[type="checkbox"]').forEach((el) => {
                el.checked = state.transfers.filters.status.includes(el.value);
            });
        }
        loadTransfers(1);
    });
});
if (elements.transferDrawer) {
    elements.transferDrawer.querySelectorAll('[data-admin-transfer-drawer-close]').forEach((el) => {
        el.addEventListener('click', closeTransferDrawer);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !elements.transferDrawer.hidden && (elements.imeiPicker?.hidden ?? true)) {
            closeTransferDrawer();
        }
    });
}
if (elements.imeiPicker) {
    elements.imeiPicker.querySelectorAll('[data-admin-imei-picker-close]').forEach((el) => {
        el.addEventListener('click', closeImeiPicker);
    });
    elements.imeiPickerConfirm?.addEventListener('click', confirmImeiSelection);
    let imeiSearchDebounce;
    elements.imeiPickerSearch?.addEventListener('input', () => {
        window.clearTimeout(imeiSearchDebounce);
        imeiSearchDebounce = window.setTimeout(() => {
            renderImeiPickerList();
        }, 150);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && elements.imeiPicker && !elements.imeiPicker.hidden) {
            closeImeiPicker();
        }
    });
}
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
elements.accessUserSubtabs.forEach((btn) => {
    btn.addEventListener('click', () => {
        const subtab = btn.dataset.userSubtab;
        setActiveUserSubtab(subtab);
        loadUserSubtabData(subtab);
    });
});
elements.accessOverridesAllowBtn?.addEventListener('click', () => addUserOverride('allow'));
elements.accessOverridesDenyBtn?.addEventListener('click', () => addUserOverride('deny'));
elements.accessSaveScopes?.addEventListener('click', saveUserScopes);
elements.tenant?.addEventListener('change', () => {
    state.selectedTenant = state.tenants.find((tenant) => tenant.slug === elements.tenant.value) ?? null;
});

restoreSession();

if (state.session) {
    loadDashboard();
}

// =====================================================================
// v2 Overview redesign (Resumen)
// =====================================================================

const v2 = {
    shell: document.querySelector('.admin-shell--v2'),
    sidebar: document.querySelector('.v2-sidebar'),
    toggle: document.getElementById('v2-sidebar-toggle'),
    navItems: document.querySelectorAll('.v2-nav-item'),
    refresh: document.getElementById('v2-dashboard-refresh'),
    logout: document.getElementById('v2-admin-logout'),
    period: document.getElementById('v2-dashboard-period'),
    tenantSwitcher: document.getElementById('v2-tenant-switcher'),
    tenantField: document.getElementById('v2-tenant-field'),
    periodLabel: document.getElementById('v2-period-label'),
    dashboardStatus: document.getElementById('v2-dashboard-status'),
    salesTotal: document.getElementById('v2-metric-sales-total'),
    salesCount: document.getElementById('v2-metric-sales-count'),
    stockAvailable: document.getElementById('v2-metric-stock-available'),
    openCash: document.getElementById('v2-metric-open-cash'),
    cashExpected: document.getElementById('v2-metric-cash-expected'),
    pendingPos: document.getElementById('v2-metric-pending-pos'),
    products: document.getElementById('v2-metric-products'),
    lowStock: document.getElementById('v2-metric-low-stock'),
    withoutStock: document.getElementById('v2-metric-without-stock'),
    reserved: document.getElementById('v2-metric-reserved'),
    syncNodes: document.getElementById('v2-metric-sync-nodes'),
    syncPending: document.getElementById('v2-metric-sync-pending'),
    syncErrors: document.getElementById('v2-metric-sync-errors'),
    syncStatus: document.getElementById('v2-sync-status'),
    alertList: document.getElementById('v2-alert-list'),
    sparkContainers: document.querySelectorAll('[data-sparkline]'),
    search: document.querySelector('.v2-topbar__search input'),
    // v2 Inventario (Phase 1)
    inventory: {
        page: document.getElementById('v2-inventory-page'),
        period: document.getElementById('v2-inv-period'),
        status: document.getElementById('v2-inv-status'),
        newBtn: document.getElementById('v2-inv-new'),
        refreshBtn: document.getElementById('v2-inv-refresh'),
        exportBtn: document.getElementById('v2-inv-export'),
        metricTotal: document.getElementById('v2-inv-metric-total'),
        metricTotalHint: document.getElementById('v2-inv-metric-total-hint'),
        metricLow: document.getElementById('v2-inv-metric-low'),
        metricLowHint: document.getElementById('v2-inv-metric-low-hint'),
        metricOut: document.getElementById('v2-inv-metric-out'),
        metricAvailable: document.getElementById('v2-inv-metric-available'),
        metricReserved: document.getElementById('v2-inv-metric-reserved'),
        // alert strip (compact, single row)
        alertStrip: document.getElementById('v2-inv-alert-strip'),
        alertStripList: document.getElementById('v2-inv-alert-strip-list'),
        alertStripClose: document.getElementById('v2-inv-alert-strip-close'),
        activeStatus: document.getElementById('v2-inv-active-status'),
        search: document.getElementById('v2-inv-search'),
        tracking: document.getElementById('v2-inv-tracking'),
        stock: document.getElementById('v2-inv-stock'),
        apply: document.getElementById('v2-inv-apply'),
        quickStatus: document.getElementById('v2-inv-quick-status'),
        filterSummary: document.getElementById('v2-inv-filter-summary'),
        tbody: document.getElementById('v2-inv-tbody'),
        count: document.getElementById('v2-inv-count'),
        prev: document.getElementById('v2-inv-prev'),
        next: document.getElementById('v2-inv-next'),
        // side sheet (product detail)
        sheet: document.getElementById('v2-inv-sheet'),
        sheetBackdrop: document.getElementById('v2-inv-sheet-backdrop'),
        sheetPanel: document.querySelector('#v2-inv-sheet .v2-sheet__panel'),
        sheetBadge: document.getElementById('v2-sheet-badge'),
        sheetTitle: document.getElementById('v2-sheet-title'),
        sheetSubtitle: document.getElementById('v2-sheet-subtitle'),
        // action groups (view vs edit)
        sheetActionsView: document.getElementById('v2-sheet-actions-view'),
        sheetActionsEdit: document.getElementById('v2-sheet-actions-edit'),
        sheetEdit: document.getElementById('v2-sheet-edit'),
        sheetClose: document.getElementById('v2-sheet-close'),
        sheetCancel: document.getElementById('v2-sheet-cancel'),
        sheetSave: document.getElementById('v2-sheet-save'),
        sheetSaveLabel: document.getElementById('v2-sheet-save-label'),
        sheetCloseEdit: document.getElementById('v2-sheet-close-edit'),
        sheetTabs: document.getElementById('v2-inv-sheet-tabs'),
        // view mode (key-value)
        sheetName: document.getElementById('v2-sheet-name'),
        sheetSku: document.getElementById('v2-sheet-sku'),
        sheetTracking: document.getElementById('v2-sheet-tracking'),
        sheetCurrency: document.getElementById('v2-sheet-currency'),
        sheetBasePrice: document.getElementById('v2-sheet-base-price'),
        sheetActive: document.getElementById('v2-sheet-active'),
        // edit mode (form)
        sheetEditName: document.getElementById('v2-sheet-edit-name'),
        sheetEditSku: document.getElementById('v2-sheet-edit-sku'),
        sheetEditTracking: document.getElementById('v2-sheet-edit-tracking'),
        sheetEditCurrency: document.getElementById('v2-sheet-edit-currency'),
        sheetEditPrice: document.getElementById('v2-sheet-edit-price'),
        sheetEditActive: document.getElementById('v2-sheet-edit-active'),
        sheetEditError: document.getElementById('v2-sheet-edit-error'),
        // detail panes
        sheetStock: document.getElementById('v2-sheet-stock'),
        sheetStockTotal: document.getElementById('v2-sheet-stock-total'),
        sheetPrices: document.getElementById('v2-sheet-prices'),
        sheetPriceCount: document.getElementById('v2-sheet-price-count'),
        sheetHistory: document.getElementById('v2-sheet-history'),
        sheetChangeCount: document.getElementById('v2-sheet-change-count'),
    },
    // v2 Tasas (Phase 2)
    rates: {
        page: document.getElementById('v2-rates-page'),
        period: document.getElementById('v2-rates-period'),
        status: document.getElementById('v2-rates-status'),
        metricTypes: document.getElementById('v2-rates-metric-types'),
        metricTypesHint: document.getElementById('v2-rates-metric-types-hint'),
        metricCurrent: document.getElementById('v2-rates-metric-current'),
        metricCurrentHint: document.getElementById('v2-rates-metric-current-hint'),
        metricTotal: document.getElementById('v2-rates-metric-total'),
        metricTotalHint: document.getElementById('v2-rates-metric-total-hint'),
        metricLast: document.getElementById('v2-rates-metric-last'),
        metricLastHint: document.getElementById('v2-rates-metric-last-hint'),
        alertStrip: document.getElementById('v2-rates-alert-strip'),
        alertStripList: document.getElementById('v2-rates-alert-strip-list'),
        alertStripClose: document.getElementById('v2-rates-alert-strip-close'),
        typesCount: document.getElementById('v2-rates-types-count'),
        typesCountFoot: document.getElementById('v2-rates-types-count-foot'),
        typesTbody: document.getElementById('v2-rates-types-tbody'),
        historyCount: document.getElementById('v2-rates-history-count'),
        historyCountFoot: document.getElementById('v2-rates-history-count-foot'),
        historyTbody: document.getElementById('v2-rates-history-tbody'),
        prev: document.getElementById('v2-rates-prev'),
        next: document.getElementById('v2-rates-next'),
        refresh: document.getElementById('v2-rates-refresh'),
        newType: document.getElementById('v2-rates-new-type'),
        newValue: document.getElementById('v2-rates-new-value'),
        // type sheet
        typeSheet: document.getElementById('v2-rates-type-sheet'),
        typeSheetBackdrop: document.getElementById('v2-rates-type-sheet-backdrop'),
        typeSheetPanel: document.querySelector('#v2-rates-type-sheet .v2-sheet__panel'),
        typeBadge: document.getElementById('v2-rate-type-badge'),
        typeTitle: document.getElementById('v2-rate-type-title'),
        typeSubtitle: document.getElementById('v2-rate-type-subtitle'),
        typeCode: document.getElementById('v2-rate-type-code'),
        typeNameView: document.getElementById('v2-rate-type-name-view'),
        typeDefault: document.getElementById('v2-rate-type-default'),
        typeActive: document.getElementById('v2-rate-type-active'),
        typeCreated: document.getElementById('v2-rate-type-created'),
        typeEditBtn: document.getElementById('v2-rate-type-edit'),
        typeClose: document.getElementById('v2-rate-type-close'),
        typeCloseEdit: document.getElementById('v2-rate-type-close-edit'),
        typeCancel: document.getElementById('v2-rate-type-cancel'),
        typeSave: document.getElementById('v2-rate-type-save'),
        typeSaveLabel: document.getElementById('v2-rate-type-save-label'),
        typeEditCode: document.getElementById('v2-rate-type-edit-code'),
        typeEditName: document.getElementById('v2-rate-type-edit-name'),
        typeEditDefault: document.getElementById('v2-rate-type-edit-default'),
        typeEditActive: document.getElementById('v2-rate-type-edit-active'),
        typeEditError: document.getElementById('v2-rate-type-edit-error'),
        typeHistoryList: document.getElementById('v2-rate-type-history-list'),
        typeHistoryCount: document.getElementById('v2-rate-type-history-count'),
        // value sheet
        valueSheet: document.getElementById('v2-rates-value-sheet'),
        valueSheetBackdrop: document.getElementById('v2-rates-value-sheet-backdrop'),
        valueSheetPanel: document.querySelector('#v2-rates-value-sheet .v2-sheet__panel'),
        valueTitle: document.getElementById('v2-rate-value-title'),
        valueSubtitle: document.getElementById('v2-rate-value-subtitle'),
        valueTypeSelect: document.getElementById('v2-rate-value-type'),
        valueAmount: document.getElementById('v2-rate-value-amount'),
        valueEffective: document.getElementById('v2-rate-value-effective'),
        valueSource: document.getElementById('v2-rate-value-source'),
        valueActive: document.getElementById('v2-rate-value-active'),
        valueCancel: document.getElementById('v2-rate-value-cancel'),
        valueSave: document.getElementById('v2-rate-value-save'),
        valueClose: document.getElementById('v2-rate-value-close'),
        valueError: document.getElementById('v2-rate-value-error'),
    },
    // v2 Movimientos (Phase 3)
    movements: {
        page: document.getElementById('v2-movements-page'),
        period: document.getElementById('v2-mov-period'),
        status: document.getElementById('v2-mov-status'),
        refresh: document.getElementById('v2-mov-refresh'),
        // KPI row
        metricTotal: document.getElementById('v2-mov-metric-total'),
        metricTotalHint: document.getElementById('v2-mov-metric-total-hint'),
        metricIn: document.getElementById('v2-mov-metric-in'),
        metricOut: document.getElementById('v2-mov-metric-out'),
        metricProducts: document.getElementById('v2-mov-metric-products'),
        // alert strip
        alertStrip: document.getElementById('v2-mov-alert-strip'),
        alertStripList: document.getElementById('v2-mov-alert-strip-list'),
        alertStripClose: document.getElementById('v2-mov-alert-strip-close'),
        filterStatus: document.getElementById('v2-mov-filter-status'),
        // filter toolbar
        search: document.getElementById('v2-mov-search'),
        type: document.getElementById('v2-mov-type'),
        warehouse: document.getElementById('v2-mov-warehouse'),
        from: document.getElementById('v2-mov-from'),
        to: document.getElementById('v2-mov-to'),
        apply: document.getElementById('v2-mov-apply'),
        clear: document.getElementById('v2-mov-clear'),
        // table
        tbody: document.getElementById('v2-mov-tbody'),
        count: document.getElementById('v2-mov-count'),
        prev: document.getElementById('v2-mov-prev'),
        next: document.getElementById('v2-mov-next'),
        // detail sheet
        sheet: document.getElementById('v2-mov-sheet'),
        sheetBackdrop: document.getElementById('v2-mov-sheet-backdrop'),
        sheetPanel: document.querySelector('#v2-mov-sheet .v2-sheet__panel'),
        sheetBadge: document.getElementById('v2-mov-sheet-badge'),
        sheetTitle: document.getElementById('v2-mov-sheet-title'),
        sheetSubtitle: document.getElementById('v2-mov-sheet-subtitle'),
        sheetClose: document.getElementById('v2-mov-sheet-close'),
        // tab panes
        sheetType: document.getElementById('v2-mov-sheet-type'),
        sheetQty: document.getElementById('v2-mov-sheet-qty'),
        sheetCost: document.getElementById('v2-mov-sheet-cost'),
        sheetDate: document.getElementById('v2-mov-sheet-date'),
        sheetReason: document.getElementById('v2-mov-sheet-reason'),
        sheetRef: document.getElementById('v2-mov-sheet-ref'),
        sheetProductName: document.getElementById('v2-mov-sheet-product-name'),
        sheetProductSku: document.getElementById('v2-mov-sheet-product-sku'),
        sheetProductId: document.getElementById('v2-mov-sheet-product-id'),
        sheetWarehouse: document.getElementById('v2-mov-sheet-warehouse'),
        sheetBranch: document.getElementById('v2-mov-sheet-branch'),
        sheetUser: document.getElementById('v2-mov-sheet-user'),
        sheetId: document.getElementById('v2-mov-sheet-id'),
    },
    // v2 Reportes (Phase 4) — multi-panel operational dashboard
    reports: {
        page: document.getElementById('v2-reports-page'),
        period: document.getElementById('v2-reports-period'),
        status: document.getElementById('v2-reports-status'),
        refresh: document.getElementById('v2-reports-refresh'),
        // KPI row
        metricPosTotal: document.getElementById('v2-reports-metric-pos-total'),
        metricPosHint: document.getElementById('v2-reports-metric-pos-hint'),
        metricTicket: document.getElementById('v2-reports-metric-ticket'),
        metricPending: document.getElementById('v2-reports-metric-pending'),
        metricPendingHint: document.getElementById('v2-reports-metric-pending-hint'),
        metricOpenCash: document.getElementById('v2-reports-metric-open-cash'),
        metricCashHint: document.getElementById('v2-reports-metric-cash-hint'),
        // alert strip
        alertStrip: document.getElementById('v2-reports-alert-strip'),
        alertStripList: document.getElementById('v2-reports-alert-strip-list'),
        alertStripClose: document.getElementById('v2-reports-alert-strip-close'),
        // filter toolbar
        periodSelect: document.getElementById('v2-reports-period-select'),
        dateFrom: document.getElementById('v2-reports-date-from'),
        dateTo: document.getElementById('v2-reports-date-to'),
        branch: document.getElementById('v2-reports-branch'),
        cashRegister: document.getElementById('v2-reports-cash-register'),
        cashier: document.getElementById('v2-reports-cashier'),
        orderStatus: document.getElementById('v2-reports-order-status'),
        apply: document.getElementById('v2-reports-apply'),
        clear: document.getElementById('v2-reports-clear'),
        // 4 panels
        ordersTbody: document.getElementById('v2-reports-orders-tbody'),
        ordersSubtitle: document.getElementById('v2-reports-orders-subtitle'),
        paymentsTbody: document.getElementById('v2-reports-payments-tbody'),
        productsTbody: document.getElementById('v2-reports-products-tbody'),
        cashTbody: document.getElementById('v2-reports-cash-tbody'),
        // export buttons
        exportOrders: document.getElementById('v2-reports-export-orders'),
        exportPayments: document.getElementById('v2-reports-export-payments'),
        exportProducts: document.getElementById('v2-reports-export-products'),
        exportCash: document.getElementById('v2-reports-export-cash'),
    },
};

function v2StatusTone(status) {
    const map = {
        ready: 'success',
        synced: 'success',
        synced_with_pending: 'warning',
        pending: 'warning',
        with_errors: 'error',
        error: 'error',
        not_configured: 'warning',
        degraded: 'warning',
    };
    return map[status] || 'warning';
}

function v2StatusLabel(status) {
    const map = {
        ready: 'Sincronizado',
        synced: 'Sincronizado',
        synced_with_pending: 'Pendiente',
        pending: 'Pendiente',
        with_errors: 'Con errores',
        error: 'Con errores',
        not_configured: 'No configurado',
        degraded: 'Degradado',
    };
    return map[status] || 'Sin datos';
}

function v2AlertIcon(action) {
    const a = String(action || '').toLowerCase();
    if (a.includes('stock')) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    }
    if (a.includes('sync') || a.includes('event')) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 18 5.3L23 10"/></svg>';
    }
    if (a.includes('cash') || a.includes('caja')) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
}

function v2AlertTone(action) {
    const a = String(action || '').toLowerCase();
    if (a.includes('sin_stock') || a.includes('critical') || a.includes('error')) return 'danger';
    if (a.includes('low_stock') || a.includes('pending') || a.includes('stock_bajo') || a.includes('warning')) return 'warning';
    if (a.includes('sync') || a.includes('event')) return 'info';
    return 'warning';
}

// Seeded pseudo-random for deterministic sparklines (same value -> same shape)
function v2SeededRand(seed) {
    let s = seed | 0 || 1;
    return () => {
        s = (s * 9301 + 49297) % 233280;
        return s / 233280;
    };
}

function v2GenerateSparkPoints(seed, value, count = 12) {
    const rand = v2SeededRand(seed);
    const baseline = Math.max(1, Number(value) || 1);
    const points = [];
    let current = baseline * (0.55 + rand() * 0.45);
    for (let i = 0; i < count; i++) {
        const swing = (rand() - 0.5) * 0.45;
        const drift = ((baseline - current) / (count - i)) * 0.6;
        current = Math.max(0, current + drift + swing * baseline);
        points.push(current);
    }
    points[points.length - 1] = baseline;
    return points;
}

function v2RenderSparkline(container, value, color) {
    if (!container) return;
    const seedAttr = container.getAttribute('data-sparkline') || 'spark';
    let seed = 0;
    for (let i = 0; i < seedAttr.length; i++) seed = (seed * 31 + seedAttr.charCodeAt(i)) | 0;
    const points = v2GenerateSparkPoints(seed, value, 14);
    const w = 120, h = 28;
    const min = Math.min(...points);
    const max = Math.max(...points);
    const range = max - min || 1;
    const stepX = w / (points.length - 1);
    let path = '';
    let area = `M 0 ${h} `;
    points.forEach((p, i) => {
        const x = (i * stepX).toFixed(2);
        const y = (h - ((p - min) / range) * (h - 4) - 2).toFixed(2);
        path += `${i === 0 ? 'M' : 'L'} ${x} ${y} `;
        area += `${i === 0 ? 'L' : 'L'} ${x} ${y} `;
    });
    area += `L ${w} ${h} Z`;

    container.innerHTML = `<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true">` +
        `<path d="${area}" fill="${color}" fill-opacity="0.18" />` +
        `<path d="${path}" fill="none" stroke="${color}" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />` +
        `</svg>`;
}

function v2RenderSparklines(valuesByKey) {
    const colorMap = {
        success: 'var(--v2-green)',
        warning: 'var(--v2-amber)',
        danger: 'var(--v2-red)',
        info: 'var(--v2-accent)',
    };
    document.querySelectorAll('[data-sparkline]').forEach((container) => {
        const key = container.getAttribute('data-sparkline');
        const value = valuesByKey[key] ?? 0;
        const parent = container.closest('.v2-mini');
        let tone = 'info';
        if (parent) {
            for (const t of ['success', 'warning', 'danger', 'info']) {
                if (parent.classList.contains(`v2-mini--${t}`)) { tone = t; break; }
            }
        }
        v2RenderSparkline(container, value, colorMap[tone] || colorMap.info);
    });
}

function v2RenderOverview(summary) {
    if (!summary) return;

    if (v2.periodLabel && summary.period) {
        v2.periodLabel.textContent = `Vista gerencial · ${summary.period.from} a ${summary.period.to}`;
    }

    if (v2.salesTotal) v2.salesTotal.textContent = money(summary.sales?.confirmed_base_amount || 0);
    if (v2.salesCount) v2.salesCount.textContent = `${number(summary.sales?.confirmed_count || 0)} ventas confirmadas`;
    if (v2.stockAvailable) v2.stockAvailable.textContent = number(summary.inventory?.available_quantity || 0);
    if (v2.openCash) v2.openCash.textContent = number(summary.cash_register?.open_sessions_count || 0);
    if (v2.cashExpected) v2.cashExpected.textContent = `${money(summary.cash_register?.expected_base_amount || 0)} esperado`;
    if (v2.pendingPos) v2.pendingPos.textContent = number(summary.sales?.pending_pos_count || 0);

    if (v2.products) v2.products.textContent = number(summary.inventory?.active_products_count || 0);
    if (v2.lowStock) v2.lowStock.textContent = number(summary.inventory?.low_stock_count || 0);
    if (v2.withoutStock) v2.withoutStock.textContent = number(summary.inventory?.without_stock_count || 0);
    if (v2.reserved) v2.reserved.textContent = number(summary.inventory?.reserved_quantity || 0);

    if (v2.syncNodes) v2.syncNodes.textContent = number(summary.sync?.nodes_count || 0);
    if (v2.syncPending) v2.syncPending.textContent = number(summary.sync?.pending_outbox_count || 0);
    if (v2.syncErrors) v2.syncErrors.textContent = number((summary.sync?.failed_outbox_count || 0) + (summary.sync?.failed_inbox_count || 0));

    if (v2.syncStatus) {
        const status = summary.sync?.readiness_status || 'not_configured';
        v2.syncStatus.textContent = v2StatusLabel(status);
        v2.syncStatus.className = `v2-status-pill v2-status-pill--${v2StatusTone(status)}`;
    }

    v2RenderSparklines({
        'products': summary.inventory?.active_products_count || 0,
        'low-stock': summary.inventory?.low_stock_count || 0,
        'without-stock': summary.inventory?.without_stock_count || 0,
        'reserved': summary.inventory?.reserved_quantity || 0,
        'nodes': summary.sync?.nodes_count || 0,
        'pending': summary.sync?.pending_outbox_count || 0,
        'errors': (summary.sync?.failed_outbox_count || 0) + (summary.sync?.failed_inbox_count || 0),
    });

    v2RenderAlerts(summary.alerts || []);
}

function v2RenderAlerts(alerts) {
    if (!v2.alertList) return;
    if (!alerts.length) {
        v2.alertList.innerHTML = '<div class="v2-alert v2-alert--success"><div class="v2-alert__icon">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></div>' +
            '<div class="v2-alert__body"><strong>Sin alertas críticas</strong><span>La empresa no tiene alertas operativas para este resumen.</span></div></div>';
        return;
    }
    v2.alertList.replaceChildren(
        ...alerts.map((alert) => {
            const tone = v2AlertTone(alert.action);
            const node = document.createElement('div');
            node.className = `v2-alert v2-alert--${tone}`;
            const iconWrap = document.createElement('div');
            iconWrap.className = 'v2-alert__icon';
            iconWrap.innerHTML = v2AlertIcon(alert.action);
            const body = document.createElement('div');
            body.className = 'v2-alert__body';
            const strong = document.createElement('strong');
            strong.textContent = alert.title || alert.action || 'Alerta';
            const span = document.createElement('span');
            span.textContent = alert.description || alert.message || '';
            body.append(strong, span);
            node.append(iconWrap, body);
            return node;
        })
    );
}

// Sidebar collapse toggle
if (v2.toggle && v2.shell) {
    const STORAGE_KEY = 'v2-sidebar-collapsed';
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved === 'true') v2.shell.dataset.collapsed = 'true';
    } catch (e) { /* ignore */ }
    v2.toggle.addEventListener('click', () => {
        const collapsed = v2.shell.dataset.collapsed === 'true';
        const next = !collapsed;
        v2.shell.dataset.collapsed = String(next);
        try { localStorage.setItem(STORAGE_KEY, String(next)); } catch (e) { /* ignore */ }
    });
}

// V2 nav item click -> also switch section (existing handler covers portal-section)
v2.navItems.forEach((item) => {
    item.addEventListener('click', (event) => {
        // Stop propagation? The existing handler in admin.js binds to portalNavItems
        // which includes both v1 and v2 items. Calling the existing handler twice
        // is harmless (idempotent toggle), so we just let the original handler run.
        // The default behavior will be triggered by the existing click handler since
        // both nav items have data-portal-section.
    });
});

// V2 refresh button
if (v2.refresh) {
    v2.refresh.addEventListener('click', () => {
        if (typeof loadDashboard === 'function') loadDashboard();
    });
}

// V2 logout button
if (v2.logout) {
    v2.logout.addEventListener('click', () => {
        const logoutBtn = document.getElementById('admin-logout');
        if (logoutBtn) logoutBtn.click();
    });
}

// V2 period select -> triggers reload
if (v2.period) {
    v2.period.addEventListener('change', () => {
        const v1Period = document.getElementById('dashboard-period');
        if (v1Period) {
            v1Period.value = v2.period.value;
            v1Period.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (typeof loadDashboard === 'function') loadDashboard();
    });
}

// Hook into loadDashboard to also render v2
const _origLoadDashboard = typeof loadDashboard === 'function' ? loadDashboard : null;
window.loadDashboard = async function v2WrappedLoadDashboard(...args) {
    if (_origLoadDashboard) {
        await _origLoadDashboard(...args);
    }
    // Render v2 from the most recent fetch
    try {
        const query = new URLSearchParams({
            period: (v2.period && v2.period.value) || 'today',
            low_stock_threshold: '3',
        });
        const session = state && state.session;
        if (!session) return;
        const summary = await api(`/api/admin-portal/dashboard?${query}`, { headers: authHeaders(session) });
        v2RenderOverview(summary);
        if (v2.dashboardStatus) {
            v2.dashboardStatus.textContent = `Dashboard actualizado: ${formatDateTime(summary.generated_at)}.`;
        }
    } catch (error) {
        if (v2.dashboardStatus) {
            v2.dashboardStatus.textContent = normalizeError(error);
        }
    }
};

// V2 tenant switcher sync
function v2SyncTenantSwitcher() {
    if (!v2.tenantSwitcher) return;
    const session = state && state.session;
    const tenants = (session && session.available_tenants) || state.tenants || [];
    const current = session && session.tenant;
    v2.tenantSwitcher.innerHTML = '';
    if (v2.tenantField) v2.tenantField.hidden = tenants.length <= 1;
    tenants.forEach((tenant) => {
        const opt = document.createElement('option');
        opt.value = tenant.slug;
        opt.textContent = tenant.name;
        if (current && current.slug === tenant.slug) opt.selected = true;
        v2.tenantSwitcher.appendChild(opt);
    });
}

if (v2.tenantSwitcher) {
    v2.tenantSwitcher.addEventListener('change', () => {
        const slug = v2.tenantSwitcher.value;
        const v1Switcher = document.getElementById('admin-tenant-switcher');
        if (v1Switcher) {
            v1Switcher.value = slug;
            v1Switcher.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

// V2 search (UI only for now)
if (v2.search) {
    v2.search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            // Placeholder: scroll to first matching module
            const term = v2.search.value.trim().toLowerCase();
            if (!term) return;
            const sections = ['sales', 'inventory', 'customers', 'transfers', 'purchases'];
            const match = sections.find((s) => s.includes(term));
            if (match) {
                const btn = document.querySelector(`.v2-nav-item[data-portal-section="${match}"]`);
                if (btn) btn.click();
            }
        }
    });
}

// Sync tenant switcher after each session restore
const _origSaveSession = typeof saveSession === 'function' ? saveSession : null;
if (_origSaveSession) {
    window.saveSession = function v2WrappedSaveSession(session, tenants) {
        const result = _origSaveSession(session, tenants);
        try { v2SyncTenantSwitcher(); } catch (e) { /* ignore */ }
        return result;
    };
}

// Initial sync
try { v2SyncTenantSwitcher(); } catch (e) { /* ignore */ }

// =====================================================================
// v2 Inventario (Phase 1)
// Re-skin of the existing v1 inventory module with v2 visual language.
// Real data is sourced from the same /api/inventory-center/summary endpoint
// already used by loadInventory(); v2RenderInventory is called from inside
// loadInventory so we don't double-fetch.
// =====================================================================

function v2Money(value) {
    if (value === null || value === undefined || value === '') return '—';
    const num = Number(value);
    if (!Number.isFinite(num)) return '—';
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function v2StatusPillTone(severity) {
    const sev = String(severity || '').toLowerCase();
    if (sev === 'danger' || sev === 'critical' || sev === 'error') return 'error';
    if (sev === 'warning' || sev === 'warn') return 'warning';
    if (sev === 'info') return 'info';
    if (sev === 'success' || sev === 'ok') return 'success';
    return 'warning';
}

function v2InventoryStatusTone(stockStatus) {
    const s = String(stockStatus || '');
    if (s === 'available') return 'success';
    if (s === 'low') return 'warning';
    if (s === 'out') return 'danger';
    return 'info';
}

function v2InventoryStatusLabel(stockStatus) {
    const s = String(stockStatus || '');
    if (s === 'available') return 'Disponible';
    if (s === 'low') return 'Stock bajo';
    if (s === 'out') return 'Sin stock';
    return '—';
}

function v2InventoryTrackingLabel(tracking) {
    return tracking === 'serialized' ? 'Serializado' : 'Por cantidad';
}

// ----- v2 row menu (3-dot dropdown) -----
function v2BuildRowMenu(products) {
    const wrap = document.createElement('div');
    wrap.className = 'v2-row-menu';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'v2-row-menu__trigger';
    trigger.setAttribute('aria-label', 'Acciones');
    trigger.setAttribute('aria-haspopup', 'true');
    trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';
    const panel = document.createElement('div');
    panel.className = 'v2-row-menu__panel';
    panel.setAttribute('role', 'menu');

    const items = [
        { label: 'Ver detalle', icon: '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>', action: 'view' },
        { label: 'Editar', icon: '<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>', action: 'edit' },
        { divider: true },
        { label: products.is_active ? 'Desactivar' : 'Activar', icon: '<svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>', action: 'toggle', danger: !products.is_active },
    ];
    items.forEach((it) => {
        if (it.divider) {
            const div = document.createElement('div');
            div.className = 'v2-row-menu__divider';
            panel.append(div);
            return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `v2-row-menu__item${it.danger ? ' v2-row-menu__item--danger' : ''}`;
        btn.setAttribute('role', 'menuitem');
        btn.dataset.action = it.action;
        btn.innerHTML = `${it.icon}<span>${it.label}</span>`;
        panel.append(btn);
    });

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        // Close any other open menus
        document.querySelectorAll('.v2-row-menu.is-open').forEach((m) => { if (m !== wrap) m.classList.remove('is-open'); });
        wrap.classList.toggle('is-open');
    });
    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('.v2-row-menu__item');
        if (!btn) return;
        e.stopPropagation();
        wrap.classList.remove('is-open');
        const action = btn.dataset.action;
        if (action === 'view') v2OpenInventorySheet(products.id);
        else if (action === 'edit') v2OpenInventorySheet(products.id, 'edit');
        else if (action === 'toggle') v2ToggleProductActive(products);
    });

    wrap.append(trigger, panel);
    return wrap;
}

async function v2ToggleProductActive(product) {
    const session = state.session;
    if (!session) return;
    try {
        setStatus(v2.inventory.status, `${product.is_active ? 'Desactivando' : 'Activando'} producto...`, '');
        await api(`/api/products/${product.id}`, {
            method: 'PATCH',
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify({ is_active: !product.is_active }),
        });
        setStatus(v2.inventory.status, `Producto ${product.is_active ? 'desactivado' : 'activado'}.`, 'success');
        if (typeof loadInventory === 'function') loadInventory();
    } catch (error) {
        setStatus(v2.inventory.status, normalizeError(error), 'error');
    }
}

// Close row menu on outside click
document.addEventListener('click', () => {
    document.querySelectorAll('.v2-row-menu.is-open').forEach((m) => m.classList.remove('is-open'));
});

function v2InventoryRow(product) {
    const tr = document.createElement('tr');
    tr.dataset.productId = product.id;
    tr.tabIndex = 0;
    tr.setAttribute('role', 'button');
    tr.setAttribute('aria-label', `Ver detalle de ${product.name || 'producto'}`);

    const stock = product.stock || {};
    const statusTone = v2InventoryStatusTone(stock.status);
    const statusLabel = v2InventoryStatusLabel(stock.status);

    // Column 1: Producto (name + SKU + tracking label)
    const nameCell = document.createElement('td');
    const nameWrap = document.createElement('div');
    nameWrap.className = 'v2-product-cell';
    const nameStrong = document.createElement('span');
    nameStrong.className = 'v2-product-cell__name';
    nameStrong.textContent = product.name || '—';
    const skuSmall = document.createElement('span');
    skuSmall.className = 'v2-product-cell__sku';
    skuSmall.textContent = product.sku ? product.sku : '—';
    const trackingSmall = document.createElement('span');
    trackingSmall.className = 'v2-product-cell__tracking';
    trackingSmall.textContent = v2InventoryTrackingLabel(product.tracking_type);
    nameWrap.append(nameStrong, skuSmall, trackingSmall);
    nameCell.append(nameWrap);

    // Column 2: Precio (big number + currency)
    const priceCell = document.createElement('td');
    const priceWrap = document.createElement('div');
    priceWrap.className = 'v2-price-cell';
    if (product.base_price === null || product.base_price === undefined) {
        priceWrap.textContent = '—';
        const small = document.createElement('small');
        small.textContent = product.sale_currency || 'USD';
        priceWrap.appendChild(small);
    } else {
        priceWrap.textContent = v2Money(product.base_price);
        const small = document.createElement('small');
        small.textContent = product.sale_currency || 'USD';
        priceWrap.appendChild(small);
    }
    priceCell.append(priceWrap);

    // Column 3: Disponible (colored by status + dañado)
    const availableCell = document.createElement('td');
    const availWrap = document.createElement('div');
    availWrap.className = `v2-stock-cell v2-stock-cell--${stock.status || 'available'}`;
    const availStrong = document.createElement('strong');
    availStrong.textContent = v2Money(stock.available);
    const availSmall = document.createElement('small');
    availSmall.textContent = `Reservado ${v2Money(stock.reserved || 0)} · Dañado ${v2Money(stock.damaged || 0)}`;
    availWrap.append(availStrong, availSmall);
    availableCell.append(availWrap);

    // Column 4: Estado (pill)
    const statusCell = document.createElement('td');
    const statusPill = document.createElement('span');
    statusPill.className = `v2-status-inline v2-status-inline--${statusTone}`;
    statusPill.textContent = statusLabel;
    statusCell.append(statusPill);

    // Column 5: Venta (pill)
    const saleCell = document.createElement('td');
    const salePill = document.createElement('span');
    salePill.className = `v2-status-inline ${product.is_active ? 'v2-status-inline--success' : 'v2-status-inline--neutral'}`;
    salePill.textContent = product.is_active ? 'Activo' : 'Inactivo';
    saleCell.append(salePill);

    // Column 6: 3-dot action menu
    const actionCell = document.createElement('td');
    actionCell.className = 'v2-col-action';
    actionCell.append(v2BuildRowMenu(product));

    tr.append(nameCell, priceCell, availableCell, statusCell, saleCell, actionCell);

    // Row click → open sheet (but ignore clicks on the menu itself)
    tr.addEventListener('click', (e) => {
        if (e.target.closest('.v2-row-menu')) return;
        v2OpenInventorySheet(product.id);
    });
    tr.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            v2OpenInventorySheet(product.id);
        }
    });

    return tr;
}

function v2RenderInventory(summary) {
    if (!summary || !v2.inventory || !v2.inventory.page) return;

    const metrics = summary.metrics || {};
    const products = summary.products || [];
    const pagination = summary.pagination || {};
    const filters = summary.filters || {};
    const alerts = summary.alerts || [];

    // KPIs (compact)
    if (v2.inventory.metricTotal) v2.inventory.metricTotal.textContent = v2Money(metrics.total_products || 0);
    if (v2.inventory.metricTotalHint) {
        const ser = Number(metrics.serialized_products || 0);
        const qty = Number(metrics.quantity_products || 0);
        v2.inventory.metricTotalHint.textContent = `${ser} serializados · ${qty} por cantidad`;
    }
    if (v2.inventory.metricLow) v2.inventory.metricLow.textContent = v2Money(metrics.low_stock_count || 0);
    if (v2.inventory.metricLowHint) {
        const threshold = filters.low_stock_threshold || 3;
        v2.inventory.metricLowHint.textContent = `Mínimo operativo: ${threshold}`;
    }
    if (v2.inventory.metricOut) v2.inventory.metricOut.textContent = v2Money(metrics.without_stock_count || 0);
    if (v2.inventory.metricAvailable) v2.inventory.metricAvailable.textContent = v2Money(metrics.available_quantity || 0);
    if (v2.inventory.metricReserved) {
        v2.inventory.metricReserved.textContent = `${v2Money(metrics.reserved_quantity || 0)} reservadas · ${v2Money(metrics.damaged_quantity || 0)} dañadas`;
    }

    if (v2.inventory.period) {
        v2.inventory.period.textContent = `${pagination.total || 0} productos en vista.`;
    }

    if (v2.inventory.activeStatus) {
        const activeStatus = (elements.inventoryActive && elements.inventoryActive.value) || 'active';
        const map = { active: 'Solo activos', inactive: 'Solo inactivos', all: 'Todos' };
        const tone = activeStatus === 'inactive' ? 'warning' : 'info';
        v2.inventory.activeStatus.textContent = map[activeStatus] || 'Activos';
        v2.inventory.activeStatus.className = `v2-status-pill v2-status-pill--${tone}`;
    }

    v2.inventory.quickStatus?.querySelectorAll('[data-v2-inv-active-filter]').forEach((btn) => {
        const activeStatus = (elements.inventoryActive && elements.inventoryActive.value) || 'active';
        btn.classList.toggle('is-active', btn.dataset.v2InvActiveFilter === activeStatus);
    });

    if (v2.inventory.filterSummary) {
        const parts = [];
        const search = (elements.inventorySearch && elements.inventorySearch.value || '').trim();
        const tracking = (elements.inventoryTracking && elements.inventoryTracking.value) || '';
        const stock = (elements.inventoryStock && elements.inventoryStock.value) || 'all';
        if (search) parts.push(`"${search}"`);
        if (tracking) parts.push(v2InventoryTrackingLabel(tracking).toLowerCase());
        if (stock !== 'all') parts.push(`stock: ${v2InventoryStatusLabel(stock).toLowerCase()}`);
        v2.inventory.filterSummary.textContent = parts.length
            ? `${pagination.total || 0} producto(s) con ${parts.join(' · ')}.`
            : `${pagination.total || 0} producto(s) en vista completa.`;
    }

    // Table (full width)
    if (v2.inventory.tbody) {
        if (!products.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.innerHTML = '<div style="padding:48px 12px;text-align:center;"><strong style="display:block;color:var(--v2-ink-strong);font-size:15px;margin-bottom:6px;">Sin productos</strong><small style="color:var(--v2-muted);">No hay productos con los filtros seleccionados.</small></div>';
            tr.append(td);
            v2.inventory.tbody.replaceChildren(tr);
        } else {
            v2.inventory.tbody.replaceChildren(...products.map(v2InventoryRow));
        }
    }

    if (v2.inventory.count) {
        v2.inventory.count.textContent = pagination.total === 0
            ? 'Sin productos para mostrar.'
            : `Mostrando ${pagination.from}-${pagination.to} de ${pagination.total}.`;
    }
    if (v2.inventory.prev) v2.inventory.prev.disabled = !pagination.has_previous;
    if (v2.inventory.next) v2.inventory.next.disabled = !pagination.has_next;

    // Alert strip (compact, single row)
    v2RenderInventoryAlertStrip(alerts);

    if (v2.inventory.status) {
        setStatus(v2.inventory.status, `Catálogo actualizado · ${products.length} producto(s) en vista.`, 'success');
    }
}

function v2RenderInventoryAlertStrip(alerts) {
    const strip = v2.inventory.alertStrip;
    const list = v2.inventory.alertStripList;
    if (!strip || !list) return;
    if (!alerts.length) {
        strip.hidden = true;
        list.replaceChildren();
        return;
    }
    strip.hidden = false;
    list.replaceChildren(...alerts.map((alert) => {
        const tone = String(alert.severity || '').toLowerCase();
        const toneClass = tone === 'danger' ? 'v2-alert-chip--danger'
            : tone === 'info' ? 'v2-alert-chip--info'
            : 'v2-alert-chip--warning';
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = `v2-alert-chip ${toneClass}`;
        chip.title = alert.message || alert.title || '';
        chip.textContent = `${alert.count} · ${alert.title || alert.type}`;
        chip.addEventListener('click', () => {
            // Filter the catalog to show the products of this alert
            if (!v2.inventory) return;
            if (alert.type === 'low_stock' || alert.type === 'without_stock') {
                v2.inventory.stock.value = alert.type === 'low_stock' ? 'low' : 'out';
                v2.inventory.apply?.click();
            } else {
                setStatus(v2.inventory.status, alert.message || 'Alerta operativa', 'info');
            }
        });
        return chip;
    }));
}

// ----- v2 sheet (side panel for product detail) -----
// ----- v2 sheet (side panel for product detail/edit/create) -----
const v2SheetState = { productId: null, mode: 'view', detail: null, inflight: null };

function v2OpenInventorySheet(productId, mode = 'view') {
    const session = state.session;
    const sheet = v2.inventory.sheet;
    if (!session || !sheet) return;

    v2SheetState.productId = productId;
    v2SheetState.mode = mode;

    v2ActivateInventorySheetTab('general');
    sheet.hidden = false;
    void sheet.offsetWidth;
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');

    if (mode === 'create') {
        v2PrepareCreateSheet();
        return;
    }

    // View / Edit on existing product: load detail
    if (v2.inventory.sheetTitle) v2.inventory.sheetTitle.textContent = 'Cargando...';
    if (v2.inventory.sheetSubtitle) v2.inventory.sheetSubtitle.textContent = 'Producto #' + productId;
    if (v2.inventory.sheetBadge) v2.inventory.sheetBadge.textContent = mode === 'edit' ? 'Editar' : 'Detalle';

    v2SheetState.inflight = v2FetchProductDetail(productId);
    v2SheetState.inflight.then((payload) => {
        // Only apply if the user is still on this product (not closed/reset)
        if (v2SheetState.productId !== productId) return;
        v2SheetState.detail = payload.detail && !payload.detail.__error ? payload.detail : null;
        v2RenderInventorySheet(payload);
        if (mode === 'edit') {
            v2StartEditExistingProduct();
        }
    }).catch((error) => {
        if (v2SheetState.productId !== productId) return;
        if (v2.inventory.sheetTitle) v2.inventory.sheetTitle.textContent = 'Error';
        if (v2.inventory.sheetSubtitle) v2.inventory.sheetSubtitle.textContent = normalizeError(error);
    }).finally(() => {
        if (v2SheetState.productId === productId) v2SheetState.inflight = null;
    });
}

function v2PrepareCreateSheet() {
    v2SheetState.detail = null;
    v2SheetState.productId = null;

    if (v2.inventory.sheetTitle) v2.inventory.sheetTitle.textContent = 'Nuevo producto';
    if (v2.inventory.sheetSubtitle) v2.inventory.sheetSubtitle.textContent = 'Completa los datos básicos para crear el producto.';
    if (v2.inventory.sheetBadge) v2.inventory.sheetBadge.textContent = 'Crear';

    // Clear view-mode fields
    ['sheetName', 'sheetSku', 'sheetTracking', 'sheetCurrency', 'sheetBasePrice', 'sheetActive'].forEach((key) => {
        if (v2.inventory[key]) v2.inventory[key].textContent = '—';
    });

    // Clear detail panes
    if (v2.inventory.sheetStock) v2.inventory.sheetStock.innerHTML = '<p class="v2-sheet__empty">Sin stock todavía. Crea el producto y registra movimientos después.</p>';
    if (v2.inventory.sheetStockTotal) v2.inventory.sheetStockTotal.textContent = '0 disp.';
    if (v2.inventory.sheetPrices) v2.inventory.sheetPrices.innerHTML = '<p class="v2-sheet__empty">Asigna listas de precio después de crear el producto.</p>';
    if (v2.inventory.sheetPriceCount) v2.inventory.sheetPriceCount.textContent = '0 listas';
    if (v2.inventory.sheetHistory) v2.inventory.sheetHistory.innerHTML = '<p class="v2-sheet__empty">Sin movimientos todavía.</p>';
    if (v2.inventory.sheetChangeCount) v2.inventory.sheetChangeCount.textContent = '0 cambios';

    // Populate edit form with empty defaults
    if (v2.inventory.sheetEditName) v2.inventory.sheetEditName.value = '';
    if (v2.inventory.sheetEditSku) v2.inventory.sheetEditSku.value = '';
    if (v2.inventory.sheetEditTracking) v2.inventory.sheetEditTracking.value = 'quantity';
    if (v2.inventory.sheetEditCurrency) v2.inventory.sheetEditCurrency.value = 'USD';
    if (v2.inventory.sheetEditPrice) v2.inventory.sheetEditPrice.value = '';
    if (v2.inventory.sheetEditActive) v2.inventory.sheetEditActive.value = '1';
    v2ClearSheetEditError();

    // Show edit form, hide view
    v2EnterEditMode();
    if (v2.inventory.sheetSaveLabel) v2.inventory.sheetSaveLabel.textContent = 'Crear producto';
    if (v2.inventory.sheetEditName) v2.inventory.sheetEditName.focus();
}

function v2CloseInventorySheet() {
    const sheet = v2.inventory.sheet;
    if (!sheet) return;
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    setTimeout(() => { sheet.hidden = true; }, 280);
    v2SheetState.productId = null;
    v2SheetState.mode = 'view';
    v2SheetState.detail = null;
    v2SheetState.inflight = null;
}

function v2ActivateInventorySheetTab(tabName) {
    v2.inventory.sheetTabs?.querySelectorAll('.v2-sheet__tab').forEach((tab) => {
        const isActive = tab.dataset.v2SheetTab === tabName;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('[data-v2-sheet-pane]').forEach((pane) => {
        pane.hidden = pane.dataset.v2SheetPane !== tabName;
    });
}

function v2EnterEditMode() {
    v2SheetState.mode = v2SheetState.productId ? 'edit' : 'create';
    if (v2.inventory.sheetActionsView) v2.inventory.sheetActionsView.hidden = true;
    if (v2.inventory.sheetActionsEdit) v2.inventory.sheetActionsEdit.hidden = false;
    if (v2.inventory.sheetSaveLabel) {
        v2.inventory.sheetSaveLabel.textContent = v2SheetState.mode === 'create' ? 'Crear producto' : 'Guardar cambios';
    }
    document.querySelectorAll('[data-v2-sheet-mode]').forEach((el) => {
        el.hidden = el.dataset.v2SheetMode !== 'edit';
    });
    // Only the General tab is editable; hide others visually but keep them
    // accessible so the user can switch and view current state.
    v2ActivateInventorySheetTab('general');
}

function v2EnterViewMode() {
    v2SheetState.mode = 'view';
    if (v2.inventory.sheetActionsView) v2.inventory.sheetActionsView.hidden = false;
    if (v2.inventory.sheetActionsEdit) v2.inventory.sheetActionsEdit.hidden = true;
    document.querySelectorAll('[data-v2-sheet-mode]').forEach((el) => {
        el.hidden = el.dataset.v2SheetMode !== 'view';
    });
    v2ClearSheetEditError();
}

function v2ClearSheetEditError() {
    if (!v2.inventory.sheetEditError) return;
    v2.inventory.sheetEditError.hidden = true;
    v2.inventory.sheetEditError.replaceChildren();
}

function v2ShowSheetEditError(messageOrList) {
    if (!v2.inventory.sheetEditError) return;
    v2.inventory.sheetEditError.hidden = false;
    v2.inventory.sheetEditError.replaceChildren();
    if (typeof messageOrList === 'string') {
        const span = document.createElement('span');
        span.textContent = messageOrList;
        v2.inventory.sheetEditError.append(span);
    } else if (Array.isArray(messageOrList) && messageOrList.length) {
        const span = document.createElement('span');
        span.textContent = 'No pudimos guardar los cambios:';
        const ul = document.createElement('ul');
        messageOrList.forEach((m) => {
            const li = document.createElement('li');
            li.textContent = m;
            ul.append(li);
        });
        v2.inventory.sheetEditError.append(span, ul);
    } else {
        v2.inventory.sheetEditError.hidden = true;
    }
}

function v2CollectSheetForm() {
    return {
        name: (v2.inventory.sheetEditName?.value || '').trim(),
        sku: (v2.inventory.sheetEditSku?.value || '').trim() || null,
        tracking_type: v2.inventory.sheetEditTracking?.value || 'quantity',
        sale_currency: v2.inventory.sheetEditCurrency?.value || 'USD',
        base_price: v2.inventory.sheetEditPrice?.value ? Number(v2.inventory.sheetEditPrice.value) : null,
        is_active: v2.inventory.sheetEditActive?.value === '1',
    };
}

async function v2SaveInventorySheet() {
    const session = state.session;
    if (!session) return;
    const isCreate = v2SheetState.mode === 'create' || !v2SheetState.productId;
    const data = v2CollectSheetForm();
    if (!data.name) {
        v2ShowSheetEditError('El nombre del producto es obligatorio.');
        if (v2.inventory.sheetEditName) v2.inventory.sheetEditName.focus();
        return;
    }
    setButtonLoading(v2.inventory.sheetSave, true, isCreate ? 'Creando...' : 'Guardando...');
    v2ClearSheetEditError();
    try {
        const url = isCreate ? '/api/products' : `/api/products/${v2SheetState.productId}`;
        const method = isCreate ? 'POST' : 'PATCH';
        const response = await api(url, {
            method,
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        // api() returns the inner data, so response is the product resource
        const product = response?.data || response;
        const productId = product?.id || v2SheetState.productId;
        setStatus(v2.inventory.status, isCreate ? `Producto "${data.name}" creado.` : `Producto "${data.name}" actualizado.`, 'success');
        // Refresh catalog and re-open sheet in view mode for the saved product
        if (typeof loadInventory === 'function') await loadInventory();
        if (isCreate) {
            // Close sheet, the new product is now in the catalog with full data
            v2CloseInventorySheet();
        } else {
            v2SheetState.productId = productId;
            v2SheetState.mode = 'view';
            const payload = await v2FetchProductDetail(productId);
            v2SheetState.detail = payload.detail && !payload.detail.__error ? payload.detail : null;
            v2RenderInventorySheet(payload);
            v2EnterViewMode();
        }
    } catch (error) {
        // Try to extract Laravel validation errors from response
        const errors = error?.errors || (error?.response && error.response.errors);
        if (Array.isArray(errors)) {
            v2ShowSheetEditError(errors.map((e) => e?.message || String(e)).filter(Boolean));
        } else if (errors && typeof errors === 'object') {
            v2ShowSheetEditError(Object.values(errors).flat().filter(Boolean));
        } else {
            v2ShowSheetEditError(normalizeError(error));
        }
    } finally {
        setButtonLoading(v2.inventory.sheetSave, false);
    }
}

function v2StartEditExistingProduct() {
    // If the inflight fetch hasn't resolved yet, wait for it before populating
    if (v2SheetState.inflight) {
        v2SheetState.inflight.then(() => v2StartEditExistingProduct());
        return;
    }
    if (!v2SheetState.detail) {
        // Last-resort: trigger a fresh fetch for the current product
        const productId = v2SheetState.productId;
        if (!productId) return;
        v2SheetState.inflight = v2FetchProductDetail(productId);
        v2SheetState.inflight.then((payload) => {
            if (v2SheetState.productId !== productId) return;
            v2SheetState.detail = payload.detail && !payload.detail.__error ? payload.detail : null;
            v2StartEditExistingProduct();
        });
        return;
    }
    // detail is the inner {product, stock, ...} object; we edit the product
    const d = v2SheetState.detail.product || v2SheetState.detail;
    if (v2.inventory.sheetEditName) v2.inventory.sheetEditName.value = d.name || '';
    if (v2.inventory.sheetEditSku) v2.inventory.sheetEditSku.value = d.sku || '';
    if (v2.inventory.sheetEditTracking) v2.inventory.sheetEditTracking.value = d.tracking_type || 'quantity';
    if (v2.inventory.sheetEditCurrency) v2.inventory.sheetEditCurrency.value = d.sale_currency || 'USD';
    if (v2.inventory.sheetEditPrice) v2.inventory.sheetEditPrice.value = d.base_price ?? '';
    if (v2.inventory.sheetEditActive) v2.inventory.sheetEditActive.value = d.is_active ? '1' : '0';
    v2ClearSheetEditError();
    v2EnterEditMode();
    // Focus first field for fast keyboard editing
    if (v2.inventory.sheetEditName) v2.inventory.sheetEditName.focus();
}

async function v2FetchProductDetail(productId) {
    const session = state.session;
    const headers = authHeaders(session);
    const [detail, stockByWarehouse, movements, audits, prices] = await Promise.all([
        api(`/api/inventory-center/products/${productId}`, { headers }).catch((e) => ({ __error: e })),
        api(`/api/inventory-center/products/${productId}/stock-by-warehouse`, { headers }).catch((e) => ({ __error: e })),
        api(`/api/inventory-center/products/${productId}/movements?limit=20`, { headers }).catch((e) => ({ __error: e })),
        api(`/api/inventory-center/products/${productId}/audits?limit=20`, { headers }).catch((e) => ({ __error: e })),
        api(`/api/products/${productId}/prices`, { headers }).catch((e) => ({ __error: e })),
    ]);
    return { detail, stockByWarehouse, movements, audits, prices };
}

function v2RenderInventorySheet(payload) {
    const wrapper = payload.detail && !payload.detail.__error ? payload.detail : null;
    // The detail endpoint returns { data: { product, stock, serials, recent_movements, recent_audits } }
    // payload.detail is the inner object; payload.detail.product has the product fields.
    const detail = wrapper?.product || null;
    if (!detail) {
        if (v2.inventory.sheetTitle) v2.inventory.sheetTitle.textContent = 'Producto no disponible';
        if (v2.inventory.sheetSubtitle) v2.inventory.sheetSubtitle.textContent = 'Intenta recargar o vuelve al catálogo.';
        return;
    }
    if (v2.inventory.sheetBadge) v2.inventory.sheetBadge.textContent = detail.is_active ? 'Activo' : 'Inactivo';
    if (v2.inventory.sheetTitle) v2.inventory.sheetTitle.textContent = detail.name || 'Producto';
    if (v2.inventory.sheetSubtitle) {
        const parts = [];
        if (detail.sku) parts.push(`SKU ${detail.sku}`);
        parts.push(v2InventoryTrackingLabel(detail.tracking_type));
        v2.inventory.sheetSubtitle.textContent = parts.join(' · ');
    }

    // General tab
    if (v2.inventory.sheetName) v2.inventory.sheetName.textContent = detail.name || '—';
    if (v2.inventory.sheetSku) v2.inventory.sheetSku.textContent = detail.sku || '—';
    if (v2.inventory.sheetTracking) v2.inventory.sheetTracking.textContent = v2InventoryTrackingLabel(detail.tracking_type);
    if (v2.inventory.sheetCurrency) v2.inventory.sheetCurrency.textContent = detail.sale_currency || '—';
    if (v2.inventory.sheetBasePrice) v2.inventory.sheetBasePrice.textContent = detail.base_price !== null && detail.base_price !== undefined ? `${v2Money(detail.base_price)} ${detail.sale_currency || 'USD'}` : '—';
    if (v2.inventory.sheetActive) {
        v2.inventory.sheetActive.textContent = detail.is_active ? 'Activo' : 'Inactivo';
        v2.inventory.sheetActive.style.color = detail.is_active ? 'var(--v2-green)' : 'var(--v2-muted)';
    }

    // Stock tab — prefer bundled detail.stock.by_warehouse; fall back to parallel /stock-by-warehouse
    const stockList = v2.inventory.sheetStock;
    const stockObj = wrapper?.stock || {};
    const sw = (Array.isArray(stockObj.by_warehouse) && stockObj.by_warehouse.length)
        ? stockObj.by_warehouse
        : (payload.stockByWarehouse && !payload.stockByWarehouse.__error
            ? (payload.stockByWarehouse.data || payload.stockByWarehouse)
            : (detail.stock_by_warehouse || []));
    if (stockList) {
        if (!Array.isArray(sw) || !sw.length) {
            stockList.innerHTML = '<p class="v2-sheet__empty">Sin stock por almacén.</p>';
        } else {
            stockList.replaceChildren(...sw.map((w) => {
                const row = document.createElement('div');
                row.className = 'v2-sheet__list-row';
                const left = document.createElement('div');
                left.className = 'v2-sheet__list-row__name';
                const strong = document.createElement('strong');
                strong.textContent = w.warehouse_name || w.name || `Almacén #${w.warehouse_id}`;
                const small = document.createElement('small');
                small.textContent = w.warehouse_code || w.code || '';
                left.append(strong, small);
                const right = document.createElement('div');
                right.className = 'v2-sheet__list-row__value';
                right.textContent = v2Money(w.available ?? w.quantity_available ?? 0);
                const rightSmall = document.createElement('small');
                rightSmall.textContent = `Reservado ${v2Money(w.reserved ?? w.quantity_reserved ?? 0)}`;
                right.append(rightSmall);
                row.append(left, right);
                return row;
            }));
        }
    }
    if (v2.inventory.sheetStockTotal) {
        const totals = stockObj.totals || { available: detail.stock?.available || 0 };
        v2.inventory.sheetStockTotal.textContent = `${v2Money(totals.available || 0)} disp.`;
    }

    // Prices tab — from separate /prices endpoint
    const pricesList = v2.inventory.sheetPrices;
    if (pricesList) {
        const pricesResp = payload.prices && !payload.prices.__error
            ? (payload.prices.data || payload.prices)
            : (detail.prices || detail.price_lists || []);
        const prices = Array.isArray(pricesResp) ? pricesResp : (pricesResp?.data || []);
        if (!prices.length) {
            pricesList.innerHTML = '<p class="v2-sheet__empty">Sin precios por lista.</p>';
        } else {
            pricesList.replaceChildren(...prices.map((p) => {
                const row = document.createElement('div');
                row.className = 'v2-sheet__list-row';
                const left = document.createElement('div');
                left.className = 'v2-sheet__list-row__name';
                const strong = document.createElement('strong');
                // Price list resources return { id, price_list: {id, name, code}, price, currency, is_active, ... }
                const listName = p.price_list?.name || p.list_name || p.name || `Lista #${p.price_list_id}`;
                const listCode = p.price_list?.code || p.price_list_code || '';
                strong.textContent = listName;
                const small = document.createElement('small');
                small.textContent = `${p.currency || 'USD'}${listCode ? ' · ' + listCode : ''}`;
                left.append(strong, small);
                const right = document.createElement('div');
                right.className = 'v2-sheet__list-row__value';
                right.textContent = v2Money(p.price ?? p.amount ?? 0);
                row.append(left, right);
                return row;
            }));
        }
    }
    if (v2.inventory.sheetPriceCount) {
        const pricesResp = payload.prices && !payload.prices.__error
            ? (payload.prices.data || payload.prices)
            : (detail.prices || []);
        const prices = Array.isArray(pricesResp) ? pricesResp : (pricesResp?.data || []);
        v2.inventory.sheetPriceCount.textContent = `${prices.length} listas`;
    }

    // History tab — from bundled recent_movements or fallback to parallel /movements
    const histList = v2.inventory.sheetHistory;
    if (histList) {
        const movements = (Array.isArray(wrapper?.recent_movements) && wrapper.recent_movements.length)
            ? wrapper.recent_movements
            : (payload.movements && !payload.movements.__error
                ? (payload.movements.data?.data || payload.movements.data || payload.movements)
                : (detail.recent_movements || []));
        if (!Array.isArray(movements) || !movements.length) {
            histList.innerHTML = '<p class="v2-sheet__empty">Sin movimientos recientes.</p>';
        } else {
            histList.replaceChildren(...movements.slice(0, 12).map((c) => {
                const row = document.createElement('div');
                row.className = 'v2-sheet__list-row';
                const left = document.createElement('div');
                left.className = 'v2-sheet__list-row__name';
                const strong = document.createElement('strong');
                strong.textContent = c.type || c.action || c.event || 'Movimiento';
                const small = document.createElement('small');
                small.textContent = c.created_at || c.occurred_at || c.changed_at || '';
                left.append(strong, small);
                const right = document.createElement('div');
                right.className = 'v2-sheet__list-row__value';
                right.style.textAlign = 'right';
                if (c.quantity !== undefined) {
                    right.textContent = v2Money(c.quantity);
                    const rsmall = document.createElement('small');
                    rsmall.textContent = c.created_by_name || c.user_name || c.user || '';
                    right.append(rsmall);
                } else {
                    right.innerHTML = `<small>${escapeHtml(c.created_by_name || c.user_name || c.user || '')}</small>`;
                }
                row.append(left, right);
                return row;
            }));
        }
    }
    if (v2.inventory.sheetChangeCount) {
        const movements = (Array.isArray(wrapper?.recent_movements) && wrapper.recent_movements.length)
            ? wrapper.recent_movements
            : (detail.recent_movements || []);
        v2.inventory.sheetChangeCount.textContent = `${movements.length} movimientos`;
    }
}

// ----- v2 inventory event wiring -----
function syncV2InventoryToV1() {
    if (!v2.inventory) return;
    if (elements.inventorySearch) elements.inventorySearch.value = v2.inventory.search?.value || '';
    if (elements.inventoryTracking) elements.inventoryTracking.value = v2.inventory.tracking?.value || '';
    if (elements.inventoryStock) elements.inventoryStock.value = v2.inventory.stock?.value || 'all';
    const activeChip = v2.inventory.quickStatus?.querySelector('.is-active');
    if (activeChip && elements.inventoryActive) {
        elements.inventoryActive.value = activeChip.dataset.v2InvActiveFilter || 'active';
    }
}

v2.inventory?.apply?.addEventListener('click', () => {
    syncV2InventoryToV1();
    if (typeof loadInventory === 'function') loadInventory(1);
});

v2.inventory?.refreshBtn?.addEventListener('click', () => {
    syncV2InventoryToV1();
    if (typeof loadInventory === 'function') loadInventory();
});

v2.inventory?.exportBtn?.addEventListener('click', () => {
    syncV2InventoryToV1();
    const session = state.session;
    if (!session) return;
    const params = new URLSearchParams();
    if (elements.inventorySearch?.value) params.set('search', elements.inventorySearch.value);
    if (elements.inventoryTracking?.value) params.set('tracking_type', elements.inventoryTracking.value);
    if (elements.inventoryStock?.value && elements.inventoryStock.value !== 'all') {
        params.set('stock_status', elements.inventoryStock.value);
    }
    params.set('low_stock_threshold', '3');
    const url = `/api/inventory-center/export?${params}`;
    window.open(url, '_blank');
});

v2.inventory?.search?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        v2.inventory.apply?.click();
    }
});

v2.inventory?.prev?.addEventListener('click', () => {
    if (typeof loadInventory === 'function') loadInventory(Math.max(state.inventory.page - 1, 1));
});

v2.inventory?.next?.addEventListener('click', () => {
    if (typeof loadInventory === 'function') loadInventory(state.inventory.page + 1);
});

v2.inventory?.quickStatus?.querySelectorAll('[data-v2-inv-active-filter]')?.forEach((btn) => {
    btn.addEventListener('click', () => {
        v2.inventory.quickStatus.querySelectorAll('[data-v2-inv-active-filter]').forEach((b) => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        v2.inventory.apply?.click();
    });
});

v2.inventory?.alertStripClose?.addEventListener('click', () => {
    if (v2.inventory.alertStrip) v2.inventory.alertStrip.hidden = true;
});

v2.inventory?.sheetClose?.addEventListener('click', v2CloseInventorySheet);
v2.inventory?.sheetCloseEdit?.addEventListener('click', v2CloseInventorySheet);
v2.inventory?.sheetBackdrop?.addEventListener('click', v2CloseInventorySheet);
v2.inventory?.sheetTabs?.querySelectorAll('.v2-sheet__tab')?.forEach((tab) => {
    tab.addEventListener('click', () => v2ActivateInventorySheetTab(tab.dataset.v2SheetTab));
});
v2.inventory?.sheetEdit?.addEventListener('click', v2StartEditExistingProduct);
v2.inventory?.sheetCancel?.addEventListener('click', v2EnterViewMode);
v2.inventory?.sheetSave?.addEventListener('click', v2SaveInventorySheet);
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && v2.inventory.sheet && !v2.inventory.sheet.hidden && v2.inventory.sheet.classList.contains('is-open')) {
        v2CloseInventorySheet();
    }
    // Ctrl/Cmd+S to save when in edit mode
    if ((e.ctrlKey || e.metaKey) && e.key === 's' && v2SheetState.mode !== 'view' && v2.inventory.sheet && !v2.inventory.sheet.hidden) {
        e.preventDefault();
        v2SaveInventorySheet();
    }
});

v2.inventory?.newBtn?.addEventListener('click', () => {
    v2OpenInventorySheet(null, 'create');
});

// =====================================================================
// v2 Tasas (Phase 2)
// Same 4 patterns as Inventario: alert strip, full-width tables with
// 3-dot row menu, side sheets for detail/edit/create, compact KPI row.
// =====================================================================

function v2RelativeDate(iso) {
    if (!iso) return '';
    const ms = Date.now() - new Date(iso).getTime();
    if (Number.isNaN(ms)) return '';
    const days = Math.floor(ms / 86400000);
    if (days <= 0) return 'hoy';
    if (days === 1) return 'ayer';
    if (days < 7) return `hace ${days} días`;
    if (days < 30) return `hace ${Math.floor(days / 7)} sem`;
    if (days < 365) return `hace ${Math.floor(days / 30)} mes${Math.floor(days / 30) === 1 ? '' : 'es'}`;
    return `hace ${Math.floor(days / 365)} año${Math.floor(days / 365) === 1 ? '' : 's'}`;
}

function v2DateOnly(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('es-VE', { day: '2-digit', month: 'short', year: 'numeric' });
}

function v2DateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('es-VE', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function v2RateTrend(current, previous) {
    if (!current || !previous) return null;
    const diff = current - previous;
    if (Math.abs(diff) < 0.0001) return { cls: 'v2-rate-trend--flat', symbol: '·', text: 'sin cambio' };
    if (diff > 0) return { cls: 'v2-rate-trend--up', symbol: '▲', text: `+${diff.toFixed(4)}` };
    return { cls: 'v2-rate-trend--down', symbol: '▼', text: `${diff.toFixed(4)}` };
}

function v2BuildRateTypeRow(type, state) {
    const tr = document.createElement('tr');
    tr.dataset.typeId = type.id;
    tr.tabIndex = 0;
    tr.setAttribute('role', 'button');
    tr.setAttribute('aria-label', `Ver detalle del tipo ${type.code}`);

    // Column 1: Tipo (code + name + "Predeterminada" label)
    const nameCell = document.createElement('td');
    const nameWrap = document.createElement('div');
    nameWrap.className = 'v2-rate-cell';
    const codeSpan = document.createElement('span');
    codeSpan.className = 'v2-rate-cell__code';
    codeSpan.textContent = type.code;
    const nameSpan = document.createElement('span');
    nameSpan.className = 'v2-rate-cell__name';
    if (type.is_default) {
        const strong = document.createElement('strong');
        strong.textContent = type.name;
        nameSpan.append(strong, document.createTextNode(' · Predeterminada'));
    } else {
        nameSpan.textContent = type.name;
    }
    nameWrap.append(codeSpan, nameSpan);
    nameCell.append(nameWrap);

    // Column 2: Predeterminada pill
    const defaultCell = document.createElement('td');
    const defaultPill = document.createElement('span');
    defaultPill.className = `v2-status-inline ${type.is_default ? 'v2-status-inline--success' : 'v2-status-inline--neutral'}`;
    defaultPill.textContent = type.is_default ? 'Sí' : 'No';
    defaultCell.append(defaultPill);

    // Column 3: Estado pill
    const activeCell = document.createElement('td');
    const activePill = document.createElement('span');
    activePill.className = `v2-status-inline ${type.is_active ? 'v2-status-inline--success' : 'v2-status-inline--warning'}`;
    activePill.textContent = type.is_active ? 'Activo' : 'Inactivo';
    activeCell.append(activePill);

    // Column 4: count of rates for this type
    const countCell = document.createElement('td');
    countCell.style.textAlign = 'right';
    const myRates = state.rates.filter((r) => r.exchange_rate_type_id === type.id);
    countCell.textContent = String(myRates.length);

    // Column 5: última tasa (effective_at)
    const dateCell = document.createElement('td');
    const lastRate = myRates[0]; // rates are already ordered latest first
    if (lastRate) {
        const dateSpan = document.createElement('div');
        dateSpan.className = 'v2-rate-effective';
        const dateStrong = document.createElement('span');
        dateStrong.className = 'v2-rate-effective__date';
        dateStrong.textContent = v2DateOnly(lastRate.effective_at);
        const ageSmall = document.createElement('span');
        ageSmall.className = 'v2-rate-effective__age';
        ageSmall.textContent = v2RelativeDate(lastRate.effective_at);
        dateSpan.append(dateStrong, ageSmall);
        dateCell.append(dateSpan);
    } else {
        dateCell.innerHTML = '<span style="color:var(--v2-muted);font-size:12px;">Sin tasas</span>';
    }

    // Column 6: 3-dot menu
    const actionCell = document.createElement('td');
    actionCell.className = 'v2-col-action';
    actionCell.append(v2BuildRateTypeMenu(type, state));

    tr.append(nameCell, defaultCell, activeCell, countCell, dateCell, actionCell);

    tr.addEventListener('click', (e) => {
        if (e.target.closest('.v2-row-menu')) return;
        v2OpenRateTypeSheet(type.id, 'view');
    });
    tr.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            v2OpenRateTypeSheet(type.id, 'view');
        }
    });

    return tr;
}

function v2BuildRateTypeMenu(type, state) {
    const wrap = document.createElement('div');
    wrap.className = 'v2-row-menu';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'v2-row-menu__trigger';
    trigger.setAttribute('aria-label', `Acciones para ${type.code}`);
    trigger.setAttribute('aria-haspopup', 'true');
    trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';
    const panel = document.createElement('div');
    panel.className = 'v2-row-menu__panel';
    panel.setAttribute('role', 'menu');

    const items = [
        { label: 'Ver detalle', icon: '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>', action: 'view' },
        { label: 'Editar', icon: '<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>', action: 'edit' },
    ];
    if (!type.is_default) {
        items.push({ label: 'Marcar como predeterminada', icon: '<svg viewBox="0 0 24 24"><polygon points="12 2 15 8.5 22 9.3 17 14 18.2 21 12 17.8 5.8 21 7 14 2 9.3 9 8.5 12 2"/></svg>', action: 'default' });
    }
    items.push({ divider: true });
    if (type.is_active) {
        items.push({ label: 'Desactivar tipo', icon: '<svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>', action: 'toggle', danger: true });
    } else {
        items.push({ label: 'Activar tipo', icon: '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>', action: 'toggle' });
    }

    items.forEach((it) => {
        if (it.divider) {
            const div = document.createElement('div');
            div.className = 'v2-row-menu__divider';
            panel.append(div);
            return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `v2-row-menu__item${it.danger ? ' v2-row-menu__item--danger' : ''}`;
        btn.setAttribute('role', 'menuitem');
        btn.dataset.action = it.action;
        btn.innerHTML = `${it.icon}<span>${it.label}</span>`;
        panel.append(btn);
    });

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.v2-row-menu.is-open').forEach((m) => { if (m !== wrap) m.classList.remove('is-open'); });
        wrap.classList.toggle('is-open');
    });
    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('.v2-row-menu__item');
        if (!btn) return;
        e.stopPropagation();
        wrap.classList.remove('is-open');
        v2RunRateTypeAction(type, btn.dataset.action, state);
    });

    wrap.append(trigger, panel);
    return wrap;
}

async function v2RunRateTypeAction(type, action, state) {
    if (action === 'view') {
        v2OpenRateTypeSheet(type.id, 'view');
    } else if (action === 'edit') {
        v2OpenRateTypeSheet(type.id, 'edit');
    } else if (action === 'default') {
        await v2SetRateTypeDefault(type);
    } else if (action === 'toggle') {
        await v2ToggleRateTypeActive(type);
    }
}

async function v2SetRateTypeDefault(type) {
    const session = state.session;
    if (!session) return;
    setStatus(v2.rates.status, `Marcando ${type.code} como predeterminado…`, '');
    try {
        await api(`/api/currency/rate-types/${type.id}`, {
            method: 'PATCH',
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify({ is_default: true }),
        });
        setStatus(v2.rates.status, `${type.code} ahora es la tasa predeterminada.`, 'success');
        if (typeof loadRates === 'function') await loadRates();
    } catch (error) {
        setStatus(v2.rates.status, normalizeError(error), 'error');
    }
}

async function v2ToggleRateTypeActive(type) {
    const session = state.session;
    if (!session) return;
    const next = !type.is_active;
    setStatus(v2.rates.status, `${next ? 'Activando' : 'Desactivando'} ${type.code}…`, '');
    try {
        await api(`/api/currency/rate-types/${type.id}`, {
            method: 'PATCH',
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify({ is_active: next }),
        });
        setStatus(v2.rates.status, `Tipo ${type.code} ${next ? 'activado' : 'desactivado'}.`, 'success');
        if (typeof loadRates === 'function') await loadRates();
    } catch (error) {
        setStatus(v2.rates.status, normalizeError(error), 'error');
    }
}

function v2BuildRateHistoryRow(rate, state) {
    const tr = document.createElement('tr');
    tr.dataset.rateId = rate.id;

    // Column 1: Tipo (code badge + name)
    const typeCell = document.createElement('td');
    const typeBadge = document.createElement('span');
    typeBadge.className = 'v2-status-inline v2-status-inline--info';
    typeBadge.textContent = rate.exchange_rate_type_code || '—';
    typeCell.append(typeBadge);

    // Column 2: Tasa (big value + par + trend vs previous)
    const valueCell = document.createElement('td');
    const valueWrap = document.createElement('div');
    valueWrap.className = 'v2-rate-value';
    const valueBig = document.createElement('span');
    valueBig.className = `v2-rate-value__big${rate.is_active ? ' v2-rate-value__big--active' : ''}`;
    valueBig.textContent = Number(rate.rate).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
    // Trend vs previous rate of same type
    const sameType = state.rates.filter((r) => r.exchange_rate_type_id === rate.exchange_rate_type_id);
    const idx = sameType.findIndex((r) => r.id === rate.id);
    if (idx >= 0 && idx + 1 < sameType.length) {
        const prev = sameType[idx + 1];
        const trend = v2RateTrend(rate.rate, prev.rate);
        if (trend) {
            const trendSpan = document.createElement('span');
            trendSpan.className = `v2-rate-trend ${trend.cls}`;
            trendSpan.textContent = `${trend.symbol} ${trend.text}`;
            valueBig.append(trendSpan);
        }
    }
    const pairSmall = document.createElement('div');
    pairSmall.className = 'v2-rate-value__pair';
    pairSmall.textContent = `1 ${rate.base_currency || 'USD'} = ${Number(rate.rate).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} ${rate.quote_currency || 'VES'}`;
    valueWrap.append(valueBig, pairSmall);
    valueCell.append(valueWrap);

    // Column 3: Vigencia
    const effectiveCell = document.createElement('td');
    const effWrap = document.createElement('div');
    effWrap.className = 'v2-rate-effective';
    const effDate = document.createElement('span');
    effDate.className = 'v2-rate-effective__date';
    effDate.textContent = v2DateTime(rate.effective_at);
    const effAge = document.createElement('span');
    effAge.className = 'v2-rate-effective__age';
    effAge.textContent = v2RelativeDate(rate.effective_at);
    effWrap.append(effDate, effAge);
    effectiveCell.append(effWrap);

    // Column 4: Estado
    const statusCell = document.createElement('td');
    const statusPill = document.createElement('span');
    statusPill.className = `v2-status-inline ${rate.is_active ? 'v2-status-inline--success' : 'v2-status-inline--neutral'}`;
    statusPill.textContent = rate.is_active ? 'Vigente' : 'Inactiva';
    statusCell.append(statusPill);

    // Column 5: Fuente
    const sourceCell = document.createElement('td');
    if (rate.source) {
        sourceCell.innerHTML = `<span class="v2-rate-source">${escapeHtml(rate.source)}</span>`;
    } else {
        sourceCell.innerHTML = '<span class="v2-rate-source v2-rate-source--empty">—</span>';
    }

    // Column 6: 3-dot menu
    const actionCell = document.createElement('td');
    actionCell.className = 'v2-col-action';
    actionCell.append(v2BuildRateHistoryMenu(rate, state));

    tr.append(typeCell, valueCell, effectiveCell, statusCell, sourceCell, actionCell);
    return tr;
}

function v2BuildRateHistoryMenu(rate, state) {
    const wrap = document.createElement('div');
    wrap.className = 'v2-row-menu';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'v2-row-menu__trigger';
    trigger.setAttribute('aria-label', 'Acciones');
    trigger.setAttribute('aria-haspopup', 'true');
    trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';
    const panel = document.createElement('div');
    panel.className = 'v2-row-menu__panel';
    panel.setAttribute('role', 'menu');

    const items = [];
    if (!rate.is_active) {
        items.push({ label: 'Activar como vigente', icon: '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>', action: 'activate' });
    } else {
        items.push({ label: 'Desactivar', icon: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>', action: 'deactivate', danger: true });
    }

    items.forEach((it) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `v2-row-menu__item${it.danger ? ' v2-row-menu__item--danger' : ''}`;
        btn.setAttribute('role', 'menuitem');
        btn.dataset.action = it.action;
        btn.innerHTML = `${it.icon}<span>${it.label}</span>`;
        panel.append(btn);
    });

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.v2-row-menu.is-open').forEach((m) => { if (m !== wrap) m.classList.remove('is-open'); });
        wrap.classList.toggle('is-open');
    });
    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('.v2-row-menu__item');
        if (!btn) return;
        e.stopPropagation();
        wrap.classList.remove('is-open');
        v2RunRateHistoryAction(rate, btn.dataset.action);
    });

    wrap.append(trigger, panel);
    return wrap;
}

async function v2RunRateHistoryAction(rate, action) {
    const session = state.session;
    if (!session) return;
    try {
        if (action === 'activate') {
            setStatus(v2.rates.status, `Activando tasa #${rate.id}…`, '');
            await api(`/api/currency/rates/${rate.id}/activate`, {
                method: 'PATCH',
                headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            });
            setStatus(v2.rates.status, 'Tasa activada como vigente.', 'success');
        } else if (action === 'deactivate') {
            setStatus(v2.rates.status, `Desactivando tasa #${rate.id}…`, '');
            await api(`/api/currency/rates/${rate.id}/deactivate`, {
                method: 'PATCH',
                headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            });
            setStatus(v2.rates.status, 'Tasa desactivada.', 'success');
        }
        if (typeof loadRates === 'function') await loadRates();
    } catch (error) {
        setStatus(v2.rates.status, normalizeError(error), 'error');
    }
}

function v2RenderRates(state) {
    if (!v2.rates || !v2.rates.page) return;
    const types = state?.rateTypes || [];
    const rates = state?.rates || [];
    const defaultType = types.find((t) => t.is_default);
    const activeDefault = rates.find((r) => r.exchange_rate_type_id === defaultType?.id && r.is_active);

    // KPIs
    if (v2.rates.metricTypes) v2.rates.metricTypes.textContent = String(types.filter((t) => t.is_active).length);
    if (v2.rates.metricTypesHint) {
        v2.rates.metricTypesHint.textContent = `${types.filter((t) => t.is_default).length} predeterminados · ${types.length} totales`;
    }
    if (v2.rates.metricCurrent) {
        v2.rates.metricCurrent.textContent = activeDefault
            ? Number(activeDefault.rate).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
            : '—';
    }
    if (v2.rates.metricCurrentHint) {
        v2.rates.metricCurrentHint.textContent = activeDefault
            ? `${activeDefault.exchange_rate_type_code || ''} · vigente ${v2RelativeDate(activeDefault.effective_at)}`
            : (defaultType ? `Sin tasa activa para ${defaultType.code}` : 'Sin tipo predeterminado');
    }
    if (v2.rates.metricTotal) v2.rates.metricTotal.textContent = String(rates.length);
    if (v2.rates.metricTotalHint) {
        const active = rates.filter((r) => r.is_active).length;
        v2.rates.metricTotalHint.textContent = `${active} activas · ${rates.length - active} históricas`;
    }
    if (v2.rates.metricLast) {
        if (rates[0]) {
            v2.rates.metricLast.textContent = v2DateOnly(rates[0].effective_at);
        } else {
            v2.rates.metricLast.textContent = '—';
        }
    }
    if (v2.rates.metricLastHint) {
        v2.rates.metricLastHint.textContent = rates[0] ? v2RelativeDate(rates[0].effective_at) : '—';
    }

    // Alert strip — only show if there's a real operational concern
    v2RenderRatesAlertStrip(types, rates, defaultType, activeDefault);

    // Period summary
    if (v2.rates.period) {
        v2.rates.period.textContent = `${types.length} tipos · ${rates.length} valores registrados.`;
    }

    // Types table
    if (v2.rates.typesCount) v2.rates.typesCount.textContent = `${types.length} tipos`;
    if (v2.rates.typesCountFoot) {
        v2.rates.typesCountFoot.textContent = types.length === 0
            ? 'Sin tipos cargados.'
            : `${types.length} tipo(s) · click en una fila para ver detalle.`;
    }
    if (v2.rates.typesTbody) {
        if (!types.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.innerHTML = '<div style="padding:48px 12px;text-align:center;"><strong style="display:block;color:var(--v2-ink-strong);font-size:15px;margin-bottom:6px;">Sin tipos de tasa</strong><small style="color:var(--v2-muted);">Crea BCV, paralelo u otro tipo para empezar a registrar valores.</small></div>';
            tr.append(td);
            v2.rates.typesTbody.replaceChildren(tr);
        } else {
            v2.rates.typesTbody.replaceChildren(...types.map((t) => v2BuildRateTypeRow(t, { rates })));
        }
    }

    // History table (last 50)
    if (v2.rates.historyCount) v2.rates.historyCount.textContent = `${rates.length} valores`;
    if (v2.rates.historyCountFoot) {
        v2.rates.historyCountFoot.textContent = rates.length === 0
            ? 'Sin valores registrados.'
            : `Mostrando los últimos ${rates.length} valor(es).`;
    }
    if (v2.rates.historyTbody) {
        if (!rates.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 6;
            td.innerHTML = '<div style="padding:48px 12px;text-align:center;"><strong style="display:block;color:var(--v2-ink-strong);font-size:15px;margin-bottom:6px;">Sin tasas registradas</strong><small style="color:var(--v2-muted);">Usa el botón "Registrar tasa" para empezar.</small></div>';
            tr.append(td);
            v2.rates.historyTbody.replaceChildren(tr);
        } else {
            v2.rates.historyTbody.replaceChildren(...rates.map((r) => v2BuildRateHistoryRow(r, { rates })));
        }
    }
    // Disable prev/next (no pagination in v1 either; shown for parity)
    if (v2.rates.prev) v2.rates.prev.disabled = true;
    if (v2.rates.next) v2.rates.next.disabled = true;

    if (v2.rates.status) {
        setStatus(v2.rates.status, `Tasas actualizadas · ${types.length} tipos · ${rates.length} valores.`, 'success');
    }
}

function v2RenderRatesAlertStrip(types, rates, defaultType, activeDefault) {
    const strip = v2.rates.alertStrip;
    const list = v2.rates.alertStripList;
    if (!strip || !list) return;
    const chips = [];
    if (!defaultType) {
        chips.push({ severity: 'warning', title: 'Sin tipo predeterminado', message: 'Selecciona un tipo como predeterminado para usarlo en el POS.' });
    } else if (!activeDefault) {
        chips.push({ severity: 'danger', title: `${defaultType.code} sin tasa vigente`, message: 'Registra un valor y márcalo como vigente para activar la tasa predeterminada.' });
    }
    const typesWithoutRates = types.filter((t) => !rates.some((r) => r.exchange_rate_type_id === t.id));
    typesWithoutRates.forEach((t) => {
        chips.push({ severity: 'info', title: `${t.code} sin tasas`, message: 'Este tipo no tiene valores registrados.' });
    });
    if (!chips.length) {
        strip.hidden = true;
        list.replaceChildren();
        return;
    }
    strip.hidden = false;
    list.replaceChildren(...chips.map((alert) => {
        const tone = String(alert.severity || '').toLowerCase();
        const toneClass = tone === 'danger' ? 'v2-alert-chip--danger'
            : tone === 'info' ? 'v2-alert-chip--info'
            : 'v2-alert-chip--warning';
        const chip = document.createElement('span');
        chip.className = `v2-alert-chip ${toneClass}`;
        chip.title = alert.message || alert.title || '';
        chip.textContent = alert.title;
        return chip;
    }));
}

// =====================================================================
// v2 Rate Type Sheet (view / edit / create)
// =====================================================================
const v2RateSheetState = { typeId: null, mode: 'view', type: null, inflight: null };

function v2OpenRateTypeSheet(typeId, mode = 'view') {
    const session = state.session;
    const sheet = v2.rates.typeSheet;
    if (!session || !sheet) return;
    v2RateSheetState.typeId = typeId;
    v2RateSheetState.mode = mode;
    // Reset tabs
    v2ActivateRateTypeTab('general');
    sheet.hidden = false;
    void sheet.offsetWidth;
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    if (v2.rates.typeBadge) v2.rates.typeBadge.textContent = mode === 'edit' ? 'Editar' : (mode === 'create' ? 'Nuevo' : 'Tipo');
    if (v2.rates.typeSaveLabel) v2.rates.typeSaveLabel.textContent = mode === 'create' ? 'Crear tipo' : 'Guardar cambios';
    if (mode === 'create') {
        v2PrepareCreateRateType();
        return;
    }
    if (v2.rates.typeTitle) v2.rates.typeTitle.textContent = 'Cargando…';
    if (v2.rates.typeSubtitle) v2.rates.typeSubtitle.textContent = `Tipo #${typeId}`;
    v2RateSheetState.inflight = v2FetchRateType(typeId);
    v2RateSheetState.inflight.then((type) => {
        if (v2RateSheetState.typeId !== typeId) return;
        v2RateSheetState.type = type;
        v2RenderRateTypeSheet(type);
        if (mode === 'edit') v2StartEditRateType();
    }).catch((error) => {
        if (v2.rates.typeTitle) v2.rates.typeTitle.textContent = 'Error';
        if (v2.rates.typeSubtitle) v2.rates.typeSubtitle.textContent = normalizeError(error);
    }).finally(() => {
        if (v2RateSheetState.typeId === typeId) v2RateSheetState.inflight = null;
    });
}

function v2PrepareCreateRateType() {
    v2RateSheetState.type = null;
    v2RateSheetState.typeId = null;
    if (v2.rates.typeBadge) v2.rates.typeBadge.textContent = 'Nuevo';
    if (v2.rates.typeTitle) v2.rates.typeTitle.textContent = 'Nuevo tipo de tasa';
    if (v2.rates.typeSubtitle) v2.rates.typeSubtitle.textContent = 'Crea un tipo (BCV, paralelo, monitor, etc.) para registrar valores.';
    // Clear view fields
    ['typeCode', 'typeNameView', 'typeDefault', 'typeActive', 'typeCreated'].forEach((k) => {
        if (v2.rates[k]) v2.rates[k].textContent = '—';
    });
    // Clear history
    if (v2.rates.typeHistoryList) {
        v2.rates.typeHistoryList.innerHTML = '<p class="v2-sheet__empty">Crea el tipo y registra valores desde la página principal.</p>';
    }
    if (v2.rates.typeHistoryCount) v2.rates.typeHistoryCount.textContent = '0 valores';
    // Populate edit form
    if (v2.rates.typeEditCode) v2.rates.typeEditCode.value = '';
    if (v2.rates.typeEditName) v2.rates.typeEditName.value = '';
    if (v2.rates.typeEditDefault) v2.rates.typeEditDefault.value = '0';
    if (v2.rates.typeEditActive) v2.rates.typeEditActive.value = '1';
    v2ClearRateTypeEditError();
    v2EnterEditRateType();
    if (v2.rates.typeEditCode) v2.rates.typeEditCode.focus();
}

function v2CloseRateTypeSheet() {
    const sheet = v2.rates.typeSheet;
    if (!sheet) return;
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    setTimeout(() => { sheet.hidden = true; }, 280);
    v2RateSheetState.typeId = null;
    v2RateSheetState.mode = 'view';
    v2RateSheetState.type = null;
    v2RateSheetState.inflight = null;
}

function v2ActivateRateTypeTab(tabName) {
    document.querySelectorAll('[data-v2-rate-type-tab]').forEach((tab) => {
        const isActive = tab.dataset.v2RateTypeTab === tabName;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('[data-v2-rate-type-pane]').forEach((pane) => {
        pane.hidden = pane.dataset.v2RateTypePane !== tabName;
    });
}

function v2EnterEditRateType() {
    v2RateSheetState.mode = v2RateSheetState.typeId ? 'edit' : 'create';
    document.querySelectorAll('[data-v2-rate-sheet-mode]').forEach((el) => {
        el.hidden = el.dataset.v2RateSheetMode !== 'edit';
    });
    document.querySelectorAll('[data-v2-rate-type-mode]').forEach((el) => {
        el.hidden = el.dataset.v2RateTypeMode !== 'edit';
    });
    if (v2.rates.typeSaveLabel) {
        v2.rates.typeSaveLabel.textContent = v2RateSheetState.mode === 'create' ? 'Crear tipo' : 'Guardar cambios';
    }
    v2ActivateRateTypeTab('general');
}

function v2EnterViewRateType() {
    v2RateSheetState.mode = 'view';
    document.querySelectorAll('[data-v2-rate-sheet-mode]').forEach((el) => {
        el.hidden = el.dataset.v2RateSheetMode !== 'view';
    });
    document.querySelectorAll('[data-v2-rate-type-mode]').forEach((el) => {
        el.hidden = el.dataset.v2RateTypeMode !== 'view';
    });
    v2ClearRateTypeEditError();
}

function v2ClearRateTypeEditError() {
    if (!v2.rates.typeEditError) return;
    v2.rates.typeEditError.hidden = true;
    v2.rates.typeEditError.replaceChildren();
}

function v2ShowRateTypeEditError(messageOrList) {
    if (!v2.rates.typeEditError) return;
    v2.rates.typeEditError.hidden = false;
    v2.rates.typeEditError.replaceChildren();
    if (typeof messageOrList === 'string') {
        const span = document.createElement('span');
        span.textContent = messageOrList;
        v2.rates.typeEditError.append(span);
    } else if (Array.isArray(messageOrList) && messageOrList.length) {
        const span = document.createElement('span');
        span.textContent = 'No pudimos guardar:';
        const ul = document.createElement('ul');
        messageOrList.forEach((m) => {
            const li = document.createElement('li');
            li.textContent = m;
            ul.append(li);
        });
        v2.rates.typeEditError.append(span, ul);
    } else {
        v2.rates.typeEditError.hidden = true;
    }
}

async function v2FetchRateType(typeId) {
    const session = state.session;
    const response = await api(`/api/currency/rate-types/${typeId}`, { headers: authHeaders(session) });
    return response?.data || response;
}

function v2RenderRateTypeSheet(type) {
    if (v2.rates.typeBadge) v2.rates.typeBadge.textContent = type.is_active ? 'Activo' : 'Inactivo';
    if (v2.rates.typeTitle) v2.rates.typeTitle.textContent = type.name || 'Tipo de tasa';
    const subParts = [];
    subParts.push(type.code || '—');
    if (type.is_default) subParts.push('Predeterminada');
    if (v2.rates.typeSubtitle) v2.rates.typeSubtitle.textContent = subParts.join(' · ');

    if (v2.rates.typeCode) v2.rates.typeCode.textContent = type.code || '—';
    if (v2.rates.typeNameView) v2.rates.typeNameView.textContent = type.name || '—';
    if (v2.rates.typeDefault) {
        v2.rates.typeDefault.textContent = type.is_default ? 'Sí' : 'No';
        v2.rates.typeDefault.style.color = type.is_default ? 'var(--v2-green)' : '';
    }
    if (v2.rates.typeActive) {
        v2.rates.typeActive.textContent = type.is_active ? 'Activo' : 'Inactivo';
        v2.rates.typeActive.style.color = type.is_active ? 'var(--v2-green)' : 'var(--v2-muted)';
    }
    if (v2.rates.typeCreated) v2.rates.typeCreated.textContent = v2DateTime(type.created_at);

    // History tab — show only rates for this type, latest first (already ordered in state.rates)
    const myRates = (state.rates.rates || []).filter((r) => r.exchange_rate_type_id === type.id);
    if (v2.rates.typeHistoryCount) v2.rates.typeHistoryCount.textContent = `${myRates.length} valores`;
    if (v2.rates.typeHistoryList) {
        if (!myRates.length) {
            v2.rates.typeHistoryList.innerHTML = '<p class="v2-sheet__empty">Sin tasas registradas para este tipo.</p>';
        } else {
            v2.rates.typeHistoryList.replaceChildren(...myRates.slice(0, 15).map((r) => {
                const row = document.createElement('div');
                row.className = 'v2-sheet__list-row';
                const left = document.createElement('div');
                left.className = 'v2-sheet__list-row__name';
                const strong = document.createElement('strong');
                strong.textContent = Number(r.rate).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) + ' ' + (r.quote_currency || 'VES');
                const small = document.createElement('small');
                small.textContent = v2DateTime(r.effective_at);
                left.append(strong, small);
                const right = document.createElement('div');
                right.className = 'v2-sheet__list-row__value';
                const pill = document.createElement('span');
                pill.className = `v2-status-inline ${r.is_active ? 'v2-status-inline--success' : 'v2-status-inline--neutral'}`;
                pill.textContent = r.is_active ? 'Vigente' : 'Inactiva';
                right.append(pill);
                row.append(left, right);
                return row;
            }));
        }
    }
}

function v2StartEditRateType() {
    if (v2RateSheetState.inflight) {
        v2RateSheetState.inflight.then(() => v2StartEditRateType());
        return;
    }
    if (!v2RateSheetState.type) return;
    const t = v2RateSheetState.type;
    if (v2.rates.typeEditCode) v2.rates.typeEditCode.value = t.code || '';
    if (v2.rates.typeEditName) v2.rates.typeEditName.value = t.name || '';
    if (v2.rates.typeEditDefault) v2.rates.typeEditDefault.value = t.is_default ? '1' : '0';
    if (v2.rates.typeEditActive) v2.rates.typeEditActive.value = t.is_active ? '1' : '0';
    v2ClearRateTypeEditError();
    v2EnterEditRateType();
    if (v2.rates.typeEditName) v2.rates.typeEditName.focus();
}

async function v2SaveRateType() {
    const session = state.session;
    if (!session) return;
    const isCreate = v2RateSheetState.mode === 'create' || !v2RateSheetState.typeId;
    const payload = {
        code: (v2.rates.typeEditCode?.value || '').trim(),
        name: (v2.rates.typeEditName?.value || '').trim(),
        is_default: v2.rates.typeEditDefault?.value === '1',
        is_active: v2.rates.typeEditActive?.value === '1',
    };
    if (!payload.code || !payload.name) {
        v2ShowRateTypeEditError('Código y nombre son obligatorios.');
        return;
    }
    setButtonLoading(v2.rates.typeSave, true, isCreate ? 'Creando...' : 'Guardando...');
    v2ClearRateTypeEditError();
    try {
        const url = isCreate ? '/api/currency/rate-types' : `/api/currency/rate-types/${v2RateSheetState.typeId}`;
        const method = isCreate ? 'POST' : 'PATCH';
        const response = await api(url, {
            method,
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const saved = response?.data || response;
        setStatus(v2.rates.status, isCreate ? `Tipo ${saved.code} creado.` : `Tipo ${saved.code} actualizado.`, 'success');
        if (typeof loadRates === 'function') await loadRates();
        if (isCreate) {
            v2CloseRateTypeSheet();
        } else {
            v2RateSheetState.typeId = saved.id;
            v2RateSheetState.type = saved;
            v2RenderRateTypeSheet(saved);
            v2EnterViewRateType();
        }
    } catch (error) {
        const errors = error?.errors || (error?.response && error.response.errors);
        if (Array.isArray(errors)) {
            v2ShowRateTypeEditError(errors.map((e) => e?.message || String(e)).filter(Boolean));
        } else if (errors && typeof errors === 'object') {
            v2ShowRateTypeEditError(Object.values(errors).flat().filter(Boolean));
        } else {
            v2ShowRateTypeEditError(normalizeError(error));
        }
    } finally {
        setButtonLoading(v2.rates.typeSave, false);
    }
}

// =====================================================================
// v2 Rate Value Sheet (registrar nuevo valor)
// =====================================================================
function v2OpenRateValueSheet() {
    const sheet = v2.rates.valueSheet;
    if (!sheet) return;
    // Populate type select from current state
    if (v2.rates.valueTypeSelect) {
        const types = state.rates.rateTypes || [];
        v2.rates.valueTypeSelect.replaceChildren(...types.map((t) => {
            const opt = document.createElement('option');
            opt.value = String(t.id);
            opt.textContent = `${t.code} — ${t.name}`;
            if (t.is_default) opt.selected = true;
            return opt;
        }));
        if (!types.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'Crea primero un tipo de tasa';
            opt.disabled = true;
            v2.rates.valueTypeSelect.append(opt);
        }
    }
    // Reset form
    if (v2.rates.valueAmount) v2.rates.valueAmount.value = '';
    if (v2.rates.valueEffective) {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        v2.rates.valueEffective.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }
    if (v2.rates.valueSource) v2.rates.valueSource.value = '';
    if (v2.rates.valueActive) v2.rates.valueActive.checked = true;
    v2ClearRateValueEditError();
    sheet.hidden = false;
    void sheet.offsetWidth;
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    if (v2.rates.valueAmount) v2.rates.valueAmount.focus();
}

function v2CloseRateValueSheet() {
    const sheet = v2.rates.valueSheet;
    if (!sheet) return;
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    setTimeout(() => { sheet.hidden = true; }, 280);
}

function v2ClearRateValueEditError() {
    if (!v2.rates.valueError) return;
    v2.rates.valueError.hidden = true;
    v2.rates.valueError.replaceChildren();
}

function v2ShowRateValueEditError(messageOrList) {
    if (!v2.rates.valueError) return;
    v2.rates.valueError.hidden = false;
    v2.rates.valueError.replaceChildren();
    if (typeof messageOrList === 'string') {
        const span = document.createElement('span');
        span.textContent = messageOrList;
        v2.rates.valueError.append(span);
    } else if (Array.isArray(messageOrList) && messageOrList.length) {
        const span = document.createElement('span');
        span.textContent = 'No pudimos registrar:';
        const ul = document.createElement('ul');
        messageOrList.forEach((m) => {
            const li = document.createElement('li');
            li.textContent = m;
            ul.append(li);
        });
        v2.rates.valueError.append(span, ul);
    } else {
        v2.rates.valueError.hidden = true;
    }
}

async function v2SaveRateValue() {
    const session = state.session;
    if (!session) return;
    const typeId = parseInt(v2.rates.valueTypeSelect?.value, 10);
    const amount = parseFloat(v2.rates.valueAmount?.value);
    const effectiveAt = v2.rates.valueEffective?.value;
    const source = (v2.rates.valueSource?.value || '').trim() || null;
    const isActive = !!v2.rates.valueActive?.checked;
    if (!typeId) { v2ShowRateValueEditError('Selecciona un tipo de tasa.'); return; }
    if (!amount || amount <= 0) { v2ShowRateValueEditError('Indica un valor mayor a cero.'); return; }
    if (!effectiveAt) { v2ShowRateValueEditError('Indica la fecha de vigencia.'); return; }
    // Format effective_at as ISO (input gives "YYYY-MM-DDTHH:MM" in local TZ)
    const effectiveIso = new Date(effectiveAt).toISOString();
    setButtonLoading(v2.rates.valueSave, true, 'Registrando...');
    v2ClearRateValueEditError();
    try {
        await api('/api/currency/rates', {
            method: 'POST',
            headers: { ...authHeaders(session), 'Content-Type': 'application/json' },
            body: JSON.stringify({
                exchange_rate_type_id: typeId,
                rate: amount,
                effective_at: effectiveIso,
                is_active: isActive,
                source: source,
            }),
        });
        setStatus(v2.rates.status, 'Tasa registrada.', 'success');
        v2CloseRateValueSheet();
        if (typeof loadRates === 'function') await loadRates();
    } catch (error) {
        const errors = error?.errors || (error?.response && error.response.errors);
        if (Array.isArray(errors)) {
            v2ShowRateValueEditError(errors.map((e) => e?.message || String(e)).filter(Boolean));
        } else if (errors && typeof errors === 'object') {
            v2ShowRateValueEditError(Object.values(errors).flat().filter(Boolean));
        } else {
            v2ShowRateValueEditError(normalizeError(error));
        }
    } finally {
        setButtonLoading(v2.rates.valueSave, false);
    }
}

// ----- v2 rates event wiring -----
v2.rates?.refresh?.addEventListener('click', () => {
    if (typeof loadRates === 'function') loadRates();
});
v2.rates?.alertStripClose?.addEventListener('click', () => {
    if (v2.rates.alertStrip) v2.rates.alertStrip.hidden = true;
});
v2.rates?.newType?.addEventListener('click', () => v2OpenRateTypeSheet(null, 'create'));
v2.rates?.newValue?.addEventListener('click', () => v2OpenRateValueSheet());

v2.rates?.typeClose?.addEventListener('click', v2CloseRateTypeSheet);
v2.rates?.typeCloseEdit?.addEventListener('click', v2CloseRateTypeSheet);
v2.rates?.typeSheetBackdrop?.addEventListener('click', v2CloseRateTypeSheet);
v2.rates?.typeEditBtn?.addEventListener('click', v2StartEditRateType);
v2.rates?.typeCancel?.addEventListener('click', v2EnterViewRateType);
v2.rates?.typeSave?.addEventListener('click', v2SaveRateType);
document.querySelectorAll('[data-v2-rate-type-tab]').forEach((tab) => {
    tab.addEventListener('click', () => v2ActivateRateTypeTab(tab.dataset.v2RateTypeTab));
});

v2.rates?.valueCancel?.addEventListener('click', v2CloseRateValueSheet);
v2.rates?.valueClose?.addEventListener('click', v2CloseRateValueSheet);
v2.rates?.valueSheetBackdrop?.addEventListener('click', v2CloseRateValueSheet);
v2.rates?.valueSave?.addEventListener('click', v2SaveRateValue);

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (v2.rates?.typeSheet && !v2.rates.typeSheet.hidden && v2.rates.typeSheet.classList.contains('is-open')) {
            v2CloseRateTypeSheet();
        } else if (v2.rates?.valueSheet && !v2.rates.valueSheet.hidden && v2.rates.valueSheet.classList.contains('is-open')) {
            v2CloseRateValueSheet();
        }
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        if (v2.rates?.typeSheet && !v2.rates.typeSheet.hidden && v2RateSheetState.mode !== 'view' && v2RateSheetState.mode !== 'create') {
            e.preventDefault();
            v2SaveRateType();
        }
    }
});

// =====================================================================
// v2 Movimientos (Phase 3) — full-width catalog + side sheet
// =====================================================================

// Color/direction metadata for the 13 movement types.
// Direction drives the qty sign + cell color:
//   in      → stock increased  → green  → qty shown as +
//   out     → stock decreased  → red    → qty shown as −
//   neutral → qty is informational (reserved/released) → gray
const V2_MOVEMENT_TYPE_META = {
    purchase:        { label: 'Compra',            dir: 'in' },
    purchase_return: { label: 'Dev. proveedor',   dir: 'out' },
    sale:            { label: 'Venta',             dir: 'out' },
    sale_return:     { label: 'Dev. venta',        dir: 'in' },
    adjustment_in:   { label: 'Ajuste entrada',    dir: 'in' },
    adjustment_out:  { label: 'Ajuste salida',     dir: 'out' },
    transfer_in:     { label: 'Traslado entrada',  dir: 'in' },
    transfer_out:    { label: 'Traslado salida',   dir: 'out' },
    return_in:       { label: 'Retorno entrada',   dir: 'in' },
    return_out:      { label: 'Retorno salida',    dir: 'out' },
    damaged:         { label: 'Dañado',            dir: 'out', tone: 'warning' },
    reserved:        { label: 'Reservado',         dir: 'neutral' },
    released:        { label: 'Liberado',          dir: 'neutral' },
};

function v2MovementTypeLabel(type) {
    return V2_MOVEMENT_TYPE_META[type]?.label || movementTypeLabel(type) || (type || 'Movimiento');
}

function v2MovementTypeDir(type) {
    return V2_MOVEMENT_TYPE_META[type]?.dir || 'neutral';
}

function v2MovementTypeTone(type) {
    return V2_MOVEMENT_TYPE_META[type]?.tone || v2MovementTypeDir(type);
}

function v2FormatMovementDate(iso) {
    if (!iso) return { date: '—', time: '' };
    // Backend already returns ISO with timezone; show local date+time split.
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return { date: iso, time: '' };
    const pad = (n) => String(n).padStart(2, '0');
    const date = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
    const time = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    return { date, time };
}

function v2BuildMovementRow(movement) {
    const tr = document.createElement('tr');
    tr.dataset.movementId = movement.id;
    tr.dataset.movementType = movement.type;

    const dt = v2FormatMovementDate(movement.created_at);
    const dir = v2MovementTypeDir(movement.type);
    const tone = v2MovementTypeTone(movement.type);
    const qtyClass = `v2-mov-qty v2-mov-qty--${dir}`;
    const typeClass = `v2-mov-type v2-mov-type--${tone}`;
    const qty = stockNumber(movement.quantity);

    const reference = [movement.reference_type, movement.reference_id].filter(Boolean).join(' #');

    // Cell 1: Fecha (date + time)
    const cellDate = document.createElement('td');
    cellDate.innerHTML = `<div class="v2-mov-datetime"><strong>${escapeHtml(dt.date)}</strong><small>${escapeHtml(dt.time)}</small></div>`;
    tr.append(cellDate);

    // Cell 2: Tipo (colored pill)
    const cellType = document.createElement('td');
    const pill = document.createElement('span');
    pill.className = typeClass;
    pill.textContent = v2MovementTypeLabel(movement.type);
    cellType.append(pill);
    tr.append(cellType);

    // Cell 3: Producto (name + SKU)
    const cellProduct = document.createElement('td');
    cellProduct.innerHTML = `<div class="v2-mov-product"><strong>${escapeHtml(movement.product_name || 'Producto eliminado')}</strong><small>${escapeHtml(movement.product_sku || '—')}</small></div>`;
    tr.append(cellProduct);

    // Cell 4: Cantidad (sign + color)
    const cellQty = document.createElement('td');
    const qtyWrap = document.createElement('span');
    qtyWrap.className = qtyClass;
    qtyWrap.textContent = qty;
    cellQty.append(qtyWrap);
    if (movement.unit_cost !== null && movement.unit_cost !== undefined) {
        const cost = document.createElement('small');
        cost.style.color = 'var(--v2-muted)';
        cost.style.display = 'block';
        cost.style.fontSize = '11px';
        cost.style.marginTop = '2px';
        cost.textContent = `Costo ${money(movement.unit_cost)}`;
        cellQty.append(cost);
    }
    tr.append(cellQty);

    // Cell 5: Almacén (warehouse + branch)
    const cellWh = document.createElement('td');
    const whName = movement.warehouse_name || 'Sin almacén';
    const whCode = movement.warehouse_code ? ` · ${movement.warehouse_code}` : '';
    cellWh.innerHTML = `<div class="v2-mov-warehouse"><strong>${escapeHtml(whName)}</strong><small>${escapeHtml(whCode)}${movement.branch_name ? ` · ${escapeHtml(movement.branch_name)}` : ''}</small></div>`;
    tr.append(cellWh);

    // Cell 6: Motivo / referencia
    const cellReason = document.createElement('td');
    const reason = document.createElement('div');
    reason.className = 'v2-mov-reason';
    const reasonStr = movement.reason || 'Sin motivo';
    const reasonEl = document.createElement('strong');
    reasonEl.textContent = reasonStr;
    reason.append(reasonEl);
    if (reference) {
        const refWrap = document.createElement('span');
        refWrap.className = 'v2-mov-ref';
        if (movement.reference_type) {
            const badge = document.createElement('span');
            badge.className = 'v2-mov-ref-badge';
            badge.textContent = movement.reference_type;
            refWrap.append(badge);
        }
        const refText = document.createElement('span');
        refText.textContent = `#${movement.reference_id}`;
        refWrap.append(refText);
        reason.append(refWrap);
    } else {
        const noRef = document.createElement('small');
        noRef.style.color = 'var(--v2-muted)';
        noRef.textContent = 'Sin referencia';
        reason.append(noRef);
    }
    cellReason.append(reason);
    tr.append(cellReason);

    // Cell 7: 3-dot action menu
    const cellAction = document.createElement('td');
    cellAction.append(v2BuildMovementRowMenu(movement));
    tr.append(cellAction);

    return tr;
}

function v2BuildMovementRowMenu(movement) {
    const wrap = document.createElement('div');
    wrap.className = 'v2-row-menu';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'v2-row-menu__trigger';
    trigger.setAttribute('aria-label', `Acciones para movimiento #${movement.id}`);
    trigger.setAttribute('aria-haspopup', 'true');
    trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';
    const panel = document.createElement('div');
    panel.className = 'v2-row-menu__panel';
    panel.setAttribute('role', 'menu');

    const items = [
        { label: 'Ver detalle', action: 'view', icon: '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' },
    ];
    if (movement.product_id) {
        items.push({ label: 'Ver producto', action: 'product', icon: '<svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>' });
    }
    items.push({ divider: true });
    items.push({ label: `Copiar #${movement.id}`, action: 'copy-id', icon: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' });

    items.forEach((it) => {
        if (it.divider) {
            const div = document.createElement('div');
            div.className = 'v2-row-menu__divider';
            panel.append(div);
            return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'v2-row-menu__item';
        btn.setAttribute('role', 'menuitem');
        btn.dataset.action = it.action;
        btn.innerHTML = `${it.icon}<span>${escapeHtml(it.label)}</span>`;
        panel.append(btn);
    });

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.v2-row-menu.is-open').forEach((m) => { if (m !== wrap) m.classList.remove('is-open'); });
        wrap.classList.toggle('is-open');
    });
    panel.addEventListener('click', (e) => {
        const btn = e.target.closest('.v2-row-menu__item');
        if (!btn) return;
        e.stopPropagation();
        wrap.classList.remove('is-open');
        v2RunMovementAction(movement, btn.dataset.action);
    });

    wrap.append(trigger, panel);
    return wrap;
}

async function v2RunMovementAction(movement, action) {
    if (action === 'view') {
        v2OpenMovementSheet(movement);
    } else if (action === 'product') {
        // Switch to inventory tab and open the product in v2 sheet
        activatePortalSection('inventory');
        if (typeof v2OpenProductSheet === 'function') {
            v2OpenProductSheet(movement.product_id, 'view');
        } else {
            loadInventory(movement.product_id);
        }
    } else if (action === 'copy-id') {
        try {
            await navigator.clipboard.writeText(String(movement.id));
            if (v2.movements.status) {
                setStatus(v2.movements.status, `ID #${movement.id} copiado al portapapeles.`, 'success');
            }
        } catch {
            if (v2.movements.status) {
                setStatus(v2.movements.status, 'No se pudo copiar al portapapeles.', 'error');
            }
        }
    }
}

function v2RenderMovements(pageData = {}) {
    if (!v2.movements || !v2.movements.page) return;
    const movements = Array.isArray(pageData.data) ? pageData.data : [];
    const pagination = pageData.pagination || {};
    const totals = pageData.totals || {};
    const filters = pageData.filters || {};

    // KPIs
    const inQty = movements
        .filter((m) => v2MovementTypeDir(m.type) === 'in')
        .reduce((acc, m) => acc + (Number(m.quantity) || 0), 0);
    const outQty = movements
        .filter((m) => v2MovementTypeDir(m.type) === 'out')
        .reduce((acc, m) => acc + (Number(m.quantity) || 0), 0);
    const uniqueProducts = new Set(movements.map((m) => m.product_id).filter(Boolean)).size;

    if (v2.movements.metricTotal) {
        v2.movements.metricTotal.textContent = number(movements.length);
    }
    if (v2.movements.metricTotalHint) {
        v2.movements.metricTotalHint.textContent = pagination.total
            ? `De ${number(pagination.total)} en total`
            : 'En la página actual';
    }
    if (v2.movements.metricIn) v2.movements.metricIn.textContent = number(inQty);
    if (v2.movements.metricOut) v2.movements.metricOut.textContent = number(outQty);
    if (v2.movements.metricProducts) v2.movements.metricProducts.textContent = number(uniqueProducts);

    // Period summary
    if (v2.movements.period) {
        const from = filters.date_from || '—';
        const to = filters.date_to || 'hoy';
        const typeLabel = filters.type && filters.type !== 'all' ? v2MovementTypeLabel(filters.type) : 'Todos los tipos';
        v2.movements.period.textContent = `${typeLabel} · ${from} → ${to} · ${pagination.total || 0} movimiento(s).`;
    }

    // Filter status pill (right side of catalog head)
    if (v2.movements.filterStatus) {
        const activeFilterCount = [
            filters.search,
            filters.type && filters.type !== 'all' ? filters.type : null,
            filters.warehouse_id,
            filters.date_from,
            filters.date_to,
        ].filter(Boolean).length;
        if (activeFilterCount === 0) {
            v2.movements.filterStatus.textContent = 'Todos los tipos';
            v2.movements.filterStatus.className = 'v2-status-pill v2-status-pill--info';
        } else {
            v2.movements.filterStatus.textContent = `${activeFilterCount} filtro(s) activo(s)`;
            v2.movements.filterStatus.className = 'v2-status-pill v2-status-pill--warning';
        }
    }

    // Alert strip — surface real operational concerns from this page
    v2RenderMovementsAlertStrip(movements, pagination, inQty, outQty);

    // Table
    if (v2.movements.tbody) {
        if (!movements.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 7;
            td.innerHTML = '<div class="v2-table-empty"><strong>Sin movimientos</strong>No hay actividad que coincida con los filtros seleccionados.</div>';
            tr.append(td);
            v2.movements.tbody.replaceChildren(tr);
        } else {
            v2.movements.tbody.replaceChildren(...movements.map(v2BuildMovementRow));
        }
    }

    // Footer + pagination
    if (v2.movements.count) {
        v2.movements.count.textContent = pagination.total === 0
            ? 'Sin movimientos para mostrar.'
            : `Mostrando ${pagination.from}-${pagination.to} de ${pagination.total} movimiento(s).`;
    }
    if (v2.movements.prev) v2.movements.prev.disabled = !pagination.has_previous;
    if (v2.movements.next) v2.movements.next.disabled = !pagination.has_next;
    if (v2.movements.status) {
        v2.movements.status.textContent = `Movimientos actualizados · ${formatDateTime(new Date().toISOString())}`;
    }
}

function v2RenderMovementsAlertStrip(movements, pagination, inQty, outQty) {
    if (!v2.movements.alertStrip) return;
    const items = [];

    // Net stock delta
    if (inQty - outQty !== 0 && movements.length) {
        const delta = inQty - outQty;
        const sign = delta > 0 ? '+' : '−';
        items.push({
            tone: 'info',
            html: `<strong>Balance neto:</strong> ${sign}${number(Math.abs(delta))} unidades en esta página.`,
        });
    }

    // Damaged / adjustments
    const damaged = movements.filter((m) => m.type === 'damaged');
    if (damaged.length) {
        items.push({
            tone: 'warning',
            html: `<strong>${number(damaged.length)} registro(s)</strong> por daño — revisar motivo y responsable.`,
        });
    }

    // Reservations
    const reserved = movements.filter((m) => m.type === 'reserved' || m.type === 'released');
    if (reserved.length >= 5) {
        items.push({
            tone: 'info',
            html: `<strong>${number(reserved.length)}</strong> movimientos de reserva/liberación — fuera del stock físico.`,
        });
    }

    if (!items.length) {
        v2.movements.alertStrip.hidden = true;
        v2.movements.alertStrip.replaceChildren();
        return;
    }

    v2.movements.alertStrip.hidden = false;
    v2.movements.alertStrip.className = 'v2-alert-strip';
    items.forEach((it) => {
        if (it.tone) v2.movements.alertStrip.classList.add(`v2-alert-strip--${it.tone}`);
    });

    // Use a real SVG element (not a string) — replaceChildren() treats strings
    // as Text nodes, which is why the icon was rendering as raw HTML in the alert
    // strip on the first deploy.
    const NS = 'http://www.w3.org/2000/svg';
    const icon = document.createElementNS(NS, 'svg');
    icon.setAttribute('class', 'v2-alert-strip__icon');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('aria-hidden', 'true');
    const iconPath = document.createElementNS(NS, 'path');
    iconPath.setAttribute('d', 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z');
    const iconLine1 = document.createElementNS(NS, 'line');
    iconLine1.setAttribute('x1', '12'); iconLine1.setAttribute('y1', '9');
    iconLine1.setAttribute('x2', '12'); iconLine1.setAttribute('y2', '13');
    const iconLine2 = document.createElementNS(NS, 'line');
    iconLine2.setAttribute('x1', '12'); iconLine2.setAttribute('y1', '17');
    iconLine2.setAttribute('x2', '12.01'); iconLine2.setAttribute('y2', '17');
    icon.append(iconPath, iconLine1, iconLine2);

    const list = document.createElement('div');
    list.className = 'v2-alert-strip__list';
    items.forEach((it) => {
        const span = document.createElement('span');
        span.className = 'v2-alert-strip__item';
        span.innerHTML = it.html;
        list.append(span);
    });
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'v2-alert-strip__close';
    closeBtn.setAttribute('aria-label', 'Cerrar alertas');
    closeBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    closeBtn.addEventListener('click', () => { v2.movements.alertStrip.hidden = true; });
    v2.movements.alertStrip.replaceChildren(icon, list, closeBtn);
}

function v2OpenMovementSheet(movement) {
    if (!v2.movements.sheet) return;
    const m = v2.movements;
    const dt = v2FormatMovementDate(movement.created_at);
    const dir = v2MovementTypeDir(movement.type);
    const qtyClass = `v2-mov-qty v2-mov-qty--${dir}`;

    // Header
    if (m.sheetBadge) {
        m.sheetBadge.textContent = v2MovementTypeLabel(movement.type);
    }
    if (m.sheetTitle) {
        m.sheetTitle.textContent = `Movimiento #${movement.id}`;
    }
    if (m.sheetSubtitle) {
        const who = movement.created_by_name || 'Sistema';
        m.sheetSubtitle.textContent = `${dt.date} ${dt.time} · ${who}`;
    }

    // Tab: General
    if (m.sheetType) {
        const typePill = document.createElement('span');
        typePill.className = `v2-mov-type v2-mov-type--${v2MovementTypeTone(movement.type)}`;
        typePill.textContent = v2MovementTypeLabel(movement.type);
        m.sheetType.replaceChildren(typePill);
    }
    if (m.sheetQty) {
        m.sheetQty.replaceChildren();
        const qtyEl = document.createElement('span');
        qtyEl.className = qtyClass;
        qtyEl.textContent = stockNumber(movement.quantity);
        m.sheetQty.append(qtyEl);
        if (movement.unit_cost !== null && movement.unit_cost !== undefined) {
            const small = document.createElement('small');
            small.textContent = `Costo unitario ${money(movement.unit_cost)}`;
            m.sheetQty.append(small);
        }
    }
    if (m.sheetCost) {
        m.sheetCost.textContent = (movement.unit_cost === null || movement.unit_cost === undefined)
            ? '—'
            : money(movement.unit_cost);
    }
    if (m.sheetDate) m.sheetDate.textContent = formatDateTime(movement.created_at);
    if (m.sheetReason) m.sheetReason.textContent = movement.reason || 'Sin motivo registrado';
    if (m.sheetRef) {
        if (movement.reference_type || movement.reference_id) {
            const parts = [movement.reference_type, movement.reference_id].filter(Boolean).join(' #');
            m.sheetRef.textContent = parts || '—';
        } else {
            m.sheetRef.textContent = '—';
        }
    }

    // Tab: Producto
    if (m.sheetProductName) m.sheetProductName.textContent = movement.product_name || 'Producto eliminado';
    if (m.sheetProductSku) m.sheetProductSku.textContent = movement.product_sku || '—';
    if (m.sheetProductId) m.sheetProductId.textContent = movement.product_id ? `#${movement.product_id}` : '—';

    // Tab: Contexto
    if (m.sheetWarehouse) {
        const whLabel = [movement.warehouse_name, movement.warehouse_code ? `(${movement.warehouse_code})` : ''].filter(Boolean).join(' ');
        m.sheetWarehouse.textContent = whLabel || '—';
    }
    if (m.sheetBranch) m.sheetBranch.textContent = movement.branch_name || '—';
    if (m.sheetUser) {
        if (movement.created_by_name) {
            m.sheetUser.replaceChildren();
            const strong = document.createElement('strong');
            strong.textContent = movement.created_by_name;
            m.sheetUser.append(strong);
            if (movement.created_by_email) {
                const small = document.createElement('small');
                small.textContent = movement.created_by_email;
                m.sheetUser.append(small);
            }
        } else {
            m.sheetUser.textContent = 'Sistema';
        }
    }
    if (m.sheetId) m.sheetId.textContent = `#${movement.id}`;

    // Reset to first tab
    v2SelectMovementTab('general');

    // Open
    m.sheet.hidden = false;
    void m.sheet.offsetWidth;
    m.sheet.classList.add('is-open');
    m.sheet.setAttribute('aria-hidden', 'false');
    if (m.sheetClose) m.sheetClose.focus();
}

function v2CloseMovementSheet() {
    const sheet = v2.movements.sheet;
    if (!sheet) return;
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    setTimeout(() => { sheet.hidden = true; }, 280);
}

function v2SelectMovementTab(tab) {
    if (!v2.movements.sheet) return;
    document.querySelectorAll('#v2-mov-sheet .v2-sheet__tab').forEach((btn) => {
        const active = btn.dataset.v2MovTab === tab;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('#v2-mov-sheet .v2-sheet__pane').forEach((pane) => {
        pane.hidden = pane.dataset.v2MovPane !== tab;
    });
}

// =====================================================================
// v2 Movimientos — filter wiring (sync v2 inputs to v1 selectors so
// the existing loadMovements() picks up our values, then refresh).
// =====================================================================

function v2SyncMovementsFiltersToV1() {
    if (!v2.movements || !elements.movementsSearch) return;
    if (v2.movements.search) elements.movementsSearch.value = v2.movements.search.value;
    if (v2.movements.type) elements.movementsType.value = v2.movements.type.value || 'all';
    if (v2.movements.warehouse) elements.movementsWarehouse.value = v2.movements.warehouse.value;
    if (v2.movements.from) elements.movementsFrom.value = v2.movements.from.value;
    if (v2.movements.to) elements.movementsTo.value = v2.movements.to.value;
}

function v2ApplyMovementsFilters() {
    v2SyncMovementsFiltersToV1();
    loadMovements(1);
}

function v2ClearMovementsFilters() {
    if (!v2.movements) return;
    if (v2.movements.search) v2.movements.search.value = '';
    if (v2.movements.type) v2.movements.type.value = 'all';
    if (v2.movements.warehouse) v2.movements.warehouse.value = '';
    if (v2.movements.from) v2.movements.from.value = '';
    if (v2.movements.to) v2.movements.to.value = '';
    v2SyncMovementsFiltersToV1();
    loadMovements(1);
}

function v2PopulateMovementWarehousesIntoV2() {
    if (!v2.movements?.warehouse) return;
    if (v2.movements.warehouse.options.length > 1) return; // already populated
    const src = elements.movementsWarehouse;
    if (!src) return;
    const options = Array.from(src.options).map((o) => {
        const opt = document.createElement('option');
        opt.value = o.value;
        opt.textContent = o.textContent;
        return opt;
    });
    v2.movements.warehouse.replaceChildren(...options);
}

// Wire v2 movements controls
(function v2WireMovements() {
    if (!v2.movements) return;
    const m = v2.movements;
    if (m.refresh) m.refresh.addEventListener('click', () => loadMovements());
    if (m.apply) m.apply.addEventListener('click', v2ApplyMovementsFilters);
    if (m.clear) m.clear.addEventListener('click', v2ClearMovementsFilters);
    if (m.prev) m.prev.addEventListener('click', () => {
        if (!m.prev.disabled) loadMovements(Math.max(1, (state.movements.page || 1) - 1));
    });
    if (m.next) m.next.addEventListener('click', () => {
        if (!m.next.disabled) loadMovements((state.movements.page || 1) + 1);
    });
    if (m.search) {
        m.search.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                v2ApplyMovementsFilters();
            }
        });
    }
    // Row click → open sheet (ignore clicks that hit the 3-dot menu or its items)
    if (m.tbody) {
        m.tbody.addEventListener('click', (e) => {
            if (e.target.closest('.v2-row-menu')) return;
            const tr = e.target.closest('tr[data-movement-id]');
            if (!tr) return;
            const id = tr.dataset.movementId;
            const movement = (state.movements.lastData?.data || []).find((x) => String(x.id) === String(id));
            if (movement) v2OpenMovementSheet(movement);
        });
    }
    // Sheet close (button + backdrop)
    if (m.sheetClose) m.sheetClose.addEventListener('click', v2CloseMovementSheet);
    if (m.sheetBackdrop) m.sheetBackdrop.addEventListener('click', v2CloseMovementSheet);
    // Tab switching
    document.querySelectorAll('#v2-mov-sheet .v2-sheet__tab').forEach((btn) => {
        btn.addEventListener('click', () => v2SelectMovementTab(btn.dataset.v2MovTab));
    });
    // Esc to close
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && m.sheet && !m.sheet.hidden) {
            v2CloseMovementSheet();
        }
    });
})();

// =====================================================================
// v2 Reportes (Phase 4) — multi-panel operational dashboard
// =====================================================================

function v2ReportOrderStatusTone(status) {
    return { paid: 'success', open: 'warning', cancelled: 'danger' }[status] || 'neutral';
}
function v2ReportOrderStatusLabel(status) {
    return {
        paid: 'Pagada',
        open: 'Pendiente',
        cancelled: 'Cancelada',
    }[status] || (status || 'Sin estado');
}
function v2ReportCashStatusTone(status) {
    return { open: 'warning', closed: 'success' }[status] || 'neutral';
}
function v2ReportCashStatusLabel(status) {
    return { open: 'Abierta', closed: 'Cerrada' }[status] || (status || 'Sin estado');
}

function v2BuildReportOrderRow(order) {
    const tr = document.createElement('tr');
    tr.dataset.orderId = order.id;
    tr.innerHTML = `
        <td><strong>#${escapeHtml(order.id)}</strong><small>${escapeHtml(formatDateTime(order.opened_at))}</small></td>
        <td>${escapeHtml(order.customer_name || 'Consumidor final')}</td>
        <td>${escapeHtml(order.cash_register_name || 'Sin caja')}</td>
        <td><span class="v2-status-pill v2-status-pill--${v2ReportOrderStatusTone(order.status)}">${escapeHtml(v2ReportOrderStatusLabel(order.status))}</span></td>
        <td class="v2-col-money"><strong>${money(order.total_base_amount)}</strong></td>
        <td class="v2-col-money"><strong>${money(order.paid_base_amount)}</strong><small>Saldo ${money(order.balance_base_amount)}</small></td>
        <td>${escapeHtml(formatDateTime(order.paid_at || order.opened_at))}</td>
    `;
    return tr;
}

function v2BuildReportPaymentRow(method, maxAmount) {
    const tr = document.createElement('tr');
    const pct = maxAmount > 0 ? Math.round((Number(method.amount_base) || 0) / maxAmount * 100) : 0;
    tr.innerHTML = `
        <td><strong>${escapeHtml(method.name || 'Método')}</strong><small>${escapeHtml(method.currency || 'USD')}</small></td>
        <td class="v2-col-count">${number(method.payments_count)}</td>
        <td class="v2-col-money">
            <strong>${money(method.amount_base)}</strong>
            <div class="v2-share-bar">
                <div class="v2-share-bar__track"><div class="v2-share-bar__fill" style="width: ${pct}%;"></div></div>
                <span class="v2-share-bar__pct">${pct}%</span>
            </div>
        </td>
    `;
    return tr;
}

function v2BuildReportProductRow(product, rank) {
    const tr = document.createElement('tr');
    const rankClass = rank <= 3 ? `v2-rank v2-rank--${rank}` : 'v2-rank';
    tr.innerHTML = `
        <td class="v2-col-rank"><span class="${rankClass}">${rank}</span></td>
        <td><strong>${escapeHtml(product.product_name || 'Producto')}</strong><small>${escapeHtml(product.product_sku || '—')}</small></td>
        <td class="v2-col-count">${number(product.quantity)}</td>
        <td class="v2-col-money"><strong>${money(product.total_base_amount)}</strong></td>
    `;
    return tr;
}

function v2BuildReportCashRow(session) {
    const diff = Number(session.difference_base_amount) || 0;
    let diffClass = 'v2-money-delta--zero';
    if (diff > 0) diffClass = 'v2-money-delta--pos';
    else if (diff < 0) diffClass = 'v2-money-delta--neg';
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><strong>${escapeHtml(session.cash_register_name || 'Caja')}</strong><small>#${escapeHtml(session.id)}</small></td>
        <td>${escapeHtml(session.branch_name || '—')}</td>
        <td>${escapeHtml(session.cashier_name || '—')}</td>
        <td><span class="v2-status-pill v2-status-pill--${v2ReportCashStatusTone(session.status)}">${escapeHtml(v2ReportCashStatusLabel(session.status))}</span></td>
        <td class="v2-col-money"><strong>${money(session.expected_base_amount)}</strong></td>
        <td class="v2-col-money"><strong class="${diffClass}">${diff > 0 ? '+' : ''}${money(diff)}</strong></td>
        <td>${escapeHtml(formatDateTime(session.opened_at))}</td>
    `;
    return tr;
}

function v2RenderReportEmptyRow(tbody, message, colspan) {
    if (!tbody) return;
    const tr = document.createElement('tr');
    const td = document.createElement('td');
    td.colSpan = colspan;
    td.innerHTML = `<div class="v2-table-empty"><strong>Sin datos</strong>${escapeHtml(message)}</div>`;
    tr.append(td);
    tbody.replaceChildren(tr);
}

function v2RenderReports(report) {
    if (!v2.reports || !v2.reports.page) return;
    const data = report || {};
    const sales = data.sales || {};
    const cash = data.cash_register || {};
    const period = data.period || {};

    // Period label
    if (v2.reports.period) {
        v2.reports.period.textContent = `Periodo ${period.from || '—'} a ${period.to || '—'} · Generado ${formatDateTime(data.generated_at || new Date().toISOString())}`;
    }
    if (v2.reports.ordersSubtitle) {
        v2.reports.ordersSubtitle.textContent = `Últimas órdenes POS del ${period.from || '—'} al ${period.to || '—'}.`;
    }

    // KPIs
    if (v2.reports.metricPosTotal) v2.reports.metricPosTotal.textContent = money(sales.pos_paid_base_amount || 0);
    if (v2.reports.metricPosHint) v2.reports.metricPosHint.textContent = `${number(sales.pos_paid_count || 0)} ventas confirmadas`;
    if (v2.reports.metricTicket) v2.reports.metricTicket.textContent = money(sales.average_ticket_base_amount || 0);
    if (v2.reports.metricPending) v2.reports.metricPending.textContent = number(sales.pending_pos_count || 0);
    if (v2.reports.metricPendingHint) v2.reports.metricPendingHint.textContent = `${money(sales.pending_pos_base_amount || 0)} por cerrar`;
    if (v2.reports.metricOpenCash) v2.reports.metricOpenCash.textContent = number(cash.open_count || 0);
    if (v2.reports.metricCashHint) v2.reports.metricCashHint.textContent = `${money(cash.expected_base_amount || 0)} esperado`;

    // Alert strip
    v2RenderReportsAlertStrip(sales, cash);

    // Panel 1: Ventas recientes
    const orders = data.recent_orders || [];
    if (v2.reports.ordersTbody) {
        if (!orders.length) {
            v2RenderReportEmptyRow(v2.reports.ordersTbody, 'No hay órdenes POS en el periodo.', 7);
        } else {
            v2.reports.ordersTbody.replaceChildren(...orders.map(v2BuildReportOrderRow));
        }
    }

    // Panel 2: Métodos de pago (with share bar relative to max)
    const methods = data.payment_methods || [];
    if (v2.reports.paymentsTbody) {
        if (!methods.length) {
            v2RenderReportEmptyRow(v2.reports.paymentsTbody, 'Sin pagos capturados en el periodo.', 3);
        } else {
            const maxAmount = Math.max(0, ...methods.map((m) => Number(m.amount_base) || 0));
            v2.reports.paymentsTbody.replaceChildren(...methods.map((m) => v2BuildReportPaymentRow(m, maxAmount)));
        }
    }

    // Panel 3: Productos vendidos (rank 1..N)
    const products = data.top_products || [];
    if (v2.reports.productsTbody) {
        if (!products.length) {
            v2RenderReportEmptyRow(v2.reports.productsTbody, 'Sin productos vendidos en el periodo.', 4);
        } else {
            v2.reports.productsTbody.replaceChildren(...products.map((p, i) => v2BuildReportProductRow(p, i + 1)));
        }
    }

    // Panel 4: Actividad de caja
    const sessions = cash.sessions || [];
    if (v2.reports.cashTbody) {
        if (!sessions.length) {
            v2RenderReportEmptyRow(v2.reports.cashTbody, 'Sin turnos de caja en el periodo.', 7);
        } else {
            v2.reports.cashTbody.replaceChildren(...sessions.map(v2BuildReportCashRow));
        }
    }

    if (v2.reports.status) {
        v2.reports.status.textContent = `Reportes actualizados · ${formatDateTime(data.generated_at || new Date().toISOString())}`;
    }
}

function v2RenderReportsAlertStrip(sales, cash) {
    if (!v2.reports.alertStrip) return;
    const items = [];
    const pendingCount = Number(sales?.pending_pos_count || 0);
    const pendingTotal = Number(sales?.pending_pos_base_amount || 0);
    const openCash = Number(cash?.open_count || 0);
    const sessions = cash?.sessions || [];
    const sessionsWithDiff = sessions.filter((s) => Math.abs(Number(s.difference_base_amount) || 0) > 0.01);

    if (pendingCount > 0) {
        items.push({
            tone: 'warning',
            html: `<strong>${number(pendingCount)} orden(es) POS</strong> pendientes por cerrar — ${money(pendingTotal)} sin cobrar.`,
        });
    }
    if (openCash > 0) {
        items.push({
            tone: 'info',
            html: `<strong>${number(openCash)} caja(s) abiertas</strong> — revisar arqueos al cierre del turno.`,
        });
    }
    if (sessionsWithDiff.length > 0) {
        items.push({
            tone: 'warning',
            html: `<strong>${number(sessionsWithDiff.length)} turno(s)</strong> con diferencia en arqueo — comparar contra el esperado.`,
        });
    }
    if (!Number(sales?.pos_paid_count || 0) && !Number(sales?.confirmed_count || 0)) {
        items.push({
            tone: 'info',
            html: `<strong>Sin ventas confirmadas</strong> en el periodo seleccionado — amplia el rango de fechas.`,
        });
    }

    if (!items.length) {
        v2.reports.alertStrip.hidden = true;
        v2.reports.alertStrip.replaceChildren();
        return;
    }

    v2.reports.alertStrip.hidden = false;
    v2.reports.alertStrip.className = 'v2-alert-strip';
    items.forEach((it) => {
        if (it.tone) v2.reports.alertStrip.classList.add(`v2-alert-strip--${it.tone}`);
    });

    // Build a real SVG icon (replaceChildren with strings would render literal HTML)
    const NS = 'http://www.w3.org/2000/svg';
    const icon = document.createElementNS(NS, 'svg');
    icon.setAttribute('class', 'v2-alert-strip__icon');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('aria-hidden', 'true');
    const iconPath = document.createElementNS(NS, 'path');
    iconPath.setAttribute('d', 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z');
    const iconLine1 = document.createElementNS(NS, 'line');
    iconLine1.setAttribute('x1', '12'); iconLine1.setAttribute('y1', '9');
    iconLine1.setAttribute('x2', '12'); iconLine1.setAttribute('y2', '13');
    const iconLine2 = document.createElementNS(NS, 'line');
    iconLine2.setAttribute('x1', '12'); iconLine2.setAttribute('y1', '17');
    iconLine2.setAttribute('x2', '12.01'); iconLine2.setAttribute('y2', '17');
    icon.append(iconPath, iconLine1, iconLine2);

    const list = document.createElement('div');
    list.className = 'v2-alert-strip__list';
    items.forEach((it) => {
        const span = document.createElement('span');
        span.className = 'v2-alert-strip__item';
        span.innerHTML = it.html;
        list.append(span);
    });
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'v2-alert-strip__close';
    closeBtn.setAttribute('aria-label', 'Cerrar alertas');
    closeBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    closeBtn.addEventListener('click', () => { v2.reports.alertStrip.hidden = true; });
    v2.reports.alertStrip.replaceChildren(icon, list, closeBtn);
}

// =====================================================================
// v2 Reportes — filter wiring (sync v2 inputs to v1 selectors so
// the existing loadOperationalReports() picks up our values, then refresh).
// =====================================================================

function v2PopulateReportsFiltersFromV1() {
    if (!v2.reports || !elements.reportsBranch) return;
    // Branches
    if (v2.reports.branch.options.length <= 1 && elements.reportsBranch.options.length > 1) {
        const options = Array.from(elements.reportsBranch.options).map((o) => {
            const opt = document.createElement('option');
            opt.value = o.value;
            opt.textContent = o.textContent;
            return opt;
        });
        v2.reports.branch.replaceChildren(...options);
    }
    // Cashiers
    if (v2.reports.cashier.options.length <= 1 && elements.reportsCashier && elements.reportsCashier.options.length > 1) {
        const options = Array.from(elements.reportsCashier.options).map((o) => {
            const opt = document.createElement('option');
            opt.value = o.value;
            opt.textContent = o.textContent;
            return opt;
        });
        v2.reports.cashier.replaceChildren(...options);
    }
    // Cash registers
    if (v2.reports.cashRegister.options.length <= 1 && elements.reportsCashRegister && elements.reportsCashRegister.options.length > 1) {
        const options = Array.from(elements.reportsCashRegister.options).map((o) => {
            const opt = document.createElement('option');
            opt.value = o.value;
            opt.textContent = o.textContent;
            return opt;
        });
        v2.reports.cashRegister.replaceChildren(...options);
    }
}

function v2SyncReportsFiltersToV1() {
    if (!v2.reports || !elements.reportsDateFrom) return;
    if (v2.reports.dateFrom) elements.reportsDateFrom.value = v2.reports.dateFrom.value;
    if (v2.reports.dateTo) elements.reportsDateTo.value = v2.reports.dateTo.value;
    if (v2.reports.branch) elements.reportsBranch.value = v2.reports.branch.value;
    if (v2.reports.cashRegister) elements.reportsCashRegister.value = v2.reports.cashRegister.value;
    if (v2.reports.cashier) elements.reportsCashier.value = v2.reports.cashier.value;
    if (v2.reports.orderStatus) elements.reportsOrderStatus.value = v2.reports.orderStatus.value || 'all';
    if (v2.reports.periodSelect && elements.period) elements.period.value = v2.reports.periodSelect.value;
}

function v2ApplyReportsFilters() {
    v2SyncReportsFiltersToV1();
    loadOperationalReports();
}

function v2ClearReportsFilters() {
    if (!v2.reports) return;
    if (v2.reports.dateFrom) v2.reports.dateFrom.value = '';
    if (v2.reports.dateTo) v2.reports.dateTo.value = '';
    if (v2.reports.branch) v2.reports.branch.value = '';
    if (v2.reports.cashRegister) v2.reports.cashRegister.value = '';
    if (v2.reports.cashier) v2.reports.cashier.value = '';
    if (v2.reports.orderStatus) v2.reports.orderStatus.value = 'all';
    if (v2.reports.periodSelect) v2.reports.periodSelect.value = 'today';
    v2SyncReportsFiltersToV1();
    loadOperationalReports();
}

// Wire v2 reports controls
(function v2WireReports() {
    if (!v2.reports) return;
    const r = v2.reports;
    if (r.refresh) r.refresh.addEventListener('click', () => loadOperationalReports());
    if (r.apply) r.apply.addEventListener('click', v2ApplyReportsFilters);
    if (r.clear) r.clear.addEventListener('click', v2ClearReportsFilters);
    if (r.periodSelect) {
        r.periodSelect.addEventListener('change', () => {
            v2SyncReportsFiltersToV1();
            loadOperationalReports();
        });
    }
    // Export buttons (reuse existing exportOperationalReport from v1)
    if (r.exportOrders && elements.reportsExportOrders) r.exportOrders.addEventListener('click', () => exportOperationalReport('recent_orders', elements.reportsExportOrders));
    if (r.exportPayments && elements.reportsExportPayments) r.exportPayments.addEventListener('click', () => exportOperationalReport('payment_methods', elements.reportsExportPayments));
    if (r.exportProducts && elements.reportsExportProducts) r.exportProducts.addEventListener('click', () => exportOperationalReport('top_products', elements.reportsExportProducts));
    if (r.exportCash && elements.reportsExportCash) r.exportCash.addEventListener('click', () => exportOperationalReport('cash_sessions', elements.reportsExportCash));
    // Branch change → cascade to cash-register filter (mirror v1 behavior)
    if (r.branch) {
        r.branch.addEventListener('change', () => {
            const selected = r.branch.value;
            if (elements.reportsBranch) elements.reportsBranch.value = selected;
            // Reload reports to get filtered cash registers
            v2SyncReportsFiltersToV1();
            loadOperationalReports();
        });
    }
    // Mirror v1 topbar period changes to the v2 period select so both stay in sync
    if (elements.period && r.periodSelect) {
        elements.period.addEventListener('change', () => {
            r.periodSelect.value = elements.period.value;
        });
    }
})();

