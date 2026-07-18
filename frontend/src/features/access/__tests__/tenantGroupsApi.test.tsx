import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: (path: string) => mockGetOne(path),
}));

import { useGroupSpinoffs, useGroupUsers } from '@/features/access/tenantGroupsApi';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('tenantGroupsApi', () => {
  beforeEach(() => {
    mockGetOne.mockReset();
  });

  it('useGroupUsers acepta respuestas paginadas de Laravel', async () => {
    mockGetOne.mockResolvedValue({
      data: [
        {
          id: 100,
          name: 'Usuario Danubio',
          email: 'usuario@danubio.test',
          status: 'active',
          roles: [{ id: 1, name: 'Administrador' }],
          tenants: [{ id: 2, name: 'Danubio', slug: 'danubio', is_group: false }],
        },
      ],
      current_page: 1,
      total: 1,
    });

    const { result } = renderHook(() => useGroupUsers(1), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toHaveLength(1);
    expect(result.current.data?.[0]?.email).toBe('usuario@danubio.test');
  });

  it('useGroupSpinoffs conserva compatibilidad con arrays directos', async () => {
    mockGetOne.mockResolvedValue([
      {
        id: 2,
        name: 'Danubio',
        slug: 'danubio',
        status: 'active',
        users_count: 2,
      },
    ]);

    const { result } = renderHook(() => useGroupSpinoffs(1), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toHaveLength(1);
    expect(result.current.data?.[0]?.slug).toBe('danubio');
  });
});
