/**
 * CreateSpinoffDialog: formulario para crear una empresa hija (spinoff)
 * dentro de un grupo. Solo Owners del grupo pueden invocar este dialog.
 *
 * Estructura del payload (valida contra CreateSpinoffPayloadSchema):
 *  - name, slug, plan?, domain?
 *  - admin.name, admin.email, admin.password? (si no viene, el backend genera una)
 *  - branch? warehouse? exchange_rate_type? (opcionales, igual que en el dialog de grupo)
 */
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Building2, Loader2, Plus } from 'lucide-react';
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
  useCreateSpinoff,
  type CreateSpinoffPayload,
  type TenantGroup,
  type TenantSpinoff,
} from './tenantGroupsApi';

interface CreateSpinoffDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: TenantGroup;
  onCreated: (spinoff: TenantSpinoff) => void;
}

export function CreateSpinoffDialog({
  open,
  onOpenChange,
  group,
  onCreated,
}: CreateSpinoffDialogProps) {
  const create = useCreateSpinoff(group.id);
  const [submitting, setSubmitting] = useState(false);
  const [showOptional, setShowOptional] = useState(false);

  const form = useForm<CreateSpinoffPayload>({
    defaultValues: {
      name: '',
      slug: '',
      admin: { name: '', email: '', password: '' },
    },
    mode: 'onChange',
  });

  useEffect(() => {
    if (!open) {
      form.reset();
      setShowOptional(false);
    }
  }, [open, form]);

  const watchName = form.watch('name');
  useEffect(() => {
    const slug = slugify(watchName);
    if (slug && !form.getValues('slug')) {
      form.setValue('slug', slug, { shouldValidate: false });
    }
  }, [watchName, form]);

  async function onSubmit(values: CreateSpinoffPayload) {
    setSubmitting(true);
    try {
      const res = await create.mutateAsync(values);
      const payload = res as { data?: TenantSpinoff };
      const spinoff = payload.data;
      if (spinoff) {
        toast.success(`Empresa "${spinoff.name}" creada dentro de "${group.name}".`);
        onCreated(spinoff);
      } else {
        toast.success('Empresa creada.');
        onOpenChange(false);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al crear la empresa.');
    } finally {
      setSubmitting(false);
    }
  }

  const errors = form.formState.errors;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Building2 className="size-4" /> Agregar empresa a {group.name}
          </DialogTitle>
          <DialogDescription>
            Esta empresa quedara como spinoff (hija) del grupo{' '}
            <strong className="font-mono">{group.name}</strong>{' '}
            <span className="font-mono text-text-muted">({group.slug})</span>.
            Tu como Owner del grupo seras Administrador de esta empresa tambien.
          </DialogDescription>
        </DialogHeader>

        {/* Banner explicito de a que grupo pertenece esta empresa, para evitar
            confusiones al crear spinoffs (bug: parent_id mal asignado). */}
        <div
          className="flex items-start gap-2 rounded-md border border-primary/30 bg-primary/5 p-3 text-xs"
          data-testid="spinoff-parent-banner"
          role="status"
        >
          <Building2 className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
          <div className="min-w-0 flex-1">
            <p className="font-semibold">Empresa nueva -&gt; Grupo: {group.name}</p>
            <p className="mt-0.5 font-mono text-text-muted">
              slug grupo: {group.slug} · id: {group.id}
            </p>
            <p className="mt-1 text-text-secondary">
              Si este NO es el grupo al que pertenece la empresa, cierra este dialog y
              abrielo desde el grupo correcto.
            </p>
          </div>
        </div>

        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <fieldset className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1">
                <Label htmlFor="spinoff-name">Nombre de la empresa</Label>
                <Input
                  id="spinoff-name"
                  {...form.register('name', { required: 'Requerido' })}
                  placeholder="Sucursal Valencia"
                  data-testid="create-spinoff-name"
                />
                {errors.name && <p className="text-xs text-danger">{errors.name.message}</p>}
              </div>
              <div className="space-y-1">
                <Label htmlFor="spinoff-slug">Slug</Label>
                <Input
                  id="spinoff-slug"
                  {...form.register('slug', {
                    required: 'Requerido',
                    pattern: {
                      value: /^[a-z0-9-]+$/,
                      message: 'Solo letras minusculas, numeros y guiones',
                    },
                  })}
                  placeholder="sucursal-valencia"
                  data-testid="create-spinoff-slug"
                />
                {errors.slug && <p className="text-xs text-danger">{errors.slug.message}</p>}
              </div>
            </div>
          </fieldset>

          <fieldset className="space-y-3 rounded-md border border-border p-3">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              Administrador de la nueva empresa
            </legend>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1">
                <Label htmlFor="spinoff-admin-name">Nombre</Label>
                <Input
                  id="spinoff-admin-name"
                  {...form.register('admin.name', { required: 'Requerido' })}
                  placeholder="Admin Valencia"
                  data-testid="create-spinoff-admin-name"
                />
                {errors.admin?.name && (
                  <p className="text-xs text-danger">{errors.admin.name.message}</p>
                )}
              </div>
              <div className="space-y-1">
                <Label htmlFor="spinoff-admin-email">Email</Label>
                <Input
                  id="spinoff-admin-email"
                  type="email"
                  {...form.register('admin.email', {
                    required: 'Requerido',
                    pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Email invalido' },
                  })}
                  placeholder="admin.valencia@empresa.com"
                  data-testid="create-spinoff-admin-email"
                />
                {errors.admin?.email && (
                  <p className="text-xs text-danger">{errors.admin.email.message}</p>
                )}
              </div>
            </div>
            <div className="space-y-1">
              <Label htmlFor="spinoff-admin-password">
                Contrasena{' '}
                <span className="text-text-muted">(opcional, min. 8 caracteres)</span>
              </Label>
              <Input
                id="spinoff-admin-password"
                type="password"
                {...form.register('admin.password', {
                  minLength: { value: 8, message: 'Minimo 8 caracteres' },
                })}
                placeholder="Dejar vacio para que el sistema genere una"
              />
              {errors.admin?.password && (
                <p className="text-xs text-danger">{errors.admin.password.message}</p>
              )}
            </div>
          </fieldset>

          <div>
            <button
              type="button"
              className="text-xs text-text-secondary underline"
              onClick={() => setShowOptional((s) => !s)}
            >
              {showOptional ? '- Ocultar' : '+ Mostrar'} sucursal / almacen / tasa
            </button>
            {showOptional && (
              <div className="mt-3 space-y-3 rounded-md border border-border p-3">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="spinoff-branch-name">Sucursal (nombre)</Label>
                    <Input id="spinoff-branch-name" {...form.register('branch.name')} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="spinoff-branch-code">Sucursal (codigo)</Label>
                    <Input id="spinoff-branch-code" {...form.register('branch.code')} />
                  </div>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="spinoff-warehouse-name">Almacen (nombre)</Label>
                    <Input id="spinoff-warehouse-name" {...form.register('warehouse.name')} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="spinoff-warehouse-code">Almacen (codigo)</Label>
                    <Input id="spinoff-warehouse-code" {...form.register('warehouse.code')} />
                  </div>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="spinoff-rate-code">Tipo de tasa (codigo)</Label>
                    <Input id="spinoff-rate-code" {...form.register('exchange_rate_type.code')} />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="spinoff-rate-name">Tipo de tasa (nombre)</Label>
                    <Input
                      id="spinoff-rate-name"
                      {...form.register('exchange_rate_type.name')}
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
            <Button type="submit" disabled={submitting} data-testid="create-spinoff-submit">
              {submitting ? (
                <Loader2 className="size-3.5 animate-spin" />
              ) : (
                <Plus className="size-3.5" />
              )}
              Crear empresa
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