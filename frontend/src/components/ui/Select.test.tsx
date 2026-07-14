import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Select } from './Select';

describe('<Select>', () => {
  it('renderiza las opciones', () => {
    render(
      <Select>
        <option value="a">Opcion A</option>
        <option value="b">Opcion B</option>
      </Select>,
    );
    expect(screen.getByRole('combobox')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Opcion A' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Opcion B' })).toBeInTheDocument();
  });

  it('cambia de valor al seleccionar otra opcion', async () => {
    const user = userEvent.setup();
    render(
      <Select defaultValue="a">
        <option value="a">A</option>
        <option value="b">B</option>
      </Select>,
    );
    await user.selectOptions(screen.getByRole('combobox'), 'b');
    expect(screen.getByRole('combobox')).toHaveValue('b');
  });

  it('marca aria-invalid cuando invalid=true', () => {
    render(
      <Select invalid>
        <option value="a">A</option>
      </Select>,
    );
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
  });
});