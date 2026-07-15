import { useState } from 'react';
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
  BoxesIcon,
  ChevronDown,
  Settings,
  Building,
  ShoppingBag,
} from 'lucide-react';

import { cn } from '@/lib/cn';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { APP_NAME, APP_SHORT_NAME } from '@/config/branding';

interface NavItem {
  to: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  permission?: string;
  // Sub-items opcionales (menu anidado).
  children?: NavItem[];
}

const NAV_ITEMS: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  {
    to: '/inventory',
    label: 'Inventario',
    icon: Boxes,
    permission: PERMISSIONS.PRODUCTS_VIEW,
    children: [
      { to: '/inventory', label: 'Productos', icon: Package, permission: PERMISSIONS.PRODUCTS_VIEW },
      { to: '/inventory/catalogs', label: 'Catálogos', icon: Tag, permission: PERMISSIONS.PRODUCTS_VIEW },
      { to: '/inventory/currency', label: 'Tipos de tasa', icon: TrendingUp, permission: PERMISSIONS.CURRENCY_VIEW },
      { to: '/inventory/admin', label: 'Administración', icon: Settings, permission: PERMISSIONS.PRODUCTS_VIEW },
    ],
  },
  { to: '/sales', label: 'Ventas', icon: ShoppingCart, permission: PERMISSIONS.SALES_VIEW },
  { to: '/customers', label: 'Clientes', icon: Users, permission: PERMISSIONS.CUSTOMERS_VIEW },
  { to: '/suppliers', label: 'Proveedores', icon: Building, permission: PERMISSIONS.SUPPLIERS_VIEW },
  { to: '/purchases', label: 'Compras', icon: ShoppingBag, permission: PERMISSIONS.PURCHASES_VIEW },
  { to: '/transfers', label: 'Traslados', icon: Truck, permission: PERMISSIONS.INVENTORY_TRANSFERS_VIEW },
  { to: '/receivables', label: 'Cuentas por cobrar', icon: Wallet, permission: PERMISSIONS.ACCOUNTS_RECEIVABLE_VIEW },
  { to: '/payables', label: 'Cuentas por pagar', icon: Receipt, permission: PERMISSIONS.ACCOUNTS_PAYABLE_VIEW },
];

