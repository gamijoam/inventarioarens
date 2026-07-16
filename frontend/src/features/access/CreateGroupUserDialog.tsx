/**
 * CreateGroupUserDialog: formulario para adjuntar (o crear) un usuario
 * en uno de los tenants del grupo. Solo Owners del grupo pueden llamar
 * el endpoint.
 *
 * Caso de uso tipico: un Owner de "Mi Holding" quiere agregar a un
 * nuevo vendedor a "Sucursal Caracas" (spinoff del grupo). Desde aca
 * lo crea y lo asigna al spinoff correcto.
 */
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { UserPlus, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';

import {
  useAttachGroupUser,
  type TenantGroup,
  type TenantSpinoff,
} from './tenantGroupsApi';

interface CreateGroupUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: TenantGroup;
  spinoffs: TenantSpinoff[];
  onCreated: () => void;
}

interface FormValues {
  name: string;
  email: string;
  password: string;
  tenant_slug: string;
}

export function CreateGroupUserDialog({
  open,
  onOpenChange,
  group,
  spinoffs,
  onCreated,
}: CreateGroupUserDialogProps) {
  const create = useAttachGroupUser(group.id);
  const [submitting, setSubmitting] = useState(false);

  // El user puede adjuntarse al grupo o a cualquiera de sus spinoffs.
  const tenantOptions = [
    { slug: group.slug, name: `${group.name} (grupo)` },
    ...spinoffs.map((s) => ({ slug: s.slug, name: `${s.name} (${s.slug})` })),
  ];

  const form = useForm<FormValues>({
    defaultValues: {
      name: '',
      email: '',
      password: '',
      tenant_slug: group.slug,
    },
    mode: 'onTouched',
  });

  useEffect(() => {
    if (!open) {
      form.reset();
    }
  }, [open, form]);

  async function onSubmit(values: FormValues) {
    setSubmitting(true);
    try {
      await create.mutateAsync({
        name: values.name,
        email: values.email,
        password: values.password || undefined,
        tenant_slug: values.tenant_slug,
        status: 'active',
        roles: ['Vendedor'],
      });
      toast.success(`Usuario ${values.email} agregado a ${values.tenant_slug}.`);
      onCreated();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Error al agregar el usuario.');
    } finally {
      setSubmitting(false);
    }
  }

  const errors = form.formState.errors;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <UserPlus className="size-4" /> Agregar usuario a {group.name}
          </DialogTitle>
          <DialogDescription>
            Crea un nuevo usuario (o adjunta uno existente) y lo asigna a una empresa
            del grupo. Como Owner, tu nuevo usuario recibira el rol <strong>Vendedor</strong>
            (puedes cambiarlo despues desde la gestion de usuarios de la empresa).
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-3">
          <div className="space-y-1">
            <Label htmlFor="user-name">Nombre</Label>
            <Input
              id="user-name"
              {...form.register('name', { required: 'Requerido' })}
              placeholder="Juan Perez"
              data-testid="create-group-user-name"
            />
            {errors.name && <p className="text-xs text-danger">{errors.name.message}</p>}
          </div>

          <div className="space-y-1">
            <Label htmlFor="user-email">Email</Label>
            <Input
              id="user-email"
              type="email"
              {...form.register('email', {
                required: 'Requerido',
                pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Email invalido' },
              })}
              placeholder="vendedor@empresa.com"
              data-testid="create-group-user-email"
            />
            {errors.email && <p className="text-xs text-danger">{errors.email.message}</p>}
          </div>

          <div className="space-y-1">
            <Label htmlFor="user-tenant">Empresa destino</Label>
            <Select
              id="user-tenant"
              {...form.register('tenant_slug', { required: 'Requerido' })}
              data-testid="create-group-user-tenant"
            >
              {tenantOptions.map((t) => (
                <option key={t.slug} value={t.slug}>
                  {t.name}
                </option>
              ))}
            </Select>
            {errors.tenant_slug && (
              <p className="text-xs text-danger">{errors.tenant_slug.message}</p>
            )}
          </div>

          <div className="space-y-1">
            <Label htmlFor="user-password">
              Contrasena{' '}
              <span className="text-text-muted">(opcional, min. 8 caracteres)</span>
            </Label>
            <Input
              id="user-password"
              type="password"
              {...form.register('password', {
                minLength: { value: 8, message: 'Minimo 8 caracteres' },
              })}
              placeholder="Dejar vacio para que el sistema genere una"
              data-testid="create-group-user-password"
            />
            {errors.password && (
              <p className="text-xs text-danger">{errors.password.message}</p>
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
            <Button type="submit" disabled={submitting} data-testid="create-group-user-submit">
              {submitting ? (
                <Loader2 className="size-3.5 animate-spin" />
              ) : (
                <UserPlus className="size-3.5" />
              )}
              Agregar usuario
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
