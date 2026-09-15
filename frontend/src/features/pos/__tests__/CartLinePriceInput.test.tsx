import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { CartLinePriceInput } from '../PosTerminal';

describe('<CartLinePriceInput>', () => {
  it('muestra el valor numérico inicial correctamente', () => {
    render(<CartLinePriceInput value={50} lineId="line-1" onChange={() => {}} />);

    const input = screen.getByTestId('pos-line-price-line-1') as HTMLInputElement;
    expect(input.value).toBe('50');
  });

  it('permite borrar el valor completamente sin que se quede un 0 estático', () => {
    const onChange = vi.fn();
    render(<CartLinePriceInput value={50} lineId="line-1" onChange={onChange} />);

    const input = screen.getByTestId('pos-line-price-line-1') as HTMLInputElement;

    // Usuario borra el contenido
    fireEvent.change(input, { target: { value: '' } });
    expect(input.value).toBe('');

    // Escribe un nuevo valor
    fireEvent.change(input, { target: { value: '40' } });
    expect(input.value).toBe('40');
    expect(onChange).toHaveBeenCalledWith(40);
  });

  it('selecciona el texto al hacer focus para facilitar la edición rápida', () => {
    render(<CartLinePriceInput value={50} lineId="line-1" onChange={() => {}} />);

    const input = screen.getByTestId('pos-line-price-line-1') as HTMLInputElement;
    const selectSpy = vi.spyOn(input, 'select');

    fireEvent.focus(input);
    expect(selectSpy).toHaveBeenCalled();
  });

  it('normaliza a 0 al salir del campo (onBlur) si quedó vacío', () => {
    const onChange = vi.fn();
    render(<CartLinePriceInput value={50} lineId="line-1" onChange={onChange} />);

    const input = screen.getByTestId('pos-line-price-line-1') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '' } });
    fireEvent.blur(input);

    expect(onChange).toHaveBeenCalledWith(0);
  });
});
