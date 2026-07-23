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
}));

import { CashRegisterSetup } from '../CashRegisterSetup';

function makeWrapper() {
  const value: PermissionContextValue = {
    permissions: new Set(Object.values(PERMISSIONS)),
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
    expect(screen.getByRole('tab', { name: 'Operacion' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Historial' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Infraestructura' })).toBeInTheDocument();
    expect(screen.getByText('Mi turno abierto')).toBeInTheDocument();
  });
});
