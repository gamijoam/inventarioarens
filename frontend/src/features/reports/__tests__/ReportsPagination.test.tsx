import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ReportPagination } from '../ReportsManager';

describe('ReportPagination', () => {
  it('moves to the next page and displays the result count', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();

    render(
      <ReportPagination
        meta={{ current_page: 1, per_page: 25, total: 51, last_page: 3 }}
        label="ventas"
        onPageChange={onPageChange}
      />,
    );

    expect(screen.getByText('Página 1 de 3 · 51 ventas')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Siguiente/ }));

    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('does not render controls for a single page', () => {
    const onPageChange = vi.fn();

    render(
      <ReportPagination
        meta={{ current_page: 1, per_page: 25, total: 1, last_page: 1 }}
        label="cajas"
        onPageChange={onPageChange}
      />,
    );

    expect(screen.queryByRole('button', { name: /Siguiente/ })).not.toBeInTheDocument();
  });
});
