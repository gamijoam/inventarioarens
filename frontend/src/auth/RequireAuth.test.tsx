import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type * as Router from '@tanstack/react-router';

import { RequireAuth } from './RequireAuth';
import { useSessionStore, AUTH_COOKIE_NAME } from '@/stores/session';

const navigateMock = vi.fn();
vi.mock('@tanstack/react-router', async () => {
  const actual = await vi.importActual<typeof Router>('@tanstack/react-router');
  return {
    ...actual,
    useRouter: () => ({ navigate: navigateMock }),
  };
});

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
  navigateMock.mockReset();
  useSessionStore.setState({
    user: null,
    tenant: null,
    roles: [],
    permissions: new Set(),
    scopeStatus: 'none',
    scopes: emptyScopes,
    expiresAt: null,
  });
  // Limpiar cookie.
  if (typeof document !== 'undefined') {
    document.cookie = `${AUTH_COOKIE_NAME}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  }
});

describe('<RequireAuth> (Plan C: cookie httpOnly + store hidratado)', () => {
  it('renderiza children directamente si ya hay store hidratado (sessión vigente)', () => {
    useSessionStore.getState().setSession({
      expiresAt: '2099-01-01T00:00:00Z',
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
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('muestra spinner + redirige a /login si NO hay cookie httpOnly', async () => {
    // Sin cookie, sin user -> debe redirigir.
    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );

    expect(screen.queryByText('contenido protegido')).not.toBeInTheDocument();
    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith({ to: '/login' });
    });
  });

  it('redirige a /login si expiresAt ya paso', async () => {
    document.cookie = `${AUTH_COOKIE_NAME}=stale; path=/`;
    useSessionStore.setState({
      expiresAt: '2020-01-01T00:00:00Z', // expirado
      user: { id: 1, email: 'u@e.com', name: 'User', is_active: true },
      tenant: { id: 1, slug: 'demo', name: 'Demo', is_active: true },
      roles: [],
      permissions: new Set(['products.view']),
      scopeStatus: 'none',
      scopes: emptyScopes,
    });

    render(
      <RequireAuth>
        <div>contenido protegido</div>
      </RequireAuth>,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith({ to: '/login' });
    });
    // El store se limpio por la sesion expirada.
    expect(useSessionStore.getState().user).toBeNull();
  });

  it('permite renderizar children si hay cookie httpOnly + store hidratado aunque /me no se haya llamado', () => {
    // Caso tipico post-refresh: el usuario abrio la pagina, el store
    // se rehidrato desde localStorage, la cookie esta presente, pero
    // /me no se ha llamado todavia. Los datos persistidos son suficientes
    // para renderizar la UI.
    document.cookie = `${AUTH_COOKIE_NAME}=valid; path=/`;
    useSessionStore.getState().setSession({
      expiresAt: '2099-01-01T00:00:00Z',
      user: { id: 1, email: 'u@e.com', name: 'User', is_active: true },
      tenant: { id: 1, slug: 'demo', name: 'Demo', is_active: true },
      roles: ['Administrador'],
      permissions: ['products.view', 'products.create'],
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
    // No debe navegar ni mostrar spinner.
    expect(navigateMock).not.toHaveBeenCalled();
  });
});