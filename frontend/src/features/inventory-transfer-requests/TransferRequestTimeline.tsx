/**
 * TransferRequestTimeline: cronologia de eventos de una solicitud
 * inter-empresa (created / accepted / rejected / cancelled).
 * Lee los timestamps y responded_by del modelo TransferRequest y los
 * renderiza como lista vertical con iconos y badges.
 */
import { CheckCircle2, FileText, PackageX, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { TransferRequest, TransferRequestStatus } from './schemas';

const STAGE_META: Record<TransferRequestStatus, { label: string; icon: typeof FileText; variant: 'info' | 'warning' | 'success' | 'danger' | 'default' }> = {
  requested: { label: 'Solicitada', icon: FileText, variant: 'info' },
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
    const stage: TransferRequestStatus = request.status === 'rejected' ? 'rejected' : 'completed';
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
        description="Esta solicitud no tiene eventos registrados todavia."
      />
    );
  }

  return (
    <ol className="relative space-y-3 border-l border-border pl-4" data-testid="transfer-request-timeline">
      {events.map((event, idx) => {
        const meta = STAGE_META[event.stage]!;
        const Icon = meta.icon;
        const key = `${event.stage}-${event.at ?? idx}`;
        return (
          <li key={key} className="relative" data-testid={`timeline-${event.stage}`}>
            <span
              className="absolute -left-[22px] top-0 flex size-6 items-center justify-center rounded-full border border-border bg-surface text-primary"
              aria-hidden="true"
            >
              <Icon className="size-3.5" />
            </span>
            <div className="flex flex-wrap items-baseline gap-2">
              <Badge variant={meta.variant}>{meta.label}</Badge>
              <time className="text-xs text-text-muted" dateTime={event.at ?? undefined}>
                {formatDateTime(event.at)}
              </time>
              {event.isCurrent && (
                <span className="text-[10px] uppercase tracking-wide text-primary">(actual)</span>
              )}
            </div>
            <div className="mt-1 text-sm text-text-muted">
              {event.by_user_id
                ? <span>por usuario #{event.by_user_id}</span>
                : <span className="italic">sin responsable</span>}
            </div>
            {event.notes && (
              <p className="mt-1 rounded bg-bg/40 px-2 py-1 text-xs text-text-secondary">{event.notes}</p>
            )}
          </li>
        );
      })}
    </ol>
  );
}
