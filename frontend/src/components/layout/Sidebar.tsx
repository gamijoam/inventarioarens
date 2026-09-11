import { useState, useContext } from 'react';
import { Link, useRouterState } from '@tanstack/react-router';
import {
  LayoutDashboard,
  Package,
  ShoppingCart,
  Users,
  Truck,
  Wallet,
  Receipt,
  Boxes,
  Tag,
  TrendingUp,
  ChevronLeft,
  ChevronRight,
  ChevronDown,
  Settings,
  Building,
  Building2,
  ShoppingBag,
  Monitor,
  Banknote,
  CreditCard,
  RotateCcw,
  ShieldQuestion,
  BarChart3,
  Printer,
  Upload,
  ClipboardList,
  FileText,
  Settings2,
  SlidersHorizontal,
  Send,
  BadgeDollarSign,
  Wrench,
  Sparkles,
} from 'lucide-react';

import { cn } from '@/lib/cn';
import { useTenantGroups } from '@/features/access/tenantGroupsApi';
import { PERMISSIONS } from '@/permissions/constants';
import { ShieldCheck } from 'lucide-react';
import { useSessionStore } from '@/stores/session';
import { useUiModeStore } from '@/stores/uiMode';
import { PermissionContext } from '@/permissions/PermissionContext';

interface NavItem {
  to: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  permission?: string;
  permissionAny?: string[];
  capability?: string;
  // Etiqueta de seccion que agrupa visualmente items relacionados.
  section?: string;
  // Sub-items opcionales (menu anidado).
  children?: NavItem[];
  // Si es true, el item solo se muestra si el user autenticado es
  // Owner de al menos un grupo (usado por "Organizaciones"). El resto
  // de usuarios (admin de empresa, vendedor, etc) no lo ven.
  hideIfNoOwnedGroup?: boolean;
}

interface UsersSearch {
  scope: 'tenant' | 'organization';
}

const SECTION_LABELS = {
  OPERACION: 'Operación',
  VENTAS: 'Ventas',
  FINANZAS: 'Finanzas',
  INVENTARIO: 'Inventario',
  ANALITICA: 'Analítica',
  CONFIGURACION: 'Configuración',
} as const;

