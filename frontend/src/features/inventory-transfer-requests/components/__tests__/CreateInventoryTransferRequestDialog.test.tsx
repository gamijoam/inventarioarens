/**
 * Tests para CreateInventoryTransferRequestDialog (bandeja inter-empresa).
 *
 * IMPORTANTE: Este dialog NO incluye ImeiScanner. Los IMEIs/seriales
 * especificos los elige la empresa DESTINO al aceptar la solicitud.
 * Aqui solo se eligen producto + cantidad.
 *
 * Cubre:
 *   - Renderiza el Combobox con las empresas hermanas del hook.
 *   - Seleccionar una empresa actualiza el estado y muestra preview.
 *   - Input de email como alternativa (caso edge).
 *   - Submit con slug de empresa envia destination_tenant_slug al backend.
 *   - Submit con email envia destination_user_email al backend.
 *   - Mensaje de warning cuando no hay empresas hermanas disponibles.
 *   - NO incluye ImeiScanner (es responsabilidad del Accept dialog).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const mockCreateMutateAsync = vi.fn();
let lastMutation: unknown = null;

const mockUseSiblingCompanies = vi.fn();
const mockUseWarehouses = vi.fn();
const mockUseProductsForTransfer = vi.fn();

vi.mock('@/features/inventory-transfer-requests/api', () => ({
  useCreateTransferRequest: () => ({
    mutateAsync: async (values: unknown) => {
      lastMutation = values;
      await mockCreateMutateAsync(values);
      return { id: 999 };
    },
  }),
  useSiblingCompanies: (...args: unknown[]) => mockUseSiblingCompanies(...args),
}));

vi.mock('@/features/inventory-center/api', () => ({
  useWarehouses: () => mockUseWarehouses(),
  useAvailableProductUnits: () => ({ data: [], isLoading: false, isError: false }),
}));

vi.mock('@/features/transfers/api', () => ({
  useProductsForTransfer: () => mockUseProductsForTransfer(),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: {
    getState: () => ({
      tenant: { id: 5, slug: 'mi-empresa', parent_id: 1, is_group: false },
    }),
  },
  hasAuthCookie: () => true,
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { CreateInventoryTransferRequestDialog } from '../CreateInventoryTransferRequestDialog';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const SIBLINGS = [
  { id: 2, name: 'Demo Caracas Norte', slug: 'demo-caracas-norte', users_count: 1 },
  { id: 3, name: 'Demo Valencia Centro', slug: 'demo-valencia-centro', users_count: 1 },
];

describe('CreateInventoryTransferRequestDialog', () => {
  beforeEach(() => {
    mockCreateMutateAsync.mockReset();
    mockCreateMutateAsync.mockResolvedValue({ id: 999 });
    lastMutation = null;
    mockUseSiblingCompanies.mockReset();
    mockUseWarehouses.mockReset();
    mockUseProductsForTransfer.mockReset();
    mockUseWarehouses.mockReturnValue({ data: [{ id: 10, code: 'W1' }] });
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 100, name: 'Producto A', sku: 'PA', tracking_type: 'quantity' }],
    });
  });

  it('renderiza el dialog con el Combobox de empresas hermanas', () => {
    mockUseSiblingCompanies.mockReturnValue({ data: SIBLINGS, isLoading: false });
    render(<CreateInventoryTransferRequestDialog open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    expect(screen.getByTestId('dest-company')).toBeInTheDocument();
    const select = screen.getByTestId('dest-company') as HTMLSelectElement;
    expect(select.options).toHaveLength(1 + SIBLINGS.length);
    expect(select.options[1]?.text).toContain('Demo Caracas Norte');
    expect(select.options[2]?.text).toContain('Demo Valencia Centro');
  });

  it('muestra preview en vivo al seleccionar una empresa hermana', async () => {
    mockUseSiblingCompanies.mockReturnValue({ data: SIBLINGS, isLoading: false });
    const user = userEvent.setup();
    render(<CreateInventoryTransferRequestDialog open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    await user.selectOptions(screen.getByTestId('dest-company'), 'demo-valencia-centro');
    const preview = await screen.findByTestId('dest-preview');
    expect(preview).toHaveTextContent('Demo Valencia Centro');
    expect(preview).toHaveTextContent('demo-valencia-centro');
  });

  it('muestra warning si no hay empresas hermanas', () => {
    mockUseSiblingCompanies.mockReturnValue({ data: [], isLoading: false });
    render(<CreateInventoryTransferRequestDialog open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    expect(screen.getByText(/no pertenece a un grupo/i)).toBeInTheDocument();
  });

  it('NO muestra ImeiScanner (los IMEIs se eligen al aceptar, no al crear)', () => {
    mockUseSiblingCompanies.mockReturnValue({ data: SIBLINGS, isLoading: false });
    mockUseProductsForTransfer.mockReturnValue({
      data: [{ id: 100, name: 'iPhone 15', sku: 'IP15-001', tracking_type: 'serialized' }],
    });
    render(<CreateInventoryTransferRequestDialog open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    expect(screen.queryByTestId('item-imeis-0')).not.toBeInTheDocument();
  });

  it('submit con empresa seleccionada envia destination_tenant_slug al backend', async () => {
    mockUseSiblingCompanies.mockReturnValue({ data: SIBLINGS, isLoading: false });
    const user = userEvent.setup();
    render(<CreateInventoryTransferRequestDialog open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    await user.selectOptions(screen.getByTestId('dest-company'), 'demo-caracas-norte');
    await user.selectOptions(screen.getByLabelText(/almacen origen/i), '10');
    const qtyInput = screen.getByTestId('item-qty-0') as HTMLInputElement;
    await user.clear(qtyInput);
    await user.type(qtyInput, '5');
    await user.selectOptions(screen.getByTestId('item-product-0'), '100');

    await user.click(screen.getByTestId('submit-create'));

    await waitFor(() => {
      expect(mockCreateMutateAsync).toHaveBeenCalledTimes(1);
    });
    const payload = lastMutation as {
      destination_tenant_slug?: string;
      destination_user_email?: string;
      from_warehouse_id?: number;
      items?: { product_id: number; quantity: number }[];
    };
    expect(payload.destination_tenant_slug).toBe('demo-caracas-norte');
    expect(payload.destination_user_email).toBeUndefined();
    expect(payload.from_warehouse_id).toBe(10);
    expect(payload.items?.[0]).toEqual({ product_id: 100, quantity: 5 });
  });

  it('submit con email envia destination_user_email (sin slug)', async () => {
    mockUseSiblingCompanies.mockReturnValue({ data: SIBLINGS, isLoading: false });
    const user = userEvent.setup();
    render(<CreateInventoryTransferRequestDialog open onOpenChange={() => undefined} />, {
      wrapper: makeWrapper(),
    });
    const emailInput = screen.getByLabelText(/email usuario destino/i);
    await user.type(emailInput, 'admin@otra-empresa.com');
    await user.selectOptions(screen.getByLabelText(/almacen origen/i), '10');
    const qtyInput = screen.getByTestId('item-qty-0') as HTMLInputElement;
    await user.clear(qtyInput);
    await user.type(qtyInput, '3');
    await user.selectOptions(screen.getByTestId('item-product-0'), '100');

    await user.click(screen.getByTestId('submit-create'));

    await waitFor(() => {
      expect(mockCreateMutateAsync).toHaveBeenCalledTimes(1);
    });
    const payload = lastMutation as {
      destination_tenant_slug?: string;
      destination_user_email?: string;
    };
    expect(payload.destination_user_email).toBe('admin@otra-empresa.com');
    expect(payload.destination_tenant_slug).toBeUndefined();
  });
});
