import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { type ReactNode, type ReactElement } from 'react';

import { useCan, useCanAny, useCanAll } from './useCan';
import {
  PermissionContext,
  type PermissionContextValue,
} from './PermissionContext';
import { PERMISSIONS } from './constants';

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
  function Wrapper({ children }: { children: ReactNode }): ReactElement {
    return <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>;
  }
  return Wrapper;
}

describe('useCan', () => {
  it('devuelve true si tiene el permiso', () => {
    const { result } = renderHook(() => useCan(PERMISSIONS.PRODUCTS_VIEW), {
      wrapper: makeWrapper([PERMISSIONS.PRODUCTS_VIEW, PERMISSIONS.PRODUCTS_CREATE]),
    });
    expect(result.current).toBe(true);
  });

  it('devuelve false si no tiene el permiso', () => {
    const { result } = renderHook(() => useCan(PERMISSIONS.PRODUCTS_DELETE), {
      wrapper: makeWrapper([PERMISSIONS.PRODUCTS_VIEW]),
    });
    expect(result.current).toBe(false);
  });
});

describe('useCanAny', () => {
  it('devuelve true si tiene al menos uno', () => {
    const { result } = renderHook(
      () => useCanAny([PERMISSIONS.SALES_VIEW, PERMISSIONS.SALES_CREATE]),
      { wrapper: makeWrapper([PERMISSIONS.SALES_VIEW]) },
    );
    expect(result.current).toBe(true);
  });

  it('devuelve false si no tiene ninguno', () => {
    const { result } = renderHook(
      () => useCanAny([PERMISSIONS.SALES_VIEW, PERMISSIONS.SALES_CREATE]),
      { wrapper: makeWrapper([PERMISSIONS.PRODUCTS_VIEW]) },
    );
    expect(result.current).toBe(false);
  });
});

describe('useCanAll', () => {
  it('devuelve true solo si tiene todos', () => {
    const { result } = renderHook(
      () => useCanAll([PERMISSIONS.PRODUCTS_VIEW, PERMISSIONS.PRODUCTS_CREATE]),
      { wrapper: makeWrapper([PERMISSIONS.PRODUCTS_VIEW, PERMISSIONS.PRODUCTS_CREATE]) },
    );
    expect(result.current).toBe(true);
  });

  it('devuelve false si falta uno', () => {
    const { result } = renderHook(
      () => useCanAll([PERMISSIONS.PRODUCTS_VIEW, PERMISSIONS.PRODUCTS_CREATE]),
      { wrapper: makeWrapper([PERMISSIONS.PRODUCTS_VIEW]) },
    );
    expect(result.current).toBe(false);
  });
});