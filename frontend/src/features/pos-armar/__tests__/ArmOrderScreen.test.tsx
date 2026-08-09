import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  holdMutate: vi.fn(),
  signOut: vi.fn(),
}));

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({ signOut: mocks.signOut }),
}));

vi.mock('@/components/layout/PosShell', () => ({
  PosShell: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/features/pos/api', () => ({
  useBootstrapRefsForPos: () => ({
    refs: { warehouses: [{ id: 7, name: 'Principal' }] },
  }),
  useHoldOrder: () => ({ isPending: false, mutateAsync: mocks.holdMutate }),
  usePosProductsDebounced: () => ({
    isLoading: false,
    data: {
      data: [
        {
          id: 41,
          name: 'Adaptador USB-C',
          sku: 'CAT-000516',
          barcode: null,
          base_price: 12.5,
        },
      ],
    },
  }),
}));

vi.mock('../OnScreenKeyboard', () => ({
  OnScreenKeyboard: () => <div data-testid="keyboard" />,
}));

import { ArmOrderScreen } from '../ArmOrderScreen';

describe('<ArmOrderScreen>', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('agrega el producto con el primer toque tactil en tablet', () => {
    render(<ArmOrderScreen />);

    const touchStart = new Event('pointerdown', { bubbles: true });
    Object.defineProperty(touchStart, 'pointerType', { value: 'touch' });
    fireEvent(screen.getByTestId('product-41'), touchStart);

    const ticket = screen.getByRole('complementary');
    expect(within(ticket).getByText('x1 · $12.50')).toBeInTheDocument();
    expect(within(ticket).getByText('$12.50')).toBeInTheDocument();
  });
});
