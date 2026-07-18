import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetPaginated = vi.fn();
const mockPostOne = vi.fn();

vi.mock('@/api/client', () => ({
  getMany: vi.fn(),
  getPaginated: (path: string) => mockGetPaginated(path),
  patchOne: vi.fn(),
  postOne: (path: string, body: unknown) => mockPostOne(path, body),
}));

import { useBranchesForPos, useCreateCashRegister, useCreateCustomerForPos, useCreatePaymentMethod, usePosProducts } from '../api';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('pos api', () => {
  beforeEach(() => {
    mockGetPaginated.mockReset();
    mockPostOne.mockReset();
  });

  it('lee sucursales desde respuesta paginada', async () => {
    mockGetPaginated.mockResolvedValue({
      data: [{ id: 1, code: 'MAIN', name: 'Principal', status: 'active' }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
    });

    const { result } = renderHook(() => useBranchesForPos(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetPaginated).toHaveBeenCalledWith('/branches?per_page=100');
    expect(result.current.data?.[0]?.name).toBe('Principal');
  });

  it('permite desactivar busqueda POS de productos', () => {
    const { result } = renderHook(() => usePosProducts('', 1, { enabled: false }), { wrapper });

    expect(result.current.fetchStatus).toBe('idle');
    expect(mockGetPaginated).not.toHaveBeenCalled();
  });

  it('crea caja fisica en el endpoint de cash register', async () => {
    mockPostOne.mockResolvedValue({ id: 5, name: 'Caja 1', code: 'CJ1', branch_id: 1 });

    const { result } = renderHook(() => useCreateCashRegister(), { wrapper });
    result.current.mutate({ branch_id: 1, name: 'Caja 1', code: 'CJ1', status: 'active' });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/cash-register/registers', {
      branch_id: 1,
      name: 'Caja 1',
      code: 'CJ1',
      status: 'active',
    });
  });

  it('crea metodo de pago operativo para POS', async () => {
    mockPostOne.mockResolvedValue({ id: 7, name: 'Pago movil', code: 'PM', method: 'mobile_payment' });

    const { result } = renderHook(() => useCreatePaymentMethod(), { wrapper });
    result.current.mutate({
      name: 'Pago movil',
      code: 'PM',
      method: 'mobile_payment',
      currency_mode: 'VES',
      requires_reference: true,
      is_active: true,
      sort_order: 10,
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/payment-methods', {
      name: 'Pago movil',
      code: 'PM',
      method: 'mobile_payment',
      currency_mode: 'VES',
      requires_reference: true,
      is_active: true,
      sort_order: 10,
    });
  });

  it('crea cliente rapido desde POS usando el modulo de clientes', async () => {
    mockPostOne.mockResolvedValue({ id: 9, name: 'Cliente POS', document_type: 'V', document_number: '123' });

    const { result } = renderHook(() => useCreateCustomerForPos(), { wrapper });
    result.current.mutate({
      name: 'Cliente POS',
      document_type: 'V',
      document_number: '123',
      phone: null,
      email: null,
      fiscal_address: null,
      is_active: true,
      is_generic: false,
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/customers', {
      name: 'Cliente POS',
      document_type: 'V',
      document_number: '123',
      phone: null,
      email: null,
      fiscal_address: null,
      is_active: true,
      is_generic: false,
    });
  });
});
