/**
 * KardexTab: muestra el kardex (historial cronologico de movimientos de stock)
 * de un producto. Usa GET /api/kardex/products/{id}.
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatRelative } from '@/lib/format';
import { formatCost } from '@/lib/money';
import { useSessionStore } from '@/stores/session';

const KardexEntrySchema = z.object({
  id: z.number(),
  date: z.string(),
  type: z.string(),
  warehouse_name: z.string().nullable().optional(),
  warehouse_code: z.string().nullable().optional(),
  quantity: z.union([z.string(), z.number()]),
  unit_cost: z.string().nullable().optional(),
  balance_after: z.union([z.string(), z.number()]).nullable().optional(),
  reference: z.string().nullable().optional(),
  user_name: z.string().nullable().optional(),
});

const KardexResponseSchema = z.object({
  data: z.array(KardexEntrySchema),
});

export interface KardexTabProps {
  productId: number;
  dateFrom?: string;
  dateTo?: string;
}

export function KardexTab({ productId, dateFrom, dateTo }: KardexTabProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['kardex', productId, { dateFrom, dateTo }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (dateFrom) params.set('date_from', dateFrom);
      if (dateTo) params.set('date_to', dateTo);
      const query = params.toString();
      // Peticion directa al backend con el token del session store.
      const { token, tenant } = useSessionStore.getState();
      const res = await fetch(`/api/kardex/products/${productId}${query ? `?${query}` : ''}`, {
        headers: {
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(tenant?.slug ? { 'X-Tenant': tenant.slug } : {}),
        },
      });
      if (!res.ok) throw new Error('Error al cargar kardex');
      const json: unknown = await res.json();
      return KardexResponseSchema.parse(json);
    },
    enabled: productId > 0,
  });

  if (isLoading) return <Spinner label="Cargando kardex..." />;
  if (isError)
    return <EmptyState title="Error al cargar kardex" description="Reintenta en unos segundos." />;
  const entries = data?.data ?? [];
  if (entries.length === 0) {
    return (
      <EmptyState
        title="Sin movimientos en el kardex"
        description="Aun no hay entradas para este producto en el rango seleccionado."
      />
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Kardex</CardTitle>
        <CardDescription>Historial cronologico de entradas y salidas ({entries.length}).</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Fecha</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Almacen</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Cantidad</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Costo unit.</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Saldo</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Referencia</th>
            </tr>
          </thead>
          <tbody>
            {entries.map((e) => (
              <tr key={e.id} className="border-b border-border last:border-b-0">
                <td className="px-3 py-2 text-text-muted">{formatRelative(e.date)}</td>
                <td className="px-3 py-2">
                  <Badge
                    variant={
                      e.type.startsWith('in')
                        ? 'success'
                        : e.type.startsWith('out')
                          ? 'warning'
                          : 'default'
                    }
                  >
                    {e.type}
                  </Badge>
                </td>
                <td className="px-3 py-2 text-text-muted">
                  {e.warehouse_name ?? '—'}
                  {e.warehouse_code && (
                    <span className="ml-1 text-xs text-text-muted">({e.warehouse_code})</span>
                  )}
                </td>
                <td className="px-3 py-2 text-right tabular-nums">{e.quantity}</td>
                <td className="px-3 py-2 text-right tabular-nums">
                  {e.unit_cost == null ? '—' : formatCost(e.unit_cost)}
                </td>
                <td className="px-3 py-2 text-right tabular-nums">
                  {e.balance_after ?? '—'}
                </td>
                <td className="px-3 py-2 text-xs text-text-muted">{e.reference ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}