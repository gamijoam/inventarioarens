import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { BulkActionsMenu } from './BulkActionsMenu';

const { useBulkOperation } = vi.hoisted(() => ({
  useBulkOperation: vi.fn(() => ({ data: null })),
}));

vi.mock('./useBulkAction', () => ({ useBulkOperation }));
vi.mock('./ActionDialogs', () => ({
  ActionDialog: ({ action, allMatching }: { action: string; allMatching: boolean }) => (
    <div data-testid="action-dialog">
      {action}:{String(allMatching)}
    </div>
  ),
}));

describe('<BulkActionsMenu>', () => {
  it('permite seleccionar todos los resultados filtrados', async () => {
    const user = userEvent.setup();
    const onSelectAllMatching = vi.fn();

    render(
      <BulkActionsMenu
        selectedIds={[1, 2]}
        allMatching={false}
        allVisibleSelected
        totalMatching={5}
        visibleCount={2}
        filters={{ active_status: 'active' }}
        onClearSelection={() => undefined}
        onSelectAllMatching={onSelectAllMatching}
        onUseVisibleSelection={() => undefined}
      />,
    );

    await user.click(screen.getByRole('button', { name: 'Seleccionar los 5 resultados' }));
    expect(onSelectAllMatching).toHaveBeenCalledOnce();
  });

  it('ofrece la clasificación fiscal en acciones masivas', async () => {
    const user = userEvent.setup();

    render(
      <BulkActionsMenu
        selectedIds={[1]}
        allMatching
        allVisibleSelected
        totalMatching={1}
        visibleCount={1}
        filters={{}}
        onClearSelection={() => undefined}
        onSelectAllMatching={() => undefined}
        onUseVisibleSelection={() => undefined}
      />,
    );

    await user.click(screen.getByTestId('bulk-actions-trigger'));
    await user.click(screen.getByTestId('bulk-action-assign_fiscal_tax_rate'));
    expect(screen.getByTestId('action-dialog')).toHaveTextContent('assign_fiscal_tax_rate:true');
  });
});