const NAV_ITEMS: NavItem[] = [
  // ===== Operación =====
  {
    to: '/dashboard',
    label: 'Dashboard',
    icon: LayoutDashboard,
    section: SECTION_LABELS.OPERACION,
  },
  {
    to: '/pos',
    label: 'POS',
    icon: Monitor,
    permission: PERMISSIONS.POS_VIEW,
    capability: 'pos',
    section: SECTION_LABELS.OPERACION,
  },
  {
    to: '/cash-register',
    label: 'Cajas',
    icon: Banknote,
    permission: PERMISSIONS.CASH_REGISTER_VIEW,
    capability: 'cash_register',
    section: SECTION_LABELS.OPERACION,
  },

  // ===== Ventas =====
  {
    to: '/sales',
    label: 'Ventas',
    icon: ShoppingCart,
    permission: PERMISSIONS.SALES_VIEW,
    capability: 'sales',
    section: SECTION_LABELS.VENTAS,
  },
  {
    to: '/quotations',
    label: 'Cotizaciones',
    icon: FileText,
    permission: PERMISSIONS.QUOTATIONS_VIEW,
    capability: 'quotations',
    section: SECTION_LABELS.VENTAS,
  },
  {
    to: '/sales-returns',
    label: 'Devoluciones',
    icon: RotateCcw,
    permission: PERMISSIONS.SALES_RETURNS_VIEW,
    capability: 'sales',
    section: SECTION_LABELS.VENTAS,
  },
  {
    to: '/promotions',
    label: 'Promociones',
    icon: Tag,
    permission: PERMISSIONS.PROMOTIONS_VIEW,
    capability: 'promotions',
    section: SECTION_LABELS.VENTAS,
  },
  {
    to: '/commissions',
    label: 'Comisiones',
    icon: BadgeDollarSign,
    permissionAny: [PERMISSIONS.COMMISSIONS_VIEW_OWN, PERMISSIONS.COMMISSIONS_VIEW_ALL],
    capability: 'commissions',
    section: SECTION_LABELS.VENTAS,
  },
  {
    to: '/customers',
    label: 'Clientes',
    icon: Users,
    permission: PERMISSIONS.CUSTOMERS_VIEW,
    capability: 'customers',
    section: SECTION_LABELS.VENTAS,
  },

  // ===== Finanzas =====
  {
    to: '/receivables',
    label: 'Cuentas por cobrar',
    icon: Wallet,
    permission: PERMISSIONS.ACCOUNTS_RECEIVABLE_VIEW,
    capability: 'finance',
    section: SECTION_LABELS.FINANZAS,
  },
  {
    to: '/payables',
    label: 'Cuentas por pagar',
    icon: Receipt,
    permission: PERMISSIONS.ACCOUNTS_PAYABLE_VIEW,
    capability: 'finance',
    section: SECTION_LABELS.FINANZAS,
  },
  {
    to: '/payment-methods',
    label: 'Metodos de pago',
    icon: CreditCard,
    permission: PERMISSIONS.PAYMENT_METHODS_VIEW,
    capability: 'pos',
    section: SECTION_LABELS.FINANZAS,
  },
  {
    to: '/suppliers',
    label: 'Proveedores',
    icon: Building,
    permission: PERMISSIONS.SUPPLIERS_VIEW,
    capability: 'suppliers',
    section: SECTION_LABELS.FINANZAS,
  },

  // ===== Inventario =====
  {
    to: '/inventory',
    label: 'Inventario',
    icon: Boxes,
    permission: PERMISSIONS.PRODUCTS_VIEW,
    capability: 'inventory',
    section: SECTION_LABELS.INVENTARIO,
    children: [
      {
        to: '/inventory',
        label: 'Productos',
        icon: Package,
        permission: PERMISSIONS.PRODUCTS_VIEW,
      },
      {
        to: '/inventory/catalogs',
        label: 'Catálogos',
        icon: Tag,
        permission: PERMISSIONS.PRODUCTS_VIEW,
      },
      {
        to: '/inventory/currency',
        label: 'Tipos de tasa',
        icon: TrendingUp,
        permission: PERMISSIONS.CURRENCY_VIEW,
      },
      {
        to: '/inventory/manual-movements',
        label: 'Movimientos manuales',
        icon: ClipboardList,
        permission: PERMISSIONS.INVENTORY_MANUAL_MOVEMENTS_VIEW,
      },
      {
        to: '/inventory/admin',
        label: 'Administración',
        icon: Settings,
        permission: PERMISSIONS.PRODUCTS_VIEW,
      },
    ],
  },
  {
    to: '/purchases',
    label: 'Compras',
    icon: ShoppingBag,
    permission: PERMISSIONS.PURCHASES_VIEW,
    capability: 'purchases',
    section: SECTION_LABELS.INVENTARIO,
  },
  {
    to: '/transfers',
    label: 'Traslados',
    icon: Truck,
    permission: PERMISSIONS.INVENTORY_TRANSFERS_VIEW,
    capability: 'inventory_transfers',
    section: SECTION_LABELS.INVENTARIO,
  },
  {
    to: '/inventory-transfer-requests',
    label: 'Solicitudes inter-empresa',
    icon: Building2,
    permission: PERMISSIONS.INVENTORY_TRANSFER_REQUESTS_VIEW,
    capability: 'intercompany',
    section: SECTION_LABELS.INVENTARIO,
  },
  {
    to: '/warranties',
    label: 'Garantías',
    icon: ShieldQuestion,
    permission: PERMISSIONS.WARRANTIES_VIEW,
    capability: 'warranties',
    section: SECTION_LABELS.INVENTARIO,
  },
  {
    to: '/workshop',
    label: 'Taller',
    icon: Wrench,
    permission: PERMISSIONS.SERVICE_ORDERS_VIEW,
    capability: 'workshop',
    section: SECTION_LABELS.INVENTARIO,
  },

  // ===== Analítica =====
  {
    to: '/reports',
    label: 'Reportes',
    icon: BarChart3,
    permissionAny: [PERMISSIONS.REPORTS_VIEW, PERMISSIONS.FINANCE_REPORTS_VIEW],
    capability: 'reports',
    section: SECTION_LABELS.ANALITICA,
  },
  {
    to: '/import',
    label: 'Importar datos',
    icon: Upload,
    permission: PERMISSIONS.DATA_IMPORT_VIEW,
    capability: 'data_import',
    section: SECTION_LABELS.ANALITICA,
  },
  {
    to: '/printing',
    label: 'Impresion',
    icon: Printer,
    permission: PERMISSIONS.PRINTING_VIEW,
    capability: 'printing',
    section: SECTION_LABELS.ANALITICA,
  },

  // ===== Configuración =====
  // Submenu de Acceso (Fase A+B: usuarios; Fase C: roles y permisos;
  // Fase E: catalogo de permisos standalone).
  {
    to: '/users',
    label: 'Acceso',
    icon: ShieldCheck,
    permissionAny: [PERMISSIONS.USERS_VIEW, PERMISSIONS.ROLES_VIEW, PERMISSIONS.TENANTS_VIEW],
    section: SECTION_LABELS.CONFIGURACION,
    children: [
      { to: '/users', label: 'Usuarios', icon: Users, permission: PERMISSIONS.USERS_VIEW },
      {
        to: '/access/roles',
        label: 'Roles y Permisos',
        icon: ShieldCheck,
        permission: PERMISSIONS.ROLES_VIEW,
      },
      {
        to: '/access/permissions',
        label: 'Catálogo de permisos',
        icon: ShieldCheck,
        permission: PERMISSIONS.ROLES_VIEW,
      },
      {
        to: '/access/groups',
        label: 'Organizaciones',
        icon: Building2,
        permission: PERMISSIONS.TENANTS_VIEW,
        hideIfNoOwnedGroup: true,
      },
    ],
  },
  {
    to: '/settings/company',
    label: 'Configuración',
    icon: Settings2,
    permissionAny: [PERMISSIONS.SETTINGS_MANAGE, PERMISSIONS.TENANTS_VIEW],
    section: SECTION_LABELS.CONFIGURACION,
    children: [
      {
        to: '/settings/company',
        label: 'Empresa',
        icon: Building2,
        permissionAny: [PERMISSIONS.SETTINGS_MANAGE, PERMISSIONS.TENANTS_VIEW],
      },
      {
        to: '/settings/simple-mode',
        label: 'Modo Fácil',
        icon: Sparkles,
        permissionAny: [PERMISSIONS.SETTINGS_MANAGE, PERMISSIONS.TENANTS_VIEW],
      },
      {
        to: '/settings/telegram',
        label: 'Telegram',
        icon: Send,
        permissionAny: [PERMISSIONS.SETTINGS_MANAGE, PERMISSIONS.TENANTS_VIEW],
        capability: 'telegram',
      },
      {
        to: '/settings/capabilities',
        label: 'Capacidades y modulos',
        icon: SlidersHorizontal,
        permission: PERMISSIONS.SETTINGS_MANAGE,
      },
    ],
  },
];

