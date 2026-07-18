import { FileText, RotateCcw } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useSalesReturns } from './api';

function formatDate(value?: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('es-VE');
}

function customerLabel(item: { sale?: { customer?: { name?: string; document_number?: string | null } | null } | null }): string {
  const customer = item.sale?.customer;
  if (!customer?.name) return 'Consumidor Final';
  return customer.document_number ? `${customer.name} - ${customer.document_number}` : customer.name;
}

export function SalesReturnsManager() {
  const canView = useCan(PERMISSIONS.SALES_RETURNS_VIEW);
  const returns = useSalesReturns({ enabled: canView });
  const data = returns.data?.data ?? [];

  if (!canView) {
    return (
      <PermissionDenied
        permission={PERMISSIONS.SALES_RETURNS_VIEW}
        message="No tienes permiso para ver devoluciones de venta."
      />
    );
  }

  if (returns.isLoading && !returns.data) return <Skeleton className="h-64 w-full" />;

  if (returns.isError) {
    return (
      <EmptyState
        title="No se pudieron cargar devoluciones"
        description="Intenta actualizar el listado."
        action={<Button onClick={() => void returns.refetch()}>Reintentar</Button>}
      />
    );
  }

  if (data.length === 0) {
    return (
      <EmptyState
        icon={<RotateCcw className="size-8" />}
        title="Sin devoluciones"
        description="Las devoluciones creadas desde ventas aparecerán aquí para auditoría."
      />
    );
  }

  return (
    <Card>
      <div className="overflow-x-auto">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left text-xs uppercase text-text-muted">
            <tr>
              <th className="px-3 py-2">Devolución</th>
              <th className="px-3 py-2">Venta</th>
              <th className="px-3 py-2">Cliente</th>
              <th className="px-3 py-2">Estado</th>
              <th className="px-3 py-2">Items</th>
              <th className="px-3 py-2">Fecha</th>
              <th className="px-3 py-2">Motivo</th>
            </tr>
          </thead>
          <tbody>
            {data.map((item) => (
              <tr key={item.id} className="border-b border-border last:border-0">
                <td className="px-3 py-2 font-medium">#{item.id}</td>
                <td className="px-3 py-2">#{item.sale_id}</td>
                <td className="px-3 py-2">{customerLabel(item)}</td>
                <td className="px-3 py-2"><Badge variant="info">{item.status}</Badge></td>
                <td className="px-3 py-2">{item.items?.length ?? '-'}</td>
                <td className="px-3 py-2 text-text-muted">{formatDate(item.processed_at ?? item.created_at)}</td>
                <td className="px-3 py-2 text-text-muted">
                  <FileText className="mr-1 inline size-3.5" />
                  {item.reason ?? '-'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}
