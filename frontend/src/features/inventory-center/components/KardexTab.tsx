/**
 * KardexTab: muestra el kardex (historial cronologico de movimientos de stock)
 * de un producto. Usa GET /api/kardex/products/{id}.
 *
 * Shape real del backend (verificado 2026-07-14):
 * {
 *   "data": {
 *     "product_id": 5,
 *     "product_name": "...",
 *     "warehouse_id": null,
 *     "opening_balance": 0,
 *     "closing_balance": -38,
 *     "movements": [
 *       {
 *         "id": 3,
 *         "date": "2026-07-14T...",
 *         "warehouse_id": 1,
 *         "warehouse_name": "Almacen Principal",
 *         "product_id": 5,
 *         "product_name": "...",
 *         "type": "purchase" | "sale" | "entry" | "exit" | "adjustment" | etc,
 *         "quantity_in": 2,    // number entradas
 *         "quantity_out": 0,   // number salidas
 *         "running_balance": 2,// saldo hasta este movimiento
 *         "unit_cost": 5,      // number o string
 *         "reason": "...",
 *         "reference_type": "sync_snapshot",
 *         "reference_id": 75
 *       },
 *       ...
 *     ]
 *   }
 * }
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ExternalLink } from 'lucide-react';
import { z } from 'zod';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatRelative } from '@/lib/format';
import { formatCost } from '@/lib/money';
import { useSessionStore } from '@/stores/session';

/**
 * Mapea tipos de StockMovement a labels legibles para el kardex.
 * Si el type no esta en el mapa, devuelve el type crudo (con guiones a espacios).
 */
const MOVEMENT_TYPE_LABELS: Record<string, string> = {
  purchase: 'Compra',
  purchase_return: 'Devolucion de compra',
  sale: 'Venta',
  sale_return: 'Devolucion de venta',
  adjustment_in: 'Ajuste +',
  adjustment_out: 'Ajuste -',
  transfer_in: 'Traslado +',
  transfer_out: 'Traslado -',
  transfer_request_in: 'Transferencia inter-empresa +',
  transfer_request_out: 'Transferencia inter-empresa -',
  return_in: 'Devolucion +',
  return_out: 'Devolucion -',
  damaged: 'Danado',
  reserved: 'Reservado',
  released: 'Liberado',
};

function movementTypeLabel(type: string): string {
  return MOVEMENT_TYPE_LABELS[type] ?? type.replace(/_/g, ' ');
}

/**
 * Tipos que representan ENTRADAS (verde en el badge).
 */
const IN_TYPES = new Set([
  'purchase', 'sale_return', 'adjustment_in',
  'transfer_in', 'transfer_request_in', 'return_in',
  'released',
]);

/**
 * Mapea reference_type del backend a una ruta del frontend cuando es
 * clickeable. Si retorna null, se muestra como texto plano.
 */
function referenceLink(
  refType: string | null | undefined,
  refId: number | string | null | undefined,
): { label: string; to: string } | null {
  if (!refType || refId == null) return null;
  if (refType === 'InventoryTransferRequest') {
    return {
      label: 'Solicitud inter-empresa',
      to: `/inventory-transfer-requests/${refId}`,
    };
  }
  if (refType === 'InventoryTransfer') {
    return {
      label: 'Traslado',
      to: `/transfers/${refId}`,
    };
  }
  if (refType === 'PurchaseOrder') {
    return { label: 'Orden de compra', to: `/purchases/${refId}` };
  }
  if (refType === 'Sale' || refType === 'PosOrder') {
    return { label: 'Venta', to: `/sales/${refId}` };
  }
  return null;
}

