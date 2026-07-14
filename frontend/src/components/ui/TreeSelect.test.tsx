import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TreeSelect } from './TreeSelect';

// eslint-disable-next-line @typescript-eslint/no-empty-function
const noop = () => {};

const NODES = [
  {
    id: 1,
    label: 'Electronica',
    children: [
      { id: 2, label: 'Phones' },
      {
        id: 3,
        label: 'Computacion',
        children: [
          { id: 4, label: 'Laptops' },
          { id: 5, label: 'Desktops' },
        ],
      },
    ],
  },
  { id: 6, label: 'Hogar' },
];

describe('<TreeSelect>', () => {
  it('muestra nodos raiz y nodos de primer nivel (auto-expand)', () => {
    render(<TreeSelect nodes={NODES} value={[]} onChange={noop} />);
    expect(screen.getByText('Electronica')).toBeInTheDocument();
    expect(screen.getByText('Phones')).toBeInTheDocument();
    expect(screen.getByText('Hogar')).toBeInTheDocument();
  });

  it('no muestra nodos de niveles profundos hasta expandir', () => {
    render(<TreeSelect nodes={NODES} value={[]} onChange={noop} />);
    // Laptops y Desktops son nietos, no se renderizan hasta nivel 2+.
    expect(screen.queryByText('Laptops')).not.toBeInTheDocument();
    expect(screen.queryByText('Desktops')).not.toBeInTheDocument();
  });

  it('expande/colapsa al click en el chevron', async () => {
    const user = userEvent.setup();
    render(<TreeSelect nodes={NODES} value={[]} onChange={noop} />);
    // Por default, raices y nivel 1 estan expandidos. "Computacion" se ve (es nivel 1).
    expect(screen.getByText('Computacion')).toBeInTheDocument();
    // Computacion inicia expandido, tiene 2 hijos. Laptops no se ve (nivel 2).
    expect(screen.queryByText('Laptops')).not.toBeInTheDocument();

    // Colapsar Computacion: ahora Laptops tampoco se ve (siempre estuvo oculto).
    const colapsarBtns = screen.getAllByRole('button', { name: 'Colapsar' });
    await user.click(colapsarBtns[colapsarBtns.length - 1]!);
    expect(screen.queryByText('Computacion')).not.toBeInTheDocument();
  });

  it('llama onChange con el id del nodo al seleccionarlo', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<TreeSelect nodes={NODES} value={[]} onChange={onChange} />);
    // Hay 3 raices + primer nivel. Las raices se muestran directo.
    // Seleccionar Electronica (id 1).
    await user.click(screen.getByLabelText('Seleccionar Electronica'));
    expect(onChange).toHaveBeenCalledWith([1]);
  });
});