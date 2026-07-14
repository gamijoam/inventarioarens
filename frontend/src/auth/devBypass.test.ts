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

describe('devBypass (Plan C: cookie httpOnly no manipulable)', () => {
  it('applyDevSession inyecta sesion con todos los permisos en el store local', () => {
    applyDevSession();

    const state = useSessionStore.getState();
    expect(state.user?.email).toBe('dev@local');
    expect(state.tenant?.slug).toBe('dev');
    expect(state.permissions.size).toBeGreaterThan(0);
    expect(state.roles).toContain('Administrador');
  });

  it('applyDevSession NO setea token (vive en cookie, no en store)', () => {
    // Verificamos que el store Plan C no expone token.
    applyDevSession();
    const state = useSessionStore.getState();
    expect('token' in state).toBe(false);
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

  it('isAuthDisabled retorna true por defecto (bypass activo)', () => {
    expect(isAuthDisabled()).toBe(true);
  });
});