// Shape real del kardex: data es un objeto con metadata + movements[].
const KardexMovementSchema = z.object({
  id: z.number(),
  date: z.string(),
  warehouse_id: z.number().int().nullable().optional(),
  warehouse_name: z.string().nullable().optional(),
  product_id: z.number().int(),
  product_name: z.string().optional(),
  type: z.string(),
  quantity_in: z.union([z.number(), z.string()]).optional(),
  quantity_out: z.union([z.number(), z.string()]).optional(),
  running_balance: z.union([z.number(), z.string()]).optional(),
  unit_cost: z.union([z.number(), z.string()]).nullable().optional(),
  reason: z.string().nullable().optional(),
  reference_type: z.string().nullable().optional(),
  reference_id: z.union([z.number(), z.string()]).nullable().optional(),
});

const KardexResponseSchema = z.object({
  data: z.object({
    product_id: z.number().int(),
    product_name: z.string().optional(),
    warehouse_id: z.number().int().nullable().optional(),
    opening_balance: z.union([z.number(), z.string()]).optional(),
    closing_balance: z.union([z.number(), z.string()]).optional(),
    movements: z.array(KardexMovementSchema),
  }),
});

export interface KardexTabProps {
  productId: number;
  dateFrom?: string;
  dateTo?: string;
}

function toNum(v: unknown): number {
  if (typeof v === 'number') return v;
  if (typeof v === 'string') return parseFloat(v);
  return 0;
}

export function KardexTab({ productId, dateFrom, dateTo }: KardexTabProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['kardex', productId, { dateFrom, dateTo }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (dateFrom) params.set('date_from', dateFrom);
      if (dateTo) params.set('date_to', dateTo);
      const query = params.toString();
      // Plan C: la cookie httpOnly se envia con credentials: 'include'.
      const { tenant } = useSessionStore.getState();
      const res = await fetch(`/api/kardex/products/${productId}${query ? `?${query}` : ''}`, {
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
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

  const dataObj = data?.data;
  const entries = dataObj?.movements ?? [];
  const opening = toNum(dataObj?.opening_balance);
  const closing = toNum(dataObj?.closing_balance);

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
        <CardDescription>
          Historial cronologico de entradas y salidas ({entries.length}). Saldo: {opening} → {closing}.
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Fecha</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Almacen</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Entrada
              </th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Salida
              </th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Saldo
              </th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Costo unit.
              </th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                Ref.
              </th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                Motivo
              </th>
            </tr>
          </thead>
          <tbody>
            {entries.map((e) => {
              const inQty = toNum(e.quantity_in);
              const outQty = toNum(e.quantity_out);
              const balance = toNum(e.running_balance);
              return (
                <tr key={e.id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 text-text-muted">{formatRelative(e.date)}</td>
                  <td className="px-3 py-2">
                    <Badge
                      variant={IN_TYPES.has(e.type) ? 'success' : 'warning'}
                      data-testid={`kardex-type-${e.id}`}
                    >
                      {movementTypeLabel(e.type)}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-text-muted">{e.warehouse_name ?? '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums text-success">
                    {inQty > 0 ? `+${inQty}` : '—'}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums text-warning">
                    {outQty > 0 ? `-${outQty}` : '—'}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums font-medium">{balance}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {e.unit_cost == null ? '—' : formatCost(e.unit_cost)}
                  </td>
                  <td className="px-3 py-2 text-xs text-text-muted">
                    {(() => {
                      const link = referenceLink(e.reference_type, e.reference_id);
                      if (link) {
                        return (
                          <Link
                            to={link.to}
                            className="inline-flex items-center gap-1 text-primary hover:underline"
                            data-testid={`kardex-ref-${e.id}`}
                          >
                            {link.label} #{e.reference_id}
                            <ExternalLink className="size-3" />
                          </Link>
                        );
                      }
                      return (
                        <span>
                          {e.reference_type ?? '—'}
                          {e.reference_id != null ? ` #${e.reference_id}` : ''}
                        </span>
                      );
                    })()}
                  </td>
                  <td className="px-3 py-2 text-xs text-text-muted max-w-xs">
                    {e.reason ? (
                      <span title={e.reason} className="line-clamp-2">{e.reason}</span>
                    ) : (
                      '—'
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}