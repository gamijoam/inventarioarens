import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PERMISSIONS } from '@/permissions/constants';

const permissionState = vi.hoisted(() => ({ permissions: new Set<string>() }));

vi.mock('@tanstack/react-router', () => ({
  Link: ({ to, children, ...props }: { to: string; children: ReactNode }) => (
    <a href={to} {...props}>
      {children}
    </a>
  ),
}));

vi.mock('@/permissions/PermissionContext', () => ({
  usePermissionContext: () => ({
    permissions: permissionState.permissions,
    roles: [],
    scopeStatus: 'none',
    scopes: {
      branches: [],
      warehouses: [],
      customer_groups: [],
      vendor_of: [],
      branches_count: 0,
      warehouses_count: 0,
      customer_groups_count: 0,
      vendor_of_count: 0,
    },
  }),
}));

import { PosShell } from '../PosShell';

beforeEach(() => {
  permissionState.permissions = new Set();
});

describe('<PosShell>', () => {
  it('define una superficie POS de pantalla completa y conserva su contenido', () => {
    render(
      <PosShell>
        <p>Terminal operativo</p>
      </PosShell>,
    );

    const shell = screen.getByTestId('pos-shell');

    expect(shell).toHaveAttribute('data-shell', 'pos');
    expect(shell.className).toContain('min-h-screen');
    expect(shell.className).toContain('w-full');
    expect(screen.getByText('Terminal operativo')).toBeInTheDocument();
    expect(screen.queryByRole('banner', { name: 'POS' })).not.toBeInTheDocument();
  });

  it('ofrece una salida explícita sin incluir navegación administrativa', () => {
    const onExit = vi.fn();
    render(<PosShell onExit={onExit}>Contenido POS</PosShell>);

    fireEvent.click(screen.getByRole('button', { name: 'Salir del POS' }));

    expect(onExit).toHaveBeenCalledTimes(1);
    expect(
      screen.queryByRole('navigation', { name: 'Navegación principal' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByText('Inventario')).not.toBeInTheDocument();
    expect(screen.queryByText('Cuentas por cobrar')).not.toBeInTheDocument();
  });

  it('muestra el contexto operativo y el estado de conectividad cuando se proporciona', () => {
    render(
      <PosShell
        context={{
          tenantName: 'Danubio',
          branchName: 'Soledad',
          warehouseName: 'Almacén Principal',
          cashRegisterName: 'Caja 1',
          sessionStatus: 'open',
          syncStatus: 'online',
          rateLabel: 'BCV @ 36.5',
        }}
      >
        Contenido POS
      </PosShell>,
    );

    expect(screen.getByRole('banner', { name: 'POS' })).toBeInTheDocument();
    expect(screen.getByText('Danubio')).toBeInTheDocument();
    expect(screen.getByText('Soledad')).toBeInTheDocument();
    expect(screen.getByText('Almacén Principal')).toBeInTheDocument();
    expect(screen.getByText('Caja 1')).toBeInTheDocument();
    expect(screen.getByText('Turno abierto')).toBeInTheDocument();
    expect(screen.getByText('Conectado')).toBeInTheDocument();
    expect(screen.getByText('BCV @ 36.5')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Acciones POS' })).not.toBeInTheDocument();
  });

  it.each([
    ['closed', 'offline', 'Turno cerrado', 'Sin conexión'],
    ['loading', 'syncing', 'Cargando turno', 'Sincronizando'],
  ] as const)(
    'representa el estado %s/%s del contexto POS',
    (sessionStatus, syncStatus, sessionLabel, syncLabel) => {
      render(
        <PosShell
          context={{
            tenantName: 'Danubio',
            branchName: null,
            warehouseName: null,
            cashRegisterName: null,
            sessionStatus,
            syncStatus,
          }}
        >
          Contenido POS
        </PosShell>,
      );

      expect(screen.getByText(sessionLabel)).toBeInTheDocument();
      expect(screen.getByText(syncLabel)).toBeInTheDocument();
      expect(screen.queryByTestId('pos-branch-context')).not.toBeInTheDocument();
      expect(screen.queryByTestId('pos-warehouse-context')).not.toBeInTheDocument();
      expect(screen.queryByTestId('pos-register-context')).not.toBeInTheDocument();
    },
  );

  it('no muestra una tasa cuando el contexto no tiene snapshot de tasa', () => {
    render(
      <PosShell
        context={{
          tenantName: 'Danubio',
          branchName: null,
          warehouseName: null,
          cashRegisterName: null,
          rateLabel: null,
          sessionStatus: 'open',
          syncStatus: 'online',
        }}
      >
        Contenido POS
      </PosShell>,
    );

    expect(screen.queryByTestId('pos-rate-context')).not.toBeInTheDocument();
  });

  it('filtra las acciones del shell con los permisos efectivos del usuario', () => {
    permissionState.permissions = new Set([
      PERMISSIONS.CASH_REGISTER_CLOSE,
      PERMISSIONS.CASH_REGISTER_MOVE,
    ]);

    render(
      <PosShell
        actions={[
          {
            id: 'close-session',
            label: 'Cerrar turno',
            permission: PERMISSIONS.CASH_REGISTER_CLOSE,
            onClick: vi.fn(),
          },
          {
            id: 'cash-movements',
            label: 'Movimientos de caja',
            permission: [PERMISSIONS.CASH_REGISTER_MOVE, PERMISSIONS.CASH_REGISTER_MOVEMENTS],
            onClick: vi.fn(),
          },
          {
            id: 'reprint',
            label: 'Reimprimir',
            permission: PERMISSIONS.PRINTING_REPRINT,
            onClick: vi.fn(),
          },
        ]}
      >
        Contenido POS
      </PosShell>,
    );

    expect(screen.getByRole('button', { name: 'Cerrar turno' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Movimientos de caja' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reimprimir' })).not.toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Acciones POS' })).toBeInTheDocument();
  });

  it('ejecuta el callback de una acción visible', () => {
    const onClick = vi.fn();
    permissionState.permissions = new Set([PERMISSIONS.POS_VIEW]);

    render(
      <PosShell
        actions={[
          { id: 'pending', label: 'Pendientes', permission: PERMISSIONS.POS_VIEW, onClick },
        ]}
      >
        Contenido POS
      </PosShell>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Pendientes' }));

    expect(onClick).toHaveBeenCalledOnce();
  });

  it('muestra el badge de conteo y no lo dibuja cuando es cero', () => {
    permissionState.permissions = new Set([PERMISSIONS.POS_VIEW]);
    const { rerender } = render(
      <PosShell
        actions={[
          {
            id: 'pending',
            label: 'Pendientes',
            permission: PERMISSIONS.POS_VIEW,
            onClick: vi.fn(),
            badge: 4,
          },
        ]}
      >
        Contenido POS
      </PosShell>,
    );

    expect(screen.getByTestId('pos-action-badge-pending')).toHaveTextContent('4');

    rerender(
      <PosShell
        actions={[
          {
            id: 'pending',
            label: 'Pendientes',
            permission: PERMISSIONS.POS_VIEW,
            onClick: vi.fn(),
            badge: 0,
          },
        ]}
      >
        Contenido POS
      </PosShell>,
    );

    expect(screen.queryByTestId('pos-action-badge-pending')).not.toBeInTheDocument();
  });

  it('resalta la accion cuando la alerta de orden nueva esta activa', () => {
    permissionState.permissions = new Set([PERMISSIONS.POS_VIEW]);

    render(
      <PosShell
        actions={[
          {
            id: 'pending',
            label: 'Pendientes',
            permission: PERMISSIONS.POS_VIEW,
            onClick: vi.fn(),
            alert: true,
            badge: 3,
          },
        ]}
      >
        Contenido POS
      </PosShell>,
    );

    const badge = screen.getByTestId('pos-action-badge-pending');
    const button = badge.closest('button');

    expect(button).not.toBeNull();
    expect(button?.className).toContain('text-primary');
    expect(button?.className).toContain('font-semibold');
    expect(badge).toHaveTextContent('3');
  });
});
