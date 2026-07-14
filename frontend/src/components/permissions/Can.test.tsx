import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';

import { Can } from '@/components/permissions/Can';
import {
  PermissionContext,
  type PermissionContextValue,
} from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

function makeWrapper(perms: string[]) {
  const value: PermissionContextValue = {
    permissions: new Set(perms),
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
  };
  return ({ children }: { children: ReactNode }) => (
    <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>
  );
}

describe('<Can>', () => {
  it('renderiza children si el user tiene el permiso', () => {
    render(
      <Can I={PERMISSIONS.PRODUCTS_CREATE}>
        <button>Crear producto</button>
      </Can>,
      { wrapper: makeWrapper([PERMISSIONS.PRODUCTS_CREATE]) },
    );
    expect(screen.getByRole('button', { name: 'Crear producto' })).toBeInTheDocument();
  });

  it('renderiza fallback si el user NO tiene el permiso', () => {
    render(
      <Can I={PERMISSIONS.PRODUCTS_DELETE} fallback={<span>Sin permiso</span>}>
        <button>Eliminar</button>
      </Can>,
      { wrapper: makeWrapper([PERMISSIONS.PRODUCTS_VIEW]) },
    );
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('Sin permiso')).toBeInTheDocument();
  });

  it('renderiza null por defecto si no hay permiso', () => {
    const { container } = render(
      <Can I={PERMISSIONS.PRODUCTS_DELETE}>
        <button>Eliminar</button>
      </Can>,
      { wrapper: makeWrapper([]) },
    );
    expect(container.innerHTML).not.toContain('Eliminar');
  });
});