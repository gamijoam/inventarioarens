/**
 * AuditsTab: muestra el historial de auditoria (cambios al producto) usando
 * GET /api/inventory-center/products/{id}/audits.
 *
 * Muestra: fecha, usuario, accion (created/updated/price_changed/etc),
 * y el diff antes/despues (si esta disponible).
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatRelative } from '@/lib/format';
import { useSessionStore } from '@/stores/session';

const AuditEntrySchema = z.object({
  id: z.number(),
  action: z.string(),
  user_name: z.string().nullable().optional(),
  created_at: z.string(),
  old_values: z.record(z.string(), z.unknown()).nullable().optional(),
  new_values: z.record(z.string(), z.unknown()).nullable().optional(),
  notes: z.string().nullable().optional(),
});

const AuditResponseSchema = z.object({
  data: z.array(AuditEntrySchema),
});

const AUDIT_LABELS: Record<string, string> = {
  created: 'Creado',
  updated: 'Actualizado',
  price_changed: 'Precio cambiado',
  deactivated: 'Desactivado',
  activated: 'Activado',
  category_synced: 'Categorias sincronizadas',
  tag_synced: 'Tags sincronizados',
  warranty_changed: 'Garantia cambiada',
  stock_adjusted: 'Stock ajustado',
};

export interface AuditsTabProps {
  productId: number;
}

export function AuditsTab({ productId }: AuditsTabProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['audits', productId],
    queryFn: async () => {
      const { tenant } = useSessionStore.getState();
      // Plan C: la cookie httpOnly se envia con credentials: 'include'.
      const res = await fetch(`/api/inventory-center/products/${productId}/audits`, {
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...(tenant?.slug ? { 'X-Tenant': tenant.slug } : {}),
        },
      });
      if (!res.ok) throw new Error('Error al cargar auditoria');
      const json: unknown = await res.json();
      return AuditResponseSchema.parse(json);
    },
    enabled: productId > 0,
  });

  if (isLoading) return <Spinner label="Cargando auditoria..." />;
  if (isError)
    return <EmptyState title="Error al cargar auditoria" description="Reintenta en unos segundos." />;
  const entries = data?.data ?? [];
  if (entries.length === 0) {
    return (
      <EmptyState
        title="Sin cambios registrados"
        description="Aun no hay eventos de auditoria para este producto."
      />
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Auditoria</CardTitle>
        <CardDescription>Historial de cambios ({entries.length}).</CardDescription>
      </CardHeader>
      <CardContent className="space-y-2 p-0">
        <ul className="divide-y divide-border">
          {entries.map((e) => (
            <li key={e.id} className="flex items-start gap-3 p-3">
              <div className="flex-1 space-y-1">
                <div className="flex items-center gap-2">
                  <Badge variant="default">{AUDIT_LABELS[e.action] ?? e.action}</Badge>
                  <span className="text-xs text-text-muted">{formatRelative(e.created_at)}</span>
                </div>
                <p className="text-xs text-text-muted">
                  Por <strong className="text-text-secondary">{e.user_name ?? 'Sistema'}</strong>
                  {e.notes ? ` — ${e.notes}` : ''}
                </p>
                {e.old_values && e.new_values && (
                  <DiffPreview oldValues={e.old_values} newValues={e.new_values} />
                )}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function formatValue(v: unknown): string {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'string') return v;
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  return JSON.stringify(v);
}

function DiffPreview({
  oldValues,
  newValues,
}: {
  oldValues: Record<string, unknown>;
  newValues: Record<string, unknown>;
}) {
  const changedKeys = Object.keys(newValues).filter(
    (k) => JSON.stringify(oldValues[k]) !== JSON.stringify(newValues[k]),
  );
  if (changedKeys.length === 0) return null;
  return (
    <ul className="mt-1 space-y-0.5 rounded bg-bg p-2 text-xs">
      {changedKeys.slice(0, 5).map((k) => (
        <li key={k} className="flex gap-2">
          <code className="text-text-muted">{k}:</code>
          <span className="line-through text-danger/70">{formatValue(oldValues[k])}</span>
          <span className="text-success">→ {formatValue(newValues[k])}</span>
        </li>
      ))}
      {changedKeys.length > 5 && (
        <li className="text-text-muted">... y {changedKeys.length - 5} mas</li>
      )}
    </ul>
  );
}