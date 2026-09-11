import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

import { useUiModeStore, DEFAULT_VISIBLE_ROUTES } from '@/stores/uiMode';
import { SimpleModeSettingsPanel } from '../SimpleModeSettingsPanel';

describe('<SimpleModeSettingsPanel>', () => {
  beforeEach(() => {
    localStorage.clear();
    useUiModeStore.getState().resetToDefaults();
  });

  it('muestra el interruptor principal y su estado actual', () => {
    render(<SimpleModeSettingsPanel />);

    const switchEl = screen.getByRole('switch', { name: /activar modo fácil/i });
    expect(switchEl).toBeInTheDocument();
    expect(switchEl).toBeChecked();
  });

  it('permite activar y desactivar el modo fácil mediante el interruptor', () => {
    render(<SimpleModeSettingsPanel />);

    const switchEl = screen.getByRole('switch', { name: /activar modo fácil/i });
    fireEvent.click(switchEl);

    expect(useUiModeStore.getState().isSimpleMode).toBe(false);

    fireEvent.click(switchEl);
    expect(useUiModeStore.getState().isSimpleMode).toBe(true);
  });

  it('renderiza la lista de módulos y permite marcar y desmarcar', () => {
    render(<SimpleModeSettingsPanel />);

    // El checkbox de Proveedores arranca desmarcado por defecto
    const suppliersCheckbox = screen.getByRole('checkbox', { name: /proveedores/i });
    expect(suppliersCheckbox).not.toBeChecked();

    // Al hacer click, se activa /suppliers en visibleRoutes
    fireEvent.click(suppliersCheckbox);
    expect(useUiModeStore.getState().visibleRoutes).toContain('/suppliers');

    // El checkbox de Reportes arranca marcado por defecto
    const reportsCheckbox = screen.getByRole('checkbox', { name: /reportes/i });
    expect(reportsCheckbox).toBeChecked();

    // Al hacer click, se desactiva /reports de visibleRoutes
    fireEvent.click(reportsCheckbox);
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/reports');
  });

  it('renderiza submódulos de Inventario incluyendo Tipos de tasa y Catálogos', () => {
    render(<SimpleModeSettingsPanel />);

    // Verifica que existan los submódulos específicos de inventario
    expect(screen.getByRole('checkbox', { name: /tipos de tasa/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /catálogos/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /movimientos manuales/i })).toBeInTheDocument();

    // Tipos de tasa debe estar activo por defecto (para tasas BCV/Paralelo en repuestos)
    const currencyCheckbox = screen.getByRole('checkbox', { name: /tipos de tasa/i });
    expect(currencyCheckbox).toBeChecked();

    // Catálogos debe estar activo por defecto
    const catalogsCheckbox = screen.getByRole('checkbox', { name: /catálogos/i });
    expect(catalogsCheckbox).toBeChecked();

    // Movimientos manuales inicia apagado
    const movementsCheckbox = screen.getByRole('checkbox', { name: /movimientos manuales/i });
    expect(movementsCheckbox).not.toBeChecked();

    // Al alternar Tipos de tasa, se actualiza visibleRoutes
    fireEvent.click(currencyCheckbox);
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/inventory/currency');

    // Al alternar Movimientos manuales, se activa en visibleRoutes
    fireEvent.click(movementsCheckbox);
    expect(useUiModeStore.getState().visibleRoutes).toContain('/inventory/manual-movements');
  });

  it('permite activar y desactivar un grupo completo (ej: Inventario) con sus submódulos', () => {
    render(<SimpleModeSettingsPanel />);

    // El checkbox padre de Inventario
    const inventoryParentCheckbox = screen.getByRole('checkbox', { name: /^inventario$/i });
    expect(inventoryParentCheckbox).toBeChecked();

    // Desactivar el grupo Inventario
    fireEvent.click(inventoryParentCheckbox);
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/inventory');
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/inventory/currency');
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/inventory/catalogs');

    // Activar nuevamente el grupo Inventario
    fireEvent.click(inventoryParentCheckbox);
    expect(useUiModeStore.getState().visibleRoutes).toContain('/inventory');
    expect(useUiModeStore.getState().visibleRoutes).toContain('/inventory/currency');
  });

  it('botón de restablecer restaura la configuración recomendada', () => {
    useUiModeStore.getState().setVisibleRoutes(['/dashboard']);
    render(<SimpleModeSettingsPanel />);

    const resetBtn = screen.getByRole('button', { name: /restablecer recomendados/i });
    fireEvent.click(resetBtn);

    expect(useUiModeStore.getState().visibleRoutes).toEqual(DEFAULT_VISIBLE_ROUTES);
  });
});
