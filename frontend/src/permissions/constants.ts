/**
 * Constantes de los 101 permisos del backend.
 * Usar SIEMPRE estas constantes (no strings literales) para tener
 * autocompletado en el IDE y evitar typos.
 *
 * Estructura: <modulo>.<verbo>
 * Ejemplo: PERMISSIONS.PRODUCTS_VIEW
 *
 * Ver docs/FRONTEND_PERMISSIONS.md §4.5 y docs/API.md.
 */

export const PERMISSIONS = {
  // Products
  PRODUCTS_VIEW: 'products.view',
  PRODUCTS_CREATE: 'products.create',
  PRODUCTS_UPDATE: 'products.update',
  PRODUCTS_DELETE: 'products.delete',

  // Price Lists
  PRICE_LISTS_VIEW: 'price_lists.view',
  PRICE_LISTS_CREATE: 'price_lists.create',
  PRICE_LISTS_UPDATE: 'price_lists.update',
  PRICE_LISTS_DELETE: 'price_lists.delete',

  // Inventory
  INVENTORY_VIEW: 'inventory.view',
  INVENTORY_ADJUST: 'inventory.adjust',
  INVENTORY_TRANSFER: 'inventory.transfer',
  INVENTORY_RESERVE: 'inventory.reserve',
  INVENTORY_RELEASE: 'inventory.release',
  INVENTORY_DAMAGE: 'inventory.damage',

  // Sales
  SALES_VIEW: 'sales.view',
  SALES_CREATE: 'sales.create',
  SALES_CONFIRM: 'sales.confirm',
  SALES_CANCEL: 'sales.cancel',

  // POS
  POS_VIEW: 'pos.view',
  POS_CHECKOUT: 'pos.checkout',
  POS_CANCEL: 'pos.cancel',

  // Cash Register
  CASH_REGISTER_VIEW: 'cash_register.view',
  CASH_REGISTER_OPEN: 'cash_register.open',
  CASH_REGISTER_CLOSE: 'cash_register.close',
  CASH_REGISTER_MOVE: 'cash_register.move',
  CASH_REGISTER_MOVEMENTS: 'cash_register.movements',
  CASH_REGISTER_CREATE: 'cash_register.create',
  CASH_REGISTER_UPDATE: 'cash_register.update',

  // Inventory Transfers
  INVENTORY_TRANSFERS_VIEW: 'inventory_transfers.view',
  INVENTORY_TRANSFERS_CREATE: 'inventory_transfers.create',
  INVENTORY_TRANSFERS_PREPARE: 'inventory_transfers.prepare',
  INVENTORY_TRANSFERS_DISPATCH: 'inventory_transfers.dispatch',
  INVENTORY_TRANSFERS_RECEIVE: 'inventory_transfers.receive',
  INVENTORY_TRANSFERS_CANCEL: 'inventory_transfers.cancel',
  INVENTORY_TRANSFERS_RESOLVE_DIFFERENCES: 'inventory_transfers.resolve_differences',
  INVENTORY_TRANSFERS_ADMIN: 'inventory_transfers.admin',

  // Customers
  CUSTOMERS_VIEW: 'customers.view',
  CUSTOMERS_CREATE: 'customers.create',
  CUSTOMERS_UPDATE: 'customers.update',
  CUSTOMERS_DELETE: 'customers.delete',

  // Customer Groups
  CUSTOMER_GROUPS_VIEW: 'customer_groups.view',
  CUSTOMER_GROUPS_CREATE: 'customer_groups.create',
  CUSTOMER_GROUPS_UPDATE: 'customer_groups.update',

  // Suppliers
  SUPPLIERS_VIEW: 'suppliers.view',
  SUPPLIERS_CREATE: 'suppliers.create',
  SUPPLIERS_UPDATE: 'suppliers.update',
  SUPPLIERS_DELETE: 'suppliers.delete',

  // Purchases
  PURCHASES_VIEW: 'purchases.view',
  PURCHASES_CREATE: 'purchases.create',
  // El backend usa `purchases.approve` (NO `purchases.receive`) para el
  // endpoint PATCH /api/purchases/{id}/receive. Ver AGENTS.md §8.4 nota.
  PURCHASES_APPROVE: 'purchases.approve',
  PURCHASES_RECEIVE: 'purchases.receive', // alias historico, NO usado por backend
  PURCHASES_CANCEL: 'purchases.cancel',

  // Sales Returns
  SALES_RETURNS_VIEW: 'sales_returns.view',
  SALES_RETURNS_CREATE: 'sales_returns.create',

  // Purchase Returns
  PURCHASE_RETURNS_VIEW: 'purchase_returns.view',
  PURCHASE_RETURNS_CREATE: 'purchase_returns.create',

  // Accounts Receivable
  ACCOUNTS_RECEIVABLE_VIEW: 'accounts_receivable.view',
  ACCOUNTS_RECEIVABLE_COLLECT: 'accounts_receivable.collect',

  // Accounts Payable
  ACCOUNTS_PAYABLE_VIEW: 'accounts_payable.view',
  ACCOUNTS_PAYABLE_PAY: 'accounts_payable.pay',
  ACCOUNTS_PAYABLE_PAYMENT_REQUESTS_VIEW: 'accounts_payable.payment_requests.view',
  ACCOUNTS_PAYABLE_PAYMENT_REQUESTS_PREPARE: 'accounts_payable.payment_requests.prepare',
  ACCOUNTS_PAYABLE_PAYMENT_REQUESTS_APPROVE: 'accounts_payable.payment_requests.approve',
  ACCOUNTS_PAYABLE_PAYMENT_REQUESTS_EXECUTE: 'accounts_payable.payment_requests.execute',
  ACCOUNTS_PAYABLE_PAYMENT_REQUESTS_CANCEL: 'accounts_payable.payment_requests.cancel',

  // Reports
  REPORTS_VIEW: 'reports.view',
  FINANCE_REPORTS_VIEW: 'finance_reports.view',
  KARDEX_VIEW: 'kardex.view',

  // Currency (exchange rate types + rates historicas)
  CURRENCY_VIEW: 'currency.view',
  CURRENCY_MANAGE: 'currency.manage',

  // Warranties
  WARRANTY_POLICIES_VIEW: 'warranty_policies.view',
  WARRANTY_POLICIES_CREATE: 'warranty_policies.create',
  WARRANTY_POLICIES_UPDATE: 'warranty_policies.update',
  WARRANTY_POLICIES_MANAGE: 'warranty_policies.manage',
  WARRANTIES_VIEW: 'warranties.view',
  WARRANTIES_CREATE: 'warranties.create',
  WARRANTIES_REVIEW: 'warranties.review',
  WARRANTIES_RESOLVE: 'warranties.resolve',
  WARRANTIES_DELIVER: 'warranties.deliver',

  // Branches
  BRANCHES_VIEW: 'branches.view',
  BRANCHES_CREATE: 'branches.create',
  BRANCHES_UPDATE: 'branches.update',
  BRANCHES_DELETE: 'branches.delete',

  // Warehouses
  WAREHOUSES_VIEW: 'warehouses.view',
  WAREHOUSES_CREATE: 'warehouses.create',
  WAREHOUSES_UPDATE: 'warehouses.update',
  WAREHOUSES_DELETE: 'warehouses.delete',

  // Payment Methods
  PAYMENT_METHODS_VIEW: 'payment_methods.view',
  PAYMENT_METHODS_CREATE: 'payment_methods.create',
  PAYMENT_METHODS_UPDATE: 'payment_methods.update',
  PAYMENT_METHODS_DELETE: 'payment_methods.delete',

  // Payment Receipts
  PAYMENT_RECEIPTS_VIEW: 'payment_receipts.view',
  PAYMENT_RECEIPTS_VOID: 'payment_receipts.void',

  // Financial Adjustments
  FINANCIAL_ADJUSTMENTS_VIEW: 'financial_adjustments.view',
  FINANCIAL_ADJUSTMENTS_CREATE: 'financial_adjustments.create',

  // Product Entries / Exits
  PRODUCT_ENTRIES_VIEW: 'product_entries.view',
  PRODUCT_ENTRIES_CREATE: 'product_entries.create',
  PRODUCT_EXITS_VIEW: 'product_exits.view',
  PRODUCT_EXITS_CREATE: 'product_exits.create',

  // Access Control
  USERS_VIEW: 'users.view',
  USERS_CREATE: 'users.create',
  USERS_UPDATE: 'users.update',
  USERS_DELETE: 'users.delete',
  USERS_STATUS: 'users.status',
  USERS_ROLES: 'users.roles',
  ROLES_VIEW: 'roles.view',
  ROLES_CREATE: 'roles.create',
  ROLES_UPDATE: 'roles.update',
  ROLES_DELETE: 'roles.delete',

  // Finance
  FINANCE_COSTS_VIEW: 'finance.costs.view',

  // Settings
  SETTINGS_MANAGE: 'settings.manage',
  AI_CONFIGURE: 'ai.configure',

  // Sync
  SYNC_VIEW: 'sync.view',
  SYNC_MANAGE: 'sync.manage',

  // Tenants
  TENANTS_VIEW: 'tenants.view',
} as const;

export type PermissionName = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

/** Permisos que NO requieren scope (sin scope por recurso). */
export const PERMISSIONS_WITHOUT_SCOPE: ReadonlySet<PermissionName> = new Set([
  PERMISSIONS.FINANCE_COSTS_VIEW,
  PERMISSIONS.SETTINGS_MANAGE,
  PERMISSIONS.AI_CONFIGURE,
  PERMISSIONS.TENANTS_VIEW,
]);
