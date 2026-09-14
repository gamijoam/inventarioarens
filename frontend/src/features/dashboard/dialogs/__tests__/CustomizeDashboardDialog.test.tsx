import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CustomizeDashboardDialog } from '../CustomizeDashboardDialog';
import {
  DEFAULT_DASHBOARD_VISIBILITY,
  type DashboardVisibility,
} from '../../dashboardConfig';

describe('CustomizeDashboardDialog', () => {
  const onChangeMock = vi.fn();
  const onResetMock = vi.fn();
  const onOpenChangeMock = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renderiza todas las secciones y checkboxes de métricas y paneles', () => {
    render(
      <CustomizeDashboardDialog
        open={true}
        onOpenChange={onOpenChangeMock}
        visibility={DEFAULT_DASHBOARD_VISIBILITY}
        onChange={onChangeMock}
        onReset={onResetMock}
      />,
    );

    expect(screen.getByText('Personalizar Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Métricas e Indicadores (KPIs)')).toBeInTheDocument();
    expect(screen.getByText('Secciones y Paneles')).toBeInTheDocument();

    // Nueva métrica de valor del inventario
    expect(screen.getByText('Valor del inventario ($)')).toBeInTheDocument();
    expect(screen.getByText('Valor del inventario a precio venta ($)')).toBeInTheDocument();
    expect(screen.getByText('Ventas confirmadas')).toBeInTheDocument();
    expect(screen.getByText('POS cobrado')).toBeInTheDocument();
    expect(screen.getByText('Cajas abiertas')).toBeInTheDocument();
    expect(screen.getByText('Bajo stock')).toBeInTheDocument();
    expect(screen.getByText('Cuentas por cobrar (CxC)')).toBeInTheDocument();
    expect(screen.getByText('Cuentas por pagar (CxP)')).toBeInTheDocument();
    expect(screen.getByText('Tabla de alertas de inventario')).toBeInTheDocument();
    expect(screen.getByText('Lectura ejecutiva')).toBeInTheDocument();
  });

  it('permite desmarcar y marcar métricas disparando onChange', async () => {
    const user = userEvent.setup();
    render(
      <CustomizeDashboardDialog
        open={true}
        onOpenChange={onOpenChangeMock}
        visibility={DEFAULT_DASHBOARD_VISIBILITY}
        onChange={onChangeMock}
        onReset={onResetMock}
      />,
    );

    const checkbox = screen.getByTestId('checkbox-dashboard-inventory_value');
    await user.click(checkbox);

    expect(onChangeMock).toHaveBeenCalledWith({
      ...DEFAULT_DASHBOARD_VISIBILITY,
      inventory_value: false,
    });
  });

  it('dispara onReset al hacer clic en restablecer predeterminados', async () => {
    const user = userEvent.setup();
    const customVisibility: DashboardVisibility = {
      ...DEFAULT_DASHBOARD_VISIBILITY,
      sales: false,
      inventory_value: false,
    };

    render(
      <CustomizeDashboardDialog
        open={true}
        onOpenChange={onOpenChangeMock}
        visibility={customVisibility}
        onChange={onChangeMock}
        onReset={onResetMock}
      />,
    );

    const resetBtn = screen.getByTestId('reset-dashboard-defaults-btn');
    await user.click(resetBtn);

    expect(onResetMock).toHaveBeenCalledTimes(1);
  });
});
