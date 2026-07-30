import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import { PermissionContext, type PermissionContextValue } from '@/permissions/PermissionContext';
import { PERMISSIONS } from '@/permissions/constants';

const mockUseBranchesForPos = vi.fn();
const mockUseCashRegisters = vi.fn();
const mockUseCashSessions = vi.fn();
const mockUseCashSessionsList = vi.fn();
const mockUseCurrentExchangeRatesForPos = vi.fn();
const mockUseExchangeRateTypesForPos = vi.fn();
const mockUseCashSessionsReport = vi.fn();
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
  useBranchesForPos: () => mockUseBranchesForPos(),
  useCashRegisters: () => mockUseCashRegisters(),
  useCashSessions: () => mockUseCashSessions(),
  useCashSessionsList: (params: unknown) => mockUseCashSessionsList(params),
  useCurrentExchangeRatesForPos: () => mockUseCurrentExchangeRatesForPos(),
  useExchangeRateTypesForPos: () => mockUseExchangeRateTypesForPos(),
  useCreatePosBranch: () => mockUseCreatePosBranch(),
  useCreateCashRegister: () => mockUseCreateCashRegister(),
  useOpenCashSession: () => mockUseOpenCashSession(),
  useAddCashMovement: () => mockUseAddCashMovement(),
  useCloseCashSession: () => mockUseCloseCashSession(),
  useCashSessionDetail: () => ({ data: undefined, isLoading: false }),
  useReviewCashSession: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('@/features/reports/api', () => ({
  useCashSessions: () => mockUseCashSessionsReport(),
}));

vi.mock('@/features/users/api', () => ({
  useUsers: () => mockUseUsers(),
}));

import { CashRegisterSetup } from '../CashRegisterSetup';

function makeWrapper(roles: string[] = []) {
  const value: PermissionContextValue = {
    permissions: new Set(Object.values(PERMISSIONS)),
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

beforeEach(() => {
  const mutation = {
    mutate: vi.fn(),
    isPending: false,
  };

  mockUseBranchesForPos.mockReturnValue({ data: [{ id: 1, name: 'Sucursal Centro', code: 'CTR', status: 'active' }], isLoading: false });
  mockUseCashRegisters.mockReturnValue({ data: [{ id: 1, name: 'Caja 1', code: 'C1', branch_id: 1, status: 'active' }], isLoading: false });
  mockUseCashSessions.mockReturnValue({ data: [{ id: 1, status: 'open', cash_register_id: 1, cash_register: { name: 'Caja 1' }, branch: { name: 'Sucursal Centro' }, cashier: { name: 'Ana' } }], isLoading: false });
  mockUseCashSessionsList.mockImplementation((params: { status: string }) => ({
    data: params.status === 'open' ? [{ id: 1, status: 'open', cash_register_id: 1, cash_register: { name: 'Caja 1' }, branch: { name: 'Sucursal Centro' }, cashier: { name: 'Ana' } }] : [{ id: 2, status: 'closed', cash_register_id: 1, cash_register: { name: 'Caja 1' }, branch: { name: 'Sucursal Centro' }, cashier: { name: 'Ana' } }],
    isLoading: false,
  }));
  mockUseCurrentExchangeRatesForPos.mockReturnValue({ data: [{ exchange_rate_type_id: 1, exchange_rate_type_code: 'BCV', rate: 36.5, base_currency: 'USD', quote_currency: 'VES' }] });
  mockUseExchangeRateTypesForPos.mockReturnValue({ data: [{ id: 1, code: 'BCV', is_default: true, is_active: true }] });
  mockUseCashSessionsReport.mockReturnValue({
    data: {
      period: { from: '2026-07-27', to: '2026-07-27', from_datetime: '', to_datetime: '' },
      summary: { open_count: 1, closed_count: 0, expected_base_amount: 0, expected_local_amount: 0, difference_base_amount: 0 },
      rows: [],
      movement_breakdown: [],
    },
    isLoading: false,
    isFetching: false,
    isError: false,
    refetch: vi.fn(),
  });
  mockUseUsers.mockReturnValue({ data: { data: [] }, isLoading: false });

  mockUseCreatePosBranch.mockReturnValue(mutation);
  mockUseCreateCashRegister.mockReturnValue(mutation);
  mockUseOpenCashSession.mockReturnValue(mutation);
  mockUseAddCashMovement.mockReturnValue(mutation);
  mockUseCloseCashSession.mockReturnValue(mutation);
});

describe('<CashRegisterSetup>', () => {
  it('muestra la nueva estructura visual con hero y tabs', () => {
    render(<CashRegisterSetup />, { wrapper: makeWrapper() });

    expect(screen.getByText('Control de turnos y arqueos')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Mi turno' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Supervisi/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Historial' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Configuraci/ })).toBeInTheDocument();
    expect(screen.getByText('Mi turno abierto')).toBeInTheDocument();
  });

  it('muestra selector de cajero para supervisores al abrir un turno', () => {
    mockUseCashSessions.mockReturnValue({ data: [], isLoading: false });
    mockUseUsers.mockReturnValue({
      data: { data: [{ id: 9, name: 'Juan Cajero', email: 'juan@test.test' }] },
      isLoading: false,
    });

    render(<CashRegisterSetup />, { wrapper: makeWrapper(['Administrador']) });

    expect(screen.getByText('Cajero responsable...')).toBeInTheDocument();
    expect(screen.getByText('Juan Cajero - juan@test.test')).toBeInTheDocument();
  });
});
