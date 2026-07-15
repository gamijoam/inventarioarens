/**
 * RoleEditor: dialog para crear o editar el NOMBRE de un rol.
 *
 * Si role es null -> modo crear (POST /api/roles).
 * Si role existe -> modo editar (PATCH /api/roles/{id}).
 *
 * Para los roles base (`is_protected`) el nombre no se puede editar,
 * asi que RoleEditor se renderiza en modo solo-lectura para ellos.
 * La modificacion de permisos se hace en RolePermissionsDialog
 * (que tambien respeta is_protected).
 */
import { useEffect, useState } from 'react';
import { Plus, Save } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';

import { useCreateRole, useUpdateRole, type Role } from './api';
import { ProtectedRoleBadge } from './ProtectedRoleBadge';

interface RoleEditorProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  role: Role | null;
  onSaved?: (role: Role) => void;
}

export function RoleEditor({ open, onOpenChange, role, onSaved }: RoleEditorProps) {
  const [name, setName] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setName(role?.name ?? '');
      setError(null);
    }
  }, [open, role]);

  const create = useCreateRole();
  const update = useUpdateRole();

  const isEdit = !!role;
  const isProtected = role?.is_protected ?? false;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (name.trim().length < 1) {
      setError('Requerido.');
      return;
    }
    setSubmitting(true);
    try {
      let saved: Role;
      if (isEdit && role) {
        saved = await update.mutateAsync({ id: role.id, values: { name: name.trim() } });
        toast.success('Rol actualizado.');
      } else {
        saved = await create.mutateAsync({ name: name.trim(), permissions: [] });
        toast.success('Rol creado.');
      }
      onSaved?.(saved);
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al guardar el rol.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <DialogTitle>{isEdit ? 'Editar rol' : 'Nuevo rol'}</DialogTitle>
            {isProtected && <ProtectedRoleBadge isProtected />}
          </div>
          <DialogDescription>
            {isProtected
              ? 'Este es un rol base del sistema. No se puede editar el nombre. Para crear una variante, usa "Duplicar".'
              : isEdit
                ? 'Cambia el nombre del rol. Para modificar sus permisos, usa "Permisos".'
                : 'Crea un rol custom del tenant. Despues podras asignarle permisos.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="role-name">Nombre *</Label>
            <Input
              id="role-name"
              value={name}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => setName(e.target.value)}
              maxLength={150}
              disabled={isProtected}
              data-testid="role-editor-name"
            />
            {error && <p className="text-xs text-danger">{error}</p>}
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancelar
            </Button>
            <Button
              type="submit"
              loading={submitting}
              disabled={isProtected}
              leftIcon={isEdit ? <Save className="size-4" /> : <Plus className="size-4" />}
              data-testid="role-editor-submit"
            >
              {isEdit ? 'Guardar' : 'Crear rol'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}