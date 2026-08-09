import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { TapButton } from '../TapButton';

describe('TapButton', () => {
  it('renderiza un boton con contenido y clases', () => {
    render(
      <TapButton onPress={() => {}} className="btn-x" data-testid="tap">
        Agregar
      </TapButton>,
    );

    const button = screen.getByTestId('tap');
    expect(button.tagName).toBe('BUTTON');
    expect(button.className).toContain('btn-x');
    expect(button).toHaveTextContent('Agregar');
  });

  it('responde al click de mouse (respaldo de teclado) sin romper el bind', () => {
    const onPress = vi.fn();
    render(
      <TapButton onPress={onPress} data-testid="tap">
        Agregar
      </TapButton>,
    );

    // fireEvent.click no simula pointer events, asi que use-gesture no
    // intercepta: verifica el respaldo onClick para mouse/teclado.
    fireEvent.click(screen.getByTestId('tap'));
    expect(onPress).toHaveBeenCalled();
  });

  it('no dispara onPress cuando esta deshabilitado', () => {
    const onPress = vi.fn();
    render(
      <TapButton onPress={onPress} disabled data-testid="tap">
        Agregar
      </TapButton>,
    );

    fireEvent.click(screen.getByTestId('tap'));
    expect(onPress).not.toHaveBeenCalled();
  });
});
