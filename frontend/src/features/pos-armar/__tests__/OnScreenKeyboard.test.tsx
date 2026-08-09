import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { OnScreenKeyboard } from '../OnScreenKeyboard';

describe('OnScreenKeyboard', () => {
  it('renderiza las teclas de todas las filas', () => {
    render(<OnScreenKeyboard onKey={() => {}} />);

    expect(screen.getByTestId('key-A')).toBeInTheDocument();
    expect(screen.getByTestId('key-5')).toBeInTheDocument();
    expect(screen.getByTestId('key-Ñ')).toBeInTheDocument();
    expect(screen.getByTestId('key-ESPACIO')).toBeInTheDocument();
    expect(screen.getByTestId('key-BORRAR')).toBeInTheDocument();
  });

  it('dispara la accion correcta al pulsar una letra', () => {
    const onKey = vi.fn();
    render(<OnScreenKeyboard onKey={onKey} />);

    fireEvent.click(screen.getByTestId('key-A'));
    expect(onKey).toHaveBeenCalledWith({ type: 'char', char: 'A' });
  });

  it('dispara las acciones de espacio y borrar', () => {
    const onKey = vi.fn();
    render(<OnScreenKeyboard onKey={onKey} />);

    fireEvent.click(screen.getByTestId('key-ESPACIO'));
    fireEvent.click(screen.getByTestId('key-BORRAR'));

    expect(onKey).toHaveBeenNthCalledWith(1, { type: 'space' });
    expect(onKey).toHaveBeenNthCalledWith(2, { type: 'backspace' });
  });

  it('respeta la prop disabled', () => {
    render(<OnScreenKeyboard onKey={() => {}} disabled />);

    expect(screen.getByTestId('key-A')).toBeDisabled();
  });
});
