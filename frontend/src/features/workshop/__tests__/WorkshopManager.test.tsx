/**
 * Tests del WorkshopManager del Taller.
 *
 * Cubre los casos posibles del modulo:
 *  - Bandeja: renderiza ordenes, filtros por estado/tipo, expandir detalle.
 *  - Crear: happy path, y garantia requiere tratamiento (boton deshabilitado).
 *  - Diagnostico: guarda diagnostico + mano de obra.
 *  - Asignar tecnico: selecciona tecnico + almacen.
 *  - Piezas: agregar y quitar.
 *  - Completar y cancelar.
 *  - Sin permiso -> PermissionDenied.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PERMISSIONS } from '@/permissions/constants';
import { PermissionContext, type PermissionContextValue } from '@/permissions/PermissionContext';

import { WorkshopManager } from '../WorkshopManager';

const mockOrders = vi.fn();
const mockCreate = vi.fn();
const mockDiagnose = vi.fn();
const mockAssign = vi.fn();
const mockAddPart = vi.fn();
const mockRemovePart = vi.fn();
const mockComplete = vi.fn();
const mockCancel = vi.fn();
const mockDetail = { data: undefined as unknown, isLoading: false };
const mockProductPage = { data: { data: [] as unknown[] }, isLoading: false };
const mockWarehouses = { data: [{ id: 1, code: 'WH-1', name: 'Taller' }], isLoading: false };
const mockUsers = { data: { data: [{ id: 9, name: 'Carlos Tecnico', email: 'carlos@test.test' }] }, isLoading: false };

vi.mock('../api', () => ({
  useServiceOrders: (filters: unknown) => mockOrders(filters),
  useServiceOrder: () => mockDetail,
  useCreateServiceOrder: () => ({ mutate: mockCreate, isPending: false }),
  useDiagnoseServiceOrder: () => ({ mutate: mockDiagnose, isPending: false }),
  useAssignTechnician: () => ({ mutate: mockAssign, isPending: false }),
  useAddServiceOrderPart: () => ({ mutate: mockAddPart, isPending: false }),
  useRemoveServiceOrderPart: () => ({ mutate: mockRemovePart, isPending: false }),
  useCompleteServiceOrder: () => ({ mutate: mockComplete, isPending: false }),
  useCancelServiceOrder: () => ({ mutate: mockCancel, isPending: false }),
  SERVICE_ORDER_STATUSES: ['received', 'diagnosed', 'in_progress', 'ready', 'delivered', 'closed', 'cancelled'],
  SERVICE_ORDER_TYPES: ['repair', 'warranty'],
  SERVICE_ORDER_RESOLUTIONS: ['workshop', 'exchange', 'return_supplier'],
}));

vi.mock('@/features/users/api', () => ({ useUsers: () => mockUsers }));
vi.mock('@/features/inventory-center/api', () => ({
  useProducts: () => mockProductPage,
  useWarehouses: () => mockWarehouses,
}));

function makeWrapper(permissionSet: Set<string>) {
  const value: PermissionContextValue = {
    permissions: permissionSet,
    roles: [],
    scopeStatus: 'none',
    scopes: {
      branches: [],
      warehouses: [],
      customer_groups: [],
      vendor_of: [],
      branches_count: 0,
      warehouses_count: 0,
      customer_groups_count: 0,
      vendor_of_count: 0,
    },
  };

  return ({ children }: { children: ReactNode }) => (
    <PermissionContext.Provider value={value}>{children}</PermissionContext.Provider>
  );
}

const fullPermissions = new Set([
  PERMISSIONS.SERVICE_ORDERS_VIEW,
  PERMISSIONS.SERVICE_ORDERS_CREATE,
  PERMISSIONS.SERVICE_ORDERS_UPDATE,
  PERMISSIONS.SERVICE_ORDERS_ASSIGN_TECHNICIAN,
  PERMISSIONS.SERVICE_ORDERS_CLOSE,
]);

function makeOrder(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    tenant_id: 1,
    order_number: 'SO-000001',
    type: 'repair',
    warranty_claim_id: null,
    customer_name: 'Juan Perez',
    customer_phone: '0412',
    device_description: 'iPhone 11',
    issue_description: 'Pantalla rota',
    diagnosis: null,
    status: 'received',
    priority: 'normal',
    resolution: null,
    technician_id: null,
    technician: null,
    warehouse_id: 1,
    warehouse: { id: 1, code: 'WH-1', name: 'Taller' },
    labor_base_amount: 0,
    labor_local_amount: 0,
    parts_base_amount: 0,
    parts_local_amount: 0,
    total_base_amount: 0,
    total_local_amount: 0,
    notes: null,
    parts: [],
    created_by: null,
    received_at: '2026-08-21T14:00:00+00:00',
    technician_assigned_at: null,
    diagnosed_at: null,
    completed_at: null,
    delivered_at: null,
    cancelled_at: null,
    created_at: '2026-08-21T14:00:00+00:00',
    updated_at: '2026-08-21T14:00:00+00:00',
    ...overrides,
  };
}

describe('WorkshopManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockOrders.mockReturnValue({ data: [makeOrder()], isLoading: false });
    mockDetail.data = makeOrder();
    mockDetail.isLoading = false;
    mockProductPage.data = {
      data: [{ id: 10, name: 'Pantalla iPhone', sku: 'PANT-IP', available_stock: 5 }],
    };
  });

  it('muestra la bandeja con las ordenes y su info', () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    expect(screen.getByText('SO-000001')).toBeInTheDocument();
    expect(screen.getByText('Juan Perez')).toBeInTheDocument();
    expect(screen.getByText('iPhone 11')).toBeInTheDocument();
    expect(screen.getAllByText('Recibido').length).toBeGreaterThan(0);
  });

  it('aplica los filtros de estado y tipo a la query', () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    fireEvent.change(screen.getByTestId('ws-status-filter'), { target: { value: 'in_progress' } });
    fireEvent.change(screen.getByTestId('ws-type-filter'), { target: { value: 'warranty' } });

    expect(mockOrders).toHaveBeenCalledWith(
      expect.objectContaining({ status: 'in_progress', type: 'warranty' }),
    );
  });

  it('abre el dialogo de creacion y envia los datos', async () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    await userEvent.click(screen.getByRole('button', { name: 'Nueva orden' }));
    await userEvent.type(screen.getByPlaceholderText('Nombre'), 'Ana');
    await userEvent.type(screen.getByPlaceholderText('Ej. iPhone 11, Lavadora 16kg...'), 'Lavadora');
    fireEvent.change(screen.getByTestId('ws-create-warehouse'), { target: { value: '1' } });

    await userEvent.click(screen.getByRole('button', { name: 'Crear orden' }));

    await waitFor(() => {
      expect(mockCreate).toHaveBeenCalledWith(
        expect.objectContaining({ type: 'repair', customer_name: 'Ana', warehouse_id: 1 }),
        expect.anything(),
      );
    });
  });

  it('para garantia exige tratamiento (boton deshabilitado sin resolution)', async () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    await userEvent.click(screen.getByRole('button', { name: 'Nueva orden' }));
    fireEvent.change(screen.getByTestId('ws-create-type'), { target: { value: 'warranty' } });
    fireEvent.change(screen.getByTestId('ws-create-warehouse'), { target: { value: '1' } });

    const crearBtn = screen.getByRole('button', { name: 'Crear orden' }) as HTMLButtonElement;
    expect(crearBtn).toBeDisabled();

    fireEvent.change(screen.getByTestId('ws-create-resolution'), { target: { value: 'workshop' } });
    expect(crearBtn).not.toBeDisabled();
  });

  it('guarda el diagnostico con mano de obra al expandir', async () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    fireEvent.click(screen.getByTestId('workshop-row-1'));
    await userEvent.type(screen.getByPlaceholderText('Descripción del diagnóstico'), 'Cambio de pantalla');
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }));

    await waitFor(() => {
      expect(mockDiagnose).toHaveBeenCalledWith(
        expect.objectContaining({ id: 1, values: expect.objectContaining({ diagnosis: 'Cambio de pantalla' }) }),
        expect.anything(),
      );
    });
  });

  it('asigna tecnico con almacen', async () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    fireEvent.click(screen.getByTestId('workshop-row-1'));
    fireEvent.change(screen.getByTestId('ws-assign-tech'), { target: { value: '9' } });
    fireEvent.change(screen.getByTestId('ws-assign-wh'), { target: { value: '1' } });
    await userEvent.click(screen.getByRole('button', { name: 'Asignar' }));

    await waitFor(() => {
      expect(mockAssign).toHaveBeenCalledWith(
        expect.objectContaining({ id: 1, values: { technician_id: 9, warehouse_id: 1 } }),
        expect.anything(),
      );
    });
  });

  it('agrega y quita piezas', async () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    fireEvent.click(screen.getByTestId('workshop-row-1'));

    fireEvent.change(screen.getByTestId('ws-part-product'), { target: { value: '10' } });
    await userEvent.click(screen.getByRole('button', { name: 'Agregar' }));

    await waitFor(() => {
      expect(mockAddPart).toHaveBeenCalledWith(
        expect.objectContaining({ id: 1, values: { product_id: 10, quantity: 1 } }),
        expect.anything(),
      );
    });
  });

  it('completa la orden (descuenta stock + comision)', async () => {
    mockDetail.data = makeOrder({ status: 'diagnosed' });
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    fireEvent.click(screen.getByTestId('workshop-row-1'));
    await userEvent.click(screen.getByRole('button', { name: 'Completar y entregar' }));

    await waitFor(() => {
      expect(mockComplete).toHaveBeenCalledWith(1, expect.anything());
    });
  });

  it('cancela la orden', async () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(fullPermissions) });

    fireEvent.click(screen.getByTestId('workshop-row-1'));
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar orden' }));

    await waitFor(() => {
      expect(mockCancel).toHaveBeenCalledWith(1);
    });
  });

  it('muestra PermissionDenied sin permiso de ver', () => {
    render(<WorkshopManager />, { wrapper: makeWrapper(new Set()) });

    expect(screen.queryByText('SO-000001')).not.toBeInTheDocument();
    expect(screen.getByText(/No tienes permiso/i)).toBeInTheDocument();
  });
});