import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetOne = vi.fn();
const mockPostOne = vi.fn();

vi.mock('@/api/client', () => ({
  getOne: (path: string): unknown => mockGetOne(path) as unknown,
  postOne: (...args: unknown[]): unknown => mockPostOne(...args) as unknown,
}));

import {
  useCreateSyncGroupPairingCode,
  useGroupSpinoffs,
  useGroupUsers,
} from '@/features/access/tenantGroupsApi';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('tenantGroupsApi', () => {
  beforeEach(() => {
    mockGetOne.mockReset();
    mockPostOne.mockReset();
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

  it('crea un codigo de vinculacion para el grupo completo', async () => {
    mockPostOne.mockResolvedValue({
      code: 'ARNS-GROUP-CODE',
      expires_at: '2026-08-07T22:00:00Z',
      group: { id: 1, name: 'Grupo Danubio', slug: 'danubio' },
      tenants: [{ id: 2, name: 'Danubio', slug: 'danubio' }],
      node_name: 'POS-01',
    });

    const { result } = renderHook(() => useCreateSyncGroupPairingCode(), { wrapper });
    const response = await result.current.mutateAsync({
      user_email: 'owner@danubio.test',
      node_name: 'POS-01',
      expires_in_minutes: 15,
    });

    expect(response.tenants).toHaveLength(1);
    expect(mockPostOne).toHaveBeenCalledWith('/sync/pairing-codes/group', {
      user_email: 'owner@danubio.test',
      node_name: 'POS-01',
      expires_in_minutes: 15,
    });
  });
});
