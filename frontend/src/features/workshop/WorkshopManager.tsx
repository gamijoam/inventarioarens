/**
 * WorkshopManager — Bandeja y gestion de ordenes de servicio del Taller.
 * Permite crear/recepcionar, diagnosticar, asignar tecnico, agregar piezas
 * del inventario, completar (descuenta stock) y cancelar.
 */
import { useState } from 'react';
import { ChevronDown, ChevronRight, Plus, Search, Wrench } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PermissionDenied } from '@/components/permissions/PermissionDenied';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import { useUsers } from '@/features/users/api';
import { useProducts } from '@/features/inventory-center/api';
import { useWarehouses } from '@/features/inventory-center/api';

import {
  SERVICE_ORDER_RESOLUTIONS,
  SERVICE_ORDER_STATUSES,
  SERVICE_ORDER_TYPES,
  useAddServiceOrderPart,
  useAssignTechnician,
  useCancelServiceOrder,
  useCompleteServiceOrder,
  useCreateServiceOrder,
  useDiagnoseServiceOrder,
  useRemoveServiceOrderPart,
  useServiceOrder,
  useServiceOrders,
  type ServiceOrder,
} from './api';

const STATUS_LABELS: Record<string, string> = {
  received: 'Recibido',
  diagnosed: 'Diagnosticado',
  in_progress: 'En reparación',
  ready: 'Listo',
  delivered: 'Entregado',
  closed: 'Cerrado',
  cancelled: 'Cancelado',
};

const TYPE_LABELS: Record<string, string> = {
  repair: 'Reparación',
  warranty: 'Garantía',
};

const RESOLUTION_LABELS: Record<string, string> = {
  workshop: 'Taller',
  exchange: 'Cambio',
  return_supplier: 'Devolver a proveedor',
};

function statusVariant(status: string): 'success' | 'warning' | 'info' | 'default' | 'danger' {
  if (status === 'delivered' || status === 'closed') return 'success';
  if (status === 'cancelled') return 'danger';
  if (status === 'in_progress') return 'warning';
  if (status === 'diagnosed' || status === 'ready') return 'info';
  return 'default';
}

function money(value: number): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value || 0));
}

