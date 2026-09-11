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

  it('botón de restablecer restaura la configuración recomendada', () => {
    useUiModeStore.getState().setVisibleRoutes(['/dashboard']);
    render(<SimpleModeSettingsPanel />);

    const resetBtn = screen.getByRole('button', { name: /restablecer recomendados/i });
    fireEvent.click(resetBtn);

    expect(useUiModeStore.getState().visibleRoutes).toEqual(DEFAULT_VISIBLE_ROUTES);
  });
});
