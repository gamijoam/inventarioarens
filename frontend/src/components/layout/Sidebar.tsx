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
  ChevronLeft,
  ChevronRight,
  BoxesIcon,
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
}

const NAV_ITEMS: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/inventory', label: 'Inventario', icon: Boxes, permission: PERMISSIONS.PRODUCTS_VIEW },
  { to: '/sales', label: 'Ventas', icon: ShoppingCart, permission: PERMISSIONS.SALES_VIEW },
  { to: '/customers', label: 'Clientes', icon: Users, permission: PERMISSIONS.CUSTOMERS_VIEW },
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