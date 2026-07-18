import { useState } from 'react';
import { Check, ShieldQuestion, Wrench } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  type WarrantyClaim,
  useDeliverWarrantyClaim,
  useResolveWarrantyClaim,
  useReviewWarrantyClaim,
  useWarrantyClaims,
} from './api';

const STATUS_LABELS: Record<string, string> = {
  received: 'Recibida',
  under_review: 'En revisión',
  approved: 'Aprobada',
  rejected: 'Rechazada',
  delivered: 'Entregada',
  closed: 'Cerrada',
};

function statusVariant(status: string): 'default' | 'success' | 'danger' | 'warning' | 'info' {
  if (status === 'closed' || status === 'delivered') return 'success';
  if (status === 'rejected') return 'danger';
  if (status === 'approved') return 'info';
  if (status === 'under_review') return 'warning';
  return 'default';
}

function formatDate(value?: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('es-VE');
}

export function WarrantiesManager() {
  const canView = useCan(PERMISSIONS.WARRANTIES_VIEW);
  const claims = useWarrantyClaims({ enabled: canView });
  const data = claims.data?.data ?? [];

  if (!canView) {
    return (
      <PermissionDenied
        permission={PERMISSIONS.WARRANTIES_VIEW}
        message="No tienes permiso para ver garantias."
      />
    );
  }

  if (claims.isLoading && !claims.data) return <Skeleton className="h-64 w-full" />;

  if (claims.isError) {
    return (
      <EmptyState
        title="No se pudieron cargar garantías"
        description="Intenta actualizar el listado."
        action={<Button onClick={() => void claims.refetch()}>Reintentar</Button>}
      />
    );
  }

  if (data.length === 0) {
    return (
      <EmptyState
        icon={<ShieldQuestion className="size-8" />}
        title="Sin casos de garantía"
        description="Los casos creados desde ventas o seriales aparecerán aquí."
      />
    );
  }

  return (
    <Card>
      <div className="divide-y divide-border">
        {data.map((claim) => (
          <WarrantyClaimRow key={claim.id} claim={claim} />
        ))}
      </div>
    </Card>
  );
}

function WarrantyClaimRow({ claim }: { claim: WarrantyClaim }) {
  const canReview = useCan(PERMISSIONS.WARRANTIES_REVIEW);
  const canResolve = useCan(PERMISSIONS.WARRANTIES_RESOLVE);
  const canDeliver = useCan(PERMISSIONS.WARRANTIES_DELIVER);
  const reviewable = claim.status === 'received' || claim.status === 'under_review';
  const resolvable = claim.status === 'approved' || claim.status === 'rejected';
  const deliverable = claim.status === 'approved' || claim.status === 'rejected';
  const review = useReviewWarrantyClaim();
  const resolve = useResolveWarrantyClaim();
  const deliver = useDeliverWarrantyClaim();
  const [diagnosis, setDiagnosis] = useState(claim.diagnosis ?? '');
  const [resolutionType, setResolutionType] = useState(claim.resolution_type ?? 'rejected');
  const [notes, setNotes] = useState(claim.resolution_notes ?? '');

  async function reviewClaim(status: string) {
    await review.mutateAsync({
      id: claim.id,
      payload: {
        status,
        diagnosis,
        resolution_type: status === 'approved' ? 'repair' : status === 'rejected' ? 'rejected' : undefined,
        resolution_notes: notes || undefined,
      },
    });
    toast.success('Caso revisado.');
  }

  async function resolveClaim() {
    await resolve.mutateAsync({ id: claim.id, payload: { resolution_type: resolutionType, resolution_notes: notes || undefined } });
    toast.success('Caso resuelto.');
  }

  async function deliverClaim() {
    await deliver.mutateAsync({ id: claim.id, resolution_notes: notes || undefined });
    toast.success('Caso entregado.');
  }

  return (
    <section className="grid gap-4 p-4 lg:grid-cols-[1fr_360px]">
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="font-semibold">Garantía #{claim.id}</h3>
          <Badge variant={statusVariant(claim.status)}>{STATUS_LABELS[claim.status] ?? claim.status}</Badge>
          {claim.product_unit_serial && <Badge variant="default">{claim.product_unit_serial}</Badge>}
        </div>
        <div className="mt-2 grid gap-2 text-sm md:grid-cols-3">
          <Info label="Venta" value={claim.sale_id ? `#${claim.sale_id}` : '-'} />
          <Info label="Producto" value={claim.product_name ?? `Item #${claim.sale_item_id}`} />
          <Info label="Cliente" value={claim.customer_name ?? 'Sin cliente'} />
          <Info label="Recibida" value={formatDate(claim.received_at)} />
          <Info label="Política" value={claim.warranty_policy_name ?? '-'} />
          <Info label="Vence" value={formatDate(claim.warranty_expires_at)} />
        </div>
        <p className="mt-3 text-sm text-text-secondary">{claim.issue_description}</p>
        {claim.received_notes && <p className="mt-1 text-sm text-text-muted">{claim.received_notes}</p>}
      </div>

      <div className="space-y-2 rounded border border-border bg-bg/40 p-3">
        <div className="space-y-1">
          <Label>Diagnóstico</Label>
          <Textarea value={diagnosis} onChange={(e) => setDiagnosis(e.target.value)} rows={2} disabled={!canReview || !reviewable} />
        </div>
        <div className="grid grid-cols-2 gap-2">
          {canReview && reviewable && (
            <>
              <Button size="sm" variant="secondary" loading={review.isPending} onClick={() => void reviewClaim('under_review')}>
                <Wrench className="size-4" /> Revisar
              </Button>
              <Button size="sm" variant="secondary" loading={review.isPending} onClick={() => void reviewClaim('approved')}>
                Aprobar
              </Button>
              <Button size="sm" variant="outline" loading={review.isPending} onClick={() => void reviewClaim('rejected')}>
                Rechazar
              </Button>
            </>
          )}
          {canResolve && resolvable && (
            <>
              <Select value={resolutionType} onChange={(e) => setResolutionType(e.target.value)}>
                <option value="rejected">Rechazo</option>
              </Select>
              <Button size="sm" loading={resolve.isPending} onClick={() => void resolveClaim()}>
                Resolver
              </Button>
            </>
          )}
        </div>
        <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Notas de resolución o entrega" />
        {canDeliver && deliverable && (
          <Button size="sm" variant="secondary" className="w-full" loading={deliver.isPending} onClick={() => void deliverClaim()}>
            <Check className="size-4" /> Marcar entregada
          </Button>
        )}
      </div>
    </section>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs uppercase text-text-muted">{label}</div>
      <div className="font-medium">{value}</div>
    </div>
  );
}
