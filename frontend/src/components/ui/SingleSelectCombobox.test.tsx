import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SingleSelectCombobox, type SingleSelectOption } from './SingleSelectCombobox';

const OPTIONS: SingleSelectOption[] = [
  { value: 1, label: 'Filtro de Aceite Corolla', hint: 'SKU: FIL-01 · Código: 90915-10001' },
  { value: 2, label: 'Filtro de Gasolina Yaris', hint: 'SKU: FIL-02' },
  { value: 3, label: 'Juego de Pastillas para Freno Delantero', hint: 'SKU: BRK-01 · Código: 04465-02220' },
  { value: 4, label: 'Bujía NGK Láser Platinum', hint: 'SKU: BUJ-01' },
  { value: 5, label: 'Aceite Motor Semi-Sintético 20W-50', hint: 'SKU: OIL-20W50' },
];

describe('<SingleSelectCombobox>', () => {
  it('no abre las sugerencias simplemente al recibir foco (openOnFocus=false por defecto)', () => {
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    fireEvent.focus(input);

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('abre las sugerencias al hacer click directamente en el input', async () => {
    const user = userEvent.setup();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    await user.click(input);

    expect(screen.getByRole('listbox')).toBeInTheDocument();
    expect(screen.getByText('Filtro de Aceite Corolla')).toBeInTheDocument();
  });

  it('abre las sugerencias al escribir en el input', async () => {
    const user = userEvent.setup();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    await user.type(input, 'buj');

    expect(screen.getByRole('listbox')).toBeInTheDocument();
    expect(screen.getByText('Bujía NGK Láser Platinum')).toBeInTheDocument();
  });

  it('filtra correctamente con palabras compuestas sin importar conectores (filtro aceite)', async () => {
    const user = userEvent.setup();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    await user.type(input, 'filtro aceite');

    expect(screen.getByText('Filtro de Aceite Corolla')).toBeInTheDocument();
    expect(screen.queryByText('Filtro de Gasolina Yaris')).not.toBeInTheDocument();
    expect(screen.queryByText('Juego de Pastillas para Freno Delantero')).not.toBeInTheDocument();
  });

  it('filtra palabras normales ignorando acentos y diacríticos (bujia -> Bujía, laser -> Láser)', async () => {
    const user = userEvent.setup();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    await user.type(input, 'bujia laser');

    expect(screen.getByText('Bujía NGK Láser Platinum')).toBeInTheDocument();
    expect(screen.queryByText('Filtro de Aceite Corolla')).not.toBeInTheDocument();
  });

  it('filtra sin importar el orden de las palabras (freno pastilla)', async () => {
    const user = userEvent.setup();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    await user.type(input, 'freno pastilla');

    expect(screen.getByText('Juego de Pastillas para Freno Delantero')).toBeInTheDocument();
    expect(screen.queryByText('Filtro de Aceite Corolla')).not.toBeInTheDocument();
  });

  it('filtra tolerando diferencias de guiones y códigos (20w50 encuentra 20W-50)', async () => {
    const user = userEvent.setup();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');

    await user.type(input, 'aceite 20w50');

    expect(screen.getByText('Aceite Motor Semi-Sintético 20W-50')).toBeInTheDocument();
  });

  it('permite seleccionar una opción y cerrar el dropdown', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<SingleSelectCombobox options={OPTIONS} value={null} onChange={onChange} />);
    const input = screen.getByRole('textbox');

    await user.click(input);
    await user.click(screen.getByText('Filtro de Aceite Corolla'));

    expect(onChange).toHaveBeenCalledWith(1);
  });
});
