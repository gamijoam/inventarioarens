import { describe, it, expect, beforeEach } from 'vitest';
import { useSessionStore } from './session';

const emptyScopes = {
  branches: [],
  warehouses: [],
  customer_groups: [],
  vendor_of: [],
  branches_count: 0,
  warehouses_count: 0,
  customer_groups_count: 0,
  vendor_of_count: 0,
};

beforeEach(() => {
  useSessionStore.setState({
    token: null,
    user: null,
    tenant: null,
    roles: [],
    permissions: new Set(),
    scopeStatus: 'none',
    scopes: emptyScopes,
    expiresAt: null,
  });
});

describe('useSessionStore', () => {
  it('arranca sin sesion', () => {
    const state = useSessionStore.getState();
    expect(state.token).toBeNull();
    expect(state.user).toBeNull();
    expect(state.tenant).toBeNull();
    expect(state.permissions.size).toBe(0);
    expect(state.isAuthenticated()).toBe(false);
  });

  it('setSession guarda token, user, tenant y permisos', () => {
    useSessionStore.getState().setSession({
      token: 'abc123',
      expiresAt: '2030-01-01T00:00:00Z',
      user: { id: 1, email: 'u@e.com', name: 'User', is_active: true },
      tenant: { id: 1, slug: 'demo', name: 'Demo', is_active: true },
      roles: ['Administrador'],
      permissions: ['products.view', 'products.create'],
      scopeStatus: 'none',
      scopes: emptyScopes,
    });

    const state = useSessionStore.getState();
    expect(state.token).toBe('abc123');
    expect(state.user?.email).toBe('u@e.com');
    expect(state.tenant?.slug).toBe('demo');
    expect(state.permissions.has('products.view')).toBe(true);
    expect(state.permissions.has('products.create')).toBe(true);
    expect(state.permissions.has('products.delete')).toBe(false);
    expect(state.isAuthenticated()).toBe(true);
  });

  it('clearSession limpia todo', () => {
    useSessionStore.getState().setSession({
      token: 't',
      expiresAt: '2030-01-01',
      user: { id: 1, email: 'a', name: 'A', is_active: true },
      tenant: { id: 1, slug: 'a', name: 'A', is_active: true },
      roles: [],
      permissions: ['products.view'],
      scopeStatus: 'none',
      scopes: emptyScopes,
    });
    useSessionStore.getState().clearSession();
    const state = useSessionStore.getState();
    expect(state.token).toBeNull();
    expect(state.permissions.size).toBe(0);
    expect(state.isAuthenticated()).toBe(false);
  });

  it('setTenant solo cambia el tenant activo (mantiene token + permisos)', () => {
    useSessionStore.getState().setSession({
      token: 't',
      expiresAt: '2030-01-01',
      user: { id: 1, email: 'a', name: 'A', is_active: true },
      tenant: { id: 1, slug: 'a', name: 'A', is_active: true },
      roles: [],
      permissions: ['products.view'],
      scopeStatus: 'none',
      scopes: emptyScopes,
    });
    useSessionStore.getState().setTenant({ id: 2, slug: 'b', name: 'B', is_active: true });
    expect(useSessionStore.getState().tenant?.slug).toBe('b');
    expect(useSessionStore.getState().token).toBe('t');
  });
});