export function WorkshopManager() {
  const canView = useCan(PERMISSIONS.SERVICE_ORDERS_VIEW);
  const canCreate = useCan(PERMISSIONS.SERVICE_ORDERS_CREATE);
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [search, setSearch] = useState('');
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  const { data: orders = [], isLoading } = useServiceOrders({
    status: status || undefined,
    type: type || undefined,
    search: search || undefined,
    limit: 100,
  });

  if (!canView) return <PermissionDenied />;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-2">
        <div className="relative min-w-[260px] flex-1">
          <Search className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por número, cliente o equipo..."
            className="pl-9"
          />
        </div>
        <div className="w-40">
          <Select value={status} onChange={(e) => setStatus(e.target.value)} data-testid="ws-status-filter">
            <option value="">Estado: todos</option>
            {SERVICE_ORDER_STATUSES.map((s) => (
              <option key={s} value={s}>
                {STATUS_LABELS[s]}
              </option>
            ))}
          </Select>
        </div>
        <div className="w-40">
          <Select value={type} onChange={(e) => setType(e.target.value)} data-testid="ws-type-filter">
            <option value="">Tipo: todos</option>
            {SERVICE_ORDER_TYPES.map((t) => (
              <option key={t} value={t}>
                {TYPE_LABELS[t]}
              </option>
            ))}
          </Select>
        </div>
        {canCreate && (
          <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
            Nueva orden
          </Button>
        )}
      </div>

      {isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : orders.length === 0 ? (
        <EmptyState
          title="Sin órdenes de taller"
          description="Crea una orden para recepcionar equipos en garantía o reparación."
          icon={<Wrench className="size-8" />}
        />
      ) : (
        <div className="border-border bg-surface overflow-hidden rounded-lg border">
          <table className="table-dense w-full">
            <thead className="border-border bg-bg/60 border-b text-left">
              <tr>
                <th className="w-8 px-2 py-2" />
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">Orden</th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">Tipo</th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">Cliente / Equipo</th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">Técnico</th>
                <th className="text-text-secondary px-3 py-2 font-semibold tracking-wide uppercase">Estado</th>
                <th className="text-text-secondary px-3 py-2 text-right font-semibold tracking-wide uppercase">Total (USD)</th>
              </tr>
            </thead>
            <tbody>
              {orders.map((order) => {
                const isExpanded = expandedId === order.id;
                return (
                  <Row
                    key={order.id}
                    order={order}
                    isExpanded={isExpanded}
                    onToggle={() => setExpandedId(isExpanded ? null : order.id)}
                  />
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {creating && (
        <CreateServiceOrderDialog
          onClose={() => setCreating(false)}
          onCreated={() => {
            setCreating(false);
            toast.success('Orden de servicio creada.');
          }}
        />
      )}
    </div>
  );
}

function Row({ order, isExpanded, onToggle }: { order: ServiceOrder; isExpanded: boolean; onToggle: () => void }) {
  return (
    <>
      <tr
        className="border-border hover:bg-bg/50 cursor-pointer border-b"
        onClick={onToggle}
        data-testid={`workshop-row-${order.id}`}
      >
        <td className="text-text-muted px-2 py-2">
          {isExpanded ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
        </td>
        <td className="px-3 py-2 font-medium">
          <code className="bg-bg rounded px-1.5 py-0.5 text-xs">{order.order_number}</code>
        </td>
        <td className="px-3 py-2">
          <Badge variant={order.type === 'warranty' ? 'info' : 'default'}>
            {TYPE_LABELS[order.type]}
          </Badge>
          {order.resolution && (
            <span className="text-text-muted ml-1 text-[10px]">{RESOLUTION_LABELS[order.resolution]}</span>
          )}
        </td>
        <td className="px-3 py-2">
          <p className="text-sm font-medium">{order.customer_name ?? '—'}</p>
          {order.device_description && <p className="text-text-muted text-xs">{order.device_description}</p>}
        </td>
        <td className="text-text-muted px-3 py-2">{order.technician?.name ?? '—'}</td>
        <td className="px-3 py-2">
          <Badge variant={statusVariant(order.status)}>{STATUS_LABELS[order.status]}</Badge>
        </td>
        <td className="px-3 py-2 text-right tabular-nums">{money(order.total_base_amount)}</td>
      </tr>
      {isExpanded && (
        <tr className="border-border bg-bg/20 border-b">
          <td colSpan={7} className="px-4 py-4">
            <OrderDetail orderId={order.id} order={order} />
          </td>
        </tr>
      )}
    </>
  );
}

function OrderDetail({ orderId, order }: { orderId: number; order: ServiceOrder }) {
  const { data: detail, isLoading } = useServiceOrder(orderId);
  const active = detail ?? order;

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]" onClick={(e) => e.stopPropagation()}>
      <div className="space-y-3">
        <InfoCard order={active} />
        <PartsCard order={active} />
      </div>
      <div className="space-y-3">
        <DiagnoseCard order={active} />
        <TechnicianCard order={active} />
        <ActionsCard order={active} busy={isLoading} />
      </div>
    </div>
  );
}

function InfoCard({ order }: { order: ServiceOrder }) {
  return (
    <div className="border-border bg-surface rounded-lg border p-4">
      <p className="text-primary text-[10px] font-semibold tracking-[0.2em] uppercase">Información</p>
      <div className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
        <div><span className="text-text-muted text-xs">Problema:</span> <p>{order.issue_description ?? '—'}</p></div>
        <div><span className="text-text-muted text-xs">Diagnóstico:</span> <p>{order.diagnosis ?? '—'}</p></div>
        <div><span className="text-text-muted text-xs">Teléfono:</span> <p>{order.customer_phone ?? '—'}</p></div>
        <div>
          <span className="text-text-muted text-xs">Mano de obra:</span>
          <p>{money(order.labor_base_amount)}</p>
        </div>
        <div>
          <span className="text-text-muted text-xs">Piezas:</span>
          <p>{money(order.parts_base_amount)}</p>
        </div>
        <div>
          <span className="text-text-muted text-xs">Total:</span>
          <p className="font-semibold">{money(order.total_base_amount)}</p>
        </div>
      </div>
    </div>
  );
}

function PartsCard({ order }: { order: ServiceOrder }) {
  const canUpdate = useCan(PERMISSIONS.SERVICE_ORDERS_UPDATE);
  const [search, setSearch] = useState('');
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const addPart = useAddServiceOrderPart();
  const removePart = useRemoveServiceOrderPart();

  const { data: productPage } = useProducts(
    {
      search,
      page: 1,
      per_page: 20,
      tracking_type: 'all',
      stock_status: 'all',
      active_status: 'active',
    },
    { enabled: canUpdate && ['received', 'diagnosed', 'in_progress', 'ready'].includes(order.status) },
  );

  const openStatus = ['received', 'diagnosed', 'in_progress', 'ready'].includes(order.status);

  return (
    <div className="border-border bg-surface rounded-lg border p-4">
      <div className="flex items-center justify-between">
        <p className="text-primary text-[10px] font-semibold tracking-[0.2em] uppercase">Piezas</p>
        <Badge variant="default">{order.parts?.length ?? 0}</Badge>
      </div>

      {(order.parts ?? []).length > 0 && (
        <ul className="mt-2 divide-y divide-border">
          {(order.parts ?? []).map((part) => (
            <li key={part.id} className="flex items-center justify-between gap-2 py-2 text-sm">
              <div className="min-w-0">
                <p className="truncate font-medium">{part.product?.name ?? `Producto #${part.product_id}`}</p>
                <p className="text-text-muted text-xs">
                  {part.quantity} × {money(Number(part.unit_price ?? 0))}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={part.status === 'consumed' ? 'success' : 'default'}>{part.status}</Badge>
                {part.status === 'pending' && canUpdate && openStatus && (
                  <Button size="icon-sm" variant="ghost" onClick={() => removePart.mutate({ orderId: order.id, partId: part.id })}>
                    Quitar
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {canUpdate && openStatus && (
        <div className="mt-3 space-y-2">
          <div className="relative">
            <Search className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar pieza en el inventario..."
              className="pl-9"
            />
          </div>
          <div className="flex gap-2">
            <Select value={productId} onChange={(e) => setProductId(e.target.value)} className="flex-1" data-testid="ws-part-product">
              <option value="">Selecciona pieza...</option>
              {(productPage?.data ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} ({p.sku})
                </option>
              ))}
            </Select>
            <Input
              type="text"
              inputMode="numeric"
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              placeholder="Cant."
              className="w-20"
            />
            <Button
              size="sm"
              disabled={!productId || !Number(quantity)}
              onClick={() =>
                addPart.mutate(
                  { id: order.id, values: { product_id: Number(productId), quantity: Number(quantity) } },
                  {
                    onSuccess: () => {
                      toast.success('Pieza agregada');
                      setProductId('');
                      setQuantity('1');
                    },
                    onError: (err) => toast.error(err instanceof Error ? err.message : 'Error'),
                  },
                )
              }
            >
              Agregar
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function DiagnoseCard({ order }: { order: ServiceOrder }) {
  const canUpdate = useCan(PERMISSIONS.SERVICE_ORDERS_UPDATE);
  const [diagnosis, setDiagnosis] = useState('');
  const [labor, setLabor] = useState('0');
  const diagnose = useDiagnoseServiceOrder();

  if (!canUpdate || !['received'].includes(order.status)) {
    if (order.diagnosis) return null;
    return null;
  }

  return (
    <div className="border-border bg-surface rounded-lg border p-4">
      <p className="text-primary text-[10px] font-semibold tracking-[0.2em] uppercase">Diagnóstico</p>
      <div className="mt-2 space-y-2">
        <Textarea rows={2} value={diagnosis} onChange={(e) => setDiagnosis(e.target.value)} placeholder="Descripción del diagnóstico" data-testid="ws-diagnose-text" />
        <div className="flex gap-2">
          <Input type="text" inputMode="decimal" value={labor} onChange={(e) => setLabor(e.target.value)} placeholder="Mano de obra USD" data-testid="ws-diagnose-labor" />
          <Button
            size="sm"
            disabled={!diagnosis.trim()}
            onClick={() =>
              diagnose.mutate(
                { id: order.id, values: { diagnosis, labor_base_amount: Number(labor) || 0 } },
                { onSuccess: () => toast.success('Diagnóstico guardado'), onError: (e) => toast.error(e instanceof Error ? e.message : 'Error') },
              )
            }
          >
            Guardar
          </Button>
        </div>
      </div>
    </div>
  );
}

function TechnicianCard({ order }: { order: ServiceOrder }) {
  const canAssign = useCan(PERMISSIONS.SERVICE_ORDERS_ASSIGN_TECHNICIAN);
  const [technicianId, setTechnicianId] = useState(order.technician_id ? String(order.technician_id) : '');
  const [warehouseId, setWarehouseId] = useState(order.warehouse_id ? String(order.warehouse_id) : '');
  const assign = useAssignTechnician();

  const { data: users } = useUsers({ per_page: 100 }, canAssign);
  const { data: warehouses = [] } = useWarehouses();

  if (!canAssign) return null;

  return (
    <div className="border-border bg-surface rounded-lg border p-4">
      <p className="text-primary text-[10px] font-semibold tracking-[0.2em] uppercase">Técnico</p>
      <div className="mt-2 space-y-2">
        <Select value={technicianId} onChange={(e) => setTechnicianId(e.target.value)} data-testid="ws-assign-tech">
          <option value="">Técnico...</option>
          {(users?.data ?? []).map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </Select>
        <Select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} data-testid="ws-assign-wh">
          <option value="">Almacén de trabajo...</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.code} - {w.name}
            </option>
          ))}
        </Select>
        <Button
          size="sm"
          disabled={!technicianId || !warehouseId}
          onClick={() =>
            assign.mutate(
              { id: order.id, values: { technician_id: Number(technicianId), warehouse_id: Number(warehouseId) } },
              { onSuccess: () => toast.success('Técnico asignado'), onError: (e) => toast.error(e instanceof Error ? e.message : 'Error') },
            )
          }
        >
          Asignar
        </Button>
      </div>
    </div>
  );
}

function ActionsCard({ order, busy }: { order: ServiceOrder; busy: boolean }) {
  const canClose = useCan(PERMISSIONS.SERVICE_ORDERS_CLOSE);
  const complete = useCompleteServiceOrder();
  const cancel = useCancelServiceOrder();

  if (busy || !canClose) return null;
  const canComplete = ['diagnosed', 'in_progress', 'ready'].includes(order.status);
  const canCancel = ['received', 'diagnosed', 'in_progress', 'ready'].includes(order.status);

  return (
    <div className="border-border bg-surface space-y-2 rounded-lg border p-4">
      {canComplete && (
        <Button
          className="w-full"
          variant="danger"
          onClick={() =>
            complete.mutate(order.id, {
              onSuccess: () => toast.success('Orden completada: stock descontado y comisión registrada'),
              onError: (e) => toast.error(e instanceof Error ? e.message : 'Error'),
            })
          }
        >
          Completar y entregar
        </Button>
      )}
      {canCancel && (
        <Button className="w-full" variant="outline" onClick={() => cancel.mutate(order.id)}>
          Cancelar orden
        </Button>
      )}
      {order.status === 'delivered' && (
        <p className="text-text-muted text-xs">Orden entregada. La garantía fue resuelta (si aplica).</p>
      )}
    </div>
  );
}

function CreateServiceOrderDialog({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const { data: warehouses = [] } = useWarehouses();
  const create = useCreateServiceOrder();
  const [form, setForm] = useState({
    type: 'repair',
    resolution: '',
    customer_name: '',
    customer_phone: '',
    device_description: '',
    issue_description: '',
    warehouse_id: '',
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>Nueva orden de taller</DialogTitle>
        </DialogHeader>
        <div className="space-y-3">
          <div className="grid gap-2 sm:grid-cols-2">
            <div className="space-y-1">
              <Label>Tipo</Label>
              <Select
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value, resolution: '' })}
                data-testid="ws-create-type"
              >
                {SERVICE_ORDER_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {TYPE_LABELS[t]}
                  </option>
                ))}
              </Select>
            </div>
            {form.type === 'warranty' && (
              <div className="space-y-1">
                <Label>Tratamiento (garantía)</Label>
                <Select
                  value={form.resolution}
                  onChange={(e) => setForm({ ...form, resolution: e.target.value })}
                  data-testid="ws-create-resolution"
                >
                  <option value="">Selecciona...</option>
                  {SERVICE_ORDER_RESOLUTIONS.map((r) => (
                    <option key={r} value={r}>
                      {RESOLUTION_LABELS[r]}
                    </option>
                  ))}
                </Select>
              </div>
            )}
          </div>
          <div className="grid gap-2 sm:grid-cols-2">
            <div className="space-y-1">
              <Label>Cliente</Label>
              <Input value={form.customer_name} onChange={(e) => setForm({ ...form, customer_name: e.target.value })} placeholder="Nombre" data-testid="ws-create-customer" />
            </div>
            <div className="space-y-1">
              <Label>Teléfono</Label>
              <Input value={form.customer_phone} onChange={(e) => setForm({ ...form, customer_phone: e.target.value })} placeholder="0412..." />
            </div>
          </div>
          <div className="space-y-1">
            <Label>Equipo / Dispositivo</Label>
            <Input value={form.device_description} onChange={(e) => setForm({ ...form, device_description: e.target.value })} placeholder="Ej. iPhone 11, Lavadora 16kg..." data-testid="ws-create-device" />
          </div>
          <div className="space-y-1">
            <Label>Problema reportado</Label>
            <Textarea rows={2} value={form.issue_description} onChange={(e) => setForm({ ...form, issue_description: e.target.value })} data-testid="ws-create-issue" />
          </div>
          <div className="space-y-1">
            <Label>Almacén de trabajo</Label>
            <Select value={form.warehouse_id} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })} data-testid="ws-create-warehouse">
              <option value="">Selecciona almacén...</option>
              {warehouses.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.code} - {w.name}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={create.isPending}>
            Cancelar
          </Button>
          <Button
            type="button"
            loading={create.isPending}
            disabled={!form.warehouse_id || (form.type === 'warranty' && !form.resolution)}
            onClick={() =>
              create.mutate(
                {
                  type: form.type,
                  resolution: form.type === 'warranty' ? form.resolution : null,
                  customer_name: form.customer_name || null,
                  customer_phone: form.customer_phone || null,
                  device_description: form.device_description || null,
                  issue_description: form.issue_description || null,
                  warehouse_id: Number(form.warehouse_id),
                },
                { onSuccess: onCreated, onError: (e) => toast.error(e instanceof Error ? e.message : 'Error') },
              )
            }
          >
            Crear orden
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}