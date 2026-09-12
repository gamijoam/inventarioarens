import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { useSessionStore } from '@/stores/session';
import { useAuth } from '../useAuth';
import * as authApi from '@/api/endpoints/auth';

vi.mock('@/api/endpoints/auth', () => ({
  me: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  platformLogin: vi.fn(),
  switchTenantApi: vi.fn(),
  lookupTenants: vi.fn(),
}));

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
}

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

describe('useAuth - refreshSession (TDD session preservation)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useSessionStore.setState({
      user: { id: 1, email: 'admin@repuestosavilacar.com', name: 'Admin', is_platform_admin: true, is_active: true },
      tenant: { id: 1, slug: 'repuestos-avilacar', name: 'Repuestos Avilacar', is_active: true },
      roles: ['Owner'],
      permissions: new Set(['inventory.view', 'pos.view']),
      capabilities: new Set(['inventory', 'pos']),
      scopeStatus: 'none',
      scopes: emptyScopes,
      expiresAt: '2099-01-01T00:00:00Z',
    });
  });

  it('actualiza capacidades cuando /me responde exitosamente con tenant', async () => {
    vi.mocked(authApi.me).mockResolvedValue({
      expires_at: '2099-01-01T00:00:00Z',
      user: { id: 1, email: 'admin@repuestosavilacar.com', name: 'Admin', is_platform_admin: true },
      tenant: { id: 1, slug: 'repuestos-avilacar', name: 'Repuestos Avilacar', is_active: true },
      roles: ['Owner'],
      permissions: ['inventory.view', 'pos.view'],
      capabilities: ['inventory', 'pos', 'cash_register'],
      scope_status: 'none',
      scopes: emptyScopes,
    } as any);

    const { result } = renderHook(() => useAuth(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.refreshSession();
    });

    const state = useSessionStore.getState();
    expect(state.tenant).not.toBeNull();
    expect(state.tenant?.slug).toBe('repuestos-avilacar');
    expect(state.capabilities.has('cash_register')).toBe(true);
  });

  it('NO borra el tenant activo si /me responde tenant nulo por ser platform admin', async () => {
    // Escenario de la regresión: el backend devuelve tenant: null para usuarios platform_admin
    // incluso estando en sesión de empresa. refreshSession NO debe pisar tenant con null.
    vi.mocked(authApi.me).mockResolvedValue({
      expires_at: '2099-01-01T00:00:00Z',
      user: { id: 1, email: 'admin@repuestosavilacar.com', name: 'Admin', is_platform_admin: true },
      tenant: null,
      roles: [],
      permissions: [],
      capabilities: [],
      scope_status: 'none',
      scopes: emptyScopes,
    } as any);

    const { result } = renderHook(() => useAuth(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.refreshSession();
    });

    const state = useSessionStore.getState();
    // El tenant activo previo NO debe haber sido destruido
    expect(state.tenant).not.toBeNull();
    expect(state.tenant?.slug).toBe('repuestos-avilacar');
    // Las permissions previas no deben haber sido vaciadas a Set() vacío
    expect(state.permissions.size).toBeGreaterThan(0);
    expect(state.roles.length).toBeGreaterThan(0);
  });

  it('mantiene la sesión intacta si /me lanza un error de red', async () => {
    vi.mocked(authApi.me).mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useAuth(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.refreshSession();
    });

    const state = useSessionStore.getState();
    expect(state.user).not.toBeNull();
    expect(state.tenant).not.toBeNull();
    expect(state.tenant?.slug).toBe('repuestos-avilacar');
  });

  it('signIn resuelve directo cuando usuario pertenece a una sola empresa', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      requires_tenant_selection: false,
      token: 'bearer-token-123',
      expires_at: '2099-01-01T00:00:00Z',
      user: { id: 2, email: 'single@example.com', name: 'Single User', is_active: true },
      tenant: { id: 10, slug: 'empresa-solitaria', name: 'Empresa Solitaria', is_active: true },
      roles: ['Vendedor'],
      permissions: ['pos.view'],
      capabilities: ['pos'],
      scope_status: 'none',
      scopes: emptyScopes,
    } as any);

    const { result } = renderHook(() => useAuth(), { wrapper: createWrapper() });

    let signInResult: any;
    await act(async () => {
      signInResult = await result.current.signIn({
        email: 'single@example.com',
        password: 'secret',
      });
    });

    expect(signInResult).toEqual({ requiresSelection: false });
    const state = useSessionStore.getState();
    expect(state.user?.email).toBe('single@example.com');
    expect(state.tenant?.slug).toBe('empresa-solitaria');
    expect(state.pendingTenants).toHaveLength(0);
  });

  it('signIn guarda selección pendiente cuando backend responde requires_tenant_selection = true', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      requires_tenant_selection: true,
      token: 'interim-token-456',
      expires_at: '2099-01-01T00:00:00Z',
      user: { id: 3, email: 'multi@example.com', name: 'Multi User', is_active: true },
      tenant: null,
      tenants: [
        { id: 1, slug: 'empresa-1', name: 'Empresa 1', is_active: true },
        { id: 2, slug: 'empresa-2', name: 'Empresa 2', is_active: true },
      ],
      roles: [],
      permissions: [],
      capabilities: [],
    } as any);

    const { result } = renderHook(() => useAuth(), { wrapper: createWrapper() });

    let signInResult: any;
    await act(async () => {
      signInResult = await result.current.signIn({
        email: 'multi@example.com',
        password: 'secret',
      });
    });

    expect(signInResult.requiresSelection).toBe(true);
    expect(signInResult.tenants).toHaveLength(2);
    const state = useSessionStore.getState();
    expect(state.user?.email).toBe('multi@example.com');
    expect(state.tenant).toBeNull();
    expect(state.pendingTenants).toHaveLength(2);
  });

  it('selectCompany ejecuta switchTo y limpia pendingTenants', async () => {
    useSessionStore.getState().setPendingSelection(
      { id: 3, email: 'multi@example.com', name: 'Multi User', is_active: true },
      [
        { id: 1, slug: 'empresa-1', name: 'Empresa 1', is_active: true },
        { id: 2, slug: 'empresa-2', name: 'Empresa 2', is_active: true },
      ],
    );

    vi.mocked(authApi.switchTenantApi).mockResolvedValue({
      expires_at: '2099-01-01T00:00:00Z',
      user: { id: 3, email: 'multi@example.com', name: 'Multi User', is_active: true },
      tenant: { id: 2, slug: 'empresa-2', name: 'Empresa 2', is_active: true },
      roles: ['Vendedor'],
      permissions: ['pos.view'],
      capabilities: ['pos'],
      scope_status: 'none',
      scopes: emptyScopes,
    } as any);

    const { result } = renderHook(() => useAuth(), { wrapper: createWrapper() });

    await act(async () => {
      await result.current.selectCompany('empresa-2');
    });

    const state = useSessionStore.getState();
    expect(state.tenant?.slug).toBe('empresa-2');
    expect(state.pendingTenants).toHaveLength(0);
  });
});
