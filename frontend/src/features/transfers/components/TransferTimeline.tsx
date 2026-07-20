/**
 * TransferTimeline: lista vertical de eventos cronologicos de un traslado.
 * Lee de `GET /api/inventory-transfers/{id}/timeline` via useTransferTimeline.
 *
 * Eventos posibles:
 *   - created: solicitud inicial
 *   - prepared: con has_differences opcional
 *   - dispatched: salida del almacen origen
 *   - received: con differences_count opcional
 *   - resolved: con resolution_status
 *   - cancelled: motivo en notes
 */
import { useMemo } from 'react';
import {
  CheckCircle2,
  FileText,
  PackageCheck,
  PackageOpen,
  PackageX,
  Truck,
  XCircle,
} from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { useTransferTimeline } from '@/features/transfers/api';
import type { TimelineEvent, TimelineStage } from '@/features/transfers/schemas';

const STAGE_META: Record<TimelineStage, { label: string; icon: typeof CheckCircle2; variant: 'info' | 'warning' | 'success' | 'danger' | 'default' }> = {
  created: { label: 'Solicitado', icon: FileText, variant: 'info' },
  prepared: { label: 'Preparado', icon: PackageCheck, variant: 'info' },
  dispatched: { label: 'Despachado', icon: Truck, variant: 'info' },
  received: { label: 'Recibido', icon: PackageOpen, variant: 'success' },
  resolved: { label: 'Resuelto', icon: CheckCircle2, variant: 'success' },
  cancelled: { label: 'Cancelado', icon: PackageX, variant: 'danger' },
};

function formatDateTime(iso: string): string {
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

interface TransferTimelineProps {
  transferId: number;
}

export function TransferTimeline({ transferId }: TransferTimelineProps) {
  const { data: events = [], isLoading } = useTransferTimeline(transferId);

  if (isLoading) return <Skeleton className="h-24 w-full" />;

  if (events.length === 0) {
    return (
      <EmptyState
        icon={<XCircle className="size-6" />}
        title="Sin eventos"
        description="Este traslado no tiene eventos registrados."
      />
    );
  }

  return (
    <ol className="relative space-y-3 border-l border-border pl-4" data-testid="transfer-timeline">
      {events.map((event, idx) => (
        <TimelineItem key={`${event.stage}-${event.at}-${idx}`} event={event} />
      ))}
    </ol>
  );
}

function TimelineItem({ event }: { event: TimelineEvent }) {
  const meta = STAGE_META[event.stage];
  const Icon = meta.icon;
  const extra = useMemo(() => {
    const parts: string[] = [];
    if (event.has_differences) parts.push('con diferencias');
    if (typeof event.differences_count === 'number' && event.differences_count > 0) {
      parts.push(`${event.differences_count} item(s) con diferencia`);
    }
    if (event.resolution_status) parts.push(`resolucion: ${event.resolution_status}`);
    return parts.join(' · ');
  }, [event.has_differences, event.differences_count, event.resolution_status]);

  return (
    <li className="relative" data-testid={`timeline-${event.stage}`}>
      <span
        className="absolute -left-[22px] top-0 flex size-6 items-center justify-center rounded-full border border-border bg-surface text-primary"
        aria-hidden="true"
      >
        <Icon className="size-3.5" />
      </span>
      <div className="flex flex-wrap items-baseline gap-2">
        <Badge variant={meta.variant}>{meta.label}</Badge>
        <time className="text-xs text-text-muted" dateTime={event.at}>{formatDateTime(event.at)}</time>
      </div>
      <div className="mt-1 text-sm text-text-muted">
        {event.by_user ? <span>por {event.by_user.name}</span> : <span className="italic">sin responsable</span>}
        {extra && <span className="ml-2 text-xs">({extra})</span>}
      </div>
      {event.notes && (
        <p className="mt-1 rounded bg-bg/40 px-2 py-1 text-xs text-text-secondary">{event.notes}</p>
      )}
    </li>
  );
}
