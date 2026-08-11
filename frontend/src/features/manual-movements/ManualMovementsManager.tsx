import { useState } from 'react';
import { Check, Eye, Plus, X } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useCan } from '@/permissions/useCan';
import { PERMISSIONS } from '@/permissions/constants';
import { useWarehouses } from '@/features/inventory-center/api';
import { useApproveManualMovement, useManualMovements } from './api';
import { CreateManualMovementDialog, RejectManualMovementDialog } from './ManualMovementDialogs';
import {
  MANUAL_MOVEMENT_STATUSES,
  MANUAL_MOVEMENT_STATUS_LABELS,
  MANUAL_MOVEMENT_TYPES,
  MANUAL_MOVEMENT_TYPE_LABELS,
  type ManualMovement,
  type ManualMovementFilters,
} from './schemas';

export function ManualMovementsManager() {
  const [filters, setFilters] = useState<ManualMovementFilters>({
    status: 'all',
    type: 'all',
    page: 1,
  });
  const { data, isLoading, isError } = useManualMovements(filters);
  const { data: warehouses = [] } = useWarehouses();
  const approve = useApproveManualMovement();
  const [createOpen, setCreateOpen] = useState(false);
  const [selected, setSelected] = useState<ManualMovement | null>(null);
  const [rejecting, setRejecting] = useState<ManualMovement | null>(null);
  const canCreate = useCan(PERMISSIONS.INVENTORY_MANUAL_MOVEMENTS_CREATE);
  const canApprove = useCan(PERMISSIONS.INVENTORY_MANUAL_MOVEMENTS_APPROVE);
  const canReject = useCan(PERMISSIONS.INVENTORY_MANUAL_MOVEMENTS_CANCEL);

  const update = (patch: Partial<ManualMovementFilters>) =>
    setFilters((current) => ({ ...current, ...patch, page: 1 }));

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        {canCreate && (
          <Button onClick={() => setCreateOpen(true)} leftIcon={<Plus />}>
            Nuevo movimiento
          </Button>
        )}
      </div>
      <Card>
        <CardContent className="grid gap-3 pt-4 md:grid-cols-5">
          <Select
            value={filters.status}
            onChange={(e) => update({ status: e.target.value as ManualMovementFilters['status'] })}
            aria-label="Estado"
          >
            <option value="all">Todos los estados</option>
            {MANUAL_MOVEMENT_STATUSES.map((status) => (
              <option key={status} value={status}>
                {MANUAL_MOVEMENT_STATUS_LABELS[status]}
              </option>
            ))}
          </Select>
          <Select
            value={filters.type}
            onChange={(e) => update({ type: e.target.value as ManualMovementFilters['type'] })}
            aria-label="Tipo"
          >
            <option value="all">Todos los tipos</option>
            {MANUAL_MOVEMENT_TYPES.map((type) => (
              <option key={type} value={type}>
                {MANUAL_MOVEMENT_TYPE_LABELS[type]}
              </option>
            ))}
          </Select>
          <Select
            value={filters.warehouse_id ?? 'all'}
            onChange={(e) =>
              update({ warehouse_id: e.target.value === 'all' ? 'all' : Number(e.target.value) })
            }
            aria-label="Almacén"
          >
            <option value="all">Todos los almacenes</option>
            {warehouses.map((warehouse) => (
              <option key={warehouse.id} value={warehouse.id}>
                {warehouse.name}
              </option>
            ))}
          </Select>
          <Input
            type="date"
            value={filters.from ?? ''}
            onChange={(e) => update({ from: e.target.value })}
            aria-label="Desde"
          />
          <Input
            type="date"
            value={filters.to ?? ''}
            onChange={(e) => update({ to: e.target.value })}
            aria-label="Hasta"
          />
        </CardContent>
      </Card>

      <Card>
        <CardContent className="overflow-x-auto pt-4">
          {isLoading ? (
            <p className="text-text-muted text-sm">Cargando movimientos…</p>
          ) : isError ? (
            <p className="text-danger text-sm">No se pudieron cargar los movimientos.</p>
          ) : !data?.data.length ? (
            <p className="text-text-muted py-8 text-center text-sm">
              No hay movimientos con estos filtros.
            </p>
          ) : (
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-border text-text-muted border-b">
                  <th className="p-3">Producto</th>
                  <th className="p-3">Tipo</th>
                  <th className="p-3">Cantidad</th>
                  <th className="p-3">Almacén</th>
                  <th className="p-3">Usuario</th>
                  <th className="p-3">Estado</th>
                  <th className="p-3">Fecha</th>
                  <th className="p-3 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((movement) => (
                  <tr key={movement.id} className="border-border border-b last:border-0">
                    <td className="p-3 font-medium">{movement.product.name}</td>
                    <td className="p-3">{MANUAL_MOVEMENT_TYPE_LABELS[movement.type]}</td>
                    <td className="p-3">{movement.quantity}</td>
                    <td className="p-3">{movement.warehouse.name}</td>
                    <td className="p-3">{movement.creator.name}</td>
                    <td className="p-3">
                      <Badge
                        variant={
                          movement.status === 'approved'
                            ? 'success'
                            : movement.status === 'rejected'
                              ? 'danger'
                              : 'warning'
                        }
                      >
                        {MANUAL_MOVEMENT_STATUS_LABELS[movement.status]}
                      </Badge>
                    </td>
                    <td className="p-3">{new Date(movement.created_at).toLocaleDateString()}</td>
                    <td className="p-3">
                      <div className="flex justify-end gap-2">
                        <Button
                          size="sm"
                          variant="ghost"
                          leftIcon={<Eye />}
                          onClick={() => setSelected(movement)}
                        >
                          Ver
                        </Button>
                        {movement.status === 'pending' && canApprove && (
                          <Button
                            size="sm"
                            variant="outline"
                            leftIcon={<Check />}
                            loading={approve.isPending}
                            onClick={() =>
                              approve.mutate(
                                { id: movement.id },
                                {
                                  onSuccess: () => toast.success('Movimiento aprobado'),
                                  onError: (error) =>
                                    toast.error(
                                      error instanceof Error
                                        ? error.message
                                        : 'No se pudo aprobar el movimiento.',
                                    ),
                                },
                              )
                            }
                          >
                            Aprobar
                          </Button>
                        )}
                        {movement.status === 'pending' && canReject && (
                          <Button
                            size="sm"
                            variant="danger"
                            leftIcon={<X />}
                            onClick={() => setRejecting(movement)}
                          >
                            Rechazar
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardContent>
      </Card>
      <CreateManualMovementDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        warehouses={warehouses}
        onCreated={setSelected}
      />
      <RejectManualMovementDialog
        movement={rejecting}
        onOpenChange={(open) => {
          if (!open) setRejecting(null);
        }}
      />
      <MovementDetailDialog
        movement={selected}
        onOpenChange={(open) => {
          if (!open) setSelected(null);
        }}
      />
    </div>
  );
}

function MovementDetailDialog({
  movement,
  onOpenChange,
}: {
  movement: ManualMovement | null;
  onOpenChange: (open: boolean) => void;
}) {
  if (!movement) return null;
  const audit =
    movement.status === 'approved'
      ? `Aprobado por ${movement.approver?.name ?? '—'}${movement.approved_at ? ` · ${new Date(movement.approved_at).toLocaleString()}` : ''}`
      : movement.status === 'rejected'
        ? `Rechazado por ${movement.rejector?.name ?? '—'}${movement.rejected_at ? ` · ${new Date(movement.rejected_at).toLocaleString()}` : ''}`
        : 'Pendiente de revisión';
  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Movimiento #{movement.id}</DialogTitle>
          <DialogDescription>{audit}</DialogDescription>
        </DialogHeader>
        <dl className="grid gap-4 text-sm sm:grid-cols-2">
          <Detail label="Producto" value={movement.product.name ?? '—'} />
          <Detail
            label="Variante / Color"
            value={
              movement.product_variant
                ? (movement.product_variant.color ?? movement.product_variant.sku_variant ?? '—')
                : '—'
            }
          />
          <Detail label="Almacén" value={movement.warehouse.name ?? '—'} />
          <Detail label="Tipo" value={MANUAL_MOVEMENT_TYPE_LABELS[movement.type]} />
          <Detail label="Cantidad" value={String(movement.quantity)} />
          <Detail label="Creado por" value={movement.creator.name ?? '—'} />
          <Detail label="Fecha" value={new Date(movement.created_at).toLocaleString()} />
          <div className="sm:col-span-2">
            <Detail label="Motivo" value={movement.reason} />
          </div>
          {movement.notes && (
            <div className="sm:col-span-2">
              <Detail label="Notas" value={movement.notes} />
            </div>
          )}
          {movement.rejection_reason && (
            <div className="sm:col-span-2">
              <Detail label="Motivo del rechazo" value={movement.rejection_reason} />
            </div>
          )}
          {movement.stock_movement_id && (
            <Detail label="Movimiento de stock" value={`#${movement.stock_movement_id}`} />
          )}
        </dl>
      </DialogContent>
    </Dialog>
  );
}
function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-text-muted">{label}</dt>
      <dd className="mt-1 font-medium">{value}</dd>
    </div>
  );
}
