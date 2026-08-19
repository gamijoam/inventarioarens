/**
 * ChangePasswordDialog: cambia la contrasena de un usuario (requiere
 * users.update). POST /api/users/{id}/password.
 */
import { useEffect, useState } from 'react';
import { KeyRound } from 'lucide-react';
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

import { useChangePassword, type User } from '../api';
import { ChangePasswordInputSchema } from '../schemas';

interface ChangePasswordDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: User | null;
}

export function ChangePasswordDialog({ open, onOpenChange, user }: ChangePasswordDialogProps) {
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const changePassword = useChangePassword();

  useEffect(() => {
    if (open) {
      setNewPassword('');
      setConfirmPassword('');
      setError(null);
    }
  }, [open]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    const parsed = ChangePasswordInputSchema.safeParse({
      new_password: newPassword,
      confirm_password: confirmPassword,
    });
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Datos invalidos.');
      return;
    }
    setSubmitting(true);
    try {
      await changePassword.mutateAsync({
        id: user.id,
        values: { new_password: newPassword, confirm_password: confirmPassword },
      });
      toast.success('Contrasena actualizada. El usuario debera volver a iniciar sesion.');
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al cambiar la contrasena.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Cambiar contrasena</DialogTitle>
          <DialogDescription>
            Define una nueva contrasena para <strong>{user?.email}</strong>. El usuario debera
            volver a iniciar sesion con la nueva clave.
          </DialogDescription>
        </DialogHeader>

        {user && (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="new-password">Nueva contrasena *</Label>
              <div className="relative">
                <KeyRound
                  className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                  aria-hidden="true"
                />
                <Input
                  id="new-password"
                  type="password"
                  autoComplete="new-password"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  placeholder="Minimo 8 caracteres con letras y numeros"
                  className="pl-9"
                  data-testid="user-new-password"
                />
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="confirm-password">Confirmar contrasena *</Label>
              <Input
                id="confirm-password"
                type="password"
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                placeholder="Repite la contrasena"
                data-testid="user-confirm-password"
              />
            </div>
            {error && <p className="text-xs text-danger">{error}</p>}

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
                leftIcon={<KeyRound className="size-4" />}
                data-testid="user-change-password-submit"
              >
                Cambiar contrasena
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