export function Sidebar() {
  const [collapsed, setCollapsed] = useState(false);
  const isSimpleMode = useUiModeStore((s) => s.isSimpleMode);
  const visibleRoutes = useUiModeStore((s) => s.visibleRoutes);
  // Cargamos los grupos donde soy Owner para que el item "Organizaciones"
  // aparezca solo si tengo al menos uno. Si el query falla o carga lento,
  // mostramos el item por defecto (la pagina ya maneja el empty state
  // con CTA para crear la primera organizacion).
  const { data: tenantGroups, isError, isLoading } = useTenantGroups();
  const ownedGroupIds = new Set((tenantGroups ?? []).map((g) => g.id));
  const routerState = useRouterState();
  const permissionCtx = useContext(PermissionContext);
  const permissions = permissionCtx?.permissions;
  const capabilities = useSessionStore((state) => state.capabilities);

  const currentPath = routerState.location.pathname;

  // El item "Organizaciones" aparece si:
  //   - El query completo Y tengo grupos -> mostrar.
  //   - El query completo Y NO tengo grupos -> ocultar.
  //   - El query fallo -> mostrar (la pagina /access/groups maneja el empty).
  //   - El query esta loading -> mostrar por defecto (mejor flicker
  //     positivo que tener el item invisible hasta que cargue).
  const loadedOwnedGroups = !isLoading && !isError && tenantGroups !== undefined;
  const shouldHideOrgItem = loadedOwnedGroups && ownedGroupIds.size === 0;
  const usersScope: UsersSearch['scope'] =
    loadedOwnedGroups && ownedGroupIds.size > 0 ? 'organization' : 'tenant';

  const isItemVisible = (item: NavItem): boolean => {
    if (item.hideIfNoOwnedGroup && shouldHideOrgItem) return false;
    if (item.capability && capabilities && capabilities.size > 0 && !capabilities.has(item.capability)) {
      return false;
    }
    if (permissions && item.permission && !permissions.has(item.permission)) {
      return false;
    }
    if (permissions && item.permissionAny && !item.permissionAny.some((p) => permissions.has(p))) {
      return false;
    }
    if (isSimpleMode) {
      const isSettings = item.to === '/settings/company' || item.to.startsWith('/settings');
      const isAllowed = visibleRoutes.includes(item.to);
      const hasAllowedChild = Boolean(
        item.children &&
          item.children.some(
            (c) => visibleRoutes.includes(c.to) || c.to.startsWith('/settings'),
          ),
      );
      if (!isSettings && !isAllowed && !hasAllowedChild) {
        return false;
      }
    }
    if (item.children && item.children.length > 0) {
      const visibleSub = item.children.filter(isItemVisible);
      if (visibleSub.length === 0) return false;
    }
    return true;
  };

  const visibleItems = NAV_ITEMS.filter(isItemVisible);

  const searchForItem = (item: NavItem): UsersSearch | undefined =>
    item.to === '/users' ? { scope: usersScope } : undefined;

  return (
    <aside
      className={cn(
        'bg-gradient-to-b from-[#0F1E36] via-[#142642] to-[#0A1526] text-slate-200 border-r border-slate-800 shadow-xl flex flex-col justify-between shrink-0 transition-[width] duration-200 z-30',
        collapsed ? 'w-16' : 'w-64',
      )}
      aria-label="Navegación principal"
    >
      {/* Brand */}
      <div className="flex h-16 items-center gap-3 border-b border-slate-800/80 bg-[#0A1526]/60 px-3.5">
        <div
          className="size-10 rounded-xl bg-gradient-to-tr from-orange-600 via-orange-500 to-amber-400 p-0.5 shadow-lg shadow-orange-500/20 flex items-center justify-center shrink-0"
          aria-hidden="true"
        >
          <div className="size-full bg-white rounded-[9px] flex items-center justify-center">
            <span className="text-orange-600 font-black tracking-tighter text-sm">RA</span>
          </div>
        </div>
        {!collapsed && (
          <div className="min-w-0">
            <h1 className="truncate text-sm font-bold text-white tracking-tight leading-tight">Repuestos Avilacar</h1>
            <span className="text-[11px] text-amber-300/80 font-medium flex items-center gap-1.5">
              <span className="size-1.5 rounded-full bg-emerald-400 animate-pulse" /> SDI Inventario
            </span>
          </div>
        )}
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto p-3 custom-scrollbar space-y-1.5" aria-label="Módulos">
        <ul className="space-y-1">
          {visibleItems.map((item, index) => {
            const prevSection = visibleItems[index - 1]?.section;
            const isNewSection = item.section != null && item.section !== prevSection;
            const sectionHeader =
              !collapsed && isNewSection ? (
                <div className="text-slate-400 px-3 pt-3 pb-1 text-[11px] font-bold tracking-wider uppercase">
                  {item.section}
                </div>
              ) : null;

            // Si el item tiene children, renderiza submenu anidado.
            if (item.children && item.children.length > 0) {
              const isParentActive =
                currentPath === item.to || currentPath.startsWith(`${item.to}/`);
              return (
                <li key={item.to}>
                  {sectionHeader}
                  <Group
                    item={item}
                    isParentActive={isParentActive}
                    currentPath={currentPath}
                    collapsed={collapsed}
                    usersScope={usersScope}
                    shouldHideOrgItem={shouldHideOrgItem}
                    filterSubItem={isItemVisible}
                  />
                </li>
              );
            }

            const isActive = currentPath === item.to || currentPath.startsWith(`${item.to}/`);

            const linkContent = (
              <Link
                to={item.to}
                search={searchForItem(item)}
                className={cn(
                  'flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-all',
                  isActive
                    ? 'bg-white text-[#0F1E36] font-semibold shadow-md shadow-black/10'
                    : 'text-slate-300 hover:text-white hover:bg-white/10',
                  collapsed && 'justify-center',
                )}
                title={collapsed ? item.label : undefined}
                aria-current={isActive ? 'page' : undefined}
              >
                <item.icon
                  className={cn(
                    'size-4 shrink-0',
                    isActive ? 'text-orange-600' : 'text-slate-400',
                  )}
                  aria-hidden="true"
                />
                {!collapsed && <span className="truncate">{item.label}</span>}
                {!collapsed && item.to === '/inventory-transfer-requests' && (
                  <UnreadTransferRequestsBadge />
                )}
              </Link>
            );

            return (
              <li key={item.to}>
                {sectionHeader}
                {linkContent}
              </li>
            );
          })}
        </ul>
      </nav>

      {/* Collapse */}
      <div className="border-slate-800 border-t p-2 bg-[#0A1526]/60">
        <button
          type="button"
          onClick={() => setCollapsed((v) => !v)}
          className={cn(
            'text-slate-400 hover:bg-white/10 hover:text-white flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-medium transition-colors',
            collapsed && 'justify-center',
          )}
          aria-label={collapsed ? 'Expandir menú' : 'Colapsar menú'}
          aria-expanded={!collapsed}
        >
          {collapsed ? (
            <ChevronRight className="size-4" aria-hidden="true" />
          ) : (
            <>
              <ChevronLeft className="size-4" aria-hidden="true" />
              <span>Colapsar</span>
            </>
          )}
        </button>
      </div>
    </aside>
  );
}

