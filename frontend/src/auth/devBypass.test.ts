import { describe, it, expect, beforeEach } from 'vitest';

import { useSessionStore } from '@/stores/session';
import { applyDevSession, isAuthDisabled } from './devBypass';

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
  try {
    localStorage.removeItem('dev_skip_auth');
    localStorage.removeItem('dev_enforce_auth');
    localStorage.removeItem('dev_token');
    localStorage.removeItem('dev_tenant_slug');
  } catch {
    // ignore
  }
});

describe('devBypass', () => {
  it('applyDevSession inyecta sesion con todos los permisos', () => {
    applyDevSession();

    const state = useSessionStore.getState();
    expect(state.token).toBeTruthy();
    expect(state.permissions.size).toBeGreaterThan(0);
    expect(state.user?.email).toBe('dev@local');
    expect(state.tenant?.slug).toBe('dev');
    expect(state.roles).toContain('Administrador');
  });

  it('applyDevSession usa dev_token del localStorage cuando existe', () => {
    localStorage.setItem('dev_token', 'real-bearer-from-backend');
    applyDevSession();

    expect(useSessionStore.getState().token).toBe('real-bearer-from-backend');
  });

  it('applyDevSession usa dev_tenant_slug del localStorage cuando existe', () => {
    localStorage.setItem('dev_tenant_slug', 'mi-empresa');
    applyDevSession();

    expect(useSessionStore.getState().tenant?.slug).toBe('mi-empresa');
  });

  it('isAuthDisabled respeta dev_enforce_auth=1 (fuerza flujo real)', () => {
    localStorage.setItem('dev_enforce_auth', '1');
    expect(isAuthDisabled()).toBe(false);
  });

  it('isAuthDisabled respeta dev_skip_auth=1 (fuerza bypass)', () => {
    localStorage.setItem('dev_skip_auth', '1');
    expect(isAuthDisabled()).toBe(true);
  });
});