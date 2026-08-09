import type { ReactNode } from 'react';

import { usePermissionContext } from '@/permissions/PermissionContext';
import type { PermissionName } from '@/permissions/constants';

export type PosSessionStatus = 'open' | 'closed' | 'loading';
export type PosSyncStatus = 'online' | 'offline' | 'syncing';

export interface PosShellContext {
  tenantName: string;
  branchName: string | null;
  warehouseName: string | null;
  cashRegisterName: string | null;
  sessionStatus: PosSessionStatus;
  syncStatus: PosSyncStatus;
  rateLabel?: string | null;
}

export interface PosShellAction {
  id: string;
  label: string;
  permission: PermissionName | readonly PermissionName[];
  onClick: () => void;
  disabled?: boolean;
  /**
   * Contador mostrado como badge junto a la etiqueta (p. ej. ordenes
   * pendientes). Se renderiza solo si es > 0.
   */
  badge?: number;
  /**
   * Resalta visualmente la accion (p. ej. hay una orden pendiente nueva
   * que la cajera aun no ha revisado).
   */
  alert?: boolean;
}

interface PosShellProps {
  children: ReactNode;
  context?: PosShellContext;
  actions?: readonly PosShellAction[];
  onExit?: () => void | Promise<void>;
  exitDisabled?: boolean;
}

function hasRequiredPermission(
  permissions: ReadonlySet<string>,
  required: PosShellAction['permission'],
): boolean {
  const requiredPermissions: readonly PermissionName[] =
    typeof required === 'string' ? [required] : required;
  return requiredPermissions.some((permission) => permissions.has(permission));
}

function cnAction(alert: boolean | undefined, disabled: boolean | undefined): string {
  const base =
    'border-border bg-bg text-text-secondary hover:text-text-primary relative rounded-md border px-3 py-2 text-xs font-medium transition-colors';
  if (disabled) return `${base} cursor-not-allowed opacity-50`;
  if (alert) return `${base} border-primary/70 bg-primary/10 text-primary font-semibold shadow-sm`;
  return base;
}

function sessionStatusLabel(status: PosSessionStatus): string {
  if (status === 'open') return 'Turno abierto';
  if (status === 'closed') return 'Turno cerrado';
  return 'Cargando turno';
}

function syncStatusLabel(status: PosSyncStatus): string {
  if (status === 'online') return 'Conectado';
  if (status === 'offline') return 'Sin conexión';
  return 'Sincronizando';
}

export function PosShell({
  children,
  context,
  actions = [],
  onExit,
  exitDisabled = false,
}: PosShellProps) {
  const { permissions } = usePermissionContext();
  const visibleActions = actions.filter((action) =>
    hasRequiredPermission(permissions, action.permission),
  );
  const showHeader = Boolean(context) || visibleActions.length > 0;

  return (
    <div data-testid="pos-shell" data-shell="pos" className="bg-bg relative min-h-screen w-full">
      <button
        type="button"
        onClick={() => void onExit?.()}
        disabled={exitDisabled}
        className="bg-surface text-text-secondary hover:text-text-primary absolute top-3 right-3 z-50 rounded-md border px-3 py-2 text-xs font-medium shadow-sm transition-colors"
      >
        Salir del POS
      </button>
      {showHeader && (
        <header
          aria-label="POS"
          className="border-border bg-surface flex min-h-14 flex-wrap items-center gap-3 border-b px-4 py-3 pr-32"
        >
          <div className="min-w-44">
            <h1 className="text-text-primary text-lg leading-tight font-semibold">POS</h1>
            {context && <p className="text-text-muted text-xs">{context.tenantName}</p>}
          </div>
          {context && (
            <>
              <div className="text-text-secondary flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                {context.branchName && (
                  <span data-testid="pos-branch-context">{context.branchName}</span>
                )}
                {context.warehouseName && (
                  <span data-testid="pos-warehouse-context">{context.warehouseName}</span>
                )}
                {context.cashRegisterName && (
                  <span data-testid="pos-register-context">{context.cashRegisterName}</span>
                )}
              </div>
              <div
                className="text-text-muted ml-auto flex flex-wrap items-center gap-2 text-xs"
                aria-live="polite"
              >
                <span>{sessionStatusLabel(context.sessionStatus)}</span>
                <span aria-hidden="true">·</span>
                <span>{syncStatusLabel(context.syncStatus)}</span>
                {context.rateLabel && (
                  <span data-testid="pos-rate-context">{context.rateLabel}</span>
                )}
              </div>
            </>
          )}
          {visibleActions.length > 0 && (
            <div className="flex items-center gap-2" role="group" aria-label="Acciones POS">
              {visibleActions.map((action) => (
                <button
                  key={action.id}
                  type="button"
                  className={cnAction(
                    action.alert,
                    action.disabled,
                  )}
                  onClick={action.onClick}
                  disabled={action.disabled}
                >
                  {action.alert && (
                    <span
                      aria-hidden="true"
                      className="bg-danger absolute -top-1 -left-1 size-2.5 animate-pulse rounded-full"
                    />
                  )}
                  {action.label}
                  {typeof action.badge === 'number' && action.badge > 0 && (
                    <span
                      data-testid={`pos-action-badge-${action.id}`}
                      className="bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold shadow-sm"
                    >
                      {action.badge > 99 ? '99+' : action.badge}
                    </span>
                  )}
                </button>
              ))}
            </div>
          )}
        </header>
      )}
      <main className="min-h-screen">{children}</main>
    </div>
  );
}
