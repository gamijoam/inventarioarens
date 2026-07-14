import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Combobox } from './Combobox';

const OPTIONS = [
  { value: 'a', label: 'Apple' },
  { value: 'b', label: 'Banana' },
  { value: 'c', label: 'Cherry' },
];

// eslint-disable-next-line @typescript-eslint/no-empty-function
const noop = () => {};

describe('<Combobox>', () => {
  it('renderiza el placeholder cuando no hay seleccion', () => {
    render(<Combobox options={OPTIONS} value={[]} onChange={noop} placeholder="Elegir..." />);
    expect(screen.getByPlaceholderText('Elegir...')).toBeInTheDocument();
  });

  it('muestra chips para los valores seleccionados', () => {
    render(<Combobox options={OPTIONS} value={['a', 'b']} onChange={noop} />);
    expect(screen.getByText('Apple')).toBeInTheDocument();
    expect(screen.getByText('Banana')).toBeInTheDocument();
    expect(screen.queryByText('Cherry')).not.toBeInTheDocument();
  });

  it('escribir filtra las opciones', async () => {
    const user = userEvent.setup();
    render(<Combobox options={OPTIONS} value={[]} onChange={noop} />);
    const input = screen.getByRole('textbox');
    await user.click(input);
    await user.type(input, 'an');
    // Solo Banana contiene "an".
    expect(screen.getByText('Banana')).toBeInTheDocument();
    expect(screen.queryByText('Apple')).not.toBeInTheDocument();
  });

  it('llama onChange al seleccionar una opcion', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Combobox options={OPTIONS} value={[]} onChange={onChange} />);
    const input = screen.getByRole('textbox');
    await user.click(input);
    await user.type(input, 'Apple');
    await user.keyboard('{Enter}');
    expect(onChange).toHaveBeenCalledWith(['a']);
  });

  it('boton X del chip llama onChange sin ese valor', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Combobox options={OPTIONS} value={['a', 'b']} onChange={onChange} />);
    await user.click(screen.getByRole('button', { name: 'Quitar Apple' }));
    expect(onChange).toHaveBeenCalledWith(['b']);
  });
});