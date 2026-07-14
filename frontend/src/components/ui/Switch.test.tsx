import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Switch } from './Switch';

describe('<Switch>', () => {
  it('renderiza y refleja el estado inicial', () => {
    render(<Switch checked={false} aria-label="activo" />);
    expect(screen.getByRole('switch', { name: 'activo' })).toHaveAttribute(
      'data-state',
      'unchecked',
    );
  });

  it('togglea el estado al hacer click', async () => {
    const user = userEvent.setup();
    render(<Switch aria-label="activo" />);
    const sw = screen.getByRole('switch', { name: 'activo' });
    expect(sw).toHaveAttribute('data-state', 'unchecked');
    await user.click(sw);
    expect(sw).toHaveAttribute('data-state', 'checked');
  });

  it('respeta checked=true inicialmente', () => {
    render(<Switch checked aria-label="activo" />);
    expect(screen.getByRole('switch', { name: 'activo' })).toHaveAttribute('data-state', 'checked');
  });
});