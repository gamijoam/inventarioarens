import { describe, it, expect, beforeEach } from 'vitest';

import {
  useSessionStore,
  hasAuthCookie,
  hasAuthCookieWithValue,
  AUTH_COOKIE_NAME,
} from './session';

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
    user: null,
    tenant: null,
    roles: [],
    permissions: new Set(),
    scopeStatus: 'none',
    scopes: emptyScopes,
    expiresAt: null,
  });
  // Limpiar cookies del documento.
  if (typeof document !== 'undefined') {
    document.cookie = `${AUTH_COOKIE_NAME}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  }
});

describe('useSessionStore (Plan C: cookie httpOnly)', () => {
  it('arranca sin sesion (sin user, sin tenant, sin permissions)', () => {
    const state = useSessionStore.getState();
    expect(state.user).toBeNull();
    expect(state.tenant).toBeNull();
    expect(state.permissions.size).toBe(0);
    expect(state.hasSession()).toBe(false);
  });

  it('NO tiene token en el state (vive en cookie httpOnly)', () => {
    // Verificamos explícitamente que el modelo Plan C no expone `token`.
    const state = useSessionStore.getState();
    expect('token' in state).toBe(false);
  });

  it('setSession guarda user, tenant, expiresAt, permissions (sin token)', () => {
    useSessionStore.getState().setSession({
      expiresAt: '2030-01-01T00:00:00Z',
      user: { id: 1, email: 'u@e.com', name: 'User', is_active: true },
      tenant: { id: 1, slug: 'demo', name: 'Demo', is_active: true },
      roles: ['Administrador'],
      permissions: ['products.view', 'products.create'],
      scopeStatus: 'none',
      scopes: emptyScopes,
    });

    const state = useSessionStore.getState();
    expect(state.expiresAt).toBe('2030-01-01T00:00:00Z');
    expect(state.user?.email).toBe('u@e.com');
    expect(state.tenant?.slug).toBe('demo');
    expect(state.permissions.has('products.view')).toBe(true);
    expect(state.permissions.has('products.create')).toBe(true);
    expect(state.hasSession()).toBe(true);
  });

  it('clearSession limpia todo el state', () => {
    useSessionStore.getState().setSession({
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
    expect(state.user).toBeNull();
    expect(state.tenant).toBeNull();
    expect(state.permissions.size).toBe(0);
    expect(state.hasSession()).toBe(false);
  });

  it('setTenant solo cambia el tenant activo (mantiene user + permisos)', () => {
    useSessionStore.getState().setSession({
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
    expect(useSessionStore.getState().user?.email).toBe('a');
  });
});

describe('hasAuthCookie (sync detection desde document.cookie)', () => {
  it('retorna false si no hay cookie', () => {
    document.cookie = `${AUTH_COOKIE_NAME}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    expect(hasAuthCookie()).toBe(false);
  });

  it('retorna true si hay cookie', () => {
    document.cookie = `${AUTH_COOKIE_NAME}=some-token-value; path=/`;
    expect(hasAuthCookie()).toBe(true);
  });

  it('ignora otras cookies que empiezan con "auth" pero no son "auth_token"', () => {
    document.cookie = `auth_other=foo; path=/`;
    document.cookie = `${AUTH_COOKIE_NAME}=real; path=/`;
    expect(hasAuthCookie()).toBe(true);
  });

  it('hasAuthCookieWithValue retorna el valor de la cookie', () => {
    document.cookie = `${AUTH_COOKIE_NAME}=real-token-xyz; path=/`;
    expect(hasAuthCookieWithValue()).toBe('real-token-xyz');
  });

  it('hasAuthCookieWithValue retorna null si no hay cookie', () => {
    document.cookie = `${AUTH_COOKIE_NAME}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    expect(hasAuthCookieWithValue()).toBeNull();
  });
});