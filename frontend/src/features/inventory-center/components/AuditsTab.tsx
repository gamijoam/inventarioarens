/**
 * AuditsTab: muestra el historial de auditoria (cambios al producto) usando
 * GET /api/inventory-center/products/{id}/audits.
 *
 * Shape real del backend (verificado 2026-07-14):
 * {
 *   "data": {
 *     "filters": { "search": null, "limit": 24, "page": 1, "action": "all" },
 *     "data": [
 *       {
 *         "id": 74,
 *         "action": "updated" | "created" | etc,
 *         "changes": {
 *           "before": { ... } | "",
 *           "after": { ... } | "@{...}",
 *           "source": "sync" | "api"
 *         },
 *         "created_by": null | number,
 *         "created_by_name": null | string,
 *         "created_by_email": null | string,
 *         "created_at": "2026-07-14T..."
 *       }
 *     ],
 *     "pagination": { "page", "limit", "total", "last_page", "from", "to", "has_previous", "has_next" }
 *   }
 * }
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatRelative } from '@/lib/format';
import { useSessionStore } from '@/stores/session';

// Shape real del item de auditoria.
const AuditEntrySchema = z.object({
  id: z.number(),
  action: z.string(),
  changes: z
    .object({
      before: z.union([z.string(), z.record(z.string(), z.unknown())]),
      after: z.union([z.string(), z.record(z.string(), z.unknown())]),
      source: z.string().optional(),
    })
    .passthrough(),
  created_by: z.number().int().nullable().optional(),
  created_by_name: z.string().nullable().optional(),
  created_by_email: z.string().nullable().optional(),
  created_at: z.string(),
});

const AuditResponseSchema = z.object({
  data: z.object({
    filters: z
      .object({
        search: z.string().nullable().optional(),
        limit: z.number().optional(),
        page: z.number().optional(),
        action: z.string().optional(),
      })
      .optional(),
    data: z.array(AuditEntrySchema),
    pagination: z.unknown().optional(),
  }),
});

const AUDIT_LABELS: Record<string, string> = {
  created: 'Creado',
  updated: 'Actualizado',
  price_changed: 'Precio cambiado',
  deactivated: 'Desactivado',
  activated: 'Activado',
  category_synced: 'Categorías sincronizadas',
  tag_synced: 'Tags sincronizados',
  warranty_changed: 'Garantía cambiada',
  stock_adjusted: 'Stock ajustado',
};

export interface AuditsTabProps {
  productId: number;
}

// Parsea el `changes.after` (o `changes.before`) que el backend retorna
// como string tipo "@{ name=iPhone; price=5 }" o como objeto { ... }.
function parseChangesSnapshot(value: unknown): Record<string, unknown> {
  if (!value) return {};
  if (typeof value === 'object' && value !== null) {
    return value as Record<string, unknown>;
  }
  if (typeof value !== 'string') return {};
  const trimmed = value.trim();
  // Formato: @{ key=value; key=value } o {}.
  if (!trimmed.startsWith('@{') && !trimmed.startsWith('{')) return {};
  const inner = trimmed.startsWith('@{') ? trimmed.slice(2, -1) : trimmed.slice(1, -1);
  const out: Record<string, unknown> = {};
  for (const part of inner.split(';')) {
    const eq = part.indexOf('=');
    if (eq < 0) continue;
    const key = part.slice(0, eq).trim();
    const raw = part.slice(eq + 1).trim();
    if (!key) continue;
    // Quitar comillas si las tiene.
    out[key] = raw.replace(/^["']|["']$/g, '');
  }
  return out;
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
    return (
      <EmptyState title="Error al cargar auditoria" description="Reintenta en unos segundos." />
    );
  const entries = data?.data?.data ?? [];
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
          {entries.map((e) => {
            const oldValues = parseChangesSnapshot((e as { changes?: { before?: unknown } }).changes?.before);
            const newValues = parseChangesSnapshot((e as { changes?: { after?: unknown } }).changes?.after);
            const source = (e as { changes?: { source?: string } }).changes?.source;
            return (
              <li key={e.id} className="flex items-start gap-3 p-3">
                <div className="flex-1 space-y-1">
                  <div className="flex items-center gap-2">
                    <Badge variant="default">{AUDIT_LABELS[e.action] ?? e.action}</Badge>
                    <span className="text-xs text-text-muted">{formatRelative(e.created_at)}</span>
                    {source && (
                      <span className="rounded bg-bg px-2 py-0.5 text-xs text-text-muted">
                        {source}
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-text-muted">
                    Por{' '}
                    <strong className="text-text-secondary">
                      {e.created_by_name ?? 'Sistema'}
                    </strong>
                  </p>
                  {Object.keys(newValues).length > 0 && (
                    <DiffPreview oldValues={oldValues} newValues={newValues} />
                  )}
                </div>
              </li>
            );
          })}
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