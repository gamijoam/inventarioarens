import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import { PermissionContext, type PermissionContextValue } from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

const mockUseBranchesForPos = vi.fn();
const mockUseCashRegisters = vi.fn();
const mockUseCashSessions = vi.fn();
const mockUseCurrentExchangeRatesForPos = vi.fn();
const mockUseExchangeRateTypesForPos = vi.fn();
const mockUseUsers = vi.fn();

const mockUseCreatePosBranch = vi.fn();
const mockUseCreateCashRegister = vi.fn();
const mockUseOpenCashSession = vi.fn();
const mockUseAddCashMovement = vi.fn();
const mockUseCloseCashSession = vi.fn();

vi.mock('@tanstack/react-router', () => ({
  Link: ({ to, children, ...props }: any) => (
    <a href={typeof to === 'string' ? to : '#'} {...props}>
      {children}
    </a>
  ),
}));

vi.mock('../api', () => ({
  useBranchesForPos: (...args: unknown[]) => mockUseBranchesForPos(...args) as unknown,
  useCashRegisters: (...args: unknown[]) => mockUseCashRegisters(...args) as unknown,
  useCashSessions: (...args: unknown[]) => mockUseCashSessions(...args) as unknown,
  useCurrentExchangeRatesForPos: (...args: unknown[]) => mockUseCurrentExchangeRatesForPos(...args) as unknown,
  useExchangeRateTypesForPos: (...args: unknown[]) => mockUseExchangeRateTypesForPos(...args) as unknown,
  useCreatePosBranch: () => mockUseCreatePosBranch() as unknown,
  useCreateCashRegister: () => mockUseCreateCashRegister() as unknown,
  useOpenCashSession: () => mockUseOpenCashSession() as unknown,
  useAddCashMovement: () => mockUseAddCashMovement() as unknown,
  useCloseCashSession: () => mockUseCloseCashSession() as unknown,
}));

vi.mock('@/features/users/api', () => ({
  useUsers: (...args: unknown[]) => mockUseUsers(...args) as unknown,
}));

import { CashRegisterSetup } from '../CashRegisterSetup';

