import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const mockUseCan = vi.fn<(permission: string) => boolean>();
const mockUseExchangeRateTypes = vi.fn<
  (enabled?: boolean) => {
    data: {
      id: number;
      code: string;
      name: string;
      is_default: boolean;
      is_active: boolean;
    }[];
  }
>();
const mockMutateAsync = vi.fn<(payload: Record<string, unknown>) => Promise<unknown>>();

vi.mock('@tanstack/react-router', () => ({
  Link: ({ to, children }: { to: string; children: ReactNode }) => <a href={to}>{children}</a>,
}));
vi.mock('@/permissions/useCan', () => ({ useCan: (permission: string) => mockUseCan(permission) }));
vi.mock('@/features/inventory-center/api', () => ({
  useExchangeRateTypes: (enabled?: boolean) => mockUseExchangeRateTypes(enabled),
  useCreateExchangeRate: () => ({ mutateAsync: mockMutateAsync, isPending: false }),
}));

import { ExchangeRateQuickUpdate } from './ExchangeRateQuickUpdate';

describe('<ExchangeRateQuickUpdate>', () => {
  beforeEach(() => {
    mockUseCan.mockReset().mockReturnValue(true);
    mockUseExchangeRateTypes.mockReset().mockReturnValue({
      data: [
        {
          id: 8,
          code: 'BCV',
          name: 'Banco Central de Venezuela',
          is_default: true,
          is_active: true,
        },
      ],
    });
    mockMutateAsync.mockReset().mockResolvedValue({});
  });

  it('registra en un solo diálogo una tasa con su fecha, origen controlado y tipo predeterminado', async () => {
    render(
      <ExchangeRateQuickUpdate
        currentRate={{
          id: 1,
          exchange_rate_type_id: 8,
          exchange_rate_type_code: 'BCV',
          base_currency: 'USD',
          quote_currency: 'VES',
          rate: 36.5,
          is_active: true,
          source: 'bcv_official',
        }}
      />,
    );

    expect(screen.getByText(/Publicación BCV/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Actualizar tasa' }));
    fireEvent.change(screen.getByLabelText('Nueva tasa (Bs por USD)'), {
      target: { value: '38.5' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar y activar' }));

    await waitFor(() =>
      expect(mockMutateAsync).toHaveBeenCalledWith(
        expect.objectContaining({
          exchange_rate_type_id: 8,
          rate: 38.5,
          source: 'manual',
          is_active: true,
          base_currency: 'USD',
          quote_currency: 'VES',
        }),
      ),
    );
  });

  it('bloquea la actualización si el tipo predeterminado está inactivo', () => {
    mockUseExchangeRateTypes.mockReturnValue({
      data: [
        {
          id: 8,
          code: 'BCV',
          name: 'Banco Central de Venezuela',
          is_default: true,
          is_active: false,
        },
      ],
    });

    render(<ExchangeRateQuickUpdate currentRate={null} />);

    expect(screen.getByText(/El tipo predeterminado \(BCV\) está inactivo/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Actualizar tasa' })).toBeNull();
    expect(mockMutateAsync).not.toHaveBeenCalled();
  });
});
