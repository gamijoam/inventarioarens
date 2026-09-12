import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

const mockUseCustomers = vi.fn();
const mockCreateCustomer = vi.fn();
const mockUpdateCustomer = vi.fn();
const mockDeleteCustomer = vi.fn();

vi.mock('@/features/customers/api', () => ({
  useCustomers: (filters: unknown) => mockUseCustomers(filters),
  useCreateCustomer: () => ({ mutateAsync: mockCreateCustomer, isPending: false }),
  useUpdateCustomer: () => ({ mutateAsync: mockUpdateCustomer, isPending: false }),
  useDeleteCustomer: () => ({ mutateAsync: mockDeleteCustomer, isPending: false }),
}));

import { CustomersManager } from '../CustomersManager';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeCustomers = [
  {
    id: 1,
    name: 'Carlos Perez',
    document_type: 'V',
    document_number: '12345678',
    phone: '+58 412 1234567',
    email: 'carlos@example.com',
    address: 'Av. Bolivar, Caracas',
    is_active: true,
    is_generic: false,
    credit_balance_base_amount: 0,
  },
  {
    id: 2,
    name: 'Inversiones Ávila C.A.',
    document_type: 'J',
    document_number: '987654321',
    phone: '+58 212 9876543',
    email: 'avila@example.com',
    address: null,
    is_active: true,
    is_generic: false,
    credit_balance_base_amount: 50,
  },
];

describe('CustomersManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('permite escribir fluidamente en el buscador sin perder el foco ni desmontar el input', async () => {
    const user = userEvent.setup();

    mockUseCustomers.mockImplementation((filters: { search?: string }) => {
      if (filters?.search) {
        return { data: [], isLoading: true, isFetching: true };
      }
      return { data: fakeCustomers, isLoading: false, isFetching: false };
    });

    render(<CustomersManager />, { wrapper: makeWrapper() });

    const searchInput = screen.getByPlaceholderText(/Buscar por nombre o documento/i);
    expect(searchInput).toBeInTheDocument();

    await user.click(searchInput);
    expect(searchInput).toHaveFocus();

    await user.type(searchInput, 'Carlos');

    expect(searchInput).toBeInTheDocument();
    expect(searchInput).toHaveValue('Carlos');
    expect(searchInput).toHaveFocus();
  });

  it('no desmonta la barra de búsqueda cuando la consulta inicial o de búsqueda está cargando', async () => {
    mockUseCustomers.mockReturnValue({
      data: [],
      isLoading: true,
      isFetching: true,
    });

    render(<CustomersManager />, { wrapper: makeWrapper() });

    const searchInput = screen.getByPlaceholderText(/Buscar por nombre o documento/i);
    expect(searchInput).toBeInTheDocument();
  });

  it('filtra clientes y muestra estado vacío cuando no hay resultados', async () => {
    const user = userEvent.setup();

    mockUseCustomers.mockImplementation((filters: { search?: string }) => {
      if (filters?.search === 'Inexistente') {
        return { data: [], isLoading: false, isFetching: false };
      }
      return { data: fakeCustomers, isLoading: false, isFetching: false };
    });

    render(<CustomersManager />, { wrapper: makeWrapper() });

    expect(screen.getByText('Carlos Perez')).toBeInTheDocument();

    const searchInput = screen.getByPlaceholderText(/Buscar por nombre o documento/i);
    await user.type(searchInput, 'Inexistente');

    await waitFor(() => {
      expect(screen.getByText('Sin resultados')).toBeInTheDocument();
    });
  });

  it('muestra el placeholder "Nombre del cliente" en el campo nombre al abrir el diálogo de nuevo cliente', async () => {
    const user = userEvent.setup();
    mockUseCustomers.mockReturnValue({
      data: fakeCustomers,
      isLoading: false,
      isFetching: false,
    });

    render(<CustomersManager />, { wrapper: makeWrapper() });

    const newBtn = screen.getByRole('button', { name: /nuevo cliente/i });
    await user.click(newBtn);

    const nameInput = screen.getByPlaceholderText('Nombre del cliente');
    expect(nameInput).toBeInTheDocument();
  });
});
