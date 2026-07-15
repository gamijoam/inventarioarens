/**
 * CustomersManager: CRUD de clientes con busqueda, filtro activo/inactivo
 * y paginacion. Patron consistente con los otros managers (Brands,
 * Warehouses, etc.).
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
  useCustomers,
  useCreateCustomer,
  useUpdateCustomer,
  useDeleteCustomer,
} from '@/features/customers/api';
import {
  CUSTOMER_DOCUMENT_LABELS,
  CUSTOMER_DOCUMENT_TYPES,
  StoreCustomerSchema,
  type Customer,
  type CustomerDocumentType,
} from '@/features/customers/schemas';

type FormValues = z.input<typeof StoreCustomerSchema>;

export function CustomersManager() {
  const [search, setSearch] = useState('');
  const [activeOnly, setActiveOnly] = useState(true);
  const { data: customers = [], isLoading } = useCustomers({
    search: search || undefined,
    active_only: activeOnly,
  });
  const create = useCreateCustomer();
  const update = useUpdateCustomer();
  const remove = useDeleteCustomer();
  const [editing, setEditing] = useState<Customer | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<Customer | null>(null);

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
            id="active-only"
            checked={activeOnly}
            onCheckedChange={setActiveOnly}
          />
          <Label htmlFor="active-only">Solo activos</Label>
        </div>
        <Button size="sm" leftIcon={<Plus className="size-4" />} onClick={() => setCreating(true)} className="ml-auto">
          Nuevo cliente
        </Button>
      </div>

      {customers.length === 0 ? (
        <EmptyState
          title={search ? 'Sin resultados' : 'Sin clientes'}
          description={
            search
              ? 'Ningun cliente coincide con la busqueda.'
              : 'Crea el primer cliente para poder registrarlo en ventas y CxC.'
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
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
                <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
                <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Acciones</th>
              </tr>
            </thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 font-medium">{c.name}</td>
                  <td className="px-3 py-2 text-text-muted">
                    {c.document_type ? (
                      <code className="rounded bg-bg px-1.5 py-0.5 text-xs">
                        {c.document_type}-{c.document_number}
                      </code>
                    ) : (
                      <span className="text-text-muted">-</span>
                    )}
                  </td>
                  <td className="px-3 py-2 text-text-muted">
                    <div className="flex flex-col text-xs">
                      {c.phone && <span>{c.phone}</span>}
                      {c.email && <span className="text-text-muted">{c.email}</span>}
                    </div>
                  </td>
                  <td className="px-3 py-2">
                    {c.is_generic ? <Badge variant="info">Generico</Badge> : <span className="text-text-muted">Regular</span>}
                  </td>
                  <td className="px-3 py-2">
                    <Badge variant={c.is_active ? 'success' : 'default'}>
                      {c.is_active ? 'Activo' : 'Inactivo'}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <div className="flex justify-end gap-1">
                      <Button size="icon-sm" variant="ghost" onClick={() => setEditing(c)} aria-label={`Editar ${c.name}`}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(c)} aria-label={`Eliminar ${c.name}`}>
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
          customer={editing}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSubmit={async (values) => {
            try {
              if (editing) {
                await update.mutateAsync({ id: editing.id, ...values });
                toast.success('Cliente actualizado.');
              } else {
                await create.mutateAsync(values);
                toast.success('Cliente creado.');
              }
              setCreating(false);
              setEditing(null);
            } catch (err) {
              toast.error(err instanceof Error ? err.message : 'Error al guardar cliente.');
            }
          }}
          loading={create.isPending || update.isPending}
        />
      )}

      {deleting && (
        <ConfirmDialog
          open
          onOpenChange={(open) => { if (!open) setDeleting(null); }}
          title={`Eliminar cliente "${deleting.name}"`}
          description="El cliente quedara inactivo y no aparecera en nuevas ventas."
          confirmLabel="Eliminar"
          variant="danger"
          loading={remove.isPending}
          onConfirm={async () => {
            try {
              await remove.mutateAsync(deleting.id);
              setDeleting(null);
              toast.success('Cliente eliminado.');
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
  customer,
  onClose,
  onSubmit,
  loading,
}: {
  customer: Customer | null;
  onClose: () => void;
  onSubmit: (values: FormValues) => Promise<void>;
  loading: boolean;
}) {
  const form = useForm<FormValues>({
    resolver: zodResolver(StoreCustomerSchema),
    defaultValues: {
      name: customer?.name ?? '',
      document_type: (customer?.document_type as CustomerDocumentType) ?? 'V',
      document_number: customer?.document_number ?? '',
      phone: customer?.phone ?? '',
      email: customer?.email ?? '',
      fiscal_address: customer?.fiscal_address ?? '',
      is_generic: customer?.is_generic ?? false,
      is_active: customer?.is_active ?? true,
    },
  });

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{customer ? 'Editar cliente' : 'Nuevo cliente'}</DialogTitle>
          <DialogDescription>
            Cliente venezolano, juridico o extranjero. El documento debe ser unico por empresa.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={form.handleSubmit((values) => void onSubmit(values))} className="space-y-3">
          <Field label="Nombre" required error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} placeholder="Juan Perez" />
          </Field>
          <div className="grid grid-cols-3 gap-2">
            <Field label="Tipo" required error={form.formState.errors.document_type?.message}>
              <Select
                value={form.watch('document_type') ?? 'V'}
                onChange={(e) =>
                  form.setValue('document_type', e.target.value as CustomerDocumentType, { shouldValidate: true })
                }
              >
                {CUSTOMER_DOCUMENT_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {CUSTOMER_DOCUMENT_LABELS[t]}
                  </option>
                ))}
              </Select>
            </Field>
            <div className="col-span-2">
              <Field label="Numero" required error={form.formState.errors.document_number?.message}>
                <Input {...form.register('document_number')} placeholder="12345678" />
              </Field>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Telefono" error={form.formState.errors.phone?.message}>
              <Input {...form.register('phone')} placeholder="+58 412 1234567" />
            </Field>
            <Field label="Email" error={form.formState.errors.email?.message}>
              <Input type="email" {...form.register('email')} placeholder="cliente@correo.com" />
            </Field>
          </div>
          <Field label="Direccion fiscal" error={form.formState.errors.fiscal_address?.message}>
            <Textarea {...form.register('fiscal_address')} rows={2} />
          </Field>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <Switch
                id="c-generic"
                checked={Boolean(form.watch('is_generic'))}
                onCheckedChange={(v) => form.setValue('is_generic', v)}
              />
              <Label htmlFor="c-generic">Cliente generico</Label>
            </div>
            <div className="flex items-center gap-2">
              <Switch
                id="c-active"
                checked={Boolean(form.watch('is_active'))}
                onCheckedChange={(v) => form.setValue('is_active', v)}
              />
              <Label htmlFor="c-active">Activo</Label>
            </div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose} disabled={loading}>
              Cancelar
            </Button>
            <Button type="submit" loading={loading}>{customer ? 'Guardar' : 'Crear'}</Button>
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
