/**
 * WarehousesManager: CRUD de almacenes. Cada almacen pertenece a
 * una sucursal (branch). El dropdown de sucursal incluye un
 * "+ Nueva sucursal" inline (patron de InlineCatalogCreate).
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { z } from 'zod';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Switch } from '@/components/ui/Switch';
import { Badge } from '@/components/ui/Badge';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import {
  useBranches,
  useWarehouses,
  useCreateWarehouse,
  useUpdateWarehouse,
  useDeleteWarehouse,
  useCreateBranch,
} from '@/features/inventory-center/api';
import { useQueryClient } from '@tanstack/react-query';
import { catalogKeys } from '@/features/inventory-center/queries';
import { StoreWarehouseSchema, type Warehouse, type Branch } from '@/features/inventory-center/schemas';

type WarehouseFormValues = z.input<typeof StoreWarehouseSchema>;

export function WarehousesManager() {
  const { data: warehouses = [], isLoading } = useWarehouses();
  const { data: branches = [] } = useBranches();
  const create = useCreateWarehouse();
  const update = useUpdateWarehouse();
  const remove = useDeleteWarehouse();
  const [editing, setEditing] = useState<Warehouse | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Warehouse | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nuevo almacen
        </Button>
      </div>

      {warehouses.length === 0 ? (
        <EmptyState
          title="Sin almacenes"
          description="Crea un almacen para registrar stock fisico."
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Nombre</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Codigo</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Sucursal</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {warehouses.map((w) => {
                const isActive = (w.is_active ?? w.status === 'active');
                return (
                  <tr key={w.id} className="border-b border-border last:border-b-0">
                    <td className="px-3 py-2 font-medium">{w.name}</td>
                    <td className="px-3 py-2 text-text-muted">
                      <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{w.code}</code>
                    </td>
                    <td className="px-3 py-2 text-text-muted">{w.branch_name ?? '-'}</td>
                    <td className="px-3 py-2">
                      <Badge variant={isActive ? 'success' : 'default'}>{isActive ? 'Activo' : 'Inactivo'}</Badge>
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex justify-end gap-1">
                        <Button size="icon-sm" variant="ghost" onClick={() => setEditing(w)} aria-label={`Editar ${w.name}`}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(w)} aria-label={`Eliminar ${w.name}`}>
                          <Trash2 className="size-4 text-danger" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {(creating || editing) && (
        <WarehouseFormDialog
          warehouse={editing}
          branches={branches}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Almacen actualizado.');
              } else {
                await create.mutateAsync(values);
                toast.success('Almacen creado.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar almacen.');
            }
          }}
          loading={create.isPending || update.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setDeleting(null); }}
          title={`Eliminar almacen "${deleting.name}"`}
          description="El stock registrado en este almacen quedara huerfano."
          confirmLabel="Eliminar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Almacen eliminado.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );
}

function WarehouseFormDialog({
  warehouse,
  branches,
  onClose,
  onSubmit,
  loading,
}: {
  warehouse: Warehouse | null;
  branches: Branch[];
  onClose: () => void;
  onSubmit: (values: WarehouseFormValues) => Promise<void>;
  loading: boolean;
}) {
  const qc = useQueryClient();
  const createBranch = useCreateBranch();
  const [showInlineBranch, setShowInlineBranch] = useState(false);
  const [newBranchName, setNewBranchName] = useState('');
  const [newBranchCode, setNewBranchCode] = useState('');
  const [creatingBranch, setCreatingBranch] = useState(false);

  const form = useForm<WarehouseFormValues>({
    resolver: zodResolver(StoreWarehouseSchema),
    defaultValues: {
      branch_id: warehouse?.branch_id ?? (branches[0]?.id ?? 0),
      name: warehouse?.name ?? '',
      code: warehouse?.code ?? '',
      status: (warehouse?.status as 'active' | 'inactive') ?? 'active',
    },
  });

  const handleCreateBranch = async () => {
    if (!newBranchName.trim() || !newBranchCode.trim()) return;
    setCreatingBranch(true);
    try {
      const created = await createBranch.mutateAsync({
        name: newBranchName.trim(),
        code: newBranchCode.trim().toUpperCase(),
        status: 'active',
      });
      void qc.invalidateQueries({ queryKey: catalogKeys.branches() });
      const newId = (created as { id: number }).id;
      form.setValue('branch_id', newId);
      setNewBranchName('');
      setNewBranchCode('');
      setShowInlineBranch(false);
      toast.success('Sucursal creada.');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al crear sucursal.');
    } finally {
      setCreatingBranch(false);
    }
  };

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{warehouse ? 'Editar almacen' : 'Nuevo almacen'}</DialogTitle>
          <DialogDescription>
            Cada almacen pertenece a una sucursal. Si no hay, crea la primera aca mismo.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit((values) => void onSubmit(values))} className="space-y-3">
          <div className="space-y-1.5">
            <Label>Sucursal <span className="text-danger">*</span></Label>
            <div className="flex gap-2">
              <Select
                className="flex-1"
                value={String(form.watch('branch_id') ?? '')}
                onChange={(e) => form.setValue('branch_id', Number(e.target.value), { shouldValidate: true })}
              >
                <option value="" disabled>
                  Selecciona sucursal
                </option>
                {branches.map((b) => (
                  <option key={b.id} value={String(b.id)}>
                    {b.name} ({b.code})
                  </option>
                ))}
              </Select>
              <Button type="button" size="sm" variant="outline" onClick={() => setShowInlineBranch((v) => !v)}>
                <Plus className="size-3.5" /> Sucursal
              </Button>
            </div>
            {form.formState.errors.branch_id && (
              <p className="text-xs text-danger">{form.formState.errors.branch_id.message}</p>
            )}
            {showInlineBranch && (
              <div className="mt-2 space-y-2 rounded-md border border-border bg-bg/40 p-3">
                <div className="grid grid-cols-2 gap-2">
                  <Input placeholder="Nombre" value={newBranchName} onChange={(e) => setNewBranchName(e.target.value)} />
                  <Input placeholder="Codigo" value={newBranchCode} onChange={(e) => setNewBranchCode(e.target.value)} />
                </div>
                <div className="flex justify-end">
                  <Button type="button" size="sm" loading={creatingBranch} onClick={handleCreateBranch}>
                    Crear sucursal
                  </Button>
                </div>
              </div>
            )}
          </div>
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="Almacen principal" />
          </Field>
          <Field label="Codigo" required hint="Mayusculas, sin espacios" error={form.formState.errors.code?.message}>
            <Input {...form.register('code')} placeholder="MAIN" />
          </Field>
          <div className="flex items-center gap-2">
            <Switch
              id="warehouse-active"
              checked={form.watch('status') !== 'inactive'}
              onCheckedChange={(v) => form.setValue('status', v ? 'active' : 'inactive')}
            />
            <Label htmlFor="warehouse-active">Almacen activo</Label>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>Cancelar</Button>
            <Button type="submit" loading={loading}>{warehouse ? 'Guardar' : 'Crear'}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({ label, required, hint, error, children }: { label: string; required?: boolean; hint?: string; error?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label className="flex items-center gap-1">
        {label}
        {required && <span className="text-danger">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-xs text-text-muted">{hint}</p>}
      {error && <p className="text-xs text-danger">{error}</p>}
    </div>
  );
}
