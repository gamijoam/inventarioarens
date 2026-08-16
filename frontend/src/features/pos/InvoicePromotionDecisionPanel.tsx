import { Button } from '@/components/ui/Button';

export type InvoicePromotionDecision = 'validate' | 'reject';

interface InvoicePromotionDecisionPanelProps {
  promotionName: string;
  decision: InvoicePromotionDecision | null;
  onDecision: (decision: InvoicePromotionDecision) => void;
}

export function InvoicePromotionDecisionPanel({
  promotionName,
  decision,
  onDecision,
}: InvoicePromotionDecisionPanelProps) {
  if (decision !== null) {
    return (
      <div className="border-border bg-surface rounded-lg border p-3 text-sm">
        <p className="font-semibold">{promotionName}</p>
        <p className="text-text-muted mt-1">
          Decisión seleccionada: {decision === 'validate' ? 'Validar' : 'Rechazar'}
        </p>
        <div className="mt-2 grid grid-cols-2 gap-2">
          <Button
            size="sm"
            variant={decision === 'validate' ? 'primary' : 'outline'}
            onClick={() => onDecision('validate')}
          >
            Validar
          </Button>
          <Button
            size="sm"
            variant={decision === 'reject' ? 'danger' : 'outline'}
            onClick={() => onDecision('reject')}
          >
            Rechazar
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="border-warning/40 bg-warning/10 rounded-lg border p-3 text-sm">
      <p className="font-semibold">Promoción pendiente de decisión</p>
      <p className="text-text-muted mt-1">{promotionName || 'Descuento de factura'}</p>
      <div className="mt-3 grid grid-cols-2 gap-2">
        <Button size="sm" variant="primary" onClick={() => onDecision('validate')}>
          Validar
        </Button>
        <Button size="sm" variant="outline" onClick={() => onDecision('reject')}>
          Rechazar
        </Button>
      </div>
    </div>
  );
}
