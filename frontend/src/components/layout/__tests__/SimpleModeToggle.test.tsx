import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SimpleModeToggle } from '../SimpleModeToggle';
import { useUiModeStore } from '@/stores/uiMode';

beforeEach(() => {
  useUiModeStore.setState({ isSimpleMode: true });
});

describe('<SimpleModeToggle>', () => {
  it('se inicializa en Modo Fácil por defecto', () => {
    render(<SimpleModeToggle />);
    expect(screen.getByText('Modo Fácil')).toBeTruthy();
    expect(screen.getByText('ON')).toBeTruthy();
  });

  it('alterna a Modo Completo al hacer clic', () => {
    render(<SimpleModeToggle />);
    const button = screen.getByRole('button', { name: /Desactivar Modo Fácil/i });
    fireEvent.click(button);

    expect(screen.getByText('Modo Completo')).toBeTruthy();
    expect(screen.getByText('Full')).toBeTruthy();
    expect(useUiModeStore.getState().isSimpleMode).toBe(false);
  });

  it('vuelve a Modo Fácil al hacer clic nuevamente', () => {
    useUiModeStore.setState({ isSimpleMode: false });
    render(<SimpleModeToggle />);

    const button = screen.getByRole('button', { name: /Activar Modo Fácil/i });
    fireEvent.click(button);

    expect(screen.getByText('Modo Fácil')).toBeTruthy();
    expect(useUiModeStore.getState().isSimpleMode).toBe(true);
  });
});
