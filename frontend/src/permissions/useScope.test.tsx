import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { type ReactNode, type ReactElement } from 'react';

import { useHasScope, useScopeStatus } from './useScope';
import {
  PermissionContext,
  type PermissionContextValue,
} from '@/permissions/PermissionContext';

function makeWrapper(scopeStatus: 'none' | 'allow' | 'restrict', branches: number[] = []) {
  const value: PermissionContextValue = {
    permissions: new Set(),
    roles: [],
    scopeStatus,
    scopes: {
      branches,
      warehouses: [],
      customer_groups: [],
      vendor_of: [],
      branches_count: branches.length,
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

describe('useScopeStatus', () => {
  it('devuelve el estado actual del scope', () => {
    const { result: none } = renderHook(() => useScopeStatus(), { wrapper: makeWrapper('none') });
    expect(none.current).toBe('none');
    const { result: restrict } = renderHook(() => useScopeStatus(), {
      wrapper: makeWrapper('restrict'),
    });
    expect(restrict.current).toBe('restrict');
  });
});

describe('useHasScope', () => {
  it('default-allow cuando scopeStatus es "none"', () => {
    const { result } = renderHook(() => useHasScope('branches', 5), {
      wrapper: makeWrapper('none', []),
    });
    expect(result.current).toBe(true);
  });

  it('default-allow cuando scopeStatus es "allow"', () => {
    const { result } = renderHook(() => useHasScope('branches', 5), {
      wrapper: makeWrapper('allow', []),
    });
    expect(result.current).toBe(true);
  });

  it('restringe cuando scopeStatus es "restrict" y el ID no esta en la lista', () => {
    const { result } = renderHook(() => useHasScope('branches', 5), {
      wrapper: makeWrapper('restrict', [1, 2, 3]),
    });
    expect(result.current).toBe(false);
  });

  it('permite cuando scopeStatus es "restrict" y el ID esta en la lista', () => {
    const { result } = renderHook(() => useHasScope('branches', 2), {
      wrapper: makeWrapper('restrict', [1, 2, 3]),
    });
    expect(result.current).toBe(true);
  });
});