function makeWrapper(
  roles: string[] = [],
  permissions: string[] = Object.values(PERMISSIONS),
) {
  const value: PermissionContextValue = {
    permissions: new Set(permissions),
    roles,
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

const openSession = {
  id: 1,
  status: 'open',
  branch_id: 1,
  cash_register_id: 1,
  cashier_id: 7,
  opening_base_amount: 20,
  opening_local_amount: 100,
  expected_base_amount: 20,
  expected_local_amount: 100,
  expected_cash_usd: 20,
  expected_cash_ves: 100,
  counted_base_amount: null,
  counted_local_amount: null,
  counted_cash_usd: null,
  counted_cash_ves: null,
  difference_base_amount: null,
  difference_local_amount: null,
  difference_cash_usd: null,
  difference_cash_ves: null,
  opened_at: '2026-07-27T08:00:00Z',
  cash_register: { id: 1, name: 'Caja 1', branch_id: 1 },
  branch: { id: 1, name: 'Sucursal Centro', code: 'CTR' },
  cashier: { id: 7, name: 'Ana', email: 'ana@example.test' },
  movements: [],
};

beforeEach(() => {
  const mutation = { mutate: vi.fn(), isPending: false };

  mockUseBranchesForPos.mockReturnValue({
    data: [{ id: 1, name: 'Sucursal Centro', code: 'CTR', status: 'active' }],
    isLoading: false,
    isError: false,
  });
  mockUseCashRegisters.mockReturnValue({
    data: [{ id: 1, name: 'Caja 1', code: 'C1', branch_id: 1, status: 'active' }],
    isLoading: false,
    isError: false,
  });
  mockUseCashSessions.mockReturnValue({ data: [openSession], isLoading: false, isError: false });
  mockUseCurrentExchangeRatesForPos.mockReturnValue({
    data: [{ exchange_rate_type_id: 1, exchange_rate_type_code: 'BCV', rate: 36.5, base_currency: 'USD', quote_currency: 'VES' }],
    isLoading: false,
  });
  mockUseExchangeRateTypesForPos.mockReturnValue({
    data: [{ id: 1, code: 'BCV', is_default: true, is_active: true }],
    isLoading: false,
  });
  mockUseUsers.mockReturnValue({ data: { data: [] }, isLoading: false });

  mockUseCreatePosBranch.mockReturnValue(mutation);
  mockUseCreateCashRegister.mockReturnValue(mutation);
  mockUseOpenCashSession.mockReturnValue(mutation);
  mockUseAddCashMovement.mockReturnValue(mutation);
  mockUseCloseCashSession.mockReturnValue(mutation);
});

describe('<CashRegisterSetup>', () => {
  it('prioriza Mi caja y consolida supervisión e historial en Turnos', () => {
    render(<CashRegisterSetup />, { wrapper: makeWrapper() });

    expect(screen.getByRole('tab', { name: 'Mi caja' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: 'Turnos' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Supervisión' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Historial' })).not.toBeInTheDocument();
    expect(screen.getByText('Mi caja ahora está abierta')).toBeInTheDocument();
    expect(screen.getAllByText('Turno abierto')).toHaveLength(1);
  });

  it('difiere sucursales, cajas, tasas y usuarios cuando ya hay una caja abierta', () => {
    render(<CashRegisterSetup />, { wrapper: makeWrapper() });

    expect(mockUseBranchesForPos).toHaveBeenCalledWith({ enabled: false });
    expect(mockUseCashRegisters).toHaveBeenCalledWith({ enabled: false });
    expect(mockUseCurrentExchangeRatesForPos).toHaveBeenCalledWith({ enabled: false });
    expect(mockUseExchangeRateTypesForPos).toHaveBeenCalledWith({ enabled: false });
    expect(mockUseUsers).toHaveBeenCalledWith(expect.any(Object), false);
  });

  it('muestra el formulario de apertura al solicitarlo y conserva los fondos ingresados', () => {
    const mutate = vi.fn();
    mockUseCashSessions.mockReturnValue({ data: [], isLoading: false, isError: false });
    mockUseOpenCashSession.mockReturnValue({ mutate, isPending: false });
    mockUseUsers.mockReturnValue({
      data: { data: [{ id: 9, name: 'Juan Cajero', email: 'juan@test.test' }] },
      isLoading: false,
    });

    render(<CashRegisterSetup />, { wrapper: makeWrapper(['Administrador']) });

    expect(screen.queryByTestId('pos-open-branch')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Abrir turno' }));

    expect(screen.getByText('Fondo $ (USD)')).toBeInTheDocument();
    expect(screen.getByText('Fondo Bs (VES)')).toBeInTheDocument();
    fireEvent.change(screen.getByTestId('pos-open-branch'), { target: { value: '1' } });
    fireEvent.change(screen.getByTestId('pos-open-register'), { target: { value: '1' } });
    fireEvent.change(screen.getByTestId('pos-open-cashier'), { target: { value: '9' } });
    fireEvent.change(screen.getByTestId('pos-open-base'), { target: { value: '1' } });
    fireEvent.change(screen.getByTestId('pos-open-local'), { target: { value: '1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar apertura' }));

    expect(mutate).toHaveBeenCalledWith(
      expect.objectContaining({ opening_base_amount: 1, opening_local_amount: 1 }),
      expect.anything(),
    );
  });

  it('permite cancelar el arqueo de cierre desde Mi caja', () => {
    render(<CashRegisterSetup />, { wrapper: makeWrapper() });

    fireEvent.click(screen.getByRole('button', { name: 'Cerrar caja' }));
    expect(screen.getByText('Arqueo de cierre')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(screen.queryByText('Arqueo de cierre')).not.toBeInTheDocument();
  });

  it('mantiene Turnos disponible para lectura sin permiso de reportes', () => {
    render(
      <CashRegisterSetup />,
      { wrapper: makeWrapper([], [PERMISSIONS.CASH_REGISTER_VIEW]) },
    );

    expect(screen.getByRole('tab', { name: 'Turnos' })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Configuración' })).not.toBeInTheDocument();
  });
});
