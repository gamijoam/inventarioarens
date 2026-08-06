import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Input } from './Input';

describe('<Input>', () => {
  it('mantiene el texto visible sobre superficies claras aunque el padre tenga tema oscuro', () => {
    render(
      <div className="text-white">
        <Input aria-label="Campo" />
      </div>,
    );

    expect(screen.getByRole('textbox', { name: 'Campo' })).toHaveClass('text-text-primary');
  });
});