/**
 * Group: renderiza un NavItem que tiene children como un submenu colapsable.
 * Cuando el usuario esta en una ruta del grupo, el submenu se expande.
 */
function Group({
  item,
  isParentActive,
  currentPath,
  collapsed,
  usersScope,
  shouldHideOrgItem,
  filterSubItem,
}: {
  item: NavItem;
  isParentActive: boolean;
  currentPath: string;
  collapsed: boolean;
  usersScope: UsersSearch['scope'];
  shouldHideOrgItem: boolean;
  filterSubItem?: (item: NavItem) => boolean;
}) {
  const [open, setOpen] = useState(isParentActive);
  const visibleChildren = item.children!.filter((sub) => {
    if (sub.hideIfNoOwnedGroup && shouldHideOrgItem) return false;
    if (filterSubItem) return filterSubItem(sub);
    return true;
  });

  if (collapsed) {
    // En modo colapsado, mostramos solo el icono. Click navega al padre
    // (que es la pagina principal del modulo).
    return (
      <Link
        to={item.to}
        search={item.to === '/users' ? { scope: usersScope } : undefined}
        className={cn(
          'flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-medium transition-colors justify-center',
          isParentActive
            ? 'bg-white text-[#0F1E36] font-semibold shadow-md'
            : 'text-slate-300 hover:text-white hover:bg-white/10',
        )}
        title={item.label}
        aria-current={isParentActive ? 'page' : undefined}
      >
        <item.icon
          className={cn(
            'size-4 shrink-0',
            isParentActive ? 'text-orange-600' : 'text-slate-400',
          )}
          aria-hidden="true"
        />
      </Link>
    );
  }

  return (
    <div>
      <div className="flex items-center gap-1">
        <Link
          to={item.to}
          search={item.to === '/users' ? { scope: usersScope } : undefined}
          className={cn(
            'flex flex-1 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-all',
            isParentActive
              ? 'bg-white text-[#0F1E36] font-semibold shadow-md shadow-black/10'
              : 'text-slate-300 hover:text-white hover:bg-white/10',
          )}
          title={item.label}
          aria-current={isParentActive ? 'page' : undefined}
        >
          <item.icon
            className={cn(
              'size-4 shrink-0',
              isParentActive ? 'text-orange-600' : 'text-slate-400',
            )}
            aria-hidden="true"
          />
          <span className="truncate">{item.label}</span>
        </Link>
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="text-slate-400 hover:bg-white/10 hover:text-white rounded-lg p-1.5 transition-colors"
          aria-label={open ? 'Cerrar submenú' : 'Abrir submenú'}
          aria-expanded={open}
        >
          <ChevronDown
            className={cn('size-3.5 transition-transform', open && 'rotate-180')}
            aria-hidden="true"
          />
        </button>
      </div>
      {open && (
        <ul className="border-slate-700/60 mt-1 ml-4 space-y-1 border-l pl-2">
          {visibleChildren.map((sub) => {
            const isSubActive = currentPath === sub.to;
            const linkContent = (
              <Link
                to={sub.to}
                search={sub.to === '/users' ? { scope: usersScope } : undefined}
                className={cn(
                  'flex items-center gap-3 rounded-lg px-2.5 py-1.5 text-sm transition-colors',
                  isSubActive
                    ? 'bg-white/20 text-white font-medium shadow-xs'
                    : 'text-slate-400 hover:bg-white/10 hover:text-white',
                )}
                aria-current={isSubActive ? 'page' : undefined}
              >
                <sub.icon
                  className={cn(
                    'size-3.5 shrink-0',
                    isSubActive ? 'text-orange-400' : 'text-slate-400',
                  )}
                  aria-hidden="true"
                />
                <span className="truncate">{sub.label}</span>
              </Link>
            );

            return (
              <li key={sub.to}>
                {linkContent}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

/**
 * Badge rojo con el contador de solicitudes pendientes para el tenant actual.
 * Solo se monta dentro del item "Solicitudes inter-empresa" del sidebar.
 * Usa el hook useUnreadTransferRequestsCount que polea cada 30s (no 5s)
 * para no castigar al backend cuando el user esta mirando otras pantallas.
 *
 * Si el count es 0, no renderiza nada (deja el label limpio).
 * Si es > 99, muestra "99+" para no romper el layout del sidebar.
 */
function UnreadTransferRequestsBadge() {
  return null;
}
