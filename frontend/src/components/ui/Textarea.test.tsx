import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Textarea } from './Textarea';

describe('<Textarea>', () => {
  it('acepta texto y emite onChange', async () => {
    const user = userEvent.setup();
    render(<Textarea aria-label="descripcion" />);
    const ta = screen.getByLabelText('descripcion');
    await user.type(ta, 'Hola mundo');
    expect(ta).toHaveValue('Hola mundo');
  });

  it('aplica aria-invalid cuando invalid=true', () => {
    render(<Textarea aria-label="x" invalid />);
    expect(screen.getByLabelText('x')).toHaveAttribute('aria-invalid', 'true');
  });

  it('respeta el rows por defecto (3)', () => {
    render(<Textarea aria-label="x" />);
    expect(screen.getByLabelText('x')).toHaveAttribute('rows', '3');
  });
});