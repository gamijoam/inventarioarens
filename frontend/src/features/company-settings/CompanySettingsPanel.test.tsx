/**
 * Tests del CompanySettingsPanel: carga los datos de la empresa y guarda
 * el payload con los toggles de visibilidad por documento.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const updateMutation = vi.fn();
const data = {
  razon_social: 'Comercial Arens, C.A.',
  rif: 'J-12345678-9',
  domicilio_fiscal: 'Av. Principal, Local 5',
  ciudad: 'Caracas',
  estado: 'Distrito Capital',
  telefono: '+58 212 555 0101',
  correo: 'info@comercialarens.com',
  website: 'https://comercialarens.com',
  regimen: 'Contribuyente formal',
  tax_condition: 'ordinary',
  show_on: { sale_ticket: true, guide: false, report_z: true },
};

vi.mock('./api', () => ({
  useCompanySettings: () => ({ data }),
  useUpdateCompanySettings: () => ({ mutateAsync: updateMutation }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

import { CompanySettingsPanel } from './CompanySettingsPanel';

describe('CompanySettingsPanel', () => {
  beforeEach(() => {
    updateMutation.mockReset();
    updateMutation.mockResolvedValue({ tenant_id: 1, settings: {} });
  });

  it('carga y muestra los datos de la empresa', async () => {
    render(<CompanySettingsPanel />);

    await waitFor(() => {
      expect(screen.getByLabelText('Razón social / nombre fiscal')).toHaveValue(
        'Comercial Arens, C.A.',
      );
      expect(screen.getByLabelText('RIF')).toHaveValue('J-12345678-9');
      expect(screen.getByLabelText('Teléfono')).toHaveValue('+58 212 555 0101');
      expect(screen.getByLabelText('Condición frente al IVA')).toHaveValue('ordinary');
    });

    expect(screen.getByTestId('company-show-guide')).not.toBeChecked();
    expect(screen.getByTestId('company-show-sale_ticket')).toBeChecked();
  });

  it('guarda el payload con los datos y los toggles de visibilidad', async () => {
    render(<CompanySettingsPanel />);

    await waitFor(() => {
      expect(screen.getByLabelText('RIF')).toHaveValue('J-12345678-9');
    });

    await userEvent.clear(screen.getByLabelText('RIF'));
    await userEvent.type(screen.getByLabelText('RIF'), 'J-99999999-9');

    const guideToggle = screen.getByTestId('company-show-guide');
    fireEvent.click(guideToggle);

    fireEvent.click(screen.getByTestId('company-save'));

    await waitFor(() => {
      expect(updateMutation).toHaveBeenCalledTimes(1);
    });
    const payload = updateMutation.mock.calls[0]?.[0] as {
      rif: string;
      razon_social: string;
      show_on: { sale_ticket: boolean; guide: boolean; report_z: boolean };
      tax_condition: string;
    };
    expect(payload.rif).toBe('J-99999999-9');
    expect(payload.razon_social).toBe('Comercial Arens, C.A.');
    expect(payload.show_on.sale_ticket).toBe(true);
    expect(payload.show_on.guide).toBe(true);
    expect(payload.show_on.report_z).toBe(true);
    expect(payload.tax_condition).toBe('ordinary');
  });
});
