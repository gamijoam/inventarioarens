/**
 * EditUserDialog: dialog para editar nombre y correo de un usuario.
 *
 * Backend: PATCH /api/users/{id}
 *   Body: { name, email }
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
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [emailError, setEmailError] = useState<string | null>(null);

  useEffect(() => {
    if (open && user) {
      setName(user.name);
      setEmail(user.email);
      setError(null);
      setEmailError(null);
    }
  }, [open, user]);

  // El hook debe estar en el top-level, no dentro de un if.
  const update = useUpdateUser();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;

    let hasError = false;
    if (name.trim().length < 1) {
      setError('Requerido.');
      hasError = true;
    } else {
      setError(null);
    }
    if (email.trim().length < 1) {
      setEmailError('Requerido.');
      hasError = true;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setEmailError('Email inválido.');
      hasError = true;
    } else {
      setEmailError(null);
    }

    if (hasError) return;

    setSubmitting(true);
    try {
      await update.mutateAsync({
        id: user.id,
        values: {
          name: name.trim(),
          email: email.trim().toLowerCase(),
        },
      });
      toast.success('Usuario actualizado.');
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
          <DialogTitle>Editar usuario</DialogTitle>
          <DialogDescription>
            Modifica el nombre y correo electrónico del usuario.
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

            <div className="space-y-1.5">
              <Label htmlFor="edit-email">Correo electrónico *</Label>
              <Input
                id="edit-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                maxLength={255}
                data-testid="edit-user-email"
              />
              {emailError && <p className="text-xs text-danger">{emailError}</p>}
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