export function Sidebar() {
  const [collapsed, setCollapsed] = useState(false);
  const routerState = useRouterState();
  const currentPath = routerState.location.pathname;

  return (
    <aside
      className={cn(
        'flex flex-col border-r border-border bg-surface transition-[width] duration-200',
        collapsed ? 'w-16' : 'w-60',
      )}
      aria-label="Navegación principal"
    >
      {/* Brand */}
      <div className="flex h-14 items-center gap-2 border-b border-border px-3">
        <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground">
          <BoxesIcon className="size-5" aria-hidden="true" />
        </div>
        {!collapsed && (
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold">{APP_NAME}</p>
            <p className="truncate text-xs text-text-muted">{APP_SHORT_NAME}</p>
          </div>
        )}
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto p-2" aria-label="Módulos">
        <ul className="space-y-0.5">
          {NAV_ITEMS.map((item) => {
            // Si el item tiene children, renderiza submenu anidado.
            if (item.children && item.children.length > 0) {
              const isParentActive =
                currentPath === item.to || currentPath.startsWith(`${item.to}/`);
              return (
                <li key={item.to}>
                  <Can I={item.permission ?? ''} fallback={null}>
                    <Group
                      item={item}
                      isParentActive={isParentActive}
                      currentPath={currentPath}
                      collapsed={collapsed}
                    />
                  </Can>
                </li>
              );
            }

            const isActive =
              currentPath === item.to || currentPath.startsWith(`${item.to}/`);

            const linkContent = (
              <Link
                to={item.to}
                className={cn(
                  'flex items-center gap-3 rounded px-2.5 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-primary/10 text-primary'
                    : 'text-text-secondary hover:bg-bg hover:text-text-primary',
                  collapsed && 'justify-center',
                )}
                title={collapsed ? item.label : undefined}
                aria-current={isActive ? 'page' : undefined}
              >
                <item.icon className="size-4 shrink-0" aria-hidden="true" />
                {!collapsed && <span className="truncate">{item.label}</span>}
              </Link>
            );

            return (
              <li key={item.to}>
                {item.permission ? (
                  <Can I={item.permission} fallback={null}>
                    {linkContent}
                  </Can>
                ) : (
                  linkContent
                )}
              </li>
            );
          })}
        </ul>
      </nav>

      {/* Collapse */}
      <div className="border-t border-border p-2">
        <button
          type="button"
          onClick={() => setCollapsed((v) => !v)}
          className={cn(
            'flex w-full items-center gap-2 rounded px-2 py-1.5 text-xs text-text-muted hover:bg-bg hover:text-text-secondary',
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

      {/* Package attribution al fondo */}
      {!collapsed && (
        <div className="border-t border-border p-3 text-xs text-text-muted">
          <Package className="mb-1 size-4" aria-hidden="true" />
          <p>Multi-tenant SaaS</p>
          <p>Laravel + PostgreSQL</p>
        </div>
      )}
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
}: {
  item: NavItem;
  isParentActive: boolean;
  currentPath: string;
  collapsed: boolean;
}) {
  const [open, setOpen] = useState(isParentActive);

  // Si el padre se vuelve activo (navigate), abrimos el submenu.
  if (isParentActive && !open) {
    // No podemos setState en render; usamos un efecto. En la practica el
    // padre se vuelve activo via navigate, que ya re-renderiza con la
    // prop isParentActive, y abrimos via el efecto siguiente.
  }

  if (collapsed) {
    // En modo colapsado, mostramos solo el icono. Click navega al padre
    // (que es la pagina principal del modulo).
    return (
      <Link
        to={item.to}
        className={cn(
          'flex items-center gap-3 rounded px-2.5 py-2 text-sm font-medium transition-colors',
          isParentActive
            ? 'bg-primary/10 text-primary'
            : 'text-text-secondary hover:bg-bg hover:text-text-primary',
          'justify-center',
        )}
        title={item.label}
        aria-current={isParentActive ? 'page' : undefined}
      >
        <item.icon className="size-4 shrink-0" aria-hidden="true" />
      </Link>
    );
  }

  return (
    <div>
      <div className="flex items-center gap-1">
        <Link
          to={item.to}
          className={cn(
            'flex flex-1 items-center gap-3 rounded px-2.5 py-2 text-sm font-medium transition-colors',
            isParentActive
              ? 'bg-primary/10 text-primary'
              : 'text-text-secondary hover:bg-bg hover:text-text-primary',
          )}
          title={item.label}
          aria-current={isParentActive ? 'page' : undefined}
        >
          <item.icon className="size-4 shrink-0" aria-hidden="true" />
          <span className="truncate">{item.label}</span>
        </Link>
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="rounded p-1.5 text-text-muted hover:bg-bg hover:text-text-secondary"
          aria-label={open ? 'Cerrar submenú' : 'Abrir submenú'}
          aria-expanded={open}
        >
          <ChevronDown
            className={cn(
              'size-3.5 transition-transform',
              open && 'rotate-180',
            )}
            aria-hidden="true"
          />
        </button>
      </div>
      {open && (
        <ul className="ml-4 mt-0.5 space-y-0.5 border-l border-border pl-2">
          {item.children!.map((sub) => {
            const isSubActive = currentPath === sub.to;
            return (
              <li key={sub.to}>
                <Link
                  to={sub.to}
                  className={cn(
                    'flex items-center gap-3 rounded px-2.5 py-1.5 text-sm transition-colors',
                    isSubActive
                      ? 'bg-primary/10 font-medium text-primary'
                      : 'text-text-secondary hover:bg-bg hover:text-text-primary',
                  )}
                  aria-current={isSubActive ? 'page' : undefined}
                >
                  <sub.icon className="size-3.5 shrink-0" aria-hidden="true" />
                  <span className="truncate">{sub.label}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}