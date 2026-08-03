/** Cronologia comun para solicitudes de stock y propuestas de envio. */
import { CheckCircle2, FileText, PackageX, Truck, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { TransferRequest, TransferRequestStatus } from './schemas';

const STAGE_META: Record<
  TransferRequestStatus,
  {
    label: string;
    icon: typeof FileText;
    variant: 'info' | 'warning' | 'success' | 'danger' | 'default';
  }
> = {
  requested: { label: 'Solicitada', icon: FileText, variant: 'info' },
  accepted: { label: 'Aceptada', icon: CheckCircle2, variant: 'warning' },
  prepared: { label: 'Preparada', icon: CheckCircle2, variant: 'warning' },
  dispatched: { label: 'Despachada', icon: Truck, variant: 'info' },
  delivered: { label: 'Entregada', icon: Truck, variant: 'info' },
  completed: { label: 'Completada', icon: CheckCircle2, variant: 'success' },
  rejected: { label: 'Rechazada', icon: XCircle, variant: 'danger' },
  cancelled: { label: 'Cancelada', icon: PackageX, variant: 'default' },
};

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '-';
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

interface TransferRequestTimelineProps {
  request: TransferRequest;
  isLoading?: boolean;
}

interface TimelineEvent {
  stage: TransferRequestStatus;
  at: string | null | undefined;
  by_user_id: number | null | undefined;
  notes: string | null | undefined;
  isCurrent: boolean;
}

export function TransferRequestTimeline({ request, isLoading }: TransferRequestTimelineProps) {
  if (isLoading) return <Skeleton className="h-24 w-full" />;

  // Construimos eventos en orden cronologico segun los timestamps del modelo.
  const events: TimelineEvent[] = [];
  if (request.requested_at) {
    events.push({
      stage: 'requested',
      at: request.requested_at,
      by_user_id: request.requested_by ?? null,
      notes: request.reason ?? null,
      isCurrent: request.status === 'requested',
    });
  }
  if (request.responded_at) {
    const stage: TransferRequestStatus =
      request.status === 'rejected'
        ? 'rejected'
        : request.logistics_mode
          ? 'accepted'
          : 'completed';
    events.push({
      stage,
      at: request.responded_at,
      by_user_id: request.responded_by ?? null,
      notes: request.response_notes ?? null,
      isCurrent: request.status === stage,
    });
  }
  // Si la solicitud esta cancelada sin responded_at (caso edge: A cancela
  // antes de que B responda), usamos el responded_at que el backend setea
  // al cancelar.
  if (request.status === 'cancelled' && !events.some((e) => e.stage === 'cancelled')) {
    events.push({
      stage: 'cancelled',
      at: request.responded_at ?? request.requested_at,
      by_user_id: request.responded_by ?? null,
      notes: request.response_notes ?? null,
      isCurrent: true,
    });
  }

  if (events.length === 0) {
    return (
      <EmptyState
        icon={<FileText className="size-6" />}
        title="Sin eventos"
        description="Este traslado no tiene eventos registrados todavía."
      />
    );
  }

  return (
    <ol
      className="border-border relative space-y-3 border-l pl-4"
      data-testid="transfer-request-timeline"
    >
      {events.map((event, idx) => {
        const meta = STAGE_META[event.stage]!;
        const label =
          event.stage === 'requested' && request.flow_type === 'shipment_offer'
            ? 'Propuesta enviada'
            : meta.label;
        const Icon = meta.icon;
        const key = `${event.stage}-${event.at ?? idx}`;
        return (
          <li key={key} className="relative" data-testid={`timeline-${event.stage}`}>
            <span
              className="border-border bg-surface text-primary absolute top-0 -left-[22px] flex size-6 items-center justify-center rounded-full border"
              aria-hidden="true"
            >
              <Icon className="size-3.5" />
            </span>
            <div className="flex flex-wrap items-baseline gap-2">
              <Badge variant={meta.variant}>{label}</Badge>
              <time className="text-text-muted text-xs" dateTime={event.at ?? undefined}>
                {formatDateTime(event.at)}
              </time>
              {event.isCurrent && (
                <span className="text-primary text-[10px] tracking-wide uppercase">(actual)</span>
              )}
            </div>
            <div className="text-text-muted mt-1 text-sm">
              {event.by_user_id ? (
                <span>por usuario #{event.by_user_id}</span>
              ) : (
                <span className="italic">sin responsable</span>
              )}
            </div>
            {event.notes && (
              <p className="bg-bg/40 text-text-secondary mt-1 rounded px-2 py-1 text-xs">
                {event.notes}
              </p>
            )}
          </li>
        );
      })}
    </ol>
  );
}
