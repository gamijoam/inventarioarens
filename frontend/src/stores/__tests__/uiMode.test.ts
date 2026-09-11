import { describe, it, expect, beforeEach } from 'vitest';
import { useUiModeStore, DEFAULT_VISIBLE_ROUTES } from '../uiMode';

describe('useUiModeStore (Modo Fácil personalizado)', () => {
  beforeEach(() => {
    localStorage.clear();
    useUiModeStore.getState().resetToDefaults();
  });

  it('inicia en modo fácil por defecto con rutas recomendadas', () => {
    const state = useUiModeStore.getState();
    expect(state.isSimpleMode).toBe(true);
    expect(state.visibleRoutes).toEqual(DEFAULT_VISIBLE_ROUTES);
    expect(state.visibleRoutes).toContain('/inventory');
    expect(state.visibleRoutes).toContain('/pos');
    expect(state.visibleRoutes).not.toContain('/transfers');
  });

  it('permite alternar el modo fácil on/off', () => {
    useUiModeStore.getState().toggleSimpleMode();
    expect(useUiModeStore.getState().isSimpleMode).toBe(false);

    useUiModeStore.getState().setSimpleMode(true);
    expect(useUiModeStore.getState().isSimpleMode).toBe(true);
  });

  it('permite activar o desactivar módulos individuales en modo fácil', () => {
    // Activar /suppliers que viene apagado por defecto
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/suppliers');
    useUiModeStore.getState().toggleRoute('/suppliers');
    expect(useUiModeStore.getState().visibleRoutes).toContain('/suppliers');

    // Desactivar /reports que viene encendido por defecto
    expect(useUiModeStore.getState().visibleRoutes).toContain('/reports');
    useUiModeStore.getState().toggleRoute('/reports');
    expect(useUiModeStore.getState().visibleRoutes).not.toContain('/reports');
  });

  it('restablece las rutas por defecto con resetToDefaults', () => {
    useUiModeStore.getState().setVisibleRoutes(['/dashboard']);
    expect(useUiModeStore.getState().visibleRoutes).toEqual(['/dashboard']);

    useUiModeStore.getState().resetToDefaults();
    expect(useUiModeStore.getState().visibleRoutes).toEqual(DEFAULT_VISIBLE_ROUTES);
  });
});
