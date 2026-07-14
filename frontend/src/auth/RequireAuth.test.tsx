import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type * as Router from '@tanstack/react-router';

import { RequireAuth } from './RequireAuth';
import { useSessionStore } from '@/stores/session';

vi.mock('@/api/endpoints/auth', () => ({
  me: vi.fn(),
}));

const navigateMock = vi.fn();
vi.mock('@tanstack/react-router', async () => {
  const actual = await vi.importActual<typeof Router>('@tanstack/react-router');
  return {
    ...actual,
    useRouter: () => ({ navigate: navigateMock }),
  };
});

import { me as apiMe } from '@/api/endpoints/auth';
const apiMeMock = vi.mocked(apiMe);

function makeWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0 },
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

beforeEach(() => {
  apiMeMock.mockReset();
  navigateMock.mockReset();
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

describe('<RequireAuth>', () => {
  it('muestra spinner inicial si no hay sesion', async () => {
    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );
    // Antes de que el effect de no-token dispare la navegacion, muestra spinner.
    expect(screen.queryByText('contenido protegido')).not.toBeInTheDocument();
    // El effect termina navegando a /login.
    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith({ to: '/login' });
    });
  });

  it('renderiza children directamente si ya hay token + permissions cargadas', () => {
    useSessionStore.getState().setSession({
      token: 'token-ok',
      expiresAt: '2030-01-01T00:00:00Z',
      user: { id: 1, email: 'u@e.com', name: 'User', is_active: true },
      tenant: { id: 1, slug: 'demo', name: 'Demo', is_active: true },
      roles: ['Administrador'],
      permissions: ['products.view'],
      scopeStatus: 'none',
      scopes: emptyScopes,
    });

    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );

    expect(screen.getByText('contenido protegido')).toBeInTheDocument();
    expect(apiMeMock).not.toHaveBeenCalled();
  });

  it('dispara /me y rehidrata permissions cuando hay token pero NO permissions (post-refresh)', async () => {
    useSessionStore.setState({ token: 'token-stale' });

    apiMeMock.mockResolvedValueOnce({
      user: { id: 1, email: 'u@e.com', name: 'User', is_active: true },
      tenant: { id: 1, slug: 'demo', name: 'Demo', is_active: true },
      roles: [{ id: 1, name: 'Administrador', slug: 'admin', is_protected: true }],
      permissions: ['products.view', 'products.create'],
      expires_at: '2030-01-01T00:00:00Z',
      scope_status: 'none',
      scopes: emptyScopes,
    });

    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );

    expect(screen.queryByText('contenido protegido')).not.toBeInTheDocument();
    expect(apiMeMock).toHaveBeenCalledTimes(1);

    await waitFor(() => {
      expect(screen.getByText('contenido protegido')).toBeInTheDocument();
    });

    const state = useSessionStore.getState();
    expect(state.permissions.has('products.view')).toBe(true);
    expect(state.permissions.has('products.create')).toBe(true);
    expect(state.user?.email).toBe('u@e.com');
  });

  it('limpia sesion y redirige a /login si /me falla (401)', async () => {
    useSessionStore.setState({ token: 'token-expirado' });

    const error = new Error('Unauthorized') as Error & { status?: number };
    error.status = 401;
    apiMeMock.mockRejectedValueOnce(error);

    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith({ to: '/login' });
    });

    expect(useSessionStore.getState().token).toBeNull();
  });

  it('redirige a /login cuando no hay token (caso: usuario borro localStorage)', async () => {
    // El store arranca sin token (initial state). Renderizamos RequireAuth
    // y esperamos que el effect de "no-token" navegue a /login.
    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith({ to: '/login' });
    });

    expect(apiMeMock).not.toHaveBeenCalled();
  });
});
