/**
 * CreateGroupDialog: formulario para crear un grupo + tenant inicial en
 * una sola transaccion. Usado por el Owner real que quiere crear SU
 * organizacion sin pasar por platform admin.
 *
 * Estructura del payload (valida contra CreateGroupPayloadSchema):
 *  - group.name, group.slug, group.plan?, group.domain?
 *  - tenant.name, tenant.slug, tenant.plan?, tenant.domain?
 *    + branch? (con code)
 *    + warehouse? (con code, requiere branch previo)
 *    + exchange_rate_type? (code + name)
 *  - admin.name, admin.email, admin.password? (si no viene, el backend genera una)
 *
 * Al confirmar exitosamente, el admin queda como Owner del grupo Y
 * Administrador del tenant inicial.
 */
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Loader2, Plus } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';

import {
  useCreateTenantGroup,
  type CreateGroupPayload,
  type TenantGroup,
  type TenantSpinoff,
} from './tenantGroupsApi';

interface CreateGroupDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (group: TenantGroup, tenant: TenantSpinoff) => void;
}

export function CreateGroupDialog({ open, onOpenChange, onCreated }: CreateGroupDialogProps) {
  const create = useCreateTenantGroup();
  const [submitting, setSubmitting] = useState(false);
  const [showOptional, setShowOptional] = useState(false);

  const form = useForm<CreateGroupPayload>({
    defaultValues: {
      group: { name: '', slug: '', plan: 'enterprise' },
      tenant: {
        name: '',
        slug: '',
        plan: 'standard',
      },
      admin: { name: '', email: '', password: '' },
    },
    mode: 'onChange',
  });

  // Reset al cerrar
  useEffect(() => {
    if (!open) {
      form.reset();
      setShowOptional(false);
    }
  }, [open, form]);

  // Auto-llenar slug desde nombre (group y tenant)
  const watchGroupName = form.watch('group.name');
  const watchTenantName = form.watch('tenant.name');
  useEffect(() => {
    const slug = slugify(watchGroupName);
    if (slug && !form.getValues('group.slug')) {
      form.setValue('group.slug', slug, { shouldValidate: false });
    }
  }, [watchGroupName, form]);
  useEffect(() => {
    const slug = slugify(watchTenantName);
    if (slug && !form.getValues('tenant.slug')) {
      form.setValue('tenant.slug', slug, { shouldValidate: false });
    }
  }, [watchTenantName, form]);

  async function onSubmit(values: CreateGroupPayload) {
    setSubmitting(true);
    try {
      const res = await create.mutateAsync(values);
      const payload = res as { data?: { group?: TenantGroup; tenant?: TenantSpinoff } };
      const group = payload.data?.group;
      const tenant = payload.data?.tenant;
      if (group && tenant) {
        toast.success(
          `Organizacion "${group.name}" creada con empresa inicial "${tenant.name}".`,
        );
        onCreated(group, tenant);
      } else {
        toast.success('Organizacion creada.');
        onOpenChange(false);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al crear la organizacion.');
    } finally {
      setSubmitting(false);
    }
  }

  const errors = form.formState.errors;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Plus className="size-4" /> Crear organizacion
          </DialogTitle>
          <DialogDescription>
            Crea un grupo (contenedor) y tu primera empresa en una sola operacion. Tu como
            admin quedaras como Owner del grupo y Administrador de la empresa.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-5">
          {/* =================== GRUPO =================== */}
          <fieldset className="space-y-3 rounded-md border border-border p-3">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              Grupo (organizacion)
            </legend>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1">
                <Label htmlFor="group-name">Nombre del grupo</Label>
                <Input
                  id="group-name"
                  {...form.register('group.name', { required: 'Requerido' })}
                  placeholder="Mi Empresa Holding"
                  data-testid="create-group-name"
                />
                {errors.group?.name && (
                  <p className="text-xs text-danger">{errors.group.name.message}</p>
                )}
              </div>
              <div className="space-y-1">
                <Label htmlFor="group-slug">Slug del grupo</Label>
                <Input
                  id="group-slug"
                  {...form.register('group.slug', {
                    required: 'Requerido',
                    pattern: {
                      value: /^[a-z0-9-]+$/,
                      message: 'Solo letras minusculas, numeros y guiones',
                    },
                  })}
                  placeholder="mi-holding"
                  data-testid="create-group-slug"
                />
                {errors.group?.slug && (
                  <p className="text-xs text-danger">{errors.group.slug.message}</p>
                )}
              </div>
            </div>
          </fieldset>

          {/* =================== EMPRESA INICIAL =================== */}
          <fieldset className="space-y-3 rounded-md border border-border p-3">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              Empresa inicial (spinoff)
            </legend>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1">
                <Label htmlFor="tenant-name">Nombre de la empresa</Label>
                <Input
                  id="tenant-name"
                  {...form.register('tenant.name', { required: 'Requerido' })}
                  placeholder="Mi Empresa Principal"
                  data-testid="create-tenant-name"
                />
                {errors.tenant?.name && (
                  <p className="text-xs text-danger">{errors.tenant.name.message}</p>
                )}
              </div>
              <div className="space-y-1">
                <Label htmlFor="tenant-slug">Slug de la empresa</Label>
                <Input
                  id="tenant-slug"
                  {...form.register('tenant.slug', {
                    required: 'Requerido',
                    pattern: {
                      value: /^[a-z0-9-]+$/,
                      message: 'Solo letras minusculas, numeros y guiones',
                    },
                  })}
                  placeholder="mi-empresa"
                  data-testid="create-tenant-slug"
                />
                {errors.tenant?.slug && (
                  <p className="text-xs text-danger">{errors.tenant.slug.message}</p>
                )}
              </div>
            </div>
          </fieldset>

          {/* =================== ADMIN / OWNER =================== */}
          <fieldset className="space-y-3 rounded-md border border-border p-3">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              Tu cuenta (Owner del grupo y Administrador de la empresa)
            </legend>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1">
                <Label htmlFor="admin-name">Nombre</Label>
                <Input
                  id="admin-name"
                  {...form.register('admin.name', { required: 'Requerido' })}
                  placeholder="Tu nombre"
                  data-testid="create-admin-name"
                />
                {errors.admin?.name && (
                  <p className="text-xs text-danger">{errors.admin.name.message}</p>
                )}
              </div>
              <div className="space-y-1">
                <Label htmlFor="admin-email">Email</Label>
                <Input
                  id="admin-email"
                  type="email"
                  {...form.register('admin.email', {
                    required: 'Requerido',
                    pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Email invalido' },
                  })}
                  placeholder="tu@email.com"
                  data-testid="create-admin-email"
                />
                {errors.admin?.email && (
                  <p className="text-xs text-danger">{errors.admin.email.message}</p>
                )}
              </div>
            </div>
            <div className="space-y-1">
              <Label htmlFor="admin-password">
                Contrasena{' '}
                <span className="text-text-muted">(opcional, min. 8 caracteres)</span>
              </Label>
              <Input
                id="admin-password"
                type="password"
                {...form.register('admin.password', {
                  minLength: { value: 8, message: 'Minimo 8 caracteres' },
                })}
                placeholder="Dejar vacio para que el sistema genere una"
                data-testid="create-admin-password"
              />
              {errors.admin?.password && (
                <p className="text-xs text-danger">{errors.admin.password.message}</p>
              )}
            </div>
          </fieldset>

          {/* =================== OPTIONAL: BRANCH/WAREHOUSE/RATE =================== */}
          <div>
            <button
              type="button"
              className="text-xs text-text-secondary underline"
              onClick={() => setShowOptional((s) => !s)}
            >
              {showOptional ? '- Ocultar' : '+ Mostrar'} datos opcionales (sucursal, almacen, tasa)
            </button>
            {showOptional && (
              <div className="mt-3 space-y-3 rounded-md border border-border p-3">
                <p className="text-xs text-text-muted">
                  Si llenas estos campos, la empresa se crea ya con sucursal, almacen y tasa BCV.
                  Si los dejas vacios, puedes crearlos despues desde la pagina de catalogos.
                </p>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="branch-name">Sucursal (nombre)</Label>
                    <Input id="branch-name" {...form.register('tenant.branch.name')} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="branch-code">Sucursal (codigo)</Label>
                    <Input id="branch-code" {...form.register('tenant.branch.code')} />
                  </div>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="warehouse-name">Almacen (nombre)</Label>
                    <Input id="warehouse-name" {...form.register('tenant.warehouse.name')} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="warehouse-code">Almacen (codigo)</Label>
                    <Input id="warehouse-code" {...form.register('tenant.warehouse.code')} />
                  </div>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="rate-code">Tipo de tasa (codigo)</Label>
                    <Input id="rate-code" {...form.register('tenant.exchange_rate_type.code')} placeholder="BCV" />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="rate-name">Tipo de tasa (nombre)</Label>
                    <Input
                      id="rate-name"
                      {...form.register('tenant.exchange_rate_type.name')}
                      placeholder="Banco Central de Venezuela"
                    />
                  </div>
                </div>
              </div>
            )}
          </div>

          <DialogFooter className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="ghost"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={submitting} data-testid="create-group-submit">
              {submitting && <Loader2 className="size-3.5 animate-spin" />}
              Crear organizacion
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function slugify(s: string): string {
  return s
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 100);
}