/**
 * Pagina /transfers/$transferId: detalle de un traslado.
 * FASE T4 entrega: tabs (General, Items, Checklist, Guia) + acciones
 * contextuales (Recibir, Cancelar, Asignar Driver, Ver PDF).
 */
import { useState } from 'react';
import { Link, createFileRoute, useNavigate } from '@tanstack/react-router';
import { ArrowLeft, Package, Truck, XCircle } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/Tabs';
import { useTransfer } from '@/features/transfers/api';
import {
  TRANSFER_STATUS_LABELS,
  type Transfer,
  type TransferStatus,
} from '@/features/transfers/schemas';
import { TransferSummary } from '@/features/transfers/components/TransferSummary';
import { TransferReceiveDialog } from '@/features/transfers/components/TransferReceiveDialog';
import { TransferAssignDriverDialog } from '@/features/transfers/components/TransferAssignDriverDialog';
import { TransferChecklistTab } from '@/features/transfers/components/TransferChecklistTab';
import { TransferGuidePanel } from '@/features/transfers/components/TransferGuidePanel';

export const Route = createFileRoute('/_authed/transfers/$transferId')({
  component: TransferDetailPage,
});

function statusVariant(status: TransferStatus): 'info' | 'warning' | 'success' | 'default' {
  switch (status) {
    case 'requested':
      return 'default';
    case 'prepared':
      return 'info';
    case 'prepared_with_differences':
      return 'warning';
    case 'dispatched':
      return 'info';
    case 'completed':
      return 'success';
    case 'completed_with_differences':
      return 'warning';
    case 'cancelled':
      return 'default';
  }
}

function TransferDetailPage() {
  const { transferId } = Route.useParams();
  const id = parseInt(transferId, 10);
  const navigate = useNavigate();
  const { data: transfer, isLoading, isError } = useTransfer(id);
  const [activeTab, setActiveTab] = useState('general');
  const [receiveOpen, setReceiveOpen] = useState(false);
  const [driverOpen, setDriverOpen] = useState(false);

  if (isLoading) {
    return (
      <PageLayout title="Cargando traslado...">
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-64 w-full" />
      </PageLayout>
    );
  }

  if (isError || !transfer) {
    return (
      <PageLayout title="Traslado no encontrado">
        <EmptyState
          title="No se encontro el traslado"
          description="El traslado puede haber sido eliminado o no tienes permiso para verlo."
          action={
            <Button variant="outline" onClick={() => navigate({ to: '/transfers' })}>
              <ArrowLeft className="size-4" /> Volver al listado
            </Button>
          }
        />
      </PageLayout>
    );
  }

  const canReceive = transfer.status === 'requested' || transfer.status === 'prepared' || transfer.status === 'prepared_with_differences';
  const canCancel = transfer.status === 'requested' || transfer.status === 'prepared' || transfer.status === 'prepared_with_differences';
  const canAssignDriver = !transfer.driver && (transfer.status === 'prepared' || transfer.status === 'dispatched');
  const isLogistic = transfer.validation_mode === 'logistics';
  const canShowChecklist = isLogistic;

  return (
    <PageLayout
      title={`Traslado ${transfer.document_number ?? '#' + transfer.id}`}
      description={`${(transfer.from_warehouse as { name?: string } | null | undefined)?.name ?? ''} → ${(transfer.to_warehouse as { name?: string } | null | undefined)?.name ?? ''}`}
      breadcrumb={
        <Link to="/transfers" className="inline-flex items-center gap-1 text-xs text-text-muted hover:text-primary">
          <ArrowLeft className="size-3" /> Traslados
        </Link>
      }
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={statusVariant(transfer.status)}>{TRANSFER_STATUS_LABELS[transfer.status]}</Badge>
          {canReceive && (
            <Button size="sm" leftIcon={<Package className="size-4" />} onClick={() => setReceiveOpen(true)}>
              Recibir
            </Button>
          )}
          {canAssignDriver && (
            <Button size="sm" variant="outline" leftIcon={<Truck className="size-4" />} onClick={() => setDriverOpen(true)}>
              Asignar transportista
            </Button>
          )}
          {canCancel && (
            <Button size="sm" variant="ghost" leftIcon={<XCircle className="size-4 text-danger" />} onClick={() => setReceiveOpen(false)}>
              Cancelar
            </Button>
          )}
        </div>
      }
    >
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="items">Items</TabsTrigger>
          {canShowChecklist && <TabsTrigger value="checklist">Checklist</TabsTrigger>}
          <TabsTrigger value="guide">Guia</TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="space-y-4">
          <TransferSummary transfer={transfer} showItems />
        </TabsContent>

        <TabsContent value="items" className="space-y-4">
          <ItemsTab transfer={transfer} />
        </TabsContent>

        {canShowChecklist && (
          <TabsContent value="checklist" className="space-y-4">
            <TransferChecklistTab transferId={transfer.id} stage="preparation" />
            <TransferChecklistTab transferId={transfer.id} stage="reception" />
          </TabsContent>
        )}

        <TabsContent value="guide" className="space-y-4">
          <TransferGuidePanel transfer={transfer} />
        </TabsContent>
      </Tabs>

      {receiveOpen && (
        <TransferReceiveDialog
          transferId={transfer.id}
          open={receiveOpen}
          onOpenChange={setReceiveOpen}
          onReceived={() => navigate({ to: '/transfers' })}
        />
      )}

      {driverOpen && (
        <TransferAssignDriverDialog
          transferId={transfer.id}
          open={driverOpen}
          onOpenChange={setDriverOpen}
        />
      )}
    </PageLayout>
  );
}

function ItemsTab({ transfer }: { transfer: Transfer }) {
  const items = transfer.items ?? [];
  if (items.length === 0) {
    return <EmptyState title="Sin items" description="Este traslado no tiene items asociados." />;
  }
  return (
    <Card>
      <CardHeader>
        <CardTitle>Items ({items.length})</CardTitle>
        <CardDescription>Productos incluidos en este traslado.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Producto</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">SKU</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Almacen</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Pedido</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Preparado</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Recibido</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Diferencia</th>
            </tr>
          </thead>
          <tbody>
            {items.map((it) => {
              const product = it.product as { name?: string; sku?: string } | null | undefined;
              const warehouse = it.warehouse as { code?: string; name?: string } | null | undefined;
              return (
                <tr key={it.id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 font-medium">{product?.name ?? `Producto #${it.product_id}`}</td>
                  <td className="px-3 py-2 text-text-muted">
                    {product?.sku ? <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{product.sku}</code> : '-'}
                  </td>
                  <td className="px-3 py-2 text-text-muted">{warehouse?.code ?? `Almacen #${it.warehouse_id}`}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{Number(it.quantity ?? 0).toFixed(2)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{Number(it.prepared_quantity ?? 0).toFixed(2)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{Number(it.received_quantity ?? 0).toFixed(2)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{Number(it.difference_quantity ?? 0).toFixed(2)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}
