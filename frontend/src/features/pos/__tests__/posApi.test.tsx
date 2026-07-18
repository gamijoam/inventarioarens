import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetPaginated = vi.fn();
const mockGetOne = vi.fn();
const mockPostOne = vi.fn();

vi.mock('@/api/client', () => ({
  getMany: vi.fn(),
  getOne: (path: string) => mockGetOne(path),
  getPaginated: (path: string) => mockGetPaginated(path),
  patchOne: vi.fn(),
  postOne: (path: string, body: unknown) => mockPostOne(path, body),
}));

import { useAvailableProductSerialsForPos, useBranchesForPos, useCashSessions, useCheckout, useCreateCashRegister, useCreateCustomerForPos, useCreatePaymentMethod, useOpenCashSession, usePosProducts } from '../api';

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('pos api', () => {
  beforeEach(() => {
    mockGetPaginated.mockReset();
    mockGetOne.mockReset();
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

  it('lee solo sesiones abiertas del cajero actual para POS', async () => {
    mockGetPaginated.mockResolvedValue({
      data: [{
        id: 3,
        branch_id: 1,
        cash_register_id: 5,
        cashier_id: 9,
        status: 'open',
        opening_base_amount: '0.0000',
        expected_base_amount: '0.0000',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });

    const { result } = renderHook(() => useCashSessions(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetPaginated).toHaveBeenCalledWith('/cash-register/sessions?status=open&cashier_id=me&per_page=25');
    expect(result.current.data?.[0]?.cash_register_id).toBe(5);
  });

  it('lee IMEIs disponibles por producto y almacen para POS', async () => {
    mockGetOne.mockResolvedValue({
      data: [{ id: 10, serial_type: 'imei', serial_number: '860001000000001', status: 'available', warehouse_id: 4 }],
      pagination: { page: 1, total: 1 },
    });

    const { result } = renderHook(() => useAvailableProductSerialsForPos(5, 4), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockGetOne).toHaveBeenCalledWith('/inventory-center/products/5/serials?status=available&limit=100&warehouse_id=4');
    expect(result.current.data?.[0]?.serial_number).toBe('860001000000001');
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

  it('abre turno con fondos iniciales USD y VES', async () => {
    mockPostOne.mockResolvedValue({
      id: 8,
      branch_id: 1,
      cash_register_id: 5,
      status: 'open',
      opening_base_amount: '30.0000',
      opening_local_amount: '5000.0000',
      expected_base_amount: '30.0000',
      expected_local_amount: '5000.0000',
    });

    const { result } = renderHook(() => useOpenCashSession(), { wrapper });
    result.current.mutate({
      branch_id: 1,
      cash_register_id: 5,
      opening_base_amount: 25,
      opening_local_amount: 5000,
      exchange_rate_type_id: 2,
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/cash-register/sessions', {
      branch_id: 1,
      cash_register_id: 5,
      opening_base_amount: 25,
      opening_local_amount: 5000,
      exchange_rate_type_id: 2,
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

  it('envia checkout POS a credito con vencimiento opcional', async () => {
    mockPostOne.mockResolvedValue({ id: 11, status: 'paid', sale_id: 22 });

    const { result } = renderHook(() => useCheckout(), { wrapper });
    result.current.mutate({
      cash_register_session_id: 3,
      customer_id: 5,
      customer_name: 'Cliente Credito',
      credit: true,
      credit_due_date: '2026-08-01',
      items: [{ warehouse_id: 1, product_id: 2, quantity: 1 }],
      payments: [],
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockPostOne).toHaveBeenCalledWith('/pos/checkouts', {
      cash_register_session_id: 3,
      customer_id: 5,
      customer_name: 'Cliente Credito',
      credit: true,
      credit_due_date: '2026-08-01',
      items: [{ warehouse_id: 1, product_id: 2, quantity: 1 }],
      payments: [],
    });
  });
});
