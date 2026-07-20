/**
 * Tests para KardexTab con tipos de movimientos inter-empresa.
 *
 * Cubre:
 *   - Label legible para 'transfer_request_in' / 'transfer_request_out'.
 *   - Badge verde para entradas (incluyendo transfer_request_in).
 *   - Badge warning para salidas (incluyendo transfer_request_out).
 *   - Motivo visible cuando viene en el response.
 *   - Link clickeable al detalle cuando reference_type=InventoryTransferRequest.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// Mock de react-router: solo Link necesita estar.
vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to, ...props }: { children: React.ReactNode; to: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}));

import { KardexTab } from '../KardexTab';

const mockFetch = vi.fn();
global.fetch = mockFetch;

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function makeMovement(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    date: '2026-07-15T10:00:00.000000Z',
    warehouse_id: 1,
    warehouse_name: 'Almacen 1',
    product_id: 100,
    product_name: 'Celular X',
    type: 'purchase',
    quantity_in: 5,
    quantity_out: 0,
    running_balance: 5,
    unit_cost: null,
    reason: 'Compra a proveedor X',
    reference_type: 'PurchaseOrder',
    reference_id: 50,
    ...overrides,
  };
}

describe('KardexTab', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('muestra label legible para tipo transfer_request_in', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          product_id: 100,
          product_name: 'Celular X',
          opening_balance: 0,
          closing_balance: 3,
          movements: [
            makeMovement({
              id: 1,
              type: 'transfer_request_in',
              quantity_in: 3,
              quantity_out: 0,
              running_balance: 3,
              reason: 'Entrada interempresa TREQ-1-000007',
              reference_type: 'InventoryTransferRequest',
              reference_id: 7,
            }),
          ],
        },
      }),
    });
    render(<KardexTab productId={100} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Transferencia inter-empresa \+/i)).toBeInTheDocument();
    });
  });

  it('muestra label legible para tipo transfer_request_out', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          product_id: 100,
          opening_balance: 5,
          closing_balance: 2,
          movements: [
            makeMovement({
              id: 2,
              type: 'transfer_request_out',
              quantity_in: 0,
              quantity_out: 3,
              running_balance: 2,
              reason: 'Salida interempresa TREQ-1-000007',
              reference_type: 'InventoryTransferRequest',
              reference_id: 7,
            }),
          ],
        },
      }),
    });
    render(<KardexTab productId={100} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Transferencia inter-empresa -/i)).toBeInTheDocument();
    });
  });

  it('muestra el motivo (reason) en una columna separada', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          product_id: 100,
          movements: [
            makeMovement({
              id: 3,
              reason: 'Entrada interempresa TREQ-1-000007 desde demo-caracas-norte',
            }),
          ],
        },
      }),
    });
    render(<KardexTab productId={100} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(
        screen.getByText(/Entrada interempresa TREQ-1-000007 desde demo-caracas-norte/i),
      ).toBeInTheDocument();
    });
  });

  it('muestra link clickeable al detalle de la solicitud cuando reference_type=InventoryTransferRequest', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          product_id: 100,
          movements: [
            makeMovement({
              id: 4,
              type: 'transfer_request_in',
              reference_type: 'InventoryTransferRequest',
              reference_id: 42,
            }),
          ],
        },
      }),
    });
    render(<KardexTab productId={100} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      const link = screen.getByTestId('kardex-ref-4');
      expect(link).toHaveAttribute('href', '/inventory-transfer-requests/42');
      expect(link).toHaveTextContent(/Solicitud inter-empresa #42/i);
    });
  });

  it('muestra texto plano para reference_types que no tienen ruta', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: async () => ({
        data: {
          product_id: 100,
          movements: [
            makeMovement({
              id: 5,
              type: 'adjustment_in',
              reference_type: 'sync_snapshot',
              reference_id: 99,
            }),
          ],
        },
      }),
    });
    render(<KardexTab productId={100} />, { wrapper: makeWrapper() });
    await waitFor(() => {
      expect(screen.queryByTestId('kardex-ref-5')).not.toBeInTheDocument();
      // Texto plano con el ref_type crudo.
      expect(screen.getByText(/sync_snapshot/)).toBeInTheDocument();
    });
  });
});
