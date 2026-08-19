import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ReportZDialog } from '../ReportZDialog';

const mockUseReportZ = vi.fn();

vi.mock('../reportZApi', () => ({
  useReportZ: (sessionId: number | null, enabled: boolean) => mockUseReportZ(sessionId, enabled),
  openReportZPdf: vi.fn(),
  downloadReportZPdf: vi.fn(),
}));

const zFixture = {
  z_number: 3,
  emitted_at: '2026-08-18T20:00:00+00:00',
  status: 'closed',
  tenant: { name: 'OscarCell', slug: 'oscar-cell' },
  branch: 'Sucursal Principal',
  cash_register: 'CAJA1',
  cashier: 'Cajero Demo',
  opened_at: '2026-08-18T08:00:00+00:00',
  closed_at: '2026-08-18T20:00:00+00:00',
  totals: {
    orders_count: 12,
    paid_base_amount: 500,
    paid_local_amount: 37500,
    expected_base_amount: 500,
    expected_local_amount: 37500,
    counted_base_amount: 500,
    counted_local_amount: 37500,
    difference_base_amount: 0,
    difference_local_amount: 0,
    difference_cash_usd: 0,
    difference_cash_ves: 0,
  },
  payments: [
    { name: 'Efectivo', method: 'cash', currency: 'USD', payments_count: 8, amount_base: 300, amount_local: 22500, exchange_rate: 75 },
    { name: 'Pago Móvil', method: 'mobile_payment', currency: 'VES', payments_count: 4, amount_base: 200, amount_local: 15000, exchange_rate: 75 },
  ],
  counts: [],
};

describe('ReportZDialog', () => {
  beforeEach(() => {
    mockUseReportZ.mockReset();
    mockUseReportZ.mockReturnValue({ data: zFixture, isLoading: false, isError: false });
  });

  it('muestra el numero Z, totales y desglose de pagos', () => {
    render(<ReportZDialog sessionId={5} open onOpenChange={() => undefined} />);

    expect(mockUseReportZ).toHaveBeenCalledWith(5, true);
    expect(screen.getByText('Reporte Z #3')).toBeInTheDocument();
    expect(screen.getByText('Tickets')).toBeInTheDocument();
    expect(screen.getByText('Total USD')).toBeInTheDocument();
    expect(screen.getByText('Efectivo (USD) · 8')).toBeInTheDocument();
    expect(screen.getByText('Pago Móvil (VES) · 4')).toBeInTheDocument();
  });

  it('muestra los botones de impresion y descarga', () => {
    render(<ReportZDialog sessionId={5} open onOpenChange={() => undefined} />);

    expect(screen.getByText('Imprimir')).toBeInTheDocument();
    expect(screen.getByText('Descargar PDF')).toBeInTheDocument();
  });
});
