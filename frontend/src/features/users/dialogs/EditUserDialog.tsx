/**
 * EditUserDialog: dialog para editar el NOMBRE de un usuario.
 *
 * Backend: PATCH /api/users/{id}
 *   Body: { name } (otros campos son inmutables via esta ruta).
 *
 * El backend NO permite editar el email de un user existente (por seguridad:
 * eso requerira re-verificacion). Para cambiar el email hay que desactivar
 * el user y crear uno nuevo. Worklow fuera de scope de Fase B.
 *
 * El cambio de roles se hace en ChangeRolesDialog.
 * El cambio de status se hace en StatusToggle.
 */
import { useEffect, useState } from 'react';
import { Save } from 'lucide-react';
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

import { useUpdateUser, type User } from '../api';

interface EditUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: User | null;
  onUpdated?: () => void;
}

export function EditUserDialog({ open, onOpenChange, user, onUpdated }: EditUserDialogProps) {
  const [name, setName] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open && user) {
      setName(user.name);
      setError(null);
    }
  }, [open, user]);

  // El hook debe estar en el top-level, no dentro de un if.
  const update = useUpdateUser();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    if (name.trim().length < 1) {
      setError('Requerido.');
      return;
    }
    setSubmitting(true);
    try {
      await update.mutateAsync({ id: user.id, values: { name: name.trim() } });
      toast.success('Nombre actualizado.');
      onUpdated?.();
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al actualizar.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Editar nombre</DialogTitle>
          <DialogDescription>
            Cambia el nombre visible del usuario. El email no se puede editar
            (por seguridad). Para cambiar roles, usa &quot;Cambiar roles&quot;.
          </DialogDescription>
        </DialogHeader>

        {user && (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="edit-name">Nombre *</Label>
              <Input
                id="edit-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={150}
                data-testid="edit-user-name"
              />
              {error && <p className="text-xs text-danger">{error}</p>}
            </div>
            <div className="text-xs text-text-muted">
              Email: <code className="rounded bg-bg px-1 py-0.5">{user.email}</code>
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
                leftIcon={<Save className="size-4" />}
                data-testid="edit-user-submit"
              >
                Guardar
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}