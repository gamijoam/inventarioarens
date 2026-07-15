/**
 * SuppliersManager: CRUD de proveedores con busqueda, filtro activo/inactivo.
 * Mismo patron que CustomersManager (refactorizable a un shared component).
 */
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { z } from 'zod';
import { Plus, Pencil, Trash2, Search } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
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
  useSuppliers,
  useCreateSupplier,
  useUpdateSupplier,
  useDeleteSupplier,
} from '@/features/suppliers/api';
import {
  SUPPLIER_DOCUMENT_LABELS,
  SUPPLIER_DOCUMENT_TYPES,
  StoreSupplierSchema,
  type Supplier,
  type SupplierDocumentType,
} from '@/features/suppliers/schemas';

type FormValues = z.input<typeof StoreSupplierSchema>;

export function SuppliersManager() {
  const [search, setSearch] = useState('');
  const [activeOnly, setActiveOnly] = useState(true);
  const { data: suppliers = [], isLoading } = useSuppliers({
    search: search || undefined,
    active_only: activeOnly,
  });
  const create = useCreateSupplier();
  const update = useUpdateSupplier();
  const remove = useDeleteSupplier();
  const [editing, setEditing] = useState<Supplier | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Supplier | null>(null);

  if (isLoading) return <Skeleton className="h-32 w-full" />;

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por nombre o documento..."
            className="pl-9"
          />
        </div>
        <div className="flex items-center gap-2">
          <Switch
            id="s-active-only"
            checked={activeOnly}
            onCheckedChange={setActiveOnly}
          />
          <Label htmlFor="s-active-only">Solo activos</Label>
        </div>
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)} className="ml-auto">
          Nuevo proveedor
        </Button>
      </div>

      {suppliers.length === 0 ? (
        <EmptyState
          title={search ? 'Sin resultados' : 'Sin proveedores'}
          description={
            search
              ? 'Ningun proveedor coincide con la busqueda.'
              : 'Crea el primer proveedor para poder registrar ordenes de compra.'
          }
        />
      ) : (
        <div className="rounded-lg border border-border bg-surface">
          <table className="w-full table-dense">
            <thead className="border-b border-border bg-bg/60 text-left">
              <tr>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Nombre</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Documento</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Contacto</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {suppliers.map((s) => (
                <tr key={s.id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 font-medium">{s.name}</td>
                  <td className="px-3 py-2 text-text-muted">
                    {s.document_type ? (
                      <code className="rounded bg-bg px-1.5 py-0.5 text-xs">
                        {s.document_type}-{s.document_number}
                      </code>
                    ) : (
                      <span className="text-text-muted">-</span>
                    )}
                  </td>
                  <td className="px-3 py-2 text-text-muted">
                    <div className="flex flex-col text-xs">
                      {s.phone && <span>{s.phone}</span>}
                      {s.email && <span className="text-text-muted">{s.email}</span>}
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    <Badge variant={s.is_active ? 'success' : 'default'}>
                      {s.is_active ? 'Activo' : 'Inactivo'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button size="icon-sm" variant="ghost" onClick={() => setEditing(s)} aria-label={`Editar ${s.name}`}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(s)} aria-label={`Eliminar ${s.name}`}>
                        <Trash2 className="size-4 text-danger" />
                      </Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(creating || editing) && (
        <FormDialog
          supplier={editing}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Proveedor actualizado.');
              } else {
                await create.mutateAsync(values);
                toast.success('Proveedor creado.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar proveedor.');
            }
          }}
          loading={create.isPending || update.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setDeleting(null); }}
          title={`Eliminar proveedor "${deleting.name}"`}
          description="El proveedor quedara inactivo y no aparecera en nuevas ordenes de compra."
          confirmLabel="Eliminar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Proveedor eliminado.');
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al eliminar.');
            }
          }}
        />
      )}
    </>
  );
}

function FormDialog({
  supplier,
  onClose,
  onSubmit,
  loading,
}: {
  supplier: Supplier | null;
  onClose: () => void;
  onSubmit: (values: FormValues) => Promise<void>;
  loading: boolean;
}) {
  const form = useForm<FormValues>({
    resolver: zodResolver(StoreSupplierSchema),
    defaultValues: {
      name: supplier?.name ?? '',
      document_type: (supplier?.document_type as SupplierDocumentType) ?? undefined,
      document_number: supplier?.document_number ?? '',
      phone: supplier?.phone ?? '',
      email: supplier?.email ?? '',
      fiscal_address: supplier?.fiscal_address ?? '',
      notes: supplier?.notes ?? '',
      is_active: supplier?.is_active ?? true,
    },
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{supplier ? 'Editar proveedor' : 'Nuevo proveedor'}</DialogTitle>
          <DialogDescription>
            Documento y email son opcionales para proveedores informales.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit((values) => void onSubmit(values))} className="space-y-3">
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="Distribuidora XYZ" />
          </Field>
          <div className="grid grid-cols-3 gap-2">
            <Field label="Tipo doc." error={form.formState.errors.document_type?.message}>
              <Select
                value={form.watch('document_type') ?? ''}
                onChange={(e) => {
                  const v = e.target.value;
                  form.setValue('document_type', (v ? (v as SupplierDocumentType) : undefined), { shouldValidate: true });
                }}
              >
                <option value="">Sin documento</option>
                {SUPPLIER_DOCUMENT_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {SUPPLIER_DOCUMENT_LABELS[t]}
                  </option>
                ))}
              </Select>
            </Field>
            <div className="col-span-2">
              <Field label="Numero" error={form.formState.errors.document_number?.message}>
                <Input {...form.register('document_number')} placeholder="J-12345678-0" />
              </Field>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Telefono" error={form.formState.errors.phone?.message}>
              <Input {...form.register('phone')} placeholder="+58 212 1234567" />
            </Field>
            <Field label="Email" error={form.formState.errors.email?.message}>
              <Input type="email" {...form.register('email')} placeholder="ventas@proveedor.com" />
            </Field>
          </div>
          <Field label="Direccion fiscal" error={form.formState.errors.fiscal_address?.message}>
            <Textarea {...form.register('fiscal_address')} rows={2} />
          </Field>
          <Field label="Notas internas" error={form.formState.errors.notes?.message}>
            <Textarea {...form.register('notes')} rows={2} placeholder="Condiciones de pago, dias de entrega, etc." />
          </Field>
          <div className="flex items-center gap-2">
            <Switch
              id="s-active"
              checked={Boolean(form.watch('is_active'))}
              onCheckedChange={(v) => form.setValue('is_active', v)}
            />
            <Label htmlFor="s-active">Proveedor activo</Label>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>{supplier ? 'Guardar' : 'Crear'}</Button>
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
