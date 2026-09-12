import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';

const mockUseSuppliers = vi.fn();
const mockCreateSupplier = vi.fn();
const mockUpdateSupplier = vi.fn();
const mockDeleteSupplier = vi.fn();

vi.mock('@/features/suppliers/api', () => ({
  useSuppliers: (filters: unknown) => mockUseSuppliers(filters),
  useCreateSupplier: () => ({ mutateAsync: mockCreateSupplier, isPending: false }),
  useUpdateSupplier: () => ({ mutateAsync: mockUpdateSupplier, isPending: false }),
  useDeleteSupplier: () => ({ mutateAsync: mockDeleteSupplier, isPending: false }),
}));

import { SuppliersManager } from '../SuppliersManager';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const fakeSuppliers = [
  {
    id: 1,
    name: 'Distribuidora Repuestos Los Andes',
    document_type: 'J',
    document_number: '123456789',
    phone: '+58 212 5551234',
    email: 'contacto@losandes.com',
    fiscal_address: 'Av. Libertador, Caracas',
    notes: 'Descuento 5% pronto pago',
    is_active: true,
  },
  {
    id: 2,
    name: 'Ferretería y Autopartes San José',
    document_type: 'J',
    document_number: '987654321',
    phone: '+58 414 1112233',
    email: 'ventas@sanjose.com',
    fiscal_address: null,
    notes: null,
    is_active: false,
  },
];

describe('SuppliersManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('permite escribir fluidamente en el buscador sin perder el foco ni desmontar el input', async () => {
    const user = userEvent.setup();

    // Al inicio retorna datos sin filtro
    mockUseSuppliers.mockImplementation((filters: { search?: string }) => {
      // Si hay un search que simula carga de nueva query, isLoading es true si buscó algo nuevo
      if (filters?.search) {
        return { data: [], isLoading: true, isFetching: true };
      }
      return { data: fakeSuppliers, isLoading: false, isFetching: false };
    });

    render(<SuppliersManager />, { wrapper: makeWrapper() });

    const searchInput = screen.getByPlaceholderText(/Buscar por nombre o documento/i);
    expect(searchInput).toBeInTheDocument();

    // Enfocar y escribir "Repuestos"
    await user.click(searchInput);
    expect(searchInput).toHaveFocus();

    await user.type(searchInput, 'Repuestos');

    // El input DEBE mantener el valor completo y seguir en el documento y enfocado
    expect(searchInput).toBeInTheDocument();
    expect(searchInput).toHaveValue('Repuestos');
    expect(searchInput).toHaveFocus();
  });

  it('no desmonta la barra de búsqueda cuando la consulta inicial o de búsqueda está cargando', async () => {
    mockUseSuppliers.mockReturnValue({
      data: [],
      isLoading: true,
      isFetching: true,
    });

    render(<SuppliersManager />, { wrapper: makeWrapper() });

    // La barra de búsqueda DEBE permanecer presente aunque la lista esté cargando
    const searchInput = screen.getByPlaceholderText(/Buscar por nombre o documento/i);
    expect(searchInput).toBeInTheDocument();
  });

  it('filtra proveedores y muestra estado vacío cuando no hay resultados', async () => {
    const user = userEvent.setup();

    mockUseSuppliers.mockImplementation((filters: { search?: string }) => {
      if (filters?.search === 'Inexistente') {
        return { data: [], isLoading: false, isFetching: false };
      }
      return { data: fakeSuppliers, isLoading: false, isFetching: false };
    });

    render(<SuppliersManager />, { wrapper: makeWrapper() });

    expect(screen.getByText('Distribuidora Repuestos Los Andes')).toBeInTheDocument();

    const searchInput = screen.getByPlaceholderText(/Buscar por nombre o documento/i);
    await user.type(searchInput, 'Inexistente');

    await waitFor(() => {
      expect(screen.getByText('Sin resultados')).toBeInTheDocument();
    });
  });
});
