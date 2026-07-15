/**
 * BranchesManager: CRUD de sucursales. Requisito de Warehouses.
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
import { useBranches, useCreateBranch, useUpdateBranch, useDeleteBranch } from '@/features/inventory-center/api';
import { StoreBranchSchema, type Branch } from '@/features/inventory-center/schemas';

type BranchFormValues = z.input<typeof StoreBranchSchema>;

export function BranchesManager() {
  const { data: branches = [], isLoading } = useBranches();
  const create = useCreateBranch();
  const update = useUpdateBranch();
  const remove = useDeleteBranch();
  const [editing, setEditing] = useState<Branch | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Branch | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
          Nueva sucursal
        </Button>
      </div>

      {branches.length === 0 ? (
        <EmptyState
          title="Sin sucursales"
          description="Crea la primera sucursal para poder asignarle almacenes."
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Nombre</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Codigo</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {branches.map((b) => {
                const isActive = (b.is_active ?? b.status === 'active');
                return (
                  <tr key={b.id} className="border-b border-border last:border-b-0">
                    <td className="px-3 py-2 font-medium">{b.name}</td>
                    <td className="px-3 py-2 text-text-muted">
                      <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{b.code}</code>
                    </td>
                    <td className="px-3 py-2">
                      <Badge variant={isActive ? 'success' : 'default'}>{isActive ? 'Activa' : 'Inactiva'}</Badge>
                    </td>
                    <td className="px-3 py-2 text-right">
                      <div className="flex justify-end gap-1">
                        <Button size="icon-sm" variant="ghost" onClick={() => setEditing(b)} aria-label={`Editar ${b.name}`}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(b)} aria-label={`Eliminar ${b.name}`}>
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
        <BranchFormDialog
          branch={editing}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Sucursal actualizada.');
              } else {
                await create.mutateAsync(values);
                toast.success('Sucursal creada.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar sucursal.');
            }
          }}
          loading={create.isPending || update.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setDeleting(null); }}
          title={`Eliminar sucursal "${deleting.name}"`}
          description="Los almacenes asociados quedaran sin sucursal asignada."
          confirmLabel="Eliminar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Sucursal eliminada.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );
}

function BranchFormDialog({
  branch,
  onClose,
  onSubmit,
  loading,
}: {
  branch: Branch | null;
  onClose: () => void;
  onSubmit: (values: BranchFormValues) => Promise<void>;
  loading: boolean;
}) {
  const form = useForm<BranchFormValues>({
    resolver: zodResolver(StoreBranchSchema),
    defaultValues: {
      name: branch?.name ?? '',
      code: branch?.code ?? '',
      status: (branch?.status as 'active' | 'inactive') ?? 'active',
    },
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{branch ? 'Editar sucursal' : 'Nueva sucursal'}</DialogTitle>
          <DialogDescription>
            Las sucursales agrupan almacenes. Ej: "Centro", "Norte", "Deposito principal".
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit((values) => void onSubmit(values))} className="space-y-3">
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="Centro" />
          </Field>
          <Field label="Codigo" required hint="Mayusculas, sin espacios" error={form.formState.errors.code?.message}>
            <Input {...form.register('code')} placeholder="CENTRO" />
          </Field>
          <div className="flex items-center gap-2">
            <Switch
              id="branch-active"
              checked={form.watch('status') !== 'inactive'}
              onCheckedChange={(v) => form.setValue('status', v ? 'active' : 'inactive')}
            />
            <Label htmlFor="branch-active">Sucursal activa</Label>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>{branch ? 'Guardar' : 'Crear'}</Button>
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
