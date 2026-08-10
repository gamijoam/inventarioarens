import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AppVersionBadge } from '../AppVersionBadge';

describe('AppVersionBadge', () => {
  it('muestra la version de la app', () => {
    render(<AppVersionBadge />);

    const badge = screen.getByTestId('app-version-badge');
    expect(badge).toBeInTheDocument();
    expect(badge.textContent).toMatch(/^v/);
  });

  it('acepta una clase adicional', () => {
    render(<AppVersionBadge className="ml-1" />);

    const badge = screen.getByTestId('app-version-badge');
    expect(badge.className).toContain('ml-1');
  });
});
