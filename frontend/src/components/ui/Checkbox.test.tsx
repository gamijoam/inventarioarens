import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Checkbox } from './Checkbox';

describe('<Checkbox>', () => {
  it('renderiza y refleja el estado inicial', () => {
    render(<Checkbox aria-label="seleccionar" />);
    expect(screen.getByRole('checkbox', { name: 'seleccionar' })).toHaveAttribute(
      'data-state',
      'unchecked',
    );
  });

  it('togglea el estado al hacer click', async () => {
    const user = userEvent.setup();
    render(<Checkbox aria-label="seleccionar" />);
    const cb = screen.getByRole('checkbox', { name: 'seleccionar' });
    expect(cb).toHaveAttribute('data-state', 'unchecked');
    await user.click(cb);
    expect(cb).toHaveAttribute('data-state', 'checked');
  });

  it('respeta checked=true inicialmente', () => {
    render(<Checkbox checked aria-label="seleccionar" />);
    expect(screen.getByRole('checkbox', { name: 'seleccionar' })).toHaveAttribute('data-state', 'checked');
  });

  it('respeta disabled y no permite toggle', async () => {
    const user = userEvent.setup();
    render(<Checkbox disabled aria-label="seleccionar" />);
    const cb = screen.getByRole('checkbox', { name: 'seleccionar' });
    expect(cb).toBeDisabled();
    await user.click(cb);
    expect(cb).toHaveAttribute('data-state', 'unchecked');
  });
});