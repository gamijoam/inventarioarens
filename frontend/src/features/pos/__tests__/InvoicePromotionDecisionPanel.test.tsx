import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { InvoicePromotionDecisionPanel } from '../InvoicePromotionDecisionPanel';

describe('<InvoicePromotionDecisionPanel>', () => {
  it('replaces the pending decision card with the selected decision', () => {
    const onDecision = vi.fn();

    const { rerender } = render(
      <InvoicePromotionDecisionPanel
        promotionName="DESCUENTO BSS"
        decision={null}
        onDecision={onDecision}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Validar' }));
    expect(onDecision).toHaveBeenCalledWith('validate');

    rerender(
      <InvoicePromotionDecisionPanel
        promotionName="DESCUENTO BSS"
        decision="validate"
        onDecision={onDecision}
      />,
    );

    expect(screen.queryByText('Promoción pendiente de decisión')).not.toBeInTheDocument();
    expect(screen.getByText('Decisión seleccionada: Validar')).toBeInTheDocument();
  });

  it('shows the rejected decision without leaving it as pending', () => {
    render(
      <InvoicePromotionDecisionPanel
        promotionName="DESCUENTO BSS"
        decision="reject"
        onDecision={vi.fn()}
      />,
    );

    expect(screen.queryByText('Promoción pendiente de decisión')).not.toBeInTheDocument();
    expect(screen.getByText('Decisión seleccionada: Rechazar')).toBeInTheDocument();
  });
});
