import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { FiscalTaxRatesManager } from './FiscalTaxRatesManager';

const { getMany, postOne, patchOne } = vi.hoisted(() => ({
  getMany: vi.fn(),
  postOne: vi.fn(),
  patchOne: vi.fn(),
}));

vi.mock('@/api/client', () => ({ getMany, postOne, patchOne }));

function renderManager() {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <FiscalTaxRatesManager />
    </QueryClientProvider>,
  );
}

describe('<FiscalTaxRatesManager>', () => {
  it('muestra las cuatro categorías fiscales disponibles', async () => {
    getMany.mockResolvedValueOnce([
      {
        id: 1,
        tenant_id: 1,
        code: 'IVA16',
        name: 'IVA general',
        rate: 16,
        category: 'taxable',
        is_active: true,
      },
      {
        id: 2,
        tenant_id: 1,
        code: 'EXENTO',
        name: 'Exento',
        rate: 0,
        category: 'exempt',
        is_active: true,
      },
      {
        id: 3,
        tenant_id: 1,
        code: 'EXONERADO',
        name: 'Exonerado',
        rate: 0,
        category: 'exonerated',
        is_active: true,
      },
      {
        id: 4,
        tenant_id: 1,
        code: 'NO_GRAVADO',
        name: 'No gravado',
        rate: 0,
        category: 'non_taxable',
        is_active: true,
      },
    ]);

    renderManager();

    expect(await screen.findByText('IVA16')).toBeInTheDocument();
    expect(screen.getByText('Gravado')).toBeInTheDocument();
    expect(screen.getAllByText('Exento').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Exonerado').length).toBeGreaterThan(0);
    expect(screen.getAllByText('No gravado').length).toBeGreaterThan(0);
  });

  it('fuerza tasa cero al crear una categoría exonerada', async () => {
    const user = userEvent.setup();
    getMany.mockResolvedValueOnce([]);
    postOne.mockResolvedValueOnce({
      id: 5,
      tenant_id: 1,
      code: 'EXONERADO',
      name: 'Exonerado',
      rate: 0,
      category: 'exonerated',
      is_active: true,
    });

    renderManager();
    await user.click(await screen.findByRole('button', { name: 'Nueva alícuota' }));
    await user.type(screen.getByPlaceholderText('IVA16'), 'EXONERADO');
    await user.type(screen.getByPlaceholderText('IVA general'), 'Exonerado');
    await user.selectOptions(screen.getByRole('combobox'), 'exonerated');
    await user.click(screen.getByRole('button', { name: 'Crear' }));

    await waitFor(() =>
      expect(postOne).toHaveBeenCalledWith(
        '/fiscal/tax-rates',
        expect.objectContaining({
          code: 'EXONERADO',
          category: 'exonerated',
          rate: 0,
        }),
      ),
    );
  